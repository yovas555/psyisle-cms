<?php
declare(strict_types=1);

namespace app\Core;

/**
 * 插件管理器 v2（接口完整化）
 *
 * 目录：plugins/{slug}/plugin.json + Plugin.php（类 {SlugCamel}Plugin::boot()）
 *
 * manifest 完整字段：
 *   slug/name/version/author/description      必填
 *   min_cms_version                          最低核心版本（启用时校验）
 *   dependencies: ["other-slug", …]           依赖的插件必须已启用
 *   permissions: ["hooks","cron","admin_menu","settings","routes","frontend"]
 *   cron: [{schedule:"minutely"|"daily", action:"方法名"}]
 *   admin_menu: {title, icon, path}           后台侧栏菜单（默认 path="" → 设置页）
 *   settings: [{key,label,type,default,help,group,options,placeholder}]
 *                                             type: text|password|number|textarea|switch|select
 *   frontend_route: true                      允许前台 /plugin/{slug}/… 访问
 *   signature                                 官方 Ed25519 签名
 */
class PluginManager
{
    private static ?array $manifests = null;

    public static function dir(): string
    {
        return ROOT . '/plugins';
    }

    /** 扫描全部插件清单 */
    public static function all(): array
    {
        if (self::$manifests !== null) return self::$manifests;
        self::$manifests = [];
        foreach (glob(self::dir() . '/*/plugin.json') ?: [] as $file) {
            $slug = basename(dirname($file));
            $meta = json_decode((string)file_get_contents($file), true);
            if (!is_array($meta) || empty($meta['slug'])) continue;
            $meta['slug'] = preg_replace('/[^a-z0-9\-]/', '', strtolower((string)$meta['slug']));
            $meta['_dir'] = dirname($file);
            $meta['_official'] = self::verifySignature($meta);
            $meta['settings'] = is_array($meta['settings'] ?? null) ? $meta['settings'] : [];
            $meta['cron'] = is_array($meta['cron'] ?? null) ? $meta['cron'] : [];
            $meta['admin_menu'] = is_array($meta['admin_menu'] ?? null) ? $meta['admin_menu'] : null;
            self::$manifests[$meta['slug']] = $meta;
        }
        return self::$manifests;
    }

    public static function get(string $slug): ?array
    {
        return self::all()[$slug] ?? null;
    }

    /** 清空清单缓存（插件文件被覆盖/安装后调用） */
    public static function invalidate(): void
    {
        self::$manifests = null;
    }

    /** 官方签名验证（signature = base64(Ed25519 sign(slug|version|author))） */
    public static function verifySignature(array $meta): bool
    {
        $pub = (string)(CONFIG['issuer_public_key'] ?? '');
        $sig = (string)($meta['signature'] ?? '');
        if ($pub === '' || $sig === '') return false;
        try {
            $msg = $meta['slug'] . '|' . ($meta['version'] ?? '') . '|' . ($meta['author'] ?? '');
            return sodium_crypto_sign_verify_detached(base64_decode($sig, true), $msg, base64_decode($pub, true));
        } catch (\Throwable $t) {
            return false;
        }
    }

    // ==================== 启用状态 ====================

