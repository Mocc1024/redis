<?php
// 引入秒杀队列类
require_once 'SeckillQueue.php';

// 统一按 UTF-8 输出：Windows 下 Redis 扩展抛出的系统错误信息是 GBK 编码，
// 若不明确指定字符集，浏览器会按 GBK 解析页面导致中文乱码
ini_set('default_charset', 'UTF-8');
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo '<meta charset="utf-8">';
}

/**
 * 把任意编码的文本统一转换为 UTF-8
 * @param string $text
 * @return string
 */
function toUtf8($text)
{
    if ($text === null || $text === '') {
        return '';
    }
    if (function_exists('mb_detect_encoding')) {
        $encoding = mb_detect_encoding($text, ['UTF-8', 'GB18030', 'GBK', 'GB2312', 'BIG5', 'ASCII', 'ISO-8859-1'], true);
        if ($encoding !== false && strtoupper($encoding) !== 'UTF-8') {
            return mb_convert_encoding($text, 'UTF-8', $encoding);
        }
    }

    return $text;
}

/**
 * 把底层异常信息翻译成可读的中文提示，并保留原始信息便于排查
 * @param Throwable $e
 * @return string
 */
function friendlyErrorMessage($e)
{
    $message = toUtf8($e->getMessage());

    $dictionary = [
        'Connection refused' => '连接被拒绝，Redis 服务未启动或端口不正确',
        'went away'          => 'Redis 服务中途断开',
        'timed out'          => '连接或读取 Redis 超时',
        'NOAUTH'             => 'Redis 需要密码认证，请先调用 auth()',
        'invalid DB index'   => '选择的数据库编号不存在',
        'not found'          => '未找到对应的类或方法',
    ];

    foreach ($dictionary as $keyword => $description) {
        if (stripos($message, $keyword) !== false) {
            return $description . '（原始信息：' . $message . '）';
        }
    }

    return $message;
}

// Redis 服务检测：未安装 phpredis 扩展时直接提示，避免抛致命错误
if (!extension_loaded('redis')) {
    die('未检测到Redis服务');
}

// 初始化Redis连接
try {
    // 创建Redis连接
    // 用字符串形式的类名实例化：未安装扩展时 IDE 静态分析不会误报"未定义类型 Redis"
    $redisClass = 'Redis';
    $redis = new $redisClass();
    $redis->connect('127.0.0.1', 6379, 2.0);
    // 如果Redis有密码认证
    // $redis->auth('your_redis_password');

    // 选择数据库
    $redis->select(0);

    // 秒杀活动配置
    $activityId = 'Seckill_iPhone_Duo';
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

// Throwable 同时包含 Exception 与 Error，避免致命错误无法被捕获
} catch (Throwable $e) {
    $message = PHP_SAPI === 'cli'
        ? friendlyErrorMessage($e)
        : htmlspecialchars(friendlyErrorMessage($e), ENT_QUOTES, 'UTF-8');
    die("秒杀活动出错: " . $message);
}