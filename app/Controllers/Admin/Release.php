<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Services\DistributeService;

/**
 * 升级分发主站管理（仅 config.distribute.enabled=true 的站点有效）
 * 发行包上传/登记、客户（授权）管理、分发日志。
 */
class Release extends Base
{
    protected string $permNode = 'license';
    protected string $title = '升级分发主站';
    protected string $baseSeg = 'release';

    public function index(Request $req, array $p): void
    {
        $enabled = DistributeService::enabled();
        $pkgs = Database::fetchAll("SELECT * FROM upgrade_packages ORDER BY kind, version DESC, released_at DESC");
        $clients = Database::fetchAll("SELECT * FROM licenses ORDER BY id DESC");
        $logs = Database::fetchAll('SELECT * FROM upgrade_api_logs ORDER BY id DESC LIMIT 100');
        $this->view('release/index', [
            'title' => $this->title, 'enabled' => $enabled, 'pkgs' => $pkgs,
            'clients' => $clients, 'logs' => $logs, 'isHost' => $this->hostIsBound(),
        ]);
    }

    /** 当前站点域名是否与某授权绑定一致（主站自检提示用） */
    private function hostIsBound(): string
    {
        $host = strtolower((string)parse_url(base_url(), PHP_URL_HOST));
        foreach (Database::fetchAll("SELECT license_key, domain FROM licenses WHERE domain IS NOT NULL AND status='active'") as $c) {
            if (strcasecmp((string)$c['domain'], $host) === 0) return (string)$c['license_key'];
        }
        return '';
    }

