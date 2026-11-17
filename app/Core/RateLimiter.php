<?php
declare(strict_types=1);

namespace app\Core;

/** 滑动窗口限流（文件缓存实现） */
class RateLimiter
{
    /**
     * @param string $bucket  桶名（如 message、chat）
     * @param string $id      主体（IP / phone）
     * @param int $max        窗口内最大次数
     * @param int $windowSec  窗口秒数
     * @return bool true=放行
     */
    public static function hit(string $bucket, string $id, int $max, int $windowSec): bool
    {
        $key = 'rl:' . $bucket . ':' . hash('sha256', $id);
        $now = microtime(true);
        $list = Cache::get($key);
        if (!is_array($list)) $list = [];
        $list = array_values(array_filter($list, fn($t) => $t > $now - $windowSec));
        if (count($list) >= $max) { return false; }
        $list[] = $now;
        Cache::set($key, $list, $windowSec + 10);
        return true;
    }
}