    public static function enabledList(): array
    {
        $raw = (string)setting('plugins.enabled', '[]');
        $arr = json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    public static function isEnabled(string $slug): bool
    {
        return in_array($slug, self::enabledList(), true);
    }

    public static function setEnabled(array $slugs): void
    {
        Model::saveSetting('plugins.enabled', json_encode(array_values(array_unique($slugs)), JSON_UNESCAPED_UNICODE), 'system');
        Cache::forget('settings_all');
    }

    /** 启用前置校验：核心版本、依赖、清单完整性 */
    public static function canEnable(string $slug): array
    {
        $meta = self::get($slug);
        if (!$meta) return [false, '插件不存在'];
        if (!is_file($meta['_dir'] . '/Plugin.php')) return [false, '缺少 Plugin.php 引导文件'];
        $min = (string)($meta['min_cms_version'] ?? '');
        if ($min !== '' && version_compare(CMS_VERSION, $min, '<')) {
            return [false, "需要核心版本 ≥ {$min}（当前 " . CMS_VERSION . '）'];
        }
        foreach ((array)($meta['dependencies'] ?? []) as $dep) {
            if (!self::isEnabled((string)$dep)) {
                $dn = self::get((string)$dep)['name'] ?? (string)$dep;
                return [false, "依赖插件「{$dn}」尚未启用"];
            }
        }
        return [true, ''];
    }

    /** 启停（带校验） */
    public static function toggle(string $slug, bool $on): array
    {
        $list = self::enabledList();
        if ($on) {
            [$ok, $msg] = self::canEnable($slug);
            if (!$ok) return [false, $msg];
            if (!in_array($slug, $list, true)) $list[] = $slug;
            self::setEnabled($list);
            return [true, '已启用'];
        }
        // 停用前检查是否有其他已启用插件依赖它
        foreach (self::all() as $s => $m) {
            if ($s === $slug || !in_array($s, $list, true)) continue;
            if (in_array($slug, (array)($m['dependencies'] ?? []), true)) {
                return [false, '「' . ($m['name'] ?? $s) . '」依赖此插件，请先停用对方'];
            }
        }
        self::setEnabled(array_values(array_diff($list, [$slug])));
        return [true, '已停用'];
    }

    // ==================== 插件设置（接口数据） ====================

    /** 读取插件设置（manifest 里声明的 key，值存 settings 表，键名原样保留以复用存量配置） */
    public static function cfg(string $slug, string $key, $default = null)
    {
        $meta = self::get($slug);
        foreach ($meta['settings'] ?? [] as $f) {
            if (($f['key'] ?? '') === $key) {
                if (($f['type'] ?? '') === 'switch') {
                    return (bool)setting($key, $f['default'] ?? '0');
                }
                return setting($key, $f['default'] ?? $default);
            }
        }
        return setting($key, $default);
    }

    /** 保存插件设置表单（只接受 manifest 声明的 key） */
    public static function saveSettings(string $slug, array $post): int
    {
        $meta = self::get($slug);
        $n = 0;
        foreach ($meta['settings'] ?? [] as $f) {
            $key = (string)($f['key'] ?? '');
            if ($key === '') continue;
            $pk = str_replace('.', '_', $key); // 表单 name 用下划线
            $type = (string)($f['type'] ?? 'text');
            if ($type === 'switch') {
                Model::saveSetting($key, !empty($post[$pk]) ? '1' : '0', 'plugin:' . $slug);
                $n++;
                continue;
            }
            if (!array_key_exists($pk, $post)) continue;
            $val = trim((string)$post[$pk]);
            if ($type === 'number' && $val !== '' && !is_numeric($val)) continue;
            // 敏感配置：掩码/空值不修改（v2.17.0 修复：空值此前会 encrypt('') 覆盖已有密钥——升级验收保存一次即抹掉 site_secret 的事故根因），新值加密
            if (Crypto::isSecretKey($key)) {
                if (Crypto::isMask($val) || $val === '') continue;
                $val = Crypto::encrypt($val);
            }
            Model::saveSetting($key, $val, 'plugin:' . $slug);
            $n++;
        }
        Cache::forget('settings_all');
        return $n;
    }

    // ==================== 菜单与路由 ====================

    /** 已启用插件的后台菜单（v2.8.1：支持 admin_menu.group 分组，归入侧栏对应组尾） */
    public static function adminMenus(): array
    {
        $out = [];
        foreach (self::all() as $slug => $m) {
            if (!self::isEnabled($slug) || empty($m['admin_menu'])) continue;
            $out[] = [
                'slug' => $slug,
                'title' => (string)($m['admin_menu']['title'] ?? $m['name']),
                'icon' => (string)($m['admin_menu']['icon'] ?? '🧩'),
                'path' => '/admin/plugin/' . $slug . (!empty($m['admin_menu']['path']) ? '/' . ltrim((string)$m['admin_menu']['path'], '/') : ''),
                'group' => (string)($m['admin_menu']['group'] ?? ''),
            ];
        }
        return $out;
    }

    // ==================== 生命周期 ====================

    /** 启动全部已启用插件（入口调用一次） */
    public static function boot(): void
    {
        static $booted = null;
        if ($booted !== null) return;
        $booted = true;
        foreach (self::all() as $slug => $meta) {
            if (!self::isEnabled($slug)) continue;
            self::load($slug);
        }
    }

    /** 载入插件类并 boot */
    private static function load(string $slug): ?string
    {
        $meta = self::get($slug);
        if (!$meta) return null;
        $file = $meta['_dir'] . '/Plugin.php';
        if (!is_file($file)) return null;
        try {
            require_once $file;
            $class = self::classOf($slug);
            if (class_exists($class) && method_exists($class, 'boot')) {
                $class::boot($meta);
            }
            return $class;
        } catch (\Throwable $t) {
            Logger::error('plugin', "boot failed {$slug}: " . $t->getMessage());
            return null;
        }
    }

    public static function classOf(string $slug): string
    {
        return '\\plugins\\' . str_replace('-', '', $slug) . '\\Plugin';
    }

    /** 调用插件静态方法（后台页/前台页/任务共用） */
    public static function call(string $slug, string $method, ...$args)
    {
        $class = self::load($slug);
        if ($class === null || !class_exists($class)) return null;
        if (!method_exists($class, $method)) return null;
        return $class::$method(...$args);
    }

    /** 后台插件页分发：统一套入后台框架（侧栏+样式），插件只输出内容片段 */
    public static function adminPage(string $slug, Request $req, string $path = ''): void
    {
        $meta = self::get($slug);
        if (!$meta || !self::isEnabled($slug)) {
            http_response_code(404);
            echo '插件未启用';
            exit;
        }
        $title = (string)($meta['admin_menu']['title'] ?? $meta['name']);
        // P0-G3 防御性门禁：后台插件写操作实际走 Admin\Plugin@pluginAction（已统一 Csrf::guard），
        // 此处仅兜底——将来若给插件页开放 POST 入口，不会因为插件自身忘写 guard 而失守。
        if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            Csrf::guard();
        }
        $class = self::load($slug);
        ob_start();
        try {
            if ($path !== '' && $class) {
                $m = 'admin_' . preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', $path));
                if (method_exists($class, $m)) {
                    $class::$m($req);
                } else {
                    echo '<div class="panel"><p class="muted">插件页面不存在。</p></div>';
                }
            } elseif ($class && method_exists($class, 'adminIndex')) {
                $class::adminIndex($req);
            } else {
                // 默认：按 manifest.settings 渲染设置表单
                $metaV = $meta;
                $slugV = $slug;
                include APP . '/views/admin/plugin/page.php';
            }
        } catch (\Throwable $t) {
            Logger::error('plugin', "admin page failed {$slug}: " . $t->getMessage());
            echo '<div class="alert alert-error">插件页面渲染失败：' . e($t->getMessage()) . '</div>';
        }
        $content = (string)ob_get_clean();
        View::admin('plugin/frame', ['content' => $content, 'title' => $title, 'slug' => $slug]);
    }

