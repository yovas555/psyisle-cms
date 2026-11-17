<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Core\Uploader;

class Media extends Base
{
    protected string $table = 'attachments';
    protected string $permNode = 'media';
    protected string $title = '媒体库';
    protected string $baseSeg = 'media';

    public function index(Request $req, array $p): void
    {
        $page = max(1, (int)$req->get('page', 1));
        $size = 24;
        $total = Database::count('attachments');
        $rows = Database::fetchAll(
            "SELECT * FROM attachments ORDER BY id DESC LIMIT $size OFFSET " . (($page - 1) * $size)
        );
        $this->view('media/index', [
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int)ceil($total / $size)),
            'title' => $this->title,
        ]);
    }

    public function upload(Request $req, array $p): void
    {
        Csrf::guard();
        $file = $req->file('file');
        if (!$file) json_out(['ok' => false, 'msg' => '未选择文件']);
        $module = (string)$req->post('module', 'common');
        [$ok, $path, $name, $err] = Uploader::image($file, $module);
        json_out($ok ? ['ok' => true, 'path' => $path, 'name' => $name] : ['ok' => false, 'msg' => $err]);
    }

    public function listJson(Request $req, array $p): void
    {
        $rows = Database::fetchAll('SELECT path, original_name, size, mime FROM attachments ORDER BY id DESC LIMIT 200');
        json_out(['ok' => true, 'rows' => $rows]);
    }

    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        $a = Database::fetch('SELECT * FROM attachments WHERE id=?', [(int)$p['id']]);
        if ($a) {
            Uploader::remove((string)$a['path']);
            $this->audit('media.delete', 'attachments', (int)$p['id']);
        }
        \app\Core\ResponseRedirect('/admin/media');
    }
}
