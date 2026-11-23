<?php
/** 短信通知插件（阿里云 Dysmsapi）：危机即时送达，按条计量，免打扰延迟不丢失 */
declare(strict_types=1);

namespace plugins\smsnotify;

use app\Core\Cache;
use app\Core\Crypto;
use app\Core\Database;
use app\Core\Http;
use app\Core\Logger;
use app\Core\QuietHours;
use app\Services\NotifyChannel;
use app\Services\NotifyService;

final class Plugin implements NotifyChannel
{
    /** 类型 → [开关键, 默认值]；危机与转人工默认开，业务类默认关（省费用） */
    private const TYPE_SWITCHES = [
        'booking' => ['sms.on_booking', '0'],
        'message' => ['sms.on_message', '0'],
        'chat_escalate' => ['sms.on_chat_escalate', '1'],
        'crisis' => ['sms.on_crisis', '1'],
    ];

    public static function boot(array $meta): void
    {
        self::ensureTable();
        NotifyService::registerChannel(new self());
    }

    /**
     * 会员登录验证码短信（MemberService 调用；与告警共用阿里云账号，模板独立）
     * @return [bool, string] 成功/失败原因
     */
    public static function sendCode(string $phone, string $code): array
    {
        if (!(bool)setting('sms.code_enabled', '0')) return [false, '验证码短信未启用'];
        $tid = trim((string)setting('sms.code_template_code', ''));
        if ($tid === '') return [false, '未配置验证码模板'];
        $param = json_encode(['code' => $code], JSON_UNESCAPED_UNICODE);
        $r = self::sendAliyun($phone, $param, $tid);
        if ($r !== true) {
            Logger::error('sms-notify', 'member code to ' . substr($phone, 0, 3) . '****' . substr($phone, -4) . ' failed: ' . (string)$r);
            return [false, '验证码发送失败：' . (string)$r];
        }
        return [true, 'ok'];
    }

    /**
     * 会员通用短信（v2.14.0 会员系统）：用「通用通知模板」（${site} ${event} ${time}），与告警共用账号
     * @return bool 发送成功与否（失败记日志）
     */
    /** 会员侧短信（提醒/兜底/补发）。$url 非空且模板支持时（sms.template_url_enabled=1，配新模板后开启）附加查阅短链变量 */
    public static function sendMemberSms(string $phone, array $param, string $url = ''): bool
    {
        if (!(bool)setting('sms.enabled', '0')) return false;
        $tpl = trim((string)setting('sms.template_code', ''));
        if ($tpl === '') return false;
        if ($url !== '' && (bool)setting('sms.template_url_enabled', '0')) {
            $param['url'] = $url; // 新模板（含 ${url} 变量）过审后开启开关即生效
        }
        $r = self::sendAliyun($phone, json_encode($param, JSON_UNESCAPED_UNICODE), $tpl);
        if ($r !== true) {
            Logger::error('sms-notify', 'member sms to ' . substr($phone, 0, 3) . '****' . substr($phone, -4) . ' failed: ' . (string)$r);
            return false;
        }
        return true;
    }

    /** ===== NotifyChannel ===== */

    public function name(): string { return 'sms'; }
    public function label(): string { return '短信（阿里云）'; }

    public function enabled(): bool
    {
        return (bool)setting('sms.enabled', '0')
            && trim((string)setting('sms.access_key_id', '')) !== ''
            && trim((string)setting('sms.access_secret', '')) !== ''
            && trim((string)setting('sms.sign_name', '')) !== ''
            && trim((string)setting('sms.template_code', '')) !== ''
            && self::phones() !== [];
    }

    public function send(string $type, string $title, string $body, array $ref, bool $urgent): bool
    {
        if (isset(self::TYPE_SWITCHES[$type])) {
            [$swKey, $swDefault] = self::TYPE_SWITCHES[$type];
            if (!(bool)setting($swKey, $swDefault)) return false;
        } else {
            // v1.1.5 fail-closed：未列入白名单的后台通知类（upgrade/system/member/order/…）不发短信
            // （曾致升级提醒等低价值通知每日短信轰炸管理员手机；此类走站内信+邮件即可）
            return false;
        }
        $phones = self::phones();
        if (!$phones) return false;

        // 免打扰：危机/登录告警豁免（QuietHours 核心规则）；其余延迟到时段结束
        [$inQuiet, $deliverAt] = QuietHours::resolve($type);
        if ($inQuiet) {
            foreach ($phones as $ph) {
                self::log($type, $ph, 'queued', null, date('Y-m-d H:i:s', $deliverAt), $title);
            }
            return true;
        }

        $site = (string)setting('site.name', '');
        $label = self::LABELS[$type] ?? '通知';
        $param = json_encode([
            'store' => str_take($site, 12, ''),
            'event' => str_take($label . '：' . $title, 20, ''),
            'time' => date('m-d H:i'),
        ], JSON_UNESCAPED_UNICODE);

        $ok = false;
        foreach ($phones as $ph) {
            $r = self::sendAliyun($ph, $param);
            self::log($type, $ph, $r === true ? 'ok' : 'fail', is_string($r) ? $r : null, null, $label . '：' . $title);
            $ok = $ok || $r === true;
        }
        if (!$ok && $urgent && $type === 'crisis') {
            Logger::error('sms-notify', 'CRISIS sms failed for all phones: ' . $title);
        }
        return $ok;
    }

