<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Request;
use app\Core\Session;
use app\Services\LicenseService;
use app\Services\Updater;

class License extends Base
{
    protected string $permNode = 'license';
    protected string $title = '授权与升级';
    protected string $baseSeg = 'license';

    public function index(Request $req, array $p): void
    {
        $lic = LicenseService::current();
        $status = LicenseService::status();
        [$remote, $remoteMsg] = Updater::checkRemote();
        $pluginUpdates = Updater::remotePluginUpdates();
        $history = \app\Core\Database::fetchAll('SELECT * FROM upgrade_history ORDER BY id DESC LIMIT 10');
        $this->view('license/index', [
            'lic' => $lic,
            'status' => $status,
            'remote' => $remote,
            'remoteMsg' => $remoteMsg,
            'pluginUpdates' => $pluginUpdates,
            'history' => $history,
            'version' => CMS_VERSION,
            'title' => $this->title,
        ]);
    }

    public function activate(Request $req, array $p): void
    {
        Csrf::guard();
        $key = (string)$req->post('license_key', '');
        [$ok, $msg] = LicenseService::activate($key);
        Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect('/admin/license');
    }

    public function rebuildIntegrity(Request $req, array $p): void
    {
        Csrf::guard();
        \app\Services\LicenseService::rebuildIntegrityBaseline();
        $this->audit('license.integrity_rebuild');
        Session::flash('success', '完整性基线已重建（以当前核心文件为准）');
        \app\Core\ResponseRedirect('/admin/license');
    }

    public function checkRemote(Request $req, array $p): void
    {
        Csrf::guard();
        [$ok, $msg] = LicenseService::checkRemote();
        Session::flash($ok ? 'success' : 'warning', $msg);
        \app\Core\ResponseRedirect('/admin/license');
    }

    public function uploadPackage(Request $req, array $p): void
    {
        Csrf::guard();
        $file = $req->file('package');
        if (!$file) { Session::flash('error', '未选择升级包'); \app\Core\ResponseRedirect('/admin/license'); }
        if (!preg_match('/\.zip$/i', (string)$file['name'])) {
            Session::flash('error', '仅支持 .zip 升级包');
            \app\Core\ResponseRedirect('/admin/license');
        }
        if (!is_dir(ROOT . '/upgrades')) { @mkdir(ROOT . '/upgrades', 0755, true); }
        $dest = ROOT . '/upgrades/' . date('Ymd-His') . '-' . preg_replace('/[^\w\.\-]/', '', (string)$file['name']);
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Session::flash('error', '保存升级包失败');
            \app\Core\ResponseRedirect('/admin/license');
        }
        Session::set('pending_upgrade', $dest);
        Session::flash('success', '升级包已上传，点击「执行升级」安装（自动备份）。');
        \app\Core\ResponseRedirect('/admin/license');
    }

    /** 从升级分发主站一键升级（授权三重校验在主站完成，本地验签+md5 双保险） */
    public function runRemote(Request $req, array $p): void
    {
        Csrf::guard();
        [$ok, $msg] = Updater::runRemote();
        Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect('/admin/license');
    }

    /** 从主站更新单个官方插件 */
    public function updatePlugin(Request $req, array $p): void
    {
        Csrf::guard();
        $slug = (string)$req->post('slug', '');
        if (!preg_match('/^[a-z0-9\-]{1,60}$/', $slug)) { Session::flash('error', '插件名非法'); \app\Core\ResponseRedirect('/admin/license'); }
        [$ok, $msg] = Updater::updatePlugin($slug);
        Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect('/admin/license');
    }

    public function runUpgrade(Request $req, array $p): void
    {
        Csrf::guard();
        $dest = Session::get('pending_upgrade');
        if (!$dest || !is_file((string)$dest)) {
            Session::flash('error', '没有待执行的升级包');
            \app\Core\ResponseRedirect('/admin/license');
        }
        [$ok, $msg] = Updater::run((string)$dest);
        Session::forget('pending_upgrade');
        Session::flash($ok ? 'success' : 'error', $msg);
        \app\Core\ResponseRedirect('/admin/license');
    }
}
