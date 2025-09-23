<?php
// 引入秒杀队列类
require_once 'SeckillQueue.php';

// 初始化Redis连接
try {
    // 创建Redis连接
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);

    // 如果Redis有密码认证
    // $redis->auth('your_redis_password');

    // 选择数据库
    $redis->select(0);

    // 秒杀活动配置
    $activityId = 'seckill_phone_1111';
    $initialStock = 5; // 本次秒杀商品数量

    // 初始化秒杀队列
    $seckill = new SeckillQueue($redis, $activityId, $initialStock);
    $reset = $seckill->reset($initialStock); // 重置活动（测试用）
    // 如果是false则return重置失败
    if (!$reset) {
        die("秒杀活动重置失败");
    }

    echo "=== 秒杀活动初始化完成 ===" . "<br>";
    echo "活动ID: {$activityId}" . "<br>";
    echo "商品数量: {$initialStock} 件" . "<br>" . "<br>";

    // 模拟15个用户参与秒杀
    echo "=== 用户参与秒杀 ===" . "<br>";
    $userIds = [];
    for ($i = 1; $i <= 15; $i++) {
        $userId = "user_{$i}";
        $userIds[] = $userId;

        $result = $seckill->enqueueUser($userId);
        echo "用户 {$userId}: {$result['message']}" . "<br>";
    }

    // 显示参与统计
    echo "<br>" . "=== 秒杀参与统计 ===" . "<br>";
    echo "参与总人数: {$seckill->getParticipatedCount()}" . "<br>";
    echo "队列等待人数: {$seckill->getQueueLength()}" . "<br>";
    echo "剩余库存: {$seckill->getRemainingStock()}" . "<br>";

    // 处理秒杀队列
    echo "<br>" . "=== 处理秒杀队列 ===" . "<br>";
    $processResult = $seckill->processQueue(10);

    echo "处理结果: " . $processResult['message'] . "<br>";
    echo "共处理用户: {$processResult['data']['processed']} 人" . "<br>";
    echo "成功抢到商品的用户: " . implode(', ', $processResult['data']['success_users']) . "<br>";
    echo "剩余库存: {$processResult['data']['remaining_stock']}" . "<br>";

    // 显示所有成功用户
    echo "<br>" . "=== 所有成功抢到商品的用户 ===" . "<br>";
    $allSuccessUsers = $seckill->getAllSuccessUsers();
    echo "总共有 " . count($allSuccessUsers) . " 人成功抢到商品:" . "<br>";
    echo implode(', ', $allSuccessUsers) . "<br>";

    // 单个用户查询
    echo "<br>" . "=== 单个用户结果查询 ===" . "<br>";
    $testUsers = ['user_3', 'user_4', 'user_12', 'user_15'];
    foreach ($testUsers as $userId) {
        $status = $seckill->isUserSuccess($userId) ? '成功' : '失败';
        echo "用户 {$userId}: 秒杀{$status}" . "<br>";
    }

} catch (Exception $e) {
    die("秒杀活动出错: " . $e->getMessage());
}