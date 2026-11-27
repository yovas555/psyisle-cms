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

    public static function consumeIf(string $key, callable $predicate): bool
    {
        $f = self::file($key);
        if (!is_file($f)) return false;
        $fp = @fopen($f, 'c+');
        if (!$fp) return false;
        try {
            if (!flock($fp, LOCK_EX)) return false;
            $data = @unserialize((string)stream_get_contents($fp));
            if (!is_array($data) || !isset($data['expire'], $data['value'])) return false;
            if ($data['expire'] > 0 && $data['expire'] < time()) {
                rewind($fp);
                ftruncate($fp, 0);
                fwrite($fp, serialize(['expire' => time() - 1, 'value' => null]));
                fflush($fp);
                return false;
            }
            if (!$predicate($data['value'])) return false;
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, serialize(['expire' => time() - 1, 'value' => null]));
            fflush($fp);
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    public static function mutateIf(string $key, int $ttl, callable $mutator): bool
    {
        $f = self::file($key);
        $existed = is_file($f);
        $fp = @fopen($f, 'c+');
        if (!$fp) return false;
        try {
            if (!flock($fp, LOCK_EX)) return false;
            $data = @unserialize((string)stream_get_contents($fp));
            $value = null;
            if (is_array($data) && isset($data['expire'], $data['value'])
                && ($data['expire'] === 0 || $data['expire'] >= time())) {
                $value = $data['value'];
            }
            if (!$mutator($value)) {
                if (!$existed) @unlink($f);
                return false;
            }
            rewind($fp);
            ftruncate($fp, 0);
            fwrite($fp, serialize(['expire' => $ttl > 0 ? time() + $ttl : 0, 'value' => $value]));
            fflush($fp);
            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * remember（v2.18 防击穿）：并发首个过期时只允许一个进程回源计算，
     * 其余进程持锁等待最多 2 秒后复读；拿不到锁的进程自行计算（降级不阻塞）。
     */
    public static function remember(string $key, int $ttl, callable $fn)
    {
        $v = self::get($key);
        if ($v !== null) return $v;
        $lockFile = self::dir() . '/' . hash('sha256', $key) . '.lock';
        $fp = @fopen($lockFile, 'c');
        if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
            try {
                $v = self::get($key); // 双检：拿锁期间可能已被他人回填
                if ($v !== null) return $v;
                $v = $fn();
                self::set($key, $v, $ttl);
                return $v;
            } finally {
                flock($fp, LOCK_UN);
                fclose($fp);
                @unlink($lockFile);
            }
        }
        if ($fp) {
            // 等持锁者回填（最多 2 秒）
            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                usleep(50_000);
                $v = self::get($key);
                if ($v !== null) { fclose($fp); @unlink($lockFile); return $v; }
            }
            fclose($fp);
        }
        // 降级：自己算（不阻塞业务）
        $v = $fn();
        self::set($key, $v, $ttl);
        return $v;
    }

    public static function flush(): void
    {
        foreach (glob(self::dir() . '/*.cache') as $f) { @unlink($f); }
        foreach (glob(self::dir() . '/*.lock') as $f) { @unlink($f); }
    }
}
