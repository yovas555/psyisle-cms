<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Database;
use app\Core\Request;

/**
 * 域总览（v2.8.4）：每个顶部功能域的落地页——关键数据卡片 + 域内快捷入口。
 * 让「内容」「运营」等域有真正差异化的第一屏，而不是直接掉进某个子页面。
 */
class Area extends Base
{
    protected string $permNode = 'dashboard'; // 概览类页面对所有角色开放
    protected string $title = '总览';

    private const DOMAINS = ['home', 'jiaowu', 'member', 'org', 'content', 'ops', 'wechat', 'system'];
    private const LABELS = [
        'home' => '首页总览', 'jiaowu' => '教务总览', 'member' => '会员总览', 'org' => '机构总览',
        'content' => '内容总览', 'ops' => '运营总览', 'wechat' => '微信生态总览', 'system' => '系统总览',
    ];

    public function index(Request $req, array $p): void
    {
        $domain = (string)($p['domain'] ?? 'home');
        if (!in_array($domain, self::DOMAINS, true)) { \app\Core\ResponseRedirect('/admin'); }
        $method = 'data' . ucfirst($domain);
        $cards = $this->{$method}();
        $this->title = self::LABELS[$domain];
        $this->view('area', [
            'domain' => $domain,
            'domainLabel' => self::LABELS[$domain],
            'cards' => $cards,
            'checklist' => $domain === 'wechat' ? self::setupChecklist() : [],
        ]);
    }

    /** 卡片结构：[icon, 标题, 主值, 描述, 链接, 强调色?] */
    private function dataHome(): array
    {
        $today = 0;
        try { $today = (int)Database::value('SELECT COALESCE(SUM(pv),0) FROM visit_stats WHERE date=CURDATE()'); } catch (\Throwable $t) {}
        $pendingN = (int)Database::value('SELECT COUNT(*) FROM notifications WHERE is_read=0');
        $mailQ = (int)Database::value("SELECT COUNT(*) FROM email_queue WHERE status='queued'");
        $mailErr = (int)Database::value("SELECT COUNT(*) FROM email_queue WHERE status='failed'");
        return [
            ['📈', '今日访问', $today, '全站 PV（自托管统计）', '/admin/tools'],
            ['🔔', '未读通知', $pendingN, '点击进入通知中心处理', '/admin/notifications', $pendingN > 0 ? 'warn' : ''],
            ['📮', '邮件队列', $mailQ . ' 待发 / ' . $mailErr . ' 失败', '发送失败请检查 SMTP 配置', '/admin/plugin/mail-notify', $mailErr > 0 ? 'warn' : ''],
            ['🔑', '授权状态', $this->licLabel(), '到期前 30 天会提醒', '/admin/license'],
        ];
    }

    private function licLabel(): string
    {
        try { return (string)(\app\Services\LicenseService::status()['label'] ?? '未激活'); } catch (\Throwable $t) { return '未激活'; }
    }

    private function dataJiaowu(): array
    {
        $pending = (int)Database::value("SELECT COUNT(*) FROM bookings WHERE status='pending'");
        $week = (int)Database::value("SELECT COUNT(*) FROM bookings WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        $msgs = (int)Database::value("SELECT COUNT(*) FROM messages WHERE status='pending'");
        $chats = (int)Database::value("SELECT COUNT(*) FROM chat_sessions WHERE escalated=1 AND status<>'closed'");
        $signs = 0;
        try { $signs = (int)Database::value("SELECT COUNT(*) FROM consent_signatures WHERE status='pending'"); } catch (\Throwable $t) {}
        return [
            ['📅', '待确认预约', $pending, '本周新增预约 ' . $week . ' 条', '/admin/bookings?status=pending', $pending > 0 ? 'warn' : ''],
            ['💬', '待回复留言', $msgs, '及时回复提升转化', '/admin/messages?status=pending', $msgs > 0 ? 'warn' : ''],
            ['🎧', 'AI 转人工', $chats, '会话等待人工接管', '/admin/chat', $chats > 0 ? 'warn' : ''],
            ['✍️', '待签署同意书', $signs, '知情同意签署链接待完成', '/admin/plugin/consent-pro', $signs > 0 ? 'warn' : ''],
            ['🧪', '本月测评', (int)Database::value('SELECT COUNT(*) FROM assessment_results WHERE created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'), '量表提交记录', '/admin/assessments'],
            ['🎪', '进行中活动', (int)Database::value("SELECT COUNT(*) FROM events WHERE status=1"), '报名情况在活动管理查看', '/admin/events'],
        ];
    }

    private function dataMember(): array
    {
        $total = (int)Database::value("SELECT COUNT(*) FROM members WHERE status='active'");
        $week = (int)Database::value("SELECT COUNT(*) FROM members WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
        $del = (int)Database::value("SELECT COUNT(*) FROM members WHERE status='delete_requested'");
        $wx = 0;
        try { $wx = (int)Database::value("SELECT COUNT(*) FROM members WHERE openid IS NOT NULL OR wx_openid IS NOT NULL OR unionid IS NOT NULL"); } catch (\Throwable $t) {}
        $ent = 0;
        try { $ent = (int)Database::value('SELECT COUNT(*) FROM enterprises WHERE status=1'); } catch (\Throwable $t) {}
        return [
            ['👥', '在册会员', $total, '本周新增 ' . $week, '/admin/members'],
            ['🔑', '已绑微信', $wx, '绑定率 ' . ($total > 0 ? round($wx / max(1, $total) * 100) : 0) . '%', '/admin/plugin/wechat-login'],
            ['🤝', '签约企业', $ent, 'EAP 企业码入口', '/admin/plugin/eap-pro'],
            ['🗑️', '注销申请', $del, '需人工核对后删除数据', '/admin/members', $del > 0 ? 'warn' : ''],
        ];
    }

    private function dataOrg(): array
    {
        $stores = 0;
        try { $stores = (int)Database::value('SELECT COUNT(*) FROM stores WHERE status=1'); } catch (\Throwable $t) {}
        return [
            ['🧑‍⚕️', '在职咨询师', (int)Database::value('SELECT COUNT(*) FROM counselors WHERE status=1'), '资料完整度影响前台展示', '/admin/counselors'],
            ['🧩', '服务项目', (int)Database::value('SELECT COUNT(*) FROM services WHERE status=1'), 'AI 客服与预约表单共用', '/admin/services'],
            ['🏢', '启用门店', $stores, '前台预约可选门店', '/admin/plugin/stores-pro'],
            ['🗓️', '本月考勤', (int)Database::value("SELECT COUNT(*) FROM attendance WHERE marked_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"), '完成/爽约/迟到标记', '/admin/plugin/schedule-pro'],
        ];
    }

    private function dataContent(): array
    {
        $arts = (int)Database::value("SELECT COUNT(*) FROM articles WHERE deleted_at IS NULL");
        $drafts = (int)Database::value("SELECT COUNT(*) FROM articles WHERE status='draft' AND deleted_at IS NULL");
        $recent = Database::fetchAll("SELECT id, title FROM articles WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 5");
        return [
            ['📝', '文章总数', $arts, '草稿 ' . $drafts . ' 篇待发布', '/admin/articles', $drafts > 0 ? 'warn' : ''],
            ['📄', '单页', (int)Database::value('SELECT COUNT(*) FROM pages'), '关于/收费/隐私等固定页', '/admin/pages'],
            ['❓', '常见问题', (int)Database::value('SELECT COUNT(*) FROM faqs WHERE status=1'), '自动同步 AI 客服知识库', '/admin/faqs'],
            ['🖼️', '媒体文件', (int)Database::value('SELECT COUNT(*) FROM attachments'), '编辑器直接调用', '/admin/media'],
            ['💬', '会员心声', (int)Database::value('SELECT COUNT(*) FROM testimonials'), '展示前须脱敏授权', '/admin/testimonials'],
        ];
    }

    /** 最近文章列表（内容域附加渲染） */
    public static function recentArticles(): array
    {
        return Database::fetchAll("SELECT id, title, status, created_at FROM articles WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 5");
    }

    private function dataOps(): array
    {
        $cards = [];
        // 通知渠道到达率（mail/wechat/sms 渠道 stats()）
        try {
            $channels = \app\Services\NotifyService::channels();
            foreach ($channels as $ch) {
                $st = $ch->stats();
                $label = $ch->label();
                $cards[] = [$ch->name() === 'mail' ? '📮' : ($ch->name() === 'wechat' ? '💬' : '📱'), $label . '到达率',
                    $st['ok'] . ' 成功 / ' . $st['fail'] . ' 失败', '今日；失败会自动降级', '/admin/plugin/' . ($ch->name() === 'mail' ? 'mail-notify' : ($ch->name() === 'wechat' ? 'wechat-notify' : 'sms-notify')), (int)($st['fail'] ?? 0) > 0 ? 'warn' : ''];
            }
        } catch (\Throwable $t) {}
        $aiToday = (int)Database::value('SELECT COUNT(*) FROM chat_messages WHERE role="ai" AND created_at >= CURDATE()');
        $cards[] = ['🎧', 'AI 今日应答', $aiToday, '知识库优先，人工接管静默', '/admin/plugin/ai-concierge'];
        $enabled = json_decode((string)setting('plugins.enabled', '[]'), true) ?: [];
        $cards[] = ['🧩', '已启用插件', count($enabled), '共 13 个官方插件可装可停', '/admin/plugins'];
        return $cards;
    }

    private function dataWechat(): array
    {
        $cfg = static fn (string $k) => trim((string)setting($k, '')) !== '';
        $loginReady = $cfg('wxlogin.open_appid') || $cfg('wxlogin.mp_appid');
        $payReady = $cfg('wxpay.mchid') && $cfg('wxpay.appid');
        $notifyReady = $cfg('wechat.appid');
        return [
            ['💬', '服务号通知', $notifyReady ? '已配置' : '待配置', '模板消息推送到管理员微信', '/admin/plugin/wechat-notify', $notifyReady ? '' : 'warn'],
            ['🔑', '微信登录', $loginReady ? '已配置' : '待配置', '扫码 + 微信内授权，会员一体化', '/admin/plugin/wechat-login', $loginReady ? '' : 'warn'],
            ['💰', '微信支付', $payReady ? '已配置' : '待配置', '咨询套餐在线收款（V3）', '/admin/plugin/wechat-pay', $payReady ? '' : 'warn'],
            ['✅', '支付今日', (int)Database::value("SELECT COUNT(*) FROM wechat_pay_orders WHERE status='paid' AND paid_at >= CURDATE()"), '笔支付（需商户凭据联调后产生）', '/admin/plugin/wechat-pay'],
        ];
    }

    /**
     * 微信接入向导（v2.9.1）：独立站点用机构自有服务号/商户号直连 CMS。
     * 每步给出官方入口、要准备什么、CMS 侧对应的配置位，并实时检测当前完成度。
     */
    public static function setupChecklist(): array
    {
        $cfg = static fn (string $k) => trim((string)setting($k, '')) !== '';
        return [
            ['service', '① 申请并认证服务号', 'mp.weixin.qq.com → 立即注册（服务号）→ 微信认证（企业资质，300 元/年）。认证后才有网页授权、模板消息能力。', [
                ['网页授权域名已配置', $cfg('wxlogin.mp_appid') || $cfg('wechat.appid'), '公众号后台 → 设置与开发 → 功能设置 → 网页授权域名填本站域名，下载 MP_verify 文件放 public/'],
            ]],
            ['login', '② 服务号 AppID/Secret → 微信登录', '公众号后台 → 设置与开发 → 基本配置。IP 白名单加本站出口 IP。', [
                ['公众号 AppID 已填', $cfg('wxlogin.mp_appid'), '微信登录插件 → 公众号 AppID'],
                ['公众号 AppSecret 已填', $cfg('wxlogin.mp_secret'), '微信登录插件（加密存储）'],
            ]],
            ['tpl', '③ 申请模板消息（对标公众平台规范）', '公众号后台 → 广告与服务 → 模板消息 → 从模板库选用（行业类目无「心理咨询」，建议在 教育/生活服务 类目挑选）。模板字段统一用 first / keyword1-4 / remark —— CMS 按此超集发送，换模板通常不用改代码。', [
                ['服务号通知模板已填', $cfg('wechat.tpl_booking') && $cfg('wechat.tpl_system'), '微信通知插件 → 模板 ID（至少配「新预约」与「系统」）'],
            ]],
            ['mch', '④ 申请微信商户号', 'pay.weixin.qq.com → 接入微信支付（企业/个体户资质）。超级管理员法人验证。', [
                ['商户号已填', $cfg('wxpay.mchid'), '微信支付插件 → 商户号'],
                ['支付 AppID 已填', $cfg('wxpay.appid'), '微信支付插件（与服务号 AppID 一致）'],
            ]],
            ['cert', '⑤ 商户证书与 APIv3 密钥', '商户平台 → 账户中心 → API 安全：申请 API 证书（拿 apiclient_key.pem 与证书序列号）、设置 APIv3 密钥（32 位）、下载微信支付公钥。', [
                ['证书序列号已填', $cfg('wxpay.mch_serial_no'), '微信支付插件'],
                ['APIv3 密钥已填', $cfg('wxpay.apiv3_key'), '微信支付插件（加密存储）'],
                ['商户私钥已填', $cfg('wxpay.mch_key_secret'), '微信支付插件（粘贴 apiclient_key.pem 全文，加密存储）'],
                ['支付公钥已配置', $cfg('wxpay.public_key_pem') || setting('wxpay.verify_mode', '') === 'platform_cert', '微信支付插件 → 验签模式'],
            ]],
            ['dir', '⑥ 支付授权目录与产品开通', '商户平台 → 产品中心：开通 JSAPI 支付与 Native 支付；开发配置 → 支付授权目录填本站路径（如 https://域名/ ，以 / 结尾）。', [
                ['（此步在微信商户平台完成，CMS 侧无需填写）', true, ''],
            ]],
        ];
    }

    private function dataSystem(): array
    {
        $enabled = json_decode((string)setting('plugins.enabled', '[]'), true) ?: [];
        $lastBackup = '';
        try {
            $lastBackup = (string)\app\Core\Cache::get('last_backup_at');
        } catch (\Throwable $t) {}
        return [
            ['👤', '后台用户', (int)Database::value('SELECT COUNT(*) FROM users WHERE status=1'), 'RBAC 四角色 + 两步验证', '/admin/users'],
            ['🧩', '启用插件', count($enabled) . ' / 13', '官方签名可随时启停', '/admin/plugins'],
            ['🗄️', '最近备份', $lastBackup !== '' ? dt($lastBackup) : '今日未备份', '建议每日自动备份', '/admin/tools', $lastBackup === '' ? 'warn' : ''],
            ['🧾', '本月审计', (int)Database::value('SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_FORMAT(CURDATE(), "%Y-%m-01")'), '全后台写操作留痕', '/admin/audits'],
        ];
    }
}
