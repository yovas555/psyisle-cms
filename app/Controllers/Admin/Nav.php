<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Request;
use app\Core\Database;

class Nav extends Base
{
    protected string $table = 'navigations';
    protected string $permNode = 'navs';
    protected string $title = '导航菜单';
    protected string $orderBy = 'location, sort, id';
    protected string $baseSeg = 'navs';
    protected string $toggleCol = 'status';
    protected array $columns = [
        'title' => ['名称', 'text'],
        'url' => ['链接', 'text'],
        'location' => ['位置', 'text'],
        'sort' => ['排序', 'number'],
        'status' => ['展示', 'yesno'],
    ];
    /** 列表注入 page_id：指向 /p/{slug} 的导航项可直接跳转编辑该单页内容 */
    public function index(Request $req, array $p): void
    {
        $rows = Database::fetchAll('SELECT * FROM navigations ORDER BY location, sort, id');
        foreach ($rows as &$r) {
            $r['page_id'] = null;
            if (preg_match('#^/p/([\w\-]+)/?$#', (string)$r['url'], $m)) {
                $pid = Database::value('SELECT id FROM pages WHERE slug=? AND deleted_at IS NULL', [$m[1]]);
                $r['page_id'] = $pid ? (int)$pid : null;
            }
        }
        unset($r);
        $this->view('crud/list', [
            'rows' => $rows, 'total' => count($rows), 'page' => 1, 'pages' => 1,
            'columns' => $this->columns, 'kw' => '',
            'baseSeg' => 'navs', 'seg_guides' => $this->segGuides(),
        ]);
    }

    protected array $fields = [
        'title' => ['名称', 'text', ['required' => true]],
        'url' => ['链接', 'text', ['required' => true]],
        'location' => ['位置', 'loc_select', []],
        'sort' => ['排序（小在前）', 'number', []],
        'status' => ['展示中', 'switch', []],
    ];
}
