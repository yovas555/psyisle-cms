<?php
/** 全局助手函数（一二三 PsyIsle CMS） */
declare(strict_types=1);


if (!function_exists('app')) {
    function app(): \app\Core\App { return \app\Core\App::getInstance(); }
}

if (!function_exists('config')) {
    function config(string $key, $default = null) {
        $val = CONFIG[$key] ?? null;
        if ($val !== null) return $val;
        // 从 settings 表取（分组点号语法 group.key）
        if (strpos($key, '.') !== false) {
            static $settings = null;
            if ($settings === null) {
                try { $settings = \app\Core\Model::loadSettings(); } catch (\Throwable $t) { $settings = []; }
            }
            return $settings[$key] ?? $default;
        }
        return $default;
    }
}

if (!function_exists('setting')) {
    /** settings 表读取（点号：group.key），支持缓存 */
    function setting(string $key, $default = null) {
        static $cache = null;
        if ($cache === null) {
            try {
                $cache = \app\Core\Cache::remember('settings_all', 60, function () {
                    return \app\Core\Model::loadSettings();
                });
            } catch (\Throwable $t) {
                $cache = []; // 未安装/DB 不可用时静默
            }
        }
        return $cache[$key] ?? $default;
    }
}

if (!function_exists('setting_save')) {
    function setting_save(string $key, $value, string $group = 'general'): void {
        \app\Core\Model::saveSetting($key, $value, $group);
        \app\Core\Cache::forget('settings_all');
    }
}

/** HTML 转义输出（所有模板输出的默认方式） */
if (!function_exists('e')) {
    function e($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** 富文本输出（先经白名单净化） */
if (!function_exists('eraw')) {
    function eraw($value): string {
        return \app\Core\Purifier::clean((string)$value);
    }
}

/** URL 生成 */
if (!function_exists('url')) {
    function url(string $path = '/'): string {
        if (preg_match('#^https?://#', $path)) return $path;
        return rtrim(base_url(), '/') . '/' . ltrim($path, '/');
    }
}

/** 站点根 URL（含协议与端口） */
if (!function_exists('base_url')) {
    function base_url(): string {
        static $u = null;
        if ($u !== null) return $u;
        $host = setting('site.domain');
        if (!$host && !empty($_SERVER['HTTP_HOST'])) {
            $host = preg_replace('/[^a-z0-9\.\-:]/i', '', $_SERVER['HTTP_HOST']);
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $u = $scheme . '://' . ($host ?: 'localhost');
        return $u;
    }
}

/** 资产文件带版本号 */
if (!function_exists('asset')) {
    function asset(string $path): string {
        $file = PUBLIC_PATH . '/assets/' . ltrim($path, '/');
        $v = is_file($file) ? substr(md5((string)filemtime($file)), 0, 8) : CMS_VERSION;
        return url('/assets/' . ltrim($path, '/')) . '?v=' . $v;
    }
}

/** 截断（utf8 安全） */
if (!function_exists('str_take')) {
    function str_take(?string $s, int $len, string $suffix = '…'): string {
        $s = trim((string)$s);
        if (mb_strlen($s) <= $len) return $s;
        return mb_substr($s, 0, $len) . $suffix;
    }
}

/** 手机号打码 */
if (!function_exists('mask_phone')) {
    function mask_phone(?string $s): string {
        $s = (string)$s;
        if (preg_match('/^(1\d{2})\d{4}(\d{4})$/', $s, $m)) return $m[1] . '****' . $m[2];
        return $s;
    }
}

/** 时间格式化 */
if (!function_exists('dt')) {
    function dt(?string $datetime, string $fmt = 'Y-m-d H:i'): string {
        if (!$datetime) return '';
        return date($fmt, strtotime($datetime));
    }
}

/** CSRF 字段 */
if (!function_exists('csrf_field')) {
    function csrf_field(): string {
        return '<input type="hidden" name="_token" value="' . e(\app\Core\Csrf::token()) . '">';
    }
}

/** JSON 响应并退出 */
if (!function_exists('json_out')) {
    function json_out($data, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** 翻译占位（v1 单语言，为 i18n 预留） */
if (!function_exists('__')) {
    function __(string $s): string { return $s; }
}

/** 当前登录后台用户（null 表示未登录） */
if (!function_exists('admin_user')) {
    function admin_user(): ?array { return \app\Core\Session::get('admin_user'); }
}

/** 生成可读查询码（不含易混淆字符） */
if (!function_exists('gen_code')) {
    function gen_code(int $len = 8): string {
        $set = 'ACDEFGHJKLMNPQRTUVWXY3479';
        $s = '';
        for ($i = 0; $i < $len; $i++) { $s .= $set[random_int(0, strlen($set) - 1)]; }
        return $s;
    }
}

/** 缩略图路径（存在 t_ 缩略图则用，否则原图） */
if (!function_exists('thumb')) {
    function thumb(?string $path): string
    {
        $path = (string)$path;
        if ($path === '' || !preg_match('#^/uploads/(.+)/([^/]+)\.(jpg|jpeg|png)$#i', $path, $m)) return $path;
        $dir = PUBLIC_PATH . '/uploads/' . $m[1];
        $base = $m[2];
        if (is_file($dir . '/t_' . $base . '.webp')) {
            return '/uploads/' . $m[1] . '/t_' . $base . '.webp';
        }
        if (is_file($dir . '/t_' . $base . '.jpg')) {
            return '/uploads/' . $m[1] . '/t_' . $base . '.jpg';
        }
        return $path;
    }
}

/** 图片 alt 兜底文本 */
if (!function_exists('img_alt')) {
    function img_alt(?string $alt, string $fallback = '配图'): string {
        $alt = trim((string)$alt);
        return $alt !== '' ? $alt : $fallback;
    }
}
