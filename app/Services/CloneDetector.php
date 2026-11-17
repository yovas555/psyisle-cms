<?php
declare(strict_types=1);

namespace app\Services;

use app\Core\Database;

/**
 * 克隆/换身份检测与向导（v2.16.0 #73）
 * 整库克隆建站会携带源站的身份与凭据键，导致客户站流量/通知/收款记到源站名下。
 * 本类检测克隆特征并生成向导清单。
 */
class CloneDetector
{
    /** 检测全部克隆特征，返回 ['cloned'=>bool, 'checks'=>[], 'regen'=>[], 'manual'=>[]] */
    public static function scan(): array
    {
        $cloned = false;
        $checks = []; // [key, label, status(ok/warn/fail), detail]
        $regen = [];  // 可一键重生成的键
        $manual = []; // 需人工处理的键

        // ① 授权域名 vs 站点域名
        $host = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
        $licDomain = (string)setting('license.bind_domain', '');
        $licRow = Database::fetch('SELECT license_key, domain FROM licenses WHERE status="active" ORDER BY id DESC LIMIT 1');
        $dbDomain = (string)($licRow['domain'] ?? '');
        $okDomain = $dbDomain === '' || strcasecmp($dbDomain, $host) === 0;
        if (!$okDomain) $cloned = true;
        $checks[] = ['license_domain', '授权绑定域名', $okDomain ? 'ok' : 'fail', $dbDomain ?: '(未绑定)'];

        // ② wxhub.site_key 与域名不符
        $wxhubSite = (string)setting('wxhub.site_key', '');
        if ($wxhubSite !== '' && $wxhubSite !== $host) {
            // 已配置但站点短码与当前域名关联的短码不同
            $cloned = true;
            $checks[] = ['wxhub_site_key', '中台站点短码', 'warn', '当前=' . $wxhubSite . '，可能与本站域名不匹配'];
            $manual[] = ['wxhub.site_key', '中台站点短码', '在中台管理后台为本站创建新站点条目后填入新短码'];
            $manual[] = ['wxhub.site_secret', '中台站点密钥', '用新站点的 secret 替换'];
        }

        // ③ wxpay（同商户号+同序列号=源站支付凭据）
        $mchSerial = (string)setting('wxpay.mch_serial_no', '');
        $wxpayEnabled = (bool)setting('wxpay.enabled', '0');
        if ($wxpayEnabled && $mchSerial !== '') {
            $checks[] = ['wxpay', '微信支付', 'warn', '克隆站不应继承源站支付凭据——确认商户号/证书归本站法人'];
            $manual[] = ['wxpay.mchid', '微信支付商户号', '确认商户号属于本站法人（同法人可沿用）'];
            $manual[] = ['wxpay.mch_serial_no', '商户证书序列号', '确认证书序列号对应本站法人的 API 证书'];
            $manual[] = ['wxpay.apiv3_key', 'APIv3 密钥', '确认与本站商户号匹配'];
            $manual[] = ['wxpay.mch_key_secret', '商户私钥', '确认与本站商户号匹配'];
        }

        // ④ wechat.secret / wxlogin.mp_secret（服务号凭据）
        $wxSecret = (string)setting('wechat.secret', '');
        if (\app\Core\Crypto::isEncrypted($wxSecret)) {
            $checks[] = ['wechat', '服务号凭据', 'warn', '克隆站继承了源站服务号凭据——确认归本站法人或替换'];
            $manual[] = ['wechat.appid', '服务号 AppID', '确认服务号归属'];
            $manual[] = ['wechat.secret', '服务号 Secret', '确认有效且 IP 白名单含本站 IP'];
            $manual[] = ['wxlogin.mp_secret', '微信登录服务号 Secret', '确认有效'];
        }

        // ⑤ mail.*（SMTP 凭据）
        $mailHost = (string)setting('mail.host', '');
        if ($mailHost !== '') {
            $checks[] = ['mail', '邮件 SMTP', 'ok', $mailHost];
        }

        // ⑥ notify.admin_email（管理员收件箱）
        $adminEmail = (string)setting('notify.admin_email', '');
        if ($adminEmail !== '' && !str_contains($adminEmail, $host)) {
            $checks[] = ['admin_email', '管理员通知邮箱', 'warn', '克隆站可能携带源站通知邮箱'];
            $manual[] = ['notify.admin_email', '管理员通知邮箱', '替换为本站管理员/值班邮箱'];
        }

        // ⑦ sms.admin_phones
        $smsPhones = (string)setting('sms.admin_phones', '');
        if ($smsPhones !== '') {
            $checks[] = ['sms_phones', '短信通知手机号', 'warn', '克隆站可能携带源站值班手机'];
            $manual[] = ['sms.admin_phones', '短信通知手机号', '替换为本站值班手机'];
        }

        // ⑧ ai.persona / ai.api_key
        $aiKey = (string)setting('ai.api_key', '');
        if (\app\Core\Crypto::isEncrypted($aiKey) || trim($aiKey) !== '') {
            $checks[] = ['ai', 'AI 客服', 'ok', '已配置（确认 API Key 归本站）'];
            $manual[] = ['ai.api_key', 'AI API Key', '确认归本站或替换'];
        }

        // ⑨ 可一键重生成的键（无业务语义的安全值）
        $regenItems = [
            ['cron_secret', '计划任务密钥'],
            ['security.admin_gate', '后台入口门禁参数'],
        ];
        foreach ($regenItems as [$skey, $label]) {
            $cur = (string)setting($skey, '');
            $regen[] = ['skey' => $skey, 'label' => $label, 'has_value' => $cur !== ''];
        }

        // ⑩ 不可重置的 config 侧约束（仅提示）
        $manual[] = ['__config', 'config 侧约束（只读）', 'issuer_public_key/stats_salt 必须与加密数据同源不可随意换；distribute.enabled 客户站必须 false；license_secret/private_key 不得带出签发方'];

        return ['cloned' => $cloned, 'checks' => $checks, 'regen' => $regen, 'manual' => $manual, 'host' => $host];
    }

    /** 一键重生成安全键 */
    public static function regenerate(string $skey): array
    {
        $allowed = ['cron_secret', 'security.admin_gate'];
        if (!in_array($skey, $allowed, true)) return [false, '该键不允许一键重生成'];
        $val = $skey === 'cron_secret' ? bin2hex(random_bytes(16)) : bin2hex(random_bytes(12));
        \app\Core\Model::saveSetting($skey, $val, 'system');
        \app\Core\Cache::forget('settings_all');
        return [true, $val];
    }
}
