<?php
declare(strict_types=1);

namespace app\Core;

class View
{
    /** 渲染前台模板（含布局） */
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): void
    {
        extract($data, EXTR_SKIP);
        $viewFile = THEME_PATH . '/' . $template . '.php';
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo '视图缺失: ' . e($template);
            exit;
        }
        ob_start();
        include $viewFile;
        $content = ob_get_clean();

        if ($layout) {
            $layoutFile = THEME_PATH . '/' . $layout . '.php';
            if (is_file($layoutFile)) {
                ob_start();
                include $layoutFile;
                echo ob_get_clean();
                return;
            }
        }
        echo $content;
    }

    /** 渲染后台模板（$raw=true 时直接输出，用于登录页等独立布局，防止泄露后台结构） */
    public static function admin(string $template, array $data = [], bool $raw = false): void
    {
        extract($data, EXTR_SKIP);
        $file = APP . '/views/admin/' . $template . '.php';
        if (!is_file($file)) { http_response_code(500); echo '后台视图缺失: ' . e($template); exit; }
        ob_start();
        include $file;
        $content = ob_get_clean();
        if ($raw) {
            echo $content;
            return;
        }
        include APP . '/views/admin/layout.php';
    }

    /** 渲染字符串（邮件等） */
    public static function str(string $file, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }
}
