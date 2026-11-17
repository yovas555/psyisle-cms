<?php
declare(strict_types=1);

namespace app\Core;

/**
 * 轻量钩子系统（对标 WordPress action/filter 的最小实现）
 * 用法：
 *   Hook::on('article.published', function ($article) { … });   // 注册
 *   Hook::fire('article.published', $article);                  // 触发（监听器返回值会替换 payload）
 * 内置触发点：article.published / article.scheduled / booking.created / message.created /
 *            event.registered / user.login / content.import
 */
class Hook
{
    /** @var array<string, callable[]> */
    private static array $listeners = [];

    public static function on(string $name, callable $fn, int $priority = 10): void
    {
        self::$listeners[$name][$priority][] = $fn;
    }

    /** 触发：各监听器返回值链式替换 payload */
    public static function fire(string $name, $payload = null)
    {
        if (!isset(self::$listeners[$name])) return $payload;
        ksort(self::$listeners[$name]);
        foreach (self::$listeners[$name] as $fns) {
            foreach ($fns as $fn) {
                try {
                    $result = $fn($payload);
                    if ($result !== null) { $payload = $result; }
                } catch (\Throwable $t) {
                    Logger::error('hook', "listener failed for {$name}: " . $t->getMessage());
                }
            }
        }
        return $payload;
    }
}
