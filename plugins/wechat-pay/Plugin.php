<?php
/** 微信支付 V3 插件（v2.7.0）：Native/JSAPI 下单、回调验签解密、咨询套餐自动入账、每日对账 */
declare(strict_types=1);

namespace plugins\wechatpay;

use app\Core\Cache;
use app\Core\Crypto;
use app\Core\Database;
use app\Core\Hook;
use app\Core\Http;
use app\Core\Logger;

final class Plugin
{
    private const API_BASE = 'https://api.mch.weixin.qq.com';

    public static function boot(array $meta): void
    {
        self::ensureTable();
        Hook::on('member.cards', [self::class, 'memberCard']);
    }

    private static function ensureTable(): void
    {
        try {
            Database::run("CREATE TABLE IF NOT EXISTS `wechat_pay_orders` (
                `id` int unsigned NOT NULL AUTO_INCREMENT,
                `out_trade_no` varchar(32) NOT NULL,
                `member_id` int unsigned NOT NULL DEFAULT 0,
                `package_id` int unsigned NOT NULL DEFAULT 0,
                `subject` varchar(120) NOT NULL DEFAULT '',
                `amount_cents` int NOT NULL DEFAULT 0,
                `channel` varchar(10) NOT NULL DEFAULT 'native' COMMENT 'native/jsapi',
                `code_url` varchar(255) NULL,
                `prepay_id` varchar(64) NULL,
                `transaction_id` varchar(64) NULL,
                `status` varchar(12) NOT NULL DEFAULT 'created' COMMENT 'created/paid/error',
                `paid_at` datetime NULL,
                `credited` tinyint NOT NULL DEFAULT 0 COMMENT '咨询次数已入账',
                `err` varchar(120) NULL,
                `created_at` datetime NULL,
                `updated_at` datetime NULL,
                PRIMARY KEY (`id`), UNIQUE KEY `uk_out_trade_no` (`out_trade_no`),
                KEY `k_member` (`member_id`), KEY `k_status` (`status`,`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (\Throwable $t) {
            Logger::error("wechat-pay", "ensureTable: " . $t->getMessage());
        }
        // v2.13.0：存量表补 booking_id（咨询单支付）
        try {
            $col = Database::fetch("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='wechat_pay_orders' AND column_name='booking_id'");
            if ((int)($col['c'] ?? 0) === 0) {
                Database::run("ALTER TABLE wechat_pay_orders ADD COLUMN booking_id int unsigned NULL COMMENT '咨询单支付（与 package_id 互斥）' AFTER member_id, ADD KEY k_booking (booking_id)");
            }
        } catch (\Throwable $t) {
            Logger::error('wechat-pay', 'ensureTable alter: ' . $t->getMessage());
        }
    }

    public static function uninstall(): void
    {
        // 订单为资金流水，卸载不删表；仅清平台证书缓存
        Cache::forget('wxpay_platform_certs');
    }

    /** ===== 配置 ===== */

    /** @return array [appid, mchid, serial, apiv3key, mchPem]；缺配置抛异常 */
    private static function cfg(): array
    {
        $appid = trim((string)setting('wxpay.appid', ''));
        $mchid = trim((string)setting('wxpay.mchid', ''));
        $serial = trim((string)setting('wxpay.mch_serial_no', ''));
        $key = trim(Crypto::decrypt((string)setting('wxpay.apiv3_key', '')));
        $pem = trim(Crypto::decrypt((string)setting('wxpay.mch_key_secret', '')));
        // v2.13.0：下单/签名只需 AppID+商户号+序列号+私钥；APIv3 密钥仅回调解密需要（缺失时回调安全拒绝，允许先上线收款）
        if ($appid === '' || $mchid === '' || $serial === '' || $pem === '') {
            throw new \RuntimeException('微信支付配置不完整（AppID/商户号/证书序列号/商户私钥）');
        }
        return [$appid, $mchid, $serial, $key, $pem];
    }

    public static function ready(): bool
    {
        try { self::cfg(); return (bool)setting('wxpay.enabled', '0'); } catch (\Throwable $t) { return false; }
    }

    private static function notifyUrl(): string
    {
        $host = trim((string)setting('wxpay.notify_host', ''));
        $base = $host !== '' ? (preg_match('#^https?://#', $host) ? $host : 'https://' . $host) : base_url();
        return rtrim($base, '/') . '/plugin/wechat-pay/notify';
    }

    /** ===== V3 请求内核 ===== */

    /** @return array [bool, int httpStatus, array body] */
    private static function request(string $method, string $pathWithQuery, string $body = ''): array
    {
        try {
            [, $mchid, $serial, , $pem] = self::cfg();
        } catch (\Throwable $t) {
            return [false, 0, ['code' => 'FAIL', 'message' => $t->getMessage()]];
        }
        $pk = openssl_pkey_get_private($pem);
        if (!$pk) return [false, 0, ['code' => 'FAIL', 'message' => '商户私钥解析失败（检查 PEM 是否完整）']];
        $ts = (string)time();
        $nonce = bin2hex(random_bytes(8));
        $message = strtoupper($method) . "\n" . $pathWithQuery . "\n" . $ts . "\n" . $nonce . "\n" . $body . "\n";
        $sig = '';
        if (!openssl_sign($message, $sig, $pk, OPENSSL_ALGO_SHA256)) {
            return [false, 0, ['code' => 'FAIL', 'message' => '请求签名失败']];
        }
        $auth = sprintf('WECHATPAY2-SHA256-RSA2048 mchid="%s",nonce_str="%s",timestamp="%s",serial_no="%s",signature="%s"',
            $mchid, $nonce, $ts, $serial, base64_encode($sig));
        $headers = [
            'Authorization: ' . $auth,
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: PsyIsle-CMS/' . CMS_VERSION,
        ];
        if ($method === 'GET') {
            [$ok, $status, $resp] = Http::get(self::API_BASE . $pathWithQuery, $headers, 15);
        } else {
            [$ok, $status, $resp] = Http::post(self::API_BASE . $pathWithQuery, $body, $headers, 15);
        }
        $r = json_decode((string)$resp, true);
        if (!is_array($r)) $r = ['code' => 'FAIL', 'message' => '响应解析失败（HTTP ' . $status . '）'];
        return [$ok && $status >= 200 && $status < 300, (int)$status, $r];
    }

    /** ===== 下单 ===== */

    /** @return array [ok, msg, data] */
    public static function createOrder(int $memberId, int $packageId, string $channel): array
    {
        if (!(bool)setting('wxpay.enabled', '0')) return [false, '微信支付未开启，请联系机构', []];
        try { [$appid, $mchid] = self::cfg(); } catch (\Throwable $t) { return [false, $t->getMessage(), []]; }
        if (!in_array($channel, ['native', 'jsapi'], true)) $channel = 'native';
        $pkg = Database::fetch('SELECT * FROM packages WHERE id=? AND status=1', [$packageId]);
        if (!$pkg) return [false, '套餐不存在或已下架', []];
        $total = (int)$pkg['price_cents'];
        if ($total < 1) return [false, '该套餐未设置价格，请联系机构', []];
        $m = Database::fetch('SELECT * FROM members WHERE id=? AND status="active"', [$memberId]);
        if (!$m) return [false, '会员状态异常，请重新登录', []];

        $out = 'PSY' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
        $subject = str_take((string)$pkg['name'], 100, '');
        $orderId = (int)Database::insert('wechat_pay_orders', [
            'out_trade_no' => $out, 'member_id' => $memberId, 'package_id' => $packageId,
            'subject' => $subject, 'amount_cents' => $total, 'channel' => $channel,
            'status' => 'created', 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $payBody = [
            'appid' => $appid, 'mchid' => $mchid,
            'description' => $subject ?: '心理咨询套餐',
            'out_trade_no' => $out,
            'notify_url' => self::notifyUrl(),
            'amount' => ['total' => $total, 'currency' => 'CNY'],
        ];
        if ($channel === 'jsapi') {
            $openid = (string)($m['openid'] ?? '');
            if ($openid === '') {
                Database::run('UPDATE wechat_pay_orders SET status="error", err="JSAPI 支付需先绑定微信（无公众号 OpenID）" WHERE id=?', [$orderId]);
                return [false, '微信内支付需要先绑定微信：请到会员中心 → 微信绑定 完成授权', []];
            }
            $payBody['payer'] = ['openid' => $openid];
            [$ok, $status, $r] = self::request('POST', '/v3/pay/transactions/jsapi', json_encode($payBody, JSON_UNESCAPED_UNICODE));
            if (!$ok) return self::orderFail($orderId, $r, $status);
            $prepayId = (string)($r['prepay_id'] ?? '');
            if ($prepayId === '') return self::orderFail($orderId, ['message' => '响应缺少 prepay_id'], $status);
            Database::run('UPDATE wechat_pay_orders SET prepay_id=? WHERE id=?', [$prepayId, $orderId]);
            $ts = (string)time();
            $nonce = bin2hex(random_bytes(8));
            $pkgStr = 'prepay_id=' . $prepayId;
            $pem = Crypto::decrypt((string)setting('wxpay.mch_key_secret', ''));
            $pk = openssl_pkey_get_private($pem);
            $paySign = '';
            openssl_sign($appid . "\n" . $ts . "\n" . $nonce . "\n" . $pkgStr . "\n", $paySign, $pk, OPENSSL_ALGO_SHA256);
            return [true, '', [
                'out_trade_no' => $out,
                'jsapi' => [
                    'appId' => $appid, 'timeStamp' => $ts, 'nonceStr' => $nonce,
                    'package' => $pkgStr, 'signType' => 'RSA', 'paySign' => base64_encode($paySign),
                ],
            ]];
        }
        // Native
        [$ok, $status, $r] = self::request('POST', '/v3/pay/transactions/native', json_encode($payBody, JSON_UNESCAPED_UNICODE));
        if (!$ok) return self::orderFail($orderId, $r, $status);
        $codeUrl = (string)($r['code_url'] ?? '');
        if ($codeUrl === '') return self::orderFail($orderId, ['message' => '响应缺少 code_url'], $status);
        Database::run('UPDATE wechat_pay_orders SET code_url=? WHERE id=?', [$codeUrl, $orderId]);
        return [true, '', ['out_trade_no' => $out, 'code_url' => $codeUrl]];
    }

    private static function orderFail(int $orderId, array $r, int $status): array
    {
        $msg = str_take((string)($r['message'] ?? ('HTTP ' . $status)), 110, '');
        Database::run('UPDATE wechat_pay_orders SET status="error", err=?, updated_at=NOW() WHERE id=?', [$msg, $orderId]);
        return [false, '下单失败：' . $msg, []];
    }

    /** ===== 入账（幂等） ===== */

    /** 标记已支付；返回是否发生状态迁移 */
    private static function markPaid(int $orderId, string $transactionId): bool
    {
        $upd = Database::run("UPDATE wechat_pay_orders SET status='paid', paid_at=NOW(), transaction_id=?, updated_at=NOW() WHERE id=? AND status='created'",
            [$transactionId !== '' ? $transactionId : null, $orderId]);
        return $upd->rowCount() > 0;
    }

    /** 咨询次数入账（走 packages-pro openOrder，口径与后台售出一致）+ 通知 */
    private static function applyCredit(array $order, int $amountCents): bool
    {
        $credited = false;
        // v2.13.0：咨询单支付 → 预约标记已支付 + 双向通知
        // v2.17.0 用户拍板：时段预约（source=slot）支付成功 → 自动确认生效，后台/咨询师/会员三方通知
        if ((int)($order['booking_id'] ?? 0) > 0) {
            $bid = (int)$order['booking_id'];
            Database::run("UPDATE bookings SET pay_status='paid', status='confirmed'
                WHERE id=? AND pay_status='unpaid' AND status='pending' AND source='slot'", [$bid]);
            $b = Database::fetch('SELECT code, phone, prefer_date, prefer_slot, status, method, name, counselor_id FROM bookings WHERE id=?', [$bid]);
            $credited = (bool)$b;
            $phone = (string)($b['phone'] ?? '');
            $autoConfirmed = (($b['status'] ?? '') === 'confirmed');
            \app\Services\NotifyService::push('booking', $autoConfirmed ? '视频咨询费已支付（预约自动生效）' : '视频咨询费已支付',
                '查询码 ' . ($b['code'] ?? '') . '（' . \app\Services\MemberService::maskPhone($phone) . '）支付 '
                . number_format($amountCents / 100, 2) . ' 元，预约时段 ' . ($b['prefer_date'] ?? '') . ' ' . ($b['prefer_slot'] ?? '')
                . '。' . ($autoConfirmed ? '【免确认通道】预约已自动确认生效，无需人工确认。' : '请及时与会员确认咨询安排。'),
                ['type' => 'booking', 'id' => $bid]);
            $mid = (int)$order['member_id'];
            if ($autoConfirmed) {
                // 时段预约免确认：一条 booking_confirm 说清「支付+已生效」（替代单独 order_paid，避免双推）
                \app\Services\MemberNotifyService::push($mid, 'booking_confirm', [
                    'title' => '预约成功：' . ($b['code'] ?? ''),
                    'body' => ($b['prefer_date'] ?? '') . ' ' . ($b['prefer_slot'] ?? '') . ' · ' . ($b['method'] ?? '') . ' · 已支付 ' . number_format($amountCents / 100, 2) . ' 元',
                    'lines' => [
                        '查询码' => (string)($b['code'] ?? ''),
                        '时间' => trim((string)($b['prefer_date'] ?? '') . ' ' . (string)($b['prefer_slot'] ?? '')),
                        '金额' => number_format($amountCents / 100, 2) . ' 元',
                        '状态' => '已确认生效',
                    ],
                    'inapp_url' => '/member/bookings', 'ref_type' => 'booking', 'ref_id' => (string)($b['code'] ?? ''),
                    'note' => '咨询开始前将收到微信/短信提醒；视频咨询开始前 30 分钟可在「我的预约」进入咨询室。',
                    'booking' => ['id' => $bid, 'code' => (string)($b['code'] ?? ''), 'phone' => $phone],
                ]);
            } else {
                \app\Services\MemberNotifyService::push($mid, 'order_paid', [
                    'title' => '支付成功：' . (string)$order['subject'],
                    'body' => '您的视频咨询（' . ($b['prefer_date'] ?? '') . ' ' . ($b['prefer_slot'] ?? '') . '）费用已到账。咨询开始前 30 分钟可在「我的预约」进入咨询室。',
                ]);
            }
            // v2.17.0：咨询师侧通知（支付到账+预约状态）
            if ((int)($b['counselor_id'] ?? 0) > 0) {
                \app\Services\CounselorNotifyService::push((int)$b['counselor_id'], $autoConfirmed ? 'booking_confirm' : 'booking_new', [
                    'title' => ($autoConfirmed ? '咨询费已到账（预约生效）' : '咨询费已到账（待确认）') . '：' . (string)($b['name'] ?? ''),
                    'body' => ($b['prefer_date'] ?? '') . ' ' . ($b['prefer_slot'] ?? '') . ' · ' . ($b['method'] ?? '') . ' · ' . number_format($amountCents / 100, 2) . ' 元',
                    'url' => '/admin/counselor-home/bookings', 'booking_id' => $bid,
                    'booking' => ['id' => $bid, 'code' => (string)($b['code'] ?? ''), 'phone' => $phone],
                ]);
            }
            Database::run('UPDATE wechat_pay_orders SET credited=1 WHERE id=?', [(int)$order['id']]);
            return true;
        }
        if (\app\Core\PluginManager::isEnabled('packages-pro') && class_exists(\plugins\packagespro\Plugin::class)) {
            $pkg = Database::fetch('SELECT * FROM packages WHERE id=?', [(int)$order['package_id']]);
            $sessions = $pkg ? (int)$pkg['sessions'] : 0;
            if ($pkg && $sessions > 0) {
                try {
                    $oid = \plugins\packagespro\Plugin::openOrder((int)$order['member_id'], (int)$order['package_id'], $sessions, 'sale');
                    Database::run('UPDATE package_ledger SET amount_cents=? WHERE member_id=? AND order_id=? AND kind=?',
                        [$amountCents, (int)$order['member_id'], $oid, 'sale']);
                    $credited = true;
                } catch (\Throwable $t) {
                    Logger::error('wechat-pay', 'applyCredit: ' . $t->getMessage());
                }
            }
        }
        Database::run('UPDATE wechat_pay_orders SET credited=? WHERE id=?', [$credited ? 1 : 0, (int)$order['id']]);
        $phone = (string)Database::value('SELECT phone FROM members WHERE id=?', [(int)$order['member_id']]);
        \app\Services\NotifyService::push('system', '套餐支付成功',
            \app\Services\MemberService::maskPhone($phone) . ' 支付 ' . number_format($amountCents / 100, 2) . ' 元「' . (string)$order['subject'] . '」'
            . ($credited ? '，咨询次数已自动入账' : '，咨询次数未入账：请在咨询套餐后台补入账')
            . '（单号 ' . (string)$order['out_trade_no'] . '）');
        // v2.9.0：支付成功 → 通知会员（邮件/微信按偏好；次数入账走 openOrder 的 ledger_change 通知）
        \app\Services\MemberNotifyService::push((int)$order['member_id'], 'order_paid', [
            'title' => '支付成功：' . (string)$order['subject'],
            'lines' => [
                '商户单号' => (string)$order['out_trade_no'],
                '支付金额' => number_format($amountCents / 100, 2) . ' 元',
                '微信单号' => (string)($order['transaction_id'] ?? '—'),
            ],
            'note' => $credited ? '咨询次数已到账，可在「我的咨询套餐」查看。' : '次数开通确认后将另行通知。',
            'url' => base_url() . '/plugin/packages-pro/my',
        ]);
        return $credited;
    }

    /** 支付成功总入口（回调/查单/补单共用）：标记已支付 + 入账 */
    public static function credit(int $orderId, string $transactionId, int $amountCents): bool
    {
        $order = Database::fetch('SELECT * FROM wechat_pay_orders WHERE id=?', [$orderId]);
        if (!$order) return false;
        if ($order['status'] === 'paid') return (int)$order['credited'] === 1; // 幂等
        if (!$order || $order['status'] !== 'created') return false;
        self::markPaid($orderId, $transactionId);
        return self::applyCredit(Database::fetch('SELECT * FROM wechat_pay_orders WHERE id=?', [$orderId]), $amountCents);
    }

    /** ===== 回调验签 ===== */

    /** 验签公钥（微信支付公钥 / 平台证书双模式）；不可用返回 null */
    private static function verifyPublicKey(string $serial)
    {
        $mode = (string)setting('wxpay.verify_mode', 'public_key');
        if ($mode !== 'platform_cert') {
            $keyId = trim((string)setting('wxpay.public_key_id', ''));
            $pem = trim((string)setting('wxpay.public_key_pem', ''));
            if ($keyId === '' || $pem === '' || !hash_equals($keyId, $serial)) {
                Logger::error('wechat-pay', '公钥序列号不匹配：回调 ' . $serial . ' / 配置 ' . $keyId);
                return null;
            }
            return openssl_pkey_get_public($pem) ?: null;
        }
        $certs = Cache::get('wxpay_platform_certs');
        if (!is_array($certs) || !isset($certs[$serial])) $certs = self::downloadPlatformCerts();
        if (!is_array($certs) || !isset($certs[$serial])) return null;
        return openssl_pkey_get_public($certs[$serial]) ?: null;
    }

    private static function downloadPlatformCerts(): ?array
    {
        [$ok, $status, $r] = self::request('GET', '/v3/certificates');
        if (!$ok || !is_array($r['data'] ?? null)) {
            Logger::error('wechat-pay', '平台证书下载失败：' . (string)($r['message'] ?? $status));
            return null;
        }
        $key = Crypto::decrypt((string)setting('wxpay.apiv3_key', ''));
        if ($key === '') return null;
        $out = [];
        foreach ($r['data'] as $item) {
            $ec = is_array($item['encrypt_certificate'] ?? null) ? $item['encrypt_certificate'] : [];
            $raw = base64_decode((string)($ec['ciphertext'] ?? ''), true);
            if ($raw === false || strlen($raw) < 17) continue;
            $cert = openssl_decrypt(substr($raw, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
                (string)($ec['nonce'] ?? ''), substr($raw, -16), (string)($ec['associated_data'] ?? ''));
            if ($cert !== false) $out[(string)$item['serial_no']] = $cert;
        }
        if ($out) Cache::set('wxpay_platform_certs', $out, 43200);
        return $out ?: null;
    }

    /** APIv3 回调报文解密 */
    private static function aesGcmDecrypt(string $cipherB64, string $nonce, string $aad): ?string
    {
        $key = Crypto::decrypt((string)setting('wxpay.apiv3_key', ''));
        if ($key === '') return null;
        $raw = base64_decode($cipherB64, true);
        if ($raw === false || strlen($raw) < 17) return null;
        $pt = openssl_decrypt(substr($raw, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, substr($raw, -16), $aad);
        return $pt === false ? null : $pt;
    }

    /** 微信支付回调（notify_url）：验签 → 解密 → 核对金额 → 入账。无会话无 CSRF，安全靠验签。 */
    public static function front_notify($req): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $fail = static function (string $msg, int $code = 401): void {
            http_response_code($code);
            echo json_encode(['code' => 'FAIL', 'message' => $msg], JSON_UNESCAPED_UNICODE);
            exit;
        };
        $okResp = static function (): void {
            echo json_encode(['code' => 'SUCCESS', 'message' => '成功'], JSON_UNESCAPED_UNICODE);
            exit;
        };
        $body = (string)file_get_contents('php://input');
        $ts = (string)($_SERVER['HTTP_WECHATPAY_TIMESTAMP'] ?? '');
        $nonce = (string)($_SERVER['HTTP_WECHATPAY_NONCE'] ?? '');
        $sig = (string)($_SERVER['HTTP_WECHATPAY_SIGNATURE'] ?? '');
        $serial = (string)($_SERVER['HTTP_WECHATPAY_SERIAL'] ?? '');
        if ($body === '' || $ts === '' || $nonce === '' || $sig === '' || $serial === '') $fail('回调头缺失', 400);
        if (abs(time() - (int)$ts) > 300) $fail('时间戳超出容忍窗口', 401);
        $pub = self::verifyPublicKey($serial);
        if ($pub === null) $fail('验签公钥不可用（序列号不匹配或未配置）', 401);
        $v = openssl_verify($ts . "\n" . $nonce . "\n" . $body . "\n", base64_decode($sig, true) ?: '', $pub, OPENSSL_ALGO_SHA256);
        if ($v !== 1) $fail('验签失败', 401);

        $env = json_decode($body, true);
        $res = is_array($env['resource'] ?? null) ? $env['resource'] : null;
        if ($res === null) $fail('报文缺少 resource', 400);
        $plain = self::aesGcmDecrypt((string)($res['ciphertext'] ?? ''), (string)($res['nonce'] ?? ''), (string)($res['associated_data'] ?? ''));
        if ($plain === null) $fail('回调解密失败（检查 APIv3 密钥）', 400);
        $d = json_decode($plain, true);
        if (!is_array($d)) $fail('解密报文非法', 400);

        $order = Database::fetch('SELECT * FROM wechat_pay_orders WHERE out_trade_no=?', [(string)($d['out_trade_no'] ?? '')]);
        if (!$order) $fail('订单不存在', 404);
        if ($order['status'] === 'paid') $okResp(); // 幂等

        $state = (string)($d['trade_state'] ?? '');
        if ($state !== 'SUCCESS') {
            if (in_array($state, ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
                Database::run('UPDATE wechat_pay_orders SET status="error", err=?, updated_at=NOW() WHERE id=? AND status="created"',
                    [str_take('回调状态 ' . $state, 110, ''), (int)$order['id']]);
            }
            $okResp(); // 非成功状态也回 SUCCESS（避免重发；本站另有查单兜底）
        }
        $total = (int)($d['amount']['total'] ?? 0);
        $payerTotal = (int)($d['amount']['payer_total'] ?? 0);
        if ($total !== (int)$order['amount_cents']) $fail('订单金额不符', 400);
        self::credit((int)$order['id'], (string)($d['transaction_id'] ?? ''), $payerTotal > 0 ? $payerTotal : $total);
        $okResp();
    }

    /** 查单（兜底，V3 API 直调）：[ok, body] */
    private static function queryOrderApi(string $outTradeNo): array
    {
        $mchid = trim((string)setting('wxpay.mchid', ''));
        [$ok, $status, $r] = self::request('GET', '/v3/pay/transactions/out-trade-no/' . urlencode($outTradeNo) . '?mchid=' . urlencode($mchid));
        return [$ok, $r];
    }

    /** ===== 会员前台 ===== */

    public static function memberCard(array $ctx): array
    {
        if (!self::ready()) return $ctx;
        $pkgs = (int)Database::value('SELECT COUNT(*) FROM packages WHERE status=1');
        if ($pkgs === 0) return $ctx;
        $ctx['cards'][] = ['title' => '购买套餐', 'desc' => $pkgs . ' 种咨询套餐在线购买（微信支付）', 'url' => '/plugin/wechat-pay/store', 'sort' => 45];
        return $ctx;
    }

    /** 套餐列表 */
    public static function front_store($req): void
    {
        $m = \app\Services\MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $pkgs = Database::fetchAll('SELECT * FROM packages WHERE status=1 ORDER BY price_cents ASC LIMIT 20');
        $content = \app\Core\View::str(__DIR__ . '/views/store.php', ['m' => $m, 'pkgs' => $pkgs]);
        \app\Core\View::render('plugin-page', [
            'title' => '购买套餐',
            'meta' => ['title' => '购买套餐 · 微信支付', 'keywords' => '', 'description' => '', 'canonical' => null, 'og' => ['type' => 'website']],
            'noindex' => true, 'content' => $content,
        ]);
    }

    /** 下单（POST）：微信内 JSAPI，PC Native 扫码 */
    public static function front_buy($req): void
    {
        \app\Core\Csrf::guard();
        if (!self::ready()) {
            \app\Core\Session::flash('error', '微信支付未开启');
            \app\Core\ResponseRedirect('/plugin/wechat-pay/store');
        }
        $m = \app\Services\MemberService::current();
        if (!$m) { \app\Core\ResponseRedirect('/member/login'); }
        $pkgId = (int)$req->post('package_id', 0);
        $inWx = stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'MicroMessenger') !== false;
        $channel = $inWx ? 'jsapi' : 'native';
        [$ok, $msg, $data] = self::createOrder((int)$m['id'], $pkgId, $channel);
        if (!$ok) {
            \app\Core\Session::flash('error', $msg);
            \app\Core\ResponseRedirect('/plugin/wechat-pay/store');
        }
        $out = (string)$data['out_trade_no'];
        $meta = ['title' => '支付', 'keywords' => '', 'description' => '', 'canonical' => null, 'og' => ['type' => 'website']];
        $content = \app\Core\View::str(__DIR__ . '/views/pay.php', [
            'out' => $out,
            'codeUrl' => (string)($data['code_url'] ?? ''),
            'jsapi' => $data['jsapi'] ?? null,
            'statusUrl' => url('/plugin/wechat-pay/status') . '?order=' . urlencode($out),
            'assetUrl' => url('/plugin/wechat-pay/asset') . '?f=qrcode.min.js',
            'storeUrl' => url('/plugin/wechat-pay/store'),
        ]);
        \app\Core\View::render('plugin-page', ['title' => '支付', 'meta' => $meta, 'noindex' => true, 'content' => $content]);
    }

    /** ==================== 咨询单支付（v2.13.0） ==================== */

    /**
     * 为「视频咨询预约」生成支付单：金额取 bookings.amount_cents（提交预约时按咨询师定价锁定）
     * 支付成功回调 → applyCredit 分支 → bookings.pay_status='paid'
     */
    public static function createBookingOrder(int $memberId, int $bookingId, string $channel): array
    {
        if (!(bool)setting('wxpay.enabled', '0')) return [false, '微信支付未开启，请联系机构', []];
        try { [$appid, $mchid] = self::cfg(); } catch (\Throwable $t) { return [false, $t->getMessage(), []]; }
        if (!in_array($channel, ['native', 'jsapi'], true)) $channel = 'native';
        $b = Database::fetch('SELECT * FROM bookings WHERE id=?', [$bookingId]);
        if (!$b) return [false, '预约不存在', []];
        if ((string)$b['pay_status'] === 'paid') return [false, '该预约已支付，无需重复支付', []];
        $total = (int)($b['amount_cents'] ?? 0);
        if ($total < 1) return [false, '该预约未设置在线支付金额', []];
        // 防重复单：已有 created/paid 的 booking 单直接复用
        $exists = Database::fetch("SELECT * FROM wechat_pay_orders WHERE booking_id=? AND status IN ('created','paid') ORDER BY id DESC LIMIT 1", [$bookingId]);
        if ($exists && $exists['status'] === 'paid') return [false, '该预约已支付', []];
        if ($exists && $exists['channel'] === $channel) {
            return [true, '', ['out_trade_no' => (string)$exists['out_trade_no'], 'code_url' => (string)($exists['code_url'] ?? ''), 'jsapi' => null, 'reused' => true]];
        }
        $m = Database::fetch('SELECT * FROM members WHERE id=? AND status="active"', [$memberId]);
        if (!$m) return [false, '会员状态异常，请重新登录', []];

        $out = 'PSY' . date('ymdHis') . strtoupper(bin2hex(random_bytes(3)));
        $subject = str_take('视频咨询 · ' . (string)$b['code'], 100, '');
        $orderId = (int)Database::insert('wechat_pay_orders', [
            'out_trade_no' => $out, 'member_id' => $memberId, 'package_id' => 0,
            'booking_id' => $bookingId,
            'subject' => $subject, 'amount_cents' => $total, 'channel' => $channel,
            'status' => 'created', 'created_at' => date('Y-m-d H:i:s'),
        ]);
        $payBody = [
            'appid' => $appid, 'mchid' => $mchid,
            'description' => $subject ?: '视频咨询',
            'out_trade_no' => $out,
            'notify_url' => self::notifyUrl(),
            'amount' => ['total' => $total, 'currency' => 'CNY'],
        ];
        if ($channel === 'jsapi') {
            $openid = (string)($m['openid'] ?? '');
            if ($openid === '') return [false, '微信内支付需先在会员中心绑定微信', []];
            $payBody['payer'] = ['openid' => $openid];
            [$ok, $status, $r] = self::request('POST', '/v3/pay/transactions/jsapi', json_encode($payBody, JSON_UNESCAPED_UNICODE));
            if (!$ok) return self::orderFail($orderId, $r, $status);
            $prepayId = (string)($r['prepay_id'] ?? '');
            if ($prepayId === '') return self::orderFail($orderId, ['message' => '响应缺少 prepay_id'], $status);
            Database::run('UPDATE wechat_pay_orders SET prepay_id=? WHERE id=?', [$prepayId, $orderId]);
            $ts = (string)time();
            $nonce = bin2hex(random_bytes(8));
            $pkgStr = 'prepay_id=' . $prepayId;
            $pem = Crypto::decrypt((string)setting('wxpay.mch_key_secret', ''));
            $pk = openssl_pkey_get_private($pem);
            $paySign = '';
            openssl_sign($appid . "\n" . $ts . "\n" . $nonce . "\n" . $pkgStr . "\n", $paySign, $pk, OPENSSL_ALGO_SHA256);
            return [true, '', [
                'out_trade_no' => $out,
                'jsapi' => ['appId' => $appid, 'timeStamp' => $ts, 'nonceStr' => $nonce, 'package' => $pkgStr, 'signType' => 'RSA', 'paySign' => base64_encode($paySign)],
            ]];
        }
        [$ok, $status, $r] = self::request('POST', '/v3/pay/transactions/native', json_encode($payBody, JSON_UNESCAPED_UNICODE));
        if (!$ok) return self::orderFail($orderId, $r, $status);
        $codeUrl = (string)($r['code_url'] ?? '');
        if ($codeUrl === '') return self::orderFail($orderId, ['message' => '响应缺少 code_url'], $status);
        Database::run('UPDATE wechat_pay_orders SET code_url=? WHERE id=?', [$codeUrl, $orderId]);
        return [true, '', ['out_trade_no' => $out, 'code_url' => $codeUrl, 'jsapi' => null]];
    }

    /** 支付页（PC 扫码 / 微信内 JSAPI） */
    public static function front_pay_booking($req, array $segs = []): void
    {
        \app\Services\MemberService::requireLogin();
        $m = \app\Services\MemberService::current();
        $bid = (int)($segs[0] ?? 0);
        $b = Database::fetch('SELECT * FROM bookings WHERE id=?', [$bid]);
        if (!$b || (string)$b['phone'] !== (string)$m['phone']) { http_response_code(403); echo 'forbidden'; exit; }
        $backUrl = url('/member/bookings');
        if ((string)$b['pay_status'] === 'paid') { \app\Core\ResponseRedirect($backUrl); }
        $inWx = stripos((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 'MicroMessenger') !== false;
        $channel = $inWx ? 'jsapi' : 'native';
        [$ok, $msg, $data] = self::createBookingOrder((int)$m['id'], $bid, $channel);
        if (!$ok) {
            \app\Core\Session::flash('error', $msg);
            \app\Core\ResponseRedirect($backUrl);
        }
        $out = (string)$data['out_trade_no'];
        $content = \app\Core\View::str(__DIR__ . '/views/pay.php', [
            'out' => $out,
            'codeUrl' => (string)($data['code_url'] ?? ''),
            'jsapi' => $data['jsapi'] ?? null,
            'statusUrl' => url('/plugin/wechat-pay/status') . '?order=' . urlencode($out),
            'assetUrl' => url('/plugin/wechat-pay/asset') . '?f=qrcode.min.js',
            'storeUrl' => $backUrl,
            'backUrl' => $backUrl,
            'backLabel' => '我的预约',
            'backLabel2' => '预约列表',
            'subject' => '视频咨询 · ' . (string)$b['code'],
        ]);
        \app\Core\View::render('plugin-page', [
            'title' => '支付咨询费',
            'meta' => ['title' => '支付咨询费', 'keywords' => '', 'description' => '', 'canonical' => null, 'og' => ['type' => 'website']],
            'noindex' => true, 'content' => $content,
        ]);
    }

    /** 支付状态轮询（会员本人）+ 查单兜底 */
    public static function front_status($req): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $m = \app\Services\MemberService::current();
        if (!$m) { json_out(['ok' => false, 'error' => '请先登录']); }
        $out = (string)$req->get('order', '');
        $order = Database::fetch('SELECT * FROM wechat_pay_orders WHERE out_trade_no=? AND member_id=?', [$out, (int)$m['id']]);
        if (!$order) { json_out(['ok' => false, 'error' => '订单不存在']); }
        if ($order['status'] === 'created' && self::ready()) {
            [$ok, $r] = self::queryOrderApi($out);
            $state = (string)($r['trade_state'] ?? '');
            if ($ok && $state === 'SUCCESS') {
                self::credit((int)$order['id'], (string)($r['transaction_id'] ?? ''), (int)($r['amount']['payer_total'] ?? (int)$order['amount_cents']));
            } elseif ($ok && in_array($state, ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
                Database::run('UPDATE wechat_pay_orders SET status="error", err=?, updated_at=NOW() WHERE id=? AND status="created"',
                    [str_take('查单状态 ' . $state, 110, ''), (int)$order['id']]);
            }
            $order = Database::fetch('SELECT * FROM wechat_pay_orders WHERE id=?', [(int)$order['id']]);
        }
        json_out(['ok' => true, 'status' => (string)$order['status'], 'paid' => $order['status'] === 'paid',
            'credited' => (int)$order['credited'], 'remain' => class_exists(\plugins\packagespro\Plugin::class) ? \plugins\packagespro\Plugin::memberRemain((int)$m['id']) : null]);
    }

    /** 插件静态资源（qrcode.min.js） */
    public static function front_asset($req): void
    {
        $name = basename((string)$req->get('f', 'qrcode.min.js'));
        $file = __DIR__ . '/assets/' . $name;
        if (!is_file($file) || !preg_match('/^[\w\.\-]+$/', $name)) { http_response_code(404); echo '404'; exit; }
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=86400');
        echo (string)file_get_contents($file);
        exit;
    }

    /** ===== 每日对账（cron daily）===== */

    public static function reconcile(): array
    {
        if (!(bool)setting('wxpay.enabled', '0')) return ['skipped' => 'disabled'];
        if (!self::ready()) return ['skipped' => 'config'];
        $rows = Database::fetchAll("SELECT * FROM wechat_pay_orders WHERE status='created' AND created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR) LIMIT 50");
        $resolved = 0; $paid = 0;
        foreach ($rows as $o) {
            [$ok, $r] = self::queryOrderApi((string)$o['out_trade_no']);
            $state = (string)($r['trade_state'] ?? '');
            if ($ok && $state === 'SUCCESS') {
                self::credit((int)$o['id'], (string)($r['transaction_id'] ?? ''), (int)($r['amount']['payer_total'] ?? (int)$o['amount_cents']));
                $paid++; $resolved++;
            } elseif ($ok && in_array($state, ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
                Database::run('UPDATE wechat_pay_orders SET status="error", err=?, updated_at=NOW() WHERE id=? AND status="created"',
                    [str_take('对账状态 ' . $state, 110, ''), (int)$o['id']]);
                $resolved++;
            }
        }
        return ['checked' => count($rows), 'resolved' => $resolved, 'paid' => $paid];
    }

    /** ===== 后台 ===== */

    public static function adminIndex($req = null): void
    {
        $orders = Database::fetchAll('SELECT * FROM wechat_pay_orders ORDER BY id DESC LIMIT 30');
        $stats = [
            'today_paid' => (int)Database::value("SELECT COUNT(*) FROM wechat_pay_orders WHERE status='paid' AND paid_at >= CURDATE()"),
            'today_amount' => (int)Database::value("SELECT COALESCE(SUM(amount_cents),0) FROM wechat_pay_orders WHERE status='paid' AND paid_at >= CURDATE()"),
            'month_amount' => (int)Database::value("SELECT COALESCE(SUM(amount_cents),0) FROM wechat_pay_orders WHERE status='paid' AND paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"),
            'pending_credit' => (int)Database::value("SELECT COUNT(*) FROM wechat_pay_orders WHERE status='paid' AND credited=0"),
            'created' => (int)Database::value("SELECT COUNT(*) FROM wechat_pay_orders WHERE status='created'"),
        ];
        $cfgReady = self::ready();
        $verifyMode = (string)setting('wxpay.verify_mode', 'public_key');
        \app\Core\PluginManager::view('wechat-pay', 'admin', [
            'meta' => \app\Core\PluginManager::get('wechat-pay'),
            'orders' => $orders, 'stats' => $stats,
            'cfgReady' => $cfgReady, 'verifyMode' => $verifyMode,
            'notifyUrl' => self::notifyUrl(),
            'enabled' => (bool)setting('wxpay.enabled', '0'),
        ]);
    }

    /** 后台查单（admin 动作 query-order） */
    public static function queryOrder($req): array
    {
        $id = (int)$req->post('order_id', 0);
        $o = Database::fetch('SELECT * FROM wechat_pay_orders WHERE id=?', [$id]);
        if (!$o) return [false, '订单不存在'];
        if (!self::ready()) return [false, '微信支付配置不完整'];
        [$ok, $r] = self::queryOrderApi((string)$o['out_trade_no']);
        $state = (string)($r['trade_state'] ?? '?');
        if ($ok && $state === 'SUCCESS') {
            self::credit($id, (string)($r['transaction_id'] ?? ''), (int)($r['amount']['payer_total'] ?? (int)$o['amount_cents']));
            return [true, '微信侧已支付：已入账（单号 ' . (string)($r['transaction_id'] ?? '—') . '）'];
        }
        if ($ok) {
            if (in_array($state, ['CLOSED', 'REVOKED', 'PAYERROR'], true)) {
                Database::run('UPDATE wechat_pay_orders SET status="error", err=? WHERE id=? AND status="created"', [str_take('查单状态 ' . $state, 110, ''), $id]);
            }
            return [true, '微信侧状态：' . $state];
        }
        return [false, '查单失败：' . (string)($r['message'] ?? '未知错误')];
    }

    /** 后台补入账（admin 动作 credit-order）：仅已支付未入账可用 */
    public static function creditOrder($req): array
    {
        $id = (int)$req->post('order_id', 0);
        $o = Database::fetch('SELECT * FROM wechat_pay_orders WHERE id=?', [$id]);
        if (!$o) return [false, '订单不存在'];
        if ($o['status'] !== 'paid') return [false, '订单未支付，不能入账'];
        if ((int)$o['credited'] === 1) return [false, '该订单已入账'];
        $ok = self::applyCredit($o, (int)$o['amount_cents']);
        return $ok ? [true, '已补入账 ' . ((int)Database::value('SELECT sessions FROM packages WHERE id=?', [(int)$o['package_id']]) ?: '?') . ' 次'] : [false, '补入账失败：检查咨询套餐插件是否启用、套餐是否有效'];
    }
}