    /** 上传发行包：core（含 version.json）或 plugin（含 plugin.json），登记 md5/版本 */
    public function upload(Request $req, array $p): void
    {
        Csrf::guard();
        if (!DistributeService::enabled()) {
            Session::flash('error', '本站未启用升级分发服务（config.distribute.enabled）');
            \app\Core\ResponseRedirect('/admin/release');
        }
        $file = $req->file('package');
        if (!$file || !is_uploaded_file($file['tmp_name'] ?? '')) {
            Session::flash('error', '未选择发行包');
            \app\Core\ResponseRedirect('/admin/release');
        }
        if (!preg_match('/\.zip$/i', (string)$file['name']) || !class_exists('ZipArchive')) {
            Session::flash('error', '仅支持 .zip 且服务器需 zip 扩展');
            \app\Core\ResponseRedirect('/admin/release');
        }
        $zip = new \ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            Session::flash('error', '压缩包无法读取（损坏或非 zip）');
            \app\Core\ResponseRedirect('/admin/release');
        }
        $verJson = $zip->getFromName('version.json');
        $manifest = $zip->getFromName('plugin.json');
        if ($verJson !== false) {
            $ver = json_decode($verJson, true);
            if (!is_array($ver) || empty($ver['version']) || !preg_match('/^\d+\.\d+\.\d+$/', (string)$ver['version'])) {
                $zip->close();
                Session::flash('error', 'version.json 缺失或版本号非法');
                \app\Core\ResponseRedirect('/admin/release');
            }
            $kind = 'core'; $slug = ''; $version = (string)$ver['version'];
        } elseif ($manifest !== false) {
            $m = json_decode($manifest, true);
            if (!is_array($m) || empty($m['slug']) || empty($m['version'])) {
                $zip->close();
                Session::flash('error', 'plugin.json 缺失或字段不全');
                \app\Core\ResponseRedirect('/admin/release');
            }
            $kind = 'plugin'; $slug = (string)$m['slug']; $version = (string)$m['version'];
        } else {
            $zip->close();
            Session::flash('error', '包内既无 version.json 也无 plugin.json，拒绝登记');
            \app\Core\ResponseRedirect('/admin/release');
        }
        // 目录安全复查：包内不得出现穿越路径或 config 覆盖（core 包 config/config.php 模板允许）
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string)$zip->getNameIndex($i);
            if (preg_match('#^(\.\.|/|[A-Za-z]:)#', $n) || strpos($n, '\\') !== false) {
                $zip->close();
                Session::flash('error', '包内含非法路径：' . $n);
                \app\Core\ResponseRedirect('/admin/release');
            }
        }
        $zip->close();

        if (!is_dir(ROOT . '/storage/releases')) @mkdir(ROOT . '/storage/releases', 0755, true);
        $name = ($kind === 'core' ? 'psyisle-' : 'plugin-' . $slug . '-') . $version . '-' . date('Ymd-His') . '.zip';
        $dest = ROOT . '/storage/releases/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            Session::flash('error', '保存发行包失败');
            \app\Core\ResponseRedirect('/admin/release');
        }
        $md5 = md5_file($dest);
        $minFrom = trim((string)$req->post('min_from', ''));
        if ($minFrom !== '' && !preg_match('/^\d+\.\d+\.\d+$/', $minFrom)) $minFrom = '';
        $changelog = trim((string)$req->post('changelog', ''));
        [, $msg] = DistributeService::register($kind, $name, $md5, (int)filesize($dest), $version, $slug, $minFrom, $changelog);
        $this->audit('Release.upload', 'upgrade_packages', null, ['pkg' => $kind . ' ' . ($slug ? $slug . ' ' : '') . $version, 'md5' => $md5]);
        Session::flash('success', $msg . '（md5 ' . substr($md5, 0, 8) . '…）');
        \app\Core\ResponseRedirect('/admin/release');
    }

    public function setStatus(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $st = (string)$req->post('status', '');
        if (!in_array($st, ['active', 'retired'], true)) $st = 'retired';
        $pkg = Database::fetch('SELECT * FROM upgrade_packages WHERE id=?', [$id]);
        if (!$pkg) { Session::flash('error', '包不存在'); \app\Core\ResponseRedirect('/admin/release'); }
        Database::run('UPDATE upgrade_packages SET status=? WHERE id=?', [$st, $id]);
        $this->audit('Release.status', 'upgrade_packages', (int)$pkg['id'], ['pkg' => ($pkg['kind'] === 'core' ? 'core' : 'plugin:' . $pkg['slug']) . ' v' . $pkg['version'], 'status' => $st]);
        Session::flash('success', '包状态已更新');
        \app\Core\ResponseRedirect('/admin/release');
    }

    public function deletePkg(Request $req, array $p): void
    {
        Csrf::guard();
        $pkg = Database::fetch('SELECT * FROM upgrade_packages WHERE id=?', [(int)$p['id']]);
        if (!$pkg) { Session::flash('error', '包不存在'); \app\Core\ResponseRedirect('/admin/release'); }
        $path = ROOT . '/' . $pkg['file_path'];
        if (is_file($path) && strpos(realpath($path) ?: '', realpath(ROOT . '/storage/releases') ?: '##') === 0) {
            @unlink($path);
        }
        Database::run('DELETE FROM upgrade_packages WHERE id=?', [(int)$p['id']]);
        $this->audit('Release.delete', 'upgrade_packages', (int)$pkg['id'], ['pkg' => ($pkg['kind'] === 'core' ? 'core' : 'plugin:' . $pkg['slug']) . ' v' . $pkg['version']]);
        Session::flash('success', '发行包已删除');
        \app\Core\ResponseRedirect('/admin/release');
    }

    /** 编辑客户绑定：client_ip（可多 IP 逗号分隔）、update_enabled 开关 */
    public function updateClient(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $lic = Database::fetch('SELECT * FROM licenses WHERE id=?', [$id]);
        if (!$lic) { Session::flash('error', '授权不存在'); \app\Core\ResponseRedirect('/admin/release'); }
        $ips = trim((string)$req->post('client_ip', ''));
        if ($ips !== '') {
            $parts = array_filter(array_map('trim', explode(',', $ips)), fn ($v) => filter_var($v, FILTER_VALIDATE_IP));
            if (!$parts) { Session::flash('error', 'IP 格式不正确'); \app\Core\ResponseRedirect('/admin/release'); }
            $ips = implode(',', $parts);
        } else {
            $ips = null; // 清空 = 下次请求自动重新绑定
        }
        $upd = !empty($req->post('update_enabled')) ? 1 : 0;
        Database::run('UPDATE licenses SET client_ip=?, update_enabled=? WHERE id=?', [$ips, $upd, $id]);
        $this->audit('Release.client', 'licenses', (int)$lic['id'], ['license' => $lic['license_key'], 'client_ip' => $ips ?: 'auto', 'update_enabled' => $upd]);
        Session::flash('success', '客户绑定已更新');
        \app\Core\ResponseRedirect('/admin/release');
    }
}