    public function stats(): array
    {
        return [
            'ok' => (int)Database::value("SELECT COUNT(*) FROM sms_notify_log WHERE status='ok' AND created_at >= CURDATE()"),
            'fail' => (int)Database::value("SELECT COUNT(*) FROM sms_notify_log WHERE status='fail' AND created_at >= CURDATE()"),
            'queued' => (int)Database::value("SELECT COUNT(*) FROM sms_notify_log WHERE status='queued'"),
        ];
    }

    /** ===== 阿里云 RPC 签名（V1，HMAC-SHA1） ===== */

    private static function sendAliyun(string $phone, string $templateParam, ?string $templateCodeOverride = null)
    {
        $akId = trim((string)setting('sms.access_key_id', ''));
        $akSecret = trim((string)Crypto::decrypt((string)setting('sms.access_secret', '')));
        $params = [
            'Action' => 'SendSms',
            'Version' => '2017-05-25',
            'RegionId' => 'cn-hangzhou',
            'PhoneNumbers' => $phone,
            'SignName' => (string)setting('sms.sign_name', ''),
            'TemplateCode' => $templateCodeOverride ?? (string)setting('sms.template_code', ''),
            'TemplateParam' => $templateParam,
            'Format' => 'JSON',
            'AccessKeyId' => $akId,
            'SignatureMethod' => 'HMAC-SHA1',
            'SignatureVersion' => '1.0',
            'SignatureNonce' => bin2hex(random_bytes(8)),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        // 参数排序 → URL 编码 → StringToSign
        ksort($params);
        $qs = '';
        foreach ($params as $k => $v) {
            $qs .= '&' . self::percentEncode($k) . '=' . self::percentEncode((string)$v);
        }
        $qs = substr($qs, 1);
        $stringToSign = 'GET&%2F&' . self::percentEncode($qs);
        $sign = base64_encode(hash_hmac('sha1', $stringToSign, $akSecret . '&', true));
        $url = 'https://dysmsapi.aliyuncs.com/?Signature=' . self::percentEncode($sign) . '&' . $qs;
        [$ok, $status, $resp] = Http::get($url, [], 10);
        $r = json_decode((string)$resp, true);
        if (!$ok || ($r['Code'] ?? '') !== 'OK') {
            return str_take((string)($r['Message'] ?? ('status ' . $status)) . (isset($r['Code']) ? ' [' . $r['Code'] . ']' : ''), 110, '');
        }
        return true;
    }

    private static function percentEncode(string $s): string
    {
        return str_replace(['+', '*'], ['%20', '%2A'], str_replace('%7E', '~', rawurlencode($s)));
    }

    /** ===== 队列 / 日志 ===== */

    public static function flushQueue(): array
    {
        if (!(bool)setting('sms.enabled', '0')) return ['skipped' => 'disabled'];
        // v1.1.5 节流：静默时段结束的积压每分钟最多放 10 条，避免一次性轰炸
        $rows = Database::fetchAll("SELECT * FROM sms_notify_log WHERE status='queued' AND scheduled_at <= NOW() LIMIT 10");
        if (!$rows) return ['found' => 0];
        $okCount = 0;
        $phones = self::phones();
        foreach ($rows as $q) {
            if (!$phones) break;
            $site = (string)setting('site.name', '');
            $param = json_encode(['store' => str_take($site, 12, ''), 'event' => str_take((self::LABELS[$q['type']] ?? '通知'), 20, ''), 'time' => date('m-d H:i')], JSON_UNESCAPED_UNICODE);
            $r = self::sendAliyun($phones[0], $param);
            if ($r === true) {
                Database::run("UPDATE sms_notify_log SET status='ok', sent_at=NOW() WHERE id=?", [(int)$q['id']]);
                $okCount++;
            } else {
                Database::run("UPDATE sms_notify_log SET attempts=attempts+1, err=? WHERE id=?", [str_take((string)$r, 110, ''), (int)$q['id']]);
                Database::run("UPDATE sms_notify_log SET status='failed' WHERE id=? AND attempts>=3", [(int)$q['id']]);
            }
        }
        return ['found' => count($rows), 'sent' => $okCount];
    }

    private const LABELS = [
        'booking' => '新预约', 'message' => '新留言', 'chat_question' => '访客提问',
        'chat_escalate' => 'AI 转人工', 'crisis' => '危机预警', 'login_alert' => '登录告警',
        'system' => '系统', 'upgrade' => '升级', 'license' => '授权', 'event' => '活动',
    ];

    private static function log(string $type, string $phone, string $status, ?string $err, ?string $scheduledAt = null, ?string $content = null): void
    {
        try {
            Database::insert('sms_notify_log', [
                'type' => $type, 'phone_mask' => self::mask($phone), 'status' => $status,
                'err' => $err !== null ? str_take($err, 110, '') : null,
                'content' => $content !== null ? str_take($content, 150, '…') : null,
                'attempts' => 0,
                'scheduled_at' => $scheduledAt,
                'sent_at' => $status === 'ok' ? date('Y-m-d H:i:s') : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $t) {
            Logger::error('sms-notify', 'log failed: ' . $t->getMessage());
        }
    }

    private static function mask(string $phone): string
    {
        return strlen($phone) >= 11 ? substr($phone, 0, 3) . '****' . substr($phone, -4) : $phone;
    }

    private static function phones(): array
    {
        $out = [];
        foreach (preg_split('/[;，,]+/u', (string)setting('sms.admin_phones', '')) ?: [] as $p) {
            $p = trim($p);
            if ($p !== '' && preg_match('/^1[3-9]\d{9}$/', $p)) $out[] = $p;
        }
        return $out;
    }

    private static function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        // v1.1.6 并发保护：cron 与前台可能同时触发 DDL，串行化以免 "Duplicate column"
        $fp = @fopen(sys_get_temp_dir() . '/psyisle_sms_notify.lock', 'c');
        if ($fp) {
            @flock($fp, LOCK_EX);
        }
        try {
            Database::run("CREATE TABLE IF NOT EXISTS `sms_notify_log` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `type` varchar(30) NOT NULL,
                `phone_mask` varchar(20) NULL,
                `status` varchar(10) NOT NULL DEFAULT 'ok' COMMENT 'ok/fail/queued/failed/skip',
                `err` varchar(120) NULL,
                `content` varchar(160) NULL,
                `attempts` tinyint NOT NULL DEFAULT 0,
                `scheduled_at` datetime NULL,
                `sent_at` datetime NULL,
                `created_at` datetime NULL,
                PRIMARY KEY (`id`), KEY `k_status` (`status`,`scheduled_at`), KEY `k_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            // v1.1.5 幂等迁移：存量表补 content 列
            $has = (int)Database::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sms_notify_log' AND COLUMN_NAME='content'");
            if (!$has) Database::run("ALTER TABLE sms_notify_log ADD COLUMN `content` varchar(160) NULL AFTER `err`");
            $done = true;
        } catch (\Throwable $t) {
            $m = $t->getMessage();
            if (stripos($m, 'Duplicate column') === false) {
                Logger::error('sms-notify', 'ensureTable: ' . $m);
            }
        } finally {
            if ($fp) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }
    }

    public static function uninstall(): void
    {
        Database::run('DROP TABLE IF EXISTS sms_notify_log');
    }

    /** ===== 后台 ===== */

    public static function adminIndex($req = null): void
    {
        $stats = [
            'ok' => (int)Database::value("SELECT COUNT(*) FROM sms_notify_log WHERE status='ok' AND created_at >= CURDATE()"),
            'fail' => (int)Database::value("SELECT COUNT(*) FROM sms_notify_log WHERE status='fail' AND created_at >= CURDATE()"),
            'queued' => (int)Database::value("SELECT COUNT(*) FROM sms_notify_log WHERE status='queued'"),
            'failed' => (int)Database::value("SELECT COUNT(*) FROM sms_notify_log WHERE status='failed'"),
            'month' => (int)Database::value("SELECT COUNT(*) FROM sms_notify_log WHERE status='ok' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"),
        ];
        $recent = Database::fetchAll('SELECT type, phone_mask, status, err, attempts, scheduled_at, created_at FROM sms_notify_log ORDER BY id DESC LIMIT 12');
        \app\Core\PluginManager::view('sms-notify', 'admin', [
            'meta' => \app\Core\PluginManager::get('sms-notify'),
            'stats' => $stats,
            'recent' => $recent,
            'phones' => array_map([self::class, 'mask'], self::phones()),
            'quietState' => QuietHours::describe(),
            'quietOn' => QuietHours::enabled(),
        ]);
    }

    public static function sendTest($req = null): array
    {
        if (!(bool)setting('sms.enabled', '0')) return [false, '插件未启用'];
        $phones = self::phones();
        if (!$phones) return [false, '未配置接收手机号'];
        $site = (string)setting('site.name', '');
        $param = json_encode(['store' => str_take($site, 12, ''), 'event' => '配置测试', 'time' => date('m-d H:i')], JSON_UNESCAPED_UNICODE);
        $r = self::sendAliyun($phones[0], $param);
        return $r === true ? [true, '测试短信已发送至 ' . self::mask($phones[0])] : [false, '发送失败：' . (string)$r];
    }
}
