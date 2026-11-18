<?php
declare(strict_types=1);

namespace app\Core;

use app\Services\SeoService;

class App
{
    private static ?App $instance = null;
    public Request $request;
    public Response $response;
    public Router $router;

    public static function getInstance(): App
    {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
        $this->router = new Router();
        $this->registerRoutes();
    }

    /** 注册全站路由 */
    private function registerRoutes(): void
    {
        $r = $this->router;
        $u = $this->request->uri();

        // ===== 动态 SEO 资产 =====
        $r->get('/robots.txt', 'StaticPage@robots');
        $r->get('/healthz', 'StaticPage@healthz');
        $r->get('/sitemap.xml', 'StaticPage@sitemap');
        $r->get('/rss.xml', 'StaticPage@rss');
        $r->get('/llms.txt', 'StaticPage@llms');
        $r->get('/favicon.ico', 'StaticPage@favicon');
        // 面向未来的集成层
        $r->get('/api/v1/{resource:\w+}', 'Api@handle');
        $r->get('/health', 'Health@check');
        // IndexNow key 验证文件 /{key}.txt
        $r->get('/{keytxt:[a-f0-9]{8,40}}\.txt', 'StaticPage@indexNowKey');

        // ===== 静态路由 =====
        $r->get('/', 'Home@index');
        $r->get('/home', 'Home@index');
        $r->get('/about', 'Page@about');
        $r->get('/contact', 'Page@contact');
        $r->get('/faq', 'Page@faq');
        $r->get('/search', 'Search@index');
        $r->get('/booking', 'Booking@create');
        $r->post('/booking', 'Booking@store');
        $r->get('/booking/confirm', 'Booking@confirm');
        $r->get('/booking/success', 'Booking@success');
        $r->get('/booking/check', 'Booking@check');
        $r->get('/booking/slots', 'Booking@slots');
        $r->get('/n/{token:[a-f0-9]{16}}', 'NotifyClick@go');
        // v2.18：公开只读 API（api.public_enabled 开关）与 MCP Server（mcp_enabled 开关，默认关）
        $r->get('/api/public/{path:[a-zA-Z_]+}', 'PublicApi@dispatch');
        $r->post('/mcp', 'Mcp@handle');
        $r->get('/mcp', 'Mcp@handle');
        $r->get('/events', 'Event@index');
        $r->get('/events/{slug:\w[\w\-]*}', 'Event@show');
        $r->post('/events/{slug:\w[\w\-]*}/register', 'Event@register');
        $r->get('/qa', 'Qa@index');
        $r->get('/tag/{tag:[^/]+}', 'Tag@show');
        $r->get('/assessment', 'Assessment@index');
        $r->get('/assessment/{slug:\w[\w\-]*}', 'Assessment@show');
        $r->post('/assessment/{slug:\w[\w\-]*}', 'Assessment@submit');
        $r->get('/report/{token:[a-f0-9]+}', 'Assessment@report');
        $r->post('/booking/check', 'Booking@check');
        $r->get('/message', 'Message@create');
        $r->post('/message', 'Message@store');
        $r->get('/message/reply', 'Message@replyView');
        $r->post('/message/reply', 'Message@replyView');
        $r->get('/chat/start', 'Chat@start');
        $r->post('/chat/send', 'Chat@send');
        $r->post('/chat/escalate', 'Chat@escalate');
        $r->get('/chat/poll', 'Chat@poll');
        $r->post('/chat/contact', 'Chat@contact');

        // ===== 公开验证码（前台表单用；admin/captcha 受后台门禁保护不可前台引用）=====
        $r->get('/captcha', 'Admin\Auth@captcha');

        // ===== 会员自助中心（独立会话域 member_user）=====
        $r->get('/member/login', 'Member@login');
        $r->post('/member/login', 'Member@login');
        $r->post('/member/login/send-code', 'Member@sendCode');
        $r->get('/member/register', 'Member@register');
        $r->post('/member/register', 'Member@register');
        $r->get('/member/settings', 'Member@settings');
        $r->post('/member/settings', 'Member@settings');
        $r->get('/member', 'Member@index');
        $r->get('/member/bookings', 'Member@bookings');
        $r->post('/member/bookings/cancel/{id:\d+}', 'Member@cancelBooking');
        $r->post('/member/bookings/reschedule/{id:\d+}', 'Member@rescheduleBooking');
        $r->get('/member/assessments', 'Member@assessments');
        $r->get('/member/privacy', 'Member@privacy');
        $r->post('/member/privacy/export', 'Member@export');
        $r->post('/member/privacy/delete', 'Member@requestDelete');
        $r->post('/member/redeem', 'Member@redeem');
        $r->get('/member/notifications', 'Member@notifications');
        $r->post('/member/notifications/read/{id:\d+}', 'Member@markRead');
        $r->get('/member/notifications/unread.json', 'Member@unreadJson');
        $r->post('/member/forgot-password/sms', 'Member@forgotPasswordSms');
        $r->post('/member/forgot-password/reset-sms', 'Member@resetPasswordSms');
        $r->get('/member/forgot-password', 'Member@forgotPassword');
        $r->post('/member/forgot-password', 'Member@forgotPassword');
        $r->get('/member/reset-password', 'Member@resetPassword');
        $r->post('/member/reset-password', 'Member@resetPassword');
        $r->get('/member/notes', 'MemberNotes@index');
        $r->get('/member/notes/{id:\d+}', 'MemberNotes@read');
        $r->get('/member/logout', 'Member@logout');

        // ===== 栏目路由（先于 slug 详情，避免冲突）=====
        $r->get('/news', 'Article@index');
        $r->get('/news/category/{slug}', 'Article@category');

        // ===== slug 详情 =====
        $r->get('/news/{slug:\w[\w\-]*}', 'Article@show');
        $r->get('/counselors', 'Counselor@index');
        $r->get('/counselors/{slug:\w[\w\-]*}', 'Counselor@show');
        $r->get('/services', 'Service@index');
        $r->get('/services/{slug:\w[\w\-]*}', 'Service@show');
        $r->get('/p/{slug:\w[\w\-]*}', 'Page@show');

        // ===== 后台 =====
        $r->get('/admin', 'Admin\Dashboard@index');
        $r->get('/admin/login', 'Admin\Auth@showLogin');
        $r->post('/admin/login', 'Admin\Auth@login');
        $r->get('/admin/logout', 'Admin\Auth@logout');
        $r->get('/admin/captcha', 'Admin\Auth@captcha');
        $r->get('/admin/totp', 'Admin\Auth@showTotp');
        $r->post('/admin/totp', 'Admin\Auth@verifyTotp');
        $r->get('/admin/users/totp-setup', 'Admin\User@totpSetup');
        $r->post('/admin/users/totp-enable', 'Admin\User@totpEnable');
        $r->post('/admin/users/totp-disable', 'Admin\User@totpDisable');
        // 计划任务端点（Cron 控制器不经登录墙，靠 cron_secret）
        // 升级分发 API（主站专用：config.distribute.enabled=true 才生效，否则 404）
        $r->post('/upgrade-api/activate', 'UpgradeApi@activate');
        $r->post('/upgrade-api/check', 'UpgradeApi@check');
        $r->post('/upgrade-api/token', 'UpgradeApi@token');
        $r->get('/upgrade-api/download', 'UpgradeApi@download');
        $r->get('/admin/cron/mail', 'Admin\Cron@mail');
        $r->get('/admin/cron/stats', 'Admin\Cron@stats');
        $r->get('/admin/cron/upgrade-check', 'Admin\Cron@upgradeCheck');
        $r->get('/admin/cron/booking-reminder', 'Admin\Cron@bookingReminder');
        $r->get('/admin/cron/morning-push', 'Admin\Cron@morningPush');
        $r->get('/admin/cron/publish', 'Admin\Cron@publish');
        $r->post('/admin/cron/mail', 'Admin\Cron@mail');

        $r->get('/admin/users/roles', 'Admin\User@roles');
        $r->get('/admin/search-client', 'Admin\ClientSearch@index');
        $r->get('/admin/articles/trash', 'Admin\Article@trash');
        $r->post('/admin/articles/restore/{id:\d+}', 'Admin\Article@restore');
        $r->post('/admin/articles/purge/{id:\d+}', 'Admin\Article@purge');
        $r->post('/admin/articles/{id:\d+}/restore-rev/{rid:\d+}', 'Admin\Article@restoreRev');
        $r->get('/admin/pages/trash', 'Admin\Page@trash');
        $r->post('/admin/pages/restore/{id:\d+}', 'Admin\Page@restore');
        $r->post('/admin/pages/purge/{id:\d+}', 'Admin\Page@purge');
        $r->get('/admin/help', 'Admin\Help@index');
        $r->get('/admin/plugins', 'Admin\Plugin@index');
        $r->post('/admin/plugins/toggle/{slug:[a-z0-9\-]+}', 'Admin\Plugin@toggle');
        $r->post('/admin/plugins/uninstall/{slug:[a-z0-9\-]+}', 'Admin\Plugin@uninstall');
        $r->post('/admin/plugin/{slug:[a-z0-9\-]+}/save-settings', 'Admin\Plugin@saveSettings');
        $r->post('/admin/plugin/{slug:[a-z0-9\-]+}/{action:add-kb|kb-toggle|kb-delete|send-test|save-override|delete-override|mark-attendance|export-stats|sale-package|delete-package|save-package|save-coupon|delete-coupon|use-session|unbind-member|query-order|credit-order|save-template|delete-template|initiate|revoke|save-store|delete-store|save-enterprise|delete-enterprise|reset-code|confirm-request|reject-request|ping-test|export-archive|print-archive|adjust-points}', 'Admin\Plugin@pluginAction');
        $r->get('/admin/plugin/{slug:[a-z0-9\-]+}{path:(?:/[\w\-]+)*}', 'Admin\Plugin@page');
        // 前台插件网关（仅 manifest.frontend_route=true 的启用插件可用）
        $r->get('/plugin/{slug:[a-z0-9\-]+}{path:(?:/[\w\-]+)*}', 'PluginGate@front');
        $r->post('/plugin/{slug:[a-z0-9\-]+}{path:(?:/[\w\-]+)*}', 'PluginGate@front');
        $r->get('/admin/area/{domain:[a-z]+}', 'Admin\Area@index');
        // 咨询师工作台（v2.10.0：counselor_workspace 角色，自范围数据）
        $r->get('/admin/counselor-home', 'Admin\CounselorHome@index');
        $r->get('/admin/counselor-home/profile', 'Admin\CounselorHome@profile');
        $r->post('/admin/counselor-home/profile', 'Admin\CounselorHome@profile');
        $r->get('/admin/counselor-home/bookings', 'Admin\CounselorHome@bookings');
        $r->get('/admin/counselor-home/schedule', 'Admin\CounselorHome@schedule');
        $r->post('/admin/counselor-home/schedule', 'Admin\CounselorHome@schedule');
        $r->get('/admin/counselor-home/notes', 'Admin\CounselorHome@notes');
        $r->post('/admin/counselor-home/notes', 'Admin\CounselorHome@notes');
        $r->get('/admin/cron/plugins', 'Admin\Cron@plugins');
        $r->get('/admin/cron/minute', 'Admin\Cron@minute');
        $r->get('/admin/events/{id:\d+}/regs', 'Admin\Event@registrations');
        $r->get('/admin/events/{id:\d+}/regs-export', 'Admin\Event@exportRegs');
        $r->post('/admin/events/reg-status/{id:\d+}', 'Admin\Event@regStatus');
        $adminList = [
            'articles' => 'Admin\Article', 'pages' => 'Admin\Page', 'categories' => 'Admin\Category',
            'counselors' => 'Admin\Counselor', 'services' => 'Admin\Service', 'bookings' => 'Admin\Booking',
            'messages' => 'Admin\Message', 'chat' => 'Admin\Chat', 'faqs' => 'Admin\Faq',
            'media' => 'Admin\Media', 'banners' => 'Admin\Banner', 'navs' => 'Admin\Nav',
            'links' => 'Admin\Link', 'users' => 'Admin\User', 'settings' => 'Admin\Setting',
            'tools' => 'Admin\Tool', 'license' => 'Admin\License', 'audits' => 'Admin\Audit',
            'notifications' => 'Admin\Notification',
            'testimonials' => 'Admin\Testimonial',
            'events' => 'Admin\Event',
        ];
        foreach ($adminList as $seg => $ctl) {
            $r->get('/admin/' . $seg, $ctl . '@index');
            $r->get('/admin/' . $seg . '/create', $ctl . '@create');
            $r->post('/admin/' . $seg, $ctl . '@store');
            $r->get('/admin/' . $seg . '/{id:\d+}/edit', $ctl . '@edit');
            $r->post('/admin/' . $seg . '/update/{id:\d+}', $ctl . '@update');
            $r->post('/admin/' . $seg . '/delete/{id:\d+}', $ctl . '@delete');
            $r->post('/admin/' . $seg . '/toggle/{id:\d+}', $ctl . '@toggle');
        }
        $r->post('/admin/media/upload', 'Admin\Media@upload');
        $r->get('/admin/media/list', 'Admin\Media@listJson');
        $r->post('/admin/settings/save', 'Admin\Setting@save');
        $r->post('/admin/settings/testmail', 'Admin\Setting@testMail');
        $r->post('/admin/tools/sitemap', 'Admin\Tool@rebuildSitemap');
        $r->post('/admin/tools/backup', 'Admin\Tool@backup');
        $r->post('/admin/tools/full-backup', 'Admin\Tool@fullBackup');
        $r->get('/admin/tools/export-content', 'Admin\Tool@exportContent');
        $r->get('/admin/tools/clone-wizard', 'Admin\Tool@cloneWizard');
        $r->post('/admin/tools/clone-regen', 'Admin\Tool@cloneRegen');
        $r->get('/admin/tools/purge-demo', 'Admin\Tool@purgeDemo');
        $r->post('/admin/tools/purge-demo', 'Admin\Tool@purgeDemo');
        $r->get('/admin/tools/feedback', 'Admin\Tool@feedback');
        $r->post('/admin/tools/feedback', 'Admin\Tool@submitFeedback');
        $r->post('/admin/tools/import-content', 'Admin\Tool@importContent');
        $r->post('/admin/tools/cache', 'Admin\Tool@clearCache');
        $r->post('/admin/tools/robots', 'Admin\Tool@saveRobots');
        $r->post('/admin/tools/ai-open', 'Admin\Tool@saveAiOpen');
        $r->post('/admin/tools/push-urls', 'Admin\Tool@pushUrls');
        $r->post('/admin/tools/verify', 'Admin\Tool@uploadVerify');
        $r->post('/admin/license/activate', 'Admin\License@activate');
        $r->post('/admin/license/check', 'Admin\License@checkRemote');
        $r->post('/admin/license/rebuild-integrity', 'Admin\License@rebuildIntegrity');
        $r->post('/admin/upgrade/run', 'Admin\License@runUpgrade');
        $r->post('/admin/upgrade/upload', 'Admin\License@uploadPackage');
        $r->post('/admin/upgrade/remote', 'Admin\License@runRemote');
        $r->post('/admin/upgrade/plugin-update', 'Admin\License@updatePlugin');
        $r->get('/admin/release', 'Admin\Release@index');
        $r->post('/admin/release/upload', 'Admin\Release@upload');
        $r->post('/admin/release/status/{id:\d+}', 'Admin\Release@setStatus');
        $r->post('/admin/release/delete/{id:\d+}', 'Admin\Release@deletePkg');
        $r->post('/admin/release/client/{id:\d+}', 'Admin\Release@updateClient');
        $r->get('/admin/members', 'Admin\Members@index');
        $r->post('/admin/members/confirm-delete/{id:\d+}', 'Admin\Members@confirmDelete');
        $r->post('/admin/members/restore/{id:\d+}', 'Admin\Members@restore');
        $r->post('/admin/notifications/read', 'Admin\Notification@markRead');
        $r->post('/admin/notifications/delete/{id:\d+}', 'Admin\Notification@delete');
        $r->post('/admin/notifications/clear-all', 'Admin\Notification@clearAll');
        $r->post('/admin/chat/destroy/{id:\d+}', 'Admin\Chat@destroy');
        $r->post('/admin/bookings/send-sms/{id:\d+}', 'Admin\Booking@sendSmsNow');
        $r->post('/admin/chat/handback/{id:\d+}', 'Admin\Chat@handback');
        $r->post('/admin/chat/batch-delete', 'Admin\Chat@batchDelete');
        $r->post('/admin/chat/cleanup', 'Admin\Chat@cleanup');
        $r->post('/admin/messages/reply/{id:\d+}', 'Admin\Message@reply');
        $r->post('/admin/bookings/status/{id:\d+}', 'Admin\Booking@setStatus');
        $r->get('/admin/bookings/export', 'Admin\Booking@export');
        $r->get('/admin/messages/export', 'Admin\Message@export');
        $r->get('/admin/assessments', 'Admin\Assessment@index');
        $r->post('/admin/assessments/toggle/{id:\d+}', 'Admin\Assessment@toggle');
        $r->get('/admin/assessments/export', 'Admin\Assessment@export');
        $r->post('/admin/chat/reply/{id:\d+}', 'Admin\Chat@reply');
        $r->post('/admin/chat/close/{id:\d+}', 'Admin\Chat@close');
        $r->post('/admin/ai/test', 'Admin\Chat@testApi');
        $r->post('/admin/ai/kb/{id:\d+}', 'Admin\Chat@kbToggle');
        $r->post('/admin/users/password', 'Admin\User@changePassword');

        // 404
        $r->fallback('StaticPage@notFound');
    }

