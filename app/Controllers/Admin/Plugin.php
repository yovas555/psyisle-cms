<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\PluginManager;
use app\Core\Request;
use app\Core\Session;

class Plugin extends Base
{
    protected string $permNode = 'plugins';
    protected string $title = '插件管理';

    public function index(Request $req, array $p): void
    {
        $this->view('plugin/index', [
            'plugins' => PluginManager::all(),
            'enabled' => PluginManager::enabledList(),
            'title' => $this->title,
        ]);
    }

    /** 后台插件页（默认渲染插件设置表单；插件可自定义） */
    public function page(Request $req, array $p): void
    {
        $slug = (string)($p['slug'] ?? '');
        $path = (string)($p['path'] ?? '');
        PluginManager::adminPage($slug, $req, $path);
    }

    /** 保存插件设置 */
    public function saveSettings(Request $req, array $p): void
    {
        Csrf::guard();
        $slug = (string)$p['slug'];
        try {
            $n = PluginManager::saveSettings($slug, $req->posts());
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
            \app\Core\ResponseRedirect('/admin/plugin/' . $slug);
        }
        $this->audit('plugin.settings', 'plugins', $slug, ['count' => $n]);
        Session::flash('success', "已保存 {$n} 项插件设置");
        \app\Core\ResponseRedirect('/admin/plugin/' . $slug);
    }

    /** 插件自定义子页动作（action 名 → 插件静态方法，驼峰转换） */
    public function pluginAction(Request $req, array $p): void
    {
        Csrf::guard();
        $slug = (string)$p['slug'];
        $action = (string)$p['action'];
        $method = preg_replace_callback('/-([a-z])/', fn($m) => strtoupper($m[1]), $action);
        $result = \app\Core\PluginManager::call($slug, $method, $req);
        if ($result === null) { Session::flash('error', '插件未实现该操作'); \app\Core\ResponseRedirect('/admin/plugin/' . $slug); }
        $this->audit('plugin.action', 'plugins', $slug, ['action' => $action]);
        // v2.5.0+：动作返回 [ok, msg] 时转 flash；返回 null 的动作自行输出（如导出）
        if (is_array($result) && isset($result[0], $result[1])) {
            Session::flash($result[0] ? 'success' : 'error', (string)$result[1]);
        }
        \app\Core\ResponseRedirect('/admin/plugin/' . $slug);
    }

    public function toggle(Request $req, array $p): void
    {
        Csrf::guard();
        $slug = (string)$p['slug'];
        $on = !PluginManager::isEnabled($slug);
        [$ok, $msg] = PluginManager::toggle($slug, $on);
        Session::flash($ok ? 'success' : 'error', $msg);
        $this->audit('plugin.toggle', 'plugins', $slug, ['on' => $on, 'ok' => $ok]);
        \app\Core\ResponseRedirect('/admin/plugins');
    }

    public function uninstall(Request $req, array $p): void
    {
        Csrf::guard();
        $slug = (string)$p['slug'];
        [$ok, $msg] = PluginManager::uninstall($slug);
        Session::flash($ok ? 'success' : 'error', $msg);
        $this->audit('plugin.uninstall', 'plugins', $slug);
        \app\Core\ResponseRedirect('/admin/plugins');
    }
}