    /** 前台插件页分发（需 manifest.frontend_route = true）
     * v2.8.0 参数化路径：首段映射方法 front_{seg}，其余段作为数组参数传入
     * 如 /plugin/consent-pro/sign/{token} → front_sign($req, ['{token}'])
     * 老插件单参数签名 front_x($req) 保持兼容（多余参数 PHP 允许） */
    public static function frontPage(string $slug, Request $req, string $path = ''): void
    {
        $meta = self::get($slug);
        if (!$meta || !self::isEnabled($slug) || empty($meta['frontend_route'])) {
            http_response_code(404);
            echo '404';
            exit;
        }
        $class = self::load($slug);
        $segs = $path === '' ? [] : explode('/', $path);
        $first = $segs === [] ? '' : (string)array_shift($segs);
        $m = $first === '' ? 'frontIndex' : 'front_' . preg_replace('/[^a-z0-9_]/', '', str_replace('-', '_', $first));
        if (!$class || !method_exists($class, $m)) { http_response_code(404); echo '404'; exit; }
        $class::$m($req, $segs);
    }

    /** 定时任务：按频率执行插件声明的任务 */
    public static function runCron(string $schedule): array
    {
        $done = [];
        foreach (self::all() as $slug => $meta) {
            if (!self::isEnabled($slug)) continue;
            foreach ($meta['cron'] as $job) {
                if ((string)($job['schedule'] ?? 'daily') !== $schedule) continue;
                $action = (string)($job['action'] ?? '');
                if ($action === '') continue;
                try {
                    $out = self::call($slug, $action);
                    $done[] = $slug . ':' . $action . ' → ' . json_encode($out, JSON_UNESCAPED_UNICODE);
                } catch (\Throwable $t) {
                    Logger::error('plugin', "cron failed {$slug}: " . $t->getMessage());
                    $done[] = $slug . ':' . $action . ' → ERROR ' . $t->getMessage();
                }
            }
        }
        return $done;
    }

    /** 卸载：停用 + 删除插件私有设置（plugin.{slug}. 前缀）+ 调用 Plugin::uninstall() */
    public static function uninstall(string $slug): array
    {
        if (!self::get($slug)) return [false, '插件不存在'];
        self::setEnabled(array_values(array_diff(self::enabledList(), [$slug])));
        try {
            self::call($slug, 'uninstall');
        } catch (\Throwable $t) {
            Logger::error('plugin', "uninstall hook failed {$slug}: " . $t->getMessage());
        }
        $n = Database::run('DELETE FROM settings WHERE `sgroup` = ?', ['plugin:' . $slug])->rowCount();
        Cache::forget('settings_all');
        return [true, "已卸载（清理 {$n} 项私有设置）"];
    }

    /** 插件视图渲染 */
    public static function view(string $slug, string $tpl, array $data = []): void
    {
        $file = self::dir() . '/' . $slug . '/views/' . $tpl . '.php';
        if (!is_file($file)) { http_response_code(500); echo '插件视图缺失'; exit; }
        extract($data, EXTR_SKIP);
        include $file;
    }
}
