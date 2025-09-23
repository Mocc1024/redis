<?php
try {
    // 连接 Redis 服务器
    $redis = new Redis();
    $redis->connect('127.0.0.1', 6379);

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