<?php
declare(strict_types=1);

namespace app\Core;

/** 文件缓存（零依赖） */
class Cache
{
    private static string $dir = '';

    private static function dir(): string
    {
        if (self::$dir === '') {
            self::$dir = STORAGE . '/cache';
            if (!is_dir(self::$dir)) { @mkdir(self::$dir, 0755, true); }
        }
        return self::$dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . hash('sha256', $key) . '.cache';
    }

    public static function get(string $key)
    {
        $f = self::file($key);
        if (!is_file($f)) return null;
        $raw = @file_get_contents($f);
        if ($raw === false) return null;
        $data = @unserialize($raw);
        if (!is_array($data) || !isset($data['expire'], $data['value'])) return null;
        if ($data['expire'] > 0 && $data['expire'] < time()) { @unlink($f); return null; }
        return $data['value'];
    }

    public static function set(string $key, $value, int $ttl = 300): void
    {
        @file_put_contents(self::file($key), serialize([
            'expire' => $ttl > 0 ? time() + $ttl : 0,
            'value' => $value,
        ]), LOCK_EX);
    }

    public static function forget(string $key): void
    {
        @unlink(self::file($key));
    }

    public static function remember(string $key, int $ttl, callable $fn)
    {
        $v = self::get($key);
        if ($v !== null) return $v;
        $v = $fn();
        self::set($key, $v, $ttl);
        return $v;
    }

    public static function flush(): void
    {
        foreach (glob(self::dir() . '/*.cache') as $f) { @unlink($f); }
    }
}
