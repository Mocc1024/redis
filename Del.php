<?php
// Redis 服务检测：未安装 phpredis 扩展时直接提示，避免抛致命错误
if (!extension_loaded('redis')) {
    die('未检测到Redis服务');
}

try {
    // 连接 Redis 服务器
    // 用字符串形式的类名实例化：未安装扩展时 IDE 静态分析不会误报"未定义类型 Redis"
    $redisClass = 'Redis';
    $redis = new $redisClass();
    $redis->connect('127.0.0.1', 6379, 2.0);

    // 如果 Redis 有密码认证
    // $redis->auth('your_redis_password');

    // 清空所有缓存（所有数据库的所有键）
    $result = $redis->flushAll();

    if ($result) {
        echo "所有 Redis 缓存已成功清空";
    } else {
        echo "清空缓存失败";
    }

    // 关闭连接
    $redis->close();
} catch (Exception $e) {
    die("操作失败：" . $e->getMessage());
}