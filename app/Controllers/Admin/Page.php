<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Session;
use app\Core\Database;
use app\Core\Request;

class Page extends Base
{
    protected string $table = 'pages';
    protected string $permNode = 'pages';
    protected string $title = '单页管理';
    protected string $baseSeg = 'pages';
    protected array $searchFields = ['title'];
    protected string $listWhere = 'deleted_at IS NULL';

    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        Database::update('pages', ['deleted_at' => date('Y-m-d H:i:s')], 'id=?', [(int)$p['id']]);
        $this->afterDelete((int)$p['id']);
        $this->audit('Page.trash', 'pages', (int)$p['id']);
        Session::flash('success', '已移入回收站（单页回收站：文章回收站页同入口）');
        \app\Core\ResponseRedirect('/admin/pages');
    }

    public function trash(Request $req, array $p): void
    {
        $rows = Database::fetchAll('SELECT id, title, slug, deleted_at FROM pages WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 100');
        $this->view('article/trash', ['rows' => $rows, 'cats' => null, 'title' => '单页回收站', 'type' => 'page']);
    }

    public function restore(Request $req, array $p): void
    {
        Csrf::guard();
        Database::run('UPDATE pages SET deleted_at=NULL WHERE id=?', [(int)$p['id']]);
        $this->audit('page.restore', 'pages', (int)$p['id']);
        Session::flash('success', '已恢复');
        \app\Core\ResponseRedirect('/admin/pages/trash');
    }

    public function purge(Request $req, array $p): void
    {
        Csrf::guard();
        Database::delete('revisions', 'target_type=? AND target_id=?', ['page', (int)$p['id']]);
        Database::delete('pages', 'id=? AND deleted_at IS NOT NULL', [(int)$p['id']]);
        $this->audit('page.purge', 'pages', (int)$p['id']);
        Session::flash('success', '已永久删除');
        \app\Core\ResponseRedirect('/admin/pages/trash');
    }
    protected array $columns = [
        'title' => ['标题', 'text'],
        'slug' => ['URL', 'text'],
        'status' => ['状态', 'publish_status'],
        'show_in_nav' => ['导航展示', 'yesno'],
        'updated_at' => ['更新时间', 'datetime'],
    ];
    protected array $fields = [
        'title' => ['标题', 'text', ['required' => true]],
        'slug' => ['URL 别名', 'text', ['hint' => '如 about / privacy / consent；留空自动生成']],
        'content' => ['内容', 'richtext', []],
        'show_in_nav' => ['显示在底部导航', 'switch', []],
        'status' => ['状态', 'publish_select', []],
        'seo_title' => ['SEO 标题', 'text', []],
        'seo_keywords' => ['SEO 关键词', 'text', []],
        'seo_description' => ['SEO 描述', 'textarea', ['rows' => 2]],
    ];

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        if (empty($data['slug'])) {
            $data['slug'] = \app\Core\Str::slugify($data['title'] ?? '') ?: 'page-' . date('YmdHis');
        }
    }
}
