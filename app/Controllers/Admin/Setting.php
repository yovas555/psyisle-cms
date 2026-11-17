<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Cache;
use app\Core\Csrf;
use app\Core\Request;
use app\Core\Session;
use app\Core\Mailer;

class Setting extends Base
{
    protected string $permNode = 'settings';
    protected string $title = '系统设置';
    public function index(Request $req, array $p): void
    {
        $this->view('setting/index', ['title' => $this->title]);
    }

    public function save(Request $req, array $p): void
    {
        Csrf::guard();
        $post = $req->posts();
        // skey => [POST 键（PHP 会把点转下划线，故用下划线形式）, 分组]
        $allowed = [
            'site.name' => ['site_name', 'general'],
            'site.slogan' => ['site_slogan', 'general'],
            'site.brand_mark' => ['site_brand_mark', 'general'],
        'site.logo_type' => ['site_logo_type', 'general'],
        'site.logo_custom' => ['site_logo_custom', 'general'],
        'booking.require_member' => ['booking_require_member', 'general'],
        'assess.require_member' => ['assess_require_member', 'general'],
        'message.require_member' => ['message_require_member', 'general'],
        'feedback.site_key' => ['feedback_site_key', 'security'],
        'feedback.site_secret' => ['feedback_site_secret', 'security'],
        'feedback.api_url' => ['feedback_api_url', 'security'],
            'site.hero_title' => ['site_hero_title', 'general'],
            'site.hero_sub' => ['site_hero_sub', 'general'],
            'site.stat_years' => ['site_stat_years', 'general'],
            'site.stat_hours' => ['site_stat_hours', 'general'],
            'site.stat_clients' => ['site_stat_clients', 'general'],
            'site.icp' => ['site_icp', 'general'],
            'site.police_icp' => ['site_police_icp', 'general'],
            'site.maintenance' => ['site_maintenance', 'general'],
            'site.crisis_bar' => ['site_crisis_bar', 'general'],
            'site.crisis_bar_text' => ['site_crisis_bar_text', 'general'],
            'site.powered_by' => ['site_powered_by', 'general'],
            'site.domain' => ['site_domain', 'general'],
            'site.theme' => ['site_theme', 'general'],
            'seo.keywords' => ['seo_keywords', 'seo'],
            'seo.description' => ['seo_description', 'seo'],
            'seo.robots_extra' => ['seo_robots_extra', 'seo'],
            'seo.baidu_token' => ['seo_baidu_token', 'seo'],
            'seo.indexnow_key' => ['seo_indexnow_key', 'seo'],
            'seo.autolink' => ['seo_autolink', 'seo'],
            'contact.phone' => ['contact_phone', 'contact'],
            'contact.address' => ['contact_address', 'contact'],
            'contact.hours' => ['contact_hours', 'contact'],
            'contact.wechat_qr' => ['contact_wechat_qr', 'contact'],
            'contact.city' => ['contact_city', 'contact'],
            'mail.enabled' => ['mail_enabled', 'mail'],
            'mail.host' => ['mail_host', 'mail'],
            'mail.port' => ['mail_port', 'mail'],
            'mail.user' => ['mail_user', 'mail'],
            'mail.pass' => ['mail_pass', 'mail'],
            'mail.from' => ['mail_from', 'mail'],
            'mail.from_name' => ['mail_from_name', 'mail'],
            'mail.encryption' => ['mail_encryption', 'mail'],
            'notify.admin_email' => ['notify_admin_email', 'notify'],
            'notify.daily_summary' => ['notify_daily_summary', 'notify'],
            'notify.mask_phone' => ['notify_mask_phone', 'notify'],
            'security.admin_idle_timeout' => ['security_admin_idle_timeout', 'security'],
            'license.remote_check' => ['license_remote_check', 'general'],
            'license.check_url' => ['license_check_url', 'general'],
            'license.activate_url' => ['license_activate_url', 'general'],
            'security.admin_gate' => ['security_admin_gate', 'security'],
            'security.admin_ips' => ['security_admin_ips', 'security'],
            'notify.login_notice' => ['notify_login_notice', 'notify'],
            'notify.on_booking' => ['notify_on_booking', 'notify'],
            'notify.on_message' => ['notify_on_message', 'notify'],
            'notify.on_chat_question' => ['notify_on_chat_question', 'notify'],
            'notify.on_chat_escalate' => ['notify_on_chat_escalate', 'notify'],
            'notify.on_event' => ['notify_on_event', 'notify'],
            'notify.on_system' => ['notify_on_system', 'notify'],
            'notify.on_license' => ['notify_on_license', 'notify'],
            'reply.templates' => ['reply_templates', 'general'],
            'update.check_url' => ['update_check_url', 'general'],
            'stats.enabled' => ['stats_enabled', 'general'],
            'api.token' => ['api_token', 'general'],
        ];
        // API Token 重新生成
        if (!empty($post['api_token_flag'])) {
            \app\Core\Model::saveSetting('api.token', bin2hex(random_bytes(20)), 'general');
            Session::flash('success', 'API Token 已重新生成（旧 Token 立即失效）');
        }
        $saved = 0;
        // 开关型设置：checkbox 未勾选时浏览器不提交该键，必须显式写 0（否则永远无法关闭）
        $switches = [
            'mail.enabled' => 'mail', 'site.maintenance' => 'general', 'site.crisis_bar' => 'general',
            'site.powered_by' => 'general',
            'notify.mask_phone' => 'notify', 'notify.daily_summary' => 'notify', 'notify.login_notice' => 'notify',
            'license.remote_check' => 'general', 'stats.enabled' => 'general',
        ];
        // 完整表单提交（带 _full_settings 标记）才按「无键=关」处理；
        // 部分提交（脚本/AJAX/插件页）只更新显式提交的开关，避免误清零
        $isFullForm = isset($post['_full_settings']);
        foreach ($switches as $sk => $sg) {
            $pk = str_replace('.', '_', $sk);
            if ($isFullForm) {
                \app\Core\Model::saveSetting($sk, !empty($post[$pk]) ? '1' : '0', $sg);
                $saved++;
            } elseif (array_key_exists($pk, $post)) {
                \app\Core\Model::saveSetting($sk, !empty($post[$pk]) ? '1' : '0', $sg);
                $saved++;
            }
        }
        foreach ($allowed as $key => [$postKey, $group]) {
            if (isset($switches[$key])) continue; // 开关已处理
            if (!array_key_exists($postKey, $post)) continue;
            $val = (string)$post[$postKey];
            // 敏感键：掩码/空值表示不修改；新值加密存储
            if (\app\Core\Crypto::isSecretKey($key)) {
                if (\app\Core\Crypto::isMask($val)) { continue; }
                $val = \app\Core\Crypto::encrypt($val);
            }
            \app\Core\Model::saveSetting($key, $val, $group);
            $saved++;
        }
        Cache::forget('settings_all');
        Cache::forget('home_data'); // 主题/文案变更即时生效
        $this->audit('setting.save', 'settings', null, ['count' => $saved]);
        Session::flash('success', "已保存 $saved 项设置");
        \app\Core\ResponseRedirect('/admin/settings');
    }

    /** SMTP 测试邮件 */
    public function testMail(Request $req, array $p): void
    {
        Csrf::guard();
        $to = (string)$req->post('to_email', '');
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            json_out(['ok' => false, 'msg' => '邮箱格式不正确']);
        }
        [$ok, $err] = Mailer::send($to, '【' . setting('site.name', '一二三') . '】SMTP 配置测试', '<p>这是一封测试邮件，收到即表示 SMTP 配置正确。</p>');
        json_out(['ok' => $ok, 'msg' => $ok ? '测试邮件已发送，请查收' : ('发送失败：' . $err)]);
    }
}