    public function run(): void
    {
        $this->adminGuard();
        $this->router->dispatch($this->request);
    }

    /** 后台门禁：入口参数 → cookie；IP 白名单（对 /admin* 生效，cron 由 secret 独立鉴权） */
    private function adminGuard(): void
    {
        $uri = $this->request->uri();
        if (strpos($uri, '/admin') !== 0) return;
        if (strpos($uri, '/admin/cron/') === 0) return;      // cron 端点独立鉴权
        // IP 白名单
        $ips = trim((string)setting('security.admin_ips', ''));
        if ($ips !== '') {
            $ip = $this->request->ip();
            $ok = false;
            foreach (array_map('trim', explode(',', $ips)) as $rule) {
                if ($rule === '') continue;
                if (strpos($rule, '/') !== false) {
                    [$net, $mask] = explode('/', $rule, 2);
                    if (@inet_pton($ip) !== false && @inet_pton($net) !== false
                        && substr_compare($ip, $net, 0, (int)$mask >> 3) === 0) { $ok = true; break; }
                } elseif ($ip === $rule) { $ok = true; break; }
            }
            if (!$ok) { http_response_code(403); echo 'forbidden'; exit; }
        }
        // 入口门禁
        $gate = trim((string)setting('security.admin_gate', ''));
        if ($gate === '') return;
        if (($_COOKIE['psy_admin_gate'] ?? '') === hash('sha256', $gate)) return; // 已持有入口 cookie
        // 入口请求：/admin?gate=<值>（仅 GET /admin 精确路径放行设置 cookie）
        $given = (string)($_GET['gate'] ?? '');
        if ($given !== '' && hash_equals($gate, $given) && $uri === '/admin') {
            setcookie('psy_admin_gate', hash('sha256', $gate), [
                'expires' => time() + 30 * 86400, 'path' => '/', 'samesite' => 'Lax',
                'httponly' => true,
                'secure' => $this->request->isSecure(),
            ]);
            header('Location: /admin/login');
            exit;
        }
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><title>404</title><body style="font-family:sans-serif;text-align:center;padding:80px"><h2>404 Not Found</h2></body>';
        exit;
    }
}
