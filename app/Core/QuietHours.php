<?php
declare(strict_types=1);

namespace app\Core;

/**
 * 免打扰时段（夜间静默）
 *
 * 用于所有「主动打扰型」通知渠道（邮件 / 微信服务号 / 短信）：静默期内不发送，
 * 事件**延迟到静默结束后投递**（不丢失）；危机预警与登录告警默认豁免。
 *
 * 配置（settings）：
 *   notify.quiet_enabled        0/1 是否启用
 *   notify.quiet_start          如 22:00
 *   notify.quiet_end            如 08:00（支持跨午夜，start > end 视为跨天）
 *   notify.quiet_exempt_crisis  0/1 危机与安全告警豁免（默认 1）
 */
class QuietHours
{
    /** 需要豁免的安全类通知类型 */
    private const SAFETY_TYPES = ['crisis', 'login_alert'];

    public static function enabled(): bool
    {
        return (bool)setting('notify.quiet_enabled', '0');
    }

    /** 将 H:i 转为分钟数；非法返回 null */
    private static function toMinutes(?string $hhmm): ?int
    {
        if (!is_string($hhmm) || !preg_match('/^\s*(\d{1,2}):(\d{2})\s*$/', $hhmm, $m)) return null;
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h > 23 || $i > 59) return null;
        return $h * 60 + $i;
    }

    /** 区间配置（分钟） */
    private static function window(): ?array
    {
        $s = self::toMinutes((string)setting('notify.quiet_start', '22:00'));
        $e = self::toMinutes((string)setting('notify.quiet_end', '08:00'));
        if ($s === null || $e === null || $s === $e) return null;
        return [$s, $e];
    }

    /** 指定时刻是否处于静默期 */
    public static function isQuiet(?int $ts = null): bool
    {
        if (!self::enabled()) return false;
        $w = self::window();
        if ($w === null) return false;
        [$start, $end] = $w;
        $ts = $ts ?? time();
        $now = (int)date('G', $ts) * 60 + (int)date('i', $ts);
        if ($start < $end) {
            return $now >= $start && $now < $end;          // 同日区间，如 12:00-14:00
        }
        return $now >= $start || $now < $end;              // 跨午夜，如 22:00-08:00
    }

    /** 下一个可发送时刻（时间戳）；不在静默期则返回当前时间 */
    public static function nextSlot(?int $ts = null): int
    {
        $ts = $ts ?? time();
        if (!self::isQuiet($ts)) return $ts;
        $w = self::window();
        if ($w === null) return $ts;
        [$start, $end] = $w;
        $now = (int)date('G', $ts) * 60 + (int)date('i', $ts);
        $diff = ($end - $now + 1440) % 1440;
        if ($diff === 0) $diff = 1440;
        return strtotime("+{$diff} minutes", $ts - (int)date('s', $ts)); // 对齐到整分钟
    }

    /**
     * 该通知类型是否免于静默
     * v2.2.0 安全加固：危机预警与登录告警**强制豁免**，不再可配置关闭
     * （历史教训：notify.quiet_exempt_crisis 被存成 0 时危机预警会被延迟——违反「安全底线不可被配置关闭」）
     */
    public static function exempted(string $type): bool
    {
        return in_array($type, self::SAFETY_TYPES, true);
    }

    /**
     * 计算实际投递时间
     * @return array{0:bool,1:int} [是否静默, 投递时间戳]
     */
    public static function resolve(string $type, ?int $ts = null): array
    {
        $ts = $ts ?? time();
        if (self::exempted($type)) return [false, $ts];
        if (!self::isQuiet($ts)) return [false, $ts];
        return [true, self::nextSlot($ts)];
    }

    /** 人类可读状态（后台展示） */
    public static function describe(): string
    {
        if (!self::enabled()) return '未启用免打扰';
        $w = self::window();
        if ($w === null) return '免打扰时间段配置无效（需形如 22:00 / 08:00）';
        $s = (string)setting('notify.quiet_start', '22:00');
        $e = (string)setting('notify.quiet_end', '08:00');
        if (self::isQuiet()) {
            return '当前处于免打扰（' . $s . ' – ' . $e . '），通知将延迟到 ' . date('m-d H:i', self::nextSlot()) . ' 后发送（危机预警与登录告警不受限，即时送达）';
        }
        return '免打扰时段 ' . $s . ' – ' . $e . '（当前正常发送）';
    }
}
