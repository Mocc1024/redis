<?php
class SeckillQueue {
    /** 管道模式，等同于 Redis::PIPELINE；写成常量避免未安装扩展时误报未定义类型 */
    const REDIS_PIPELINE = 2;

    private $redis;
    private $activityId;
    private $stockKey;
    private $queueKey;
    private $participatedKey;
    private $successKey;

    /**
     * 初始化秒杀队列
     * @param Redis $redis Redis实例
     * @param string $activityId 活动ID
     * @param int $initialStock 初始库存
     */
    public function __construct($redis, $activityId, $initialStock = 0) {
        $this->redis = $redis;
        $this->activityId = $activityId;

        // 定义Redis键名
        $this->stockKey = "seckill:{$activityId}:stock";
        $this->queueKey = "seckill:{$activityId}:queue";
        $this->participatedKey = "seckill:{$activityId}:participated";
        $this->successKey = "seckill:{$activityId}:success";

        // 如果库存键不存在且指定了初始库存，则设置库存
        if (!$this->redis->exists($this->stockKey) && $initialStock > 0) {
            $this->redis->set($this->stockKey, $initialStock);
        }
    }

    /**
     * 重置秒杀活动
     * @param int $stock 重置后的库存数量
     */
    public function reset($stock) {
        $this->redis->multi(self::REDIS_PIPELINE);
        $this->redis->set($this->stockKey, $stock);
        $this->redis->del($this->queueKey);
        $this->redis->del($this->participatedKey);
        $this->redis->del($this->successKey);
        // 判断管道中第一个命令（设置库存）是否执行成功
        return $this->redis->exec()[0] === true;
    }

    /**
     * 用户加入秒杀队列
     * @param string $userId 用户ID
     * @return array 结果数组
     */
    public function enqueueUser($userId) {
        // 检查是否已参与
        if ($this->redis->sIsMember($this->participatedKey, $userId)) {
            return [
                'success' => false,
                'message' => '您已参与过本次秒杀，请勿重复参与'
            ];
        }

        // 检查库存是否已为0
        if ($this->redis->get($this->stockKey) <= 0) {
            return [
                'success' => false,
                'message' => '秒杀已结束，商品已抢完'
            ];
        }

        // 加入队列并标记为已参与
        // MULTI 是用于开启事务的命令，它可以将多个 Redis 命令打包成一个原子操作单元，要么全部执行成功，要么全部不执行（发生错误时），避免了中间状态导致的数据不一致
        // 1、原子性执行：用 MULTI 开启事务后，后续输入的命令会被放入一个队列中，直到执行 EXEC 才会一次性执行所有命令。期间如果有命令错误，整个事务可能失败（取决于错误类型）。
        // 2、隔离性：事务执行期间，其他客户端的命令不会插入到当前事务的命令序列中，保证操作的连续性。
        $this->redis->multi(self::REDIS_PIPELINE);
        $this->redis->rPush($this->queueKey, $userId);// 右侧入队
        $this->redis->sAdd($this->participatedKey, $userId);// 添加元素到集合
        $results = $this->redis->exec();// 执行管道中的所有命令，并获取结果（一次性发送+接收）
        return [
            'success' => $results[0] !== false,
            'message' => $results[0] !== false ? '成功加入秒杀队列，请等待结果' : '加入队列失败'
        ];
    }

    /**
     * 处理秒杀队列，发放商品
     * @param int $limit 每次处理的最大数量
     * @return array 处理结果
     */
    public function processQueue($limit = 100) {
        $successUsers = [];
        $failedUsers = [];
        $processed = 0;

        // 获取当前库存
        $remainingStock = (int)$this->redis->get($this->stockKey);

        if ($remainingStock <= 0) {
            return [
                'success' => true,
                'message' => '库存已为0，无需处理',
                'data' => [
                    'processed' => 0,
                    'success_users' => $successUsers,
                    'failed_users' => $failedUsers,
                    'remaining_stock' => 0,
                    'remaining_queue' => $this->getQueueLength()
                ]
            ];
        }

        // 计算实际可处理数量（不超过库存和限制）
        $processCount = min($remainingStock, $limit, $this->getQueueLength());

        if ($processCount <= 0) {
            return [
                'success' => true,
                'message' => '没有需要处理的队列数据',
                'data' => [
                    'processed' => 0,
                    'success_users' => $successUsers,
                    'failed_users' => $failedUsers,
                    'remaining_stock' => $remainingStock,
                    'remaining_queue' => $this->getQueueLength()
                ]
            ];
        }

        // 批量处理队列
        $this->redis->multi(self::REDIS_PIPELINE);

        // 获取队列中的用户
        for ($i = 0; $i < $processCount; $i++) {
            // 从队列左侧取出用户
            $this->redis->lPop($this->queueKey);
        }

        $users = $this->redis->exec();

        // 处理每个用户
        foreach ($users as $userId) {
            if (empty($userId)) continue;

            $processed++;
            // 监视一个或多个键，若键被修改则事务取消（乐观锁机制）
            // 用 WATCH + 事务确保库存扣减的原子性
            $this->redis->watch($this->stockKey);

            $currentStock = (int)$this->redis->get($this->stockKey);

            if ($currentStock > 0) {
                // 库存充足，扣减库存并记录成功用户
                $this->redis->multi();
                $this->redis->decr($this->stockKey);
                $this->redis->sAdd($this->successKey, $userId);
                $result = $this->redis->exec();

                if ($result !== false) {
                    $successUsers[] = $userId;
                    $remainingStock--;
                    continue;
                }
            }

            // 处理失败的用户
            $failedUsers[] = $userId;
        }

        return [
            'success' => true,
            'message' => "处理完成",
            'data' => [
                'processed' => $processed,
                'success_users' => $successUsers,
                'failed_users' => $failedUsers,
                'remaining_stock' => $remainingStock,
                'remaining_queue' => $this->getQueueLength()
            ]
        ];
    }

    /**
     * 获取所有成功抢到商品的用户
     * @param int $limit 限制返回数量，0表示全部
     * @return array 成功用户列表
     */
    public function getAllSuccessUsers($limit = 0) {
        $users = $this->redis->sMembers($this->successKey);
        sort($users); // 排序用户ID，方便查看

        if ($limit > 0) {
            return array_slice($users, 0, $limit);
        }

        return $users;
    }

    /**
     * 检查用户是否秒杀成功
     * @param string $userId 用户ID
     * @return bool 是否成功
     */
    public function isUserSuccess($userId) {
        return $this->redis->sIsMember($this->successKey, $userId);
    }

    /**
     * 获取剩余库存
     * @return int 剩余库存数量
     */
    public function getRemainingStock() {
        return (int)$this->redis->get($this->stockKey);
    }

    /**
     * 获取队列长度
     * @return int 队列中的用户数量
     */
    public function getQueueLength() {
        return (int)$this->redis->lLen($this->queueKey);
    }

    /**
     * 获取参与人数
     * @return int 参与人数
     */
    public function getParticipatedCount() {
        return (int)$this->redis->sCard($this->participatedKey);
    }

    /**
     * 获取成功人数
     * @return int 成功人数
     */
    public function getSuccessCount() {
        return (int)$this->redis->sCard($this->successKey);
    }
}