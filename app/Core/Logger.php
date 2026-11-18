<?php
declare(strict_types=1);

namespace app\Core;

class Logger
{
    public static function error(string $channel, string $message, array $ctx = []): void
    {
        self::write('error', $channel, $message, $ctx);
    }

    /** v2.18：warning 级（慢查询/重试/降级等可自愈事件——与真错误区分，避免 error 噪音） */
    public static function warning(string $channel, string $message, array $ctx = []): void
    {
        self::write('warning', $channel, $message, $ctx);
    }

    public static function info(string $channel, string $message, array $ctx = []): void
    {
        self::write('info', $channel, $message, $ctx);
    }

    private static function write(string $level, string $channel, string $message, array $ctx): void
    {
        $line = sprintf(
            "[%s] %s.%s: %s %s\n",
            date('Y-m-d H:i:s'),
            $channel,
            $level,
            $message,
            $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
        );
        $file = STORAGE . '/logs/' . date('Ymd') . '-' . $level . '.log';
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        // 滚动：只留 14 天
        if (random_int(0, 50) === 0) {
            foreach (glob(STORAGE . '/logs/*-*.log') as $f) {
                if (filemtime($f) < strtotime('-14 days')) @unlink($f);
            }
        }
    }
}
