<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Core\Str;

class Article extends Base
{
    protected string $table = 'articles';
    protected string $title = '文章管理';
    protected string $orderBy = 'is_top DESC, published_at DESC';
    protected string $listWhere = 'deleted_at IS NULL';
    protected array $searchFields = ['title', 'tags'];
    protected string $baseSeg = 'articles';
    protected string $permNode = 'content';

    protected array $columns = [
        'title' => ['标题', 'text'],
        'category_id' => ['栏目', 'category'],
        'status' => ['状态', 'publish_status'],
        'published_at' => ['发布时间', 'datetime'],
        'views' => ['浏览', 'number'],
    ];

    protected array $fields = [
        'title' => ['标题', 'text', ['required' => true, 'maxlen' => 150]],
        'slug' => ['URL 别名', 'text', ['hint' => '留空自动按标题生成']],
        'category_id' => ['栏目', 'category_select', []],
        'cover' => ['封面图', 'image', []],
        'summary' => ['摘要（SEO 与 AI 引用共源）', 'textarea', ['rows' => 3]],
        'content' => ['正文', 'richtext', []],
        'author' => ['作者', 'text', []],
        'tags' => ['标签（顿号分隔，每个标签自动生成专题页 /tag/xx）', 'text', []],
        'is_top' => ['置顶', 'switch', []],
        'status' => ['状态', 'publish_select', []],
        'published_at' => ['发布时间', 'datetime', ['hint' => '填未来时间并选「发布」= 定时发布；留空保存时自动填当前时间']],
        'seo_title' => ['SEO 标题', 'text', []],
        'seo_keywords' => ['SEO 关键词', 'text', []],
        'seo_description' => ['SEO 描述', 'textarea', ['rows' => 2]],
    ];

    public function index(Request $req, array $p): void
    {
        // 列表含定时状态
        $page = max(1, (int)$req->get('page', 1));
        $kw = trim((string)$req->get('q', ''));
        $where = $this->listWhere;
        $params = [];
        if ($kw !== '') {
            $where .= ' AND (title LIKE ? OR tags LIKE ?)';
            $params = ['%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%', '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%'];
        }
        $size = 15;
        $offset = ($page - 1) * $size;
        $total = Database::count('articles', $where, $params);
        $rows = Database::fetchAll(
            "SELECT * FROM articles WHERE $where ORDER BY {$this->orderBy} LIMIT $size OFFSET $offset",
            $params
        );
        $this->view('crud/list', [
            'rows' => $rows, 'total' => $total, 'page' => $page,
            'pages' => max(1, (int)ceil($total / $size)),
            'columns' => $this->columns, 'kw' => $kw,
            'baseSeg' => 'articles',
            'seg_guides' => $this->segGuides(),
        ]);
    }

    public function create(Request $req, array $p): void
    {
        $this->view('crud/form', [
            'fields' => $this->fields, 'row' => null,
            'baseSeg' => 'articles', 'revisions' => [],
        ]);
    }

    public function edit(Request $req, array $p): void
    {
        $row = Database::fetch('SELECT * FROM articles WHERE id=? AND deleted_at IS NULL', [(int)$p['id']]);
        if (!$row) {
            Session::flash('error', '记录不存在或已进回收站');
            \app\Core\ResponseRedirect('/admin/articles');
        }
        $revisions = Database::fetchAll(
            "SELECT id, created_at FROM revisions WHERE target_type='article' AND target_id=? ORDER BY id DESC LIMIT 10",
            [$row['id']]
        );
        $this->view('crud/form', [
            'fields' => $this->fields, 'row' => $row,
            'baseSeg' => 'articles', 'revisions' => $revisions,
        ]);
    }

    public function update(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $this->snapshot($id); // 旧版入修订历史
        $data = $this->collect($req);
        $this->beforeSave($data, $id, 'update');
        $data['updated_at'] = date('Y-m-d H:i:s');
        try {
            Database::update($this->table, $data, 'id=?', [$id]);
        } catch (\Throwable $t) {
            Session::flash('error', '保存失败：' . $t->getMessage());
            \app\Core\ResponseRedirect('/admin/articles');
        }
        $this->afterSave($data, $id, 'update');
        $this->audit('Article.update', 'articles', $id, ['title' => $data['title'] ?? '']);
        Session::flash('success', '已保存');
        \app\Core\ResponseRedirect('/admin/articles');
    }

    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        Database::update('articles', ['deleted_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
        $this->afterDelete($id);
        $this->audit('Article.trash', 'articles', $id);
        Session::flash('success', '已移入回收站');
        \app\Core\ResponseRedirect('/admin/articles');
    }

    public function trash(Request $req, array $p): void
    {
        $rows = Database::fetchAll('SELECT id, title, category_id, deleted_at FROM articles WHERE deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 100');
        $cats = [];
        foreach (Database::fetchAll("SELECT id, name FROM categories WHERE type IN ('article','news')") as $c) {
            $cats[$c['id']] = $c['name'];
        }
        $this->view('article/trash', ['rows' => $rows, 'cats' => $cats, 'title' => '文章回收站']);
    }

    public function restore(Request $req, array $p): void
    {
        Csrf::guard();
        Database::run('UPDATE articles SET deleted_at=NULL WHERE id=? AND deleted_at IS NOT NULL', [(int)$p['id']]);
        $this->audit('Article.restore', 'articles', (int)$p['id']);
        Session::flash('success', '已恢复');
        \app\Core\ResponseRedirect('/admin/articles/trash');
    }

    public function purge(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        Database::delete('revisions', 'target_type=? AND target_id=?', ['article', $id]);
        Database::delete('articles', 'id=? AND deleted_at IS NOT NULL', [$id]);
        $this->audit('Article.purge', 'articles', $id);
        Session::flash('success', '已永久删除');
        \app\Core\ResponseRedirect('/admin/articles/trash');
    }

    public function restoreRev(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $rid = (int)$p['rid'];
        $rev = Database::fetch("SELECT * FROM revisions WHERE id=? AND target_type='article' AND target_id=?", [$rid, $id]);
        $art = Database::fetch('SELECT id FROM articles WHERE id=?', [$id]);
        if ($rev && $art) {
            $snap = json_decode((string)$rev['snapshot'], true);
            if (is_array($snap)) {
                $this->snapshot($id); // 当前版本入修订
                $snap['updated_at'] = date('Y-m-d H:i:s');
                Database::update('articles', $snap, 'id=?', [$id]);
                $this->audit('Article.restore_rev', 'articles', $id, ['revision' => $rid]);
                Session::flash('success', '已恢复到 ' . $rev['created_at'] . ' 的版本');
            }
        }
        \app\Core\ResponseRedirect('/admin/articles/' . $id . '/edit');
    }

    /** 旧版快照入修订历史（保留最近 10 版） */
    private function snapshot(int $id): void
    {
        $cur = Database::fetch(
            'SELECT title, slug, category_id, cover, summary, content, author, tags, seo_title, seo_keywords, seo_description FROM articles WHERE id=?',
            [$id]
        );
        if (!$cur) return;
        $u = Session::get('admin_user');
        Database::insert('revisions', [
            'target_type' => 'article', 'target_id' => $id,
            'snapshot' => json_encode($cur, JSON_UNESCAPED_UNICODE),
            'created_by' => $u['id'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $keep = Database::fetchAll(
            'SELECT id FROM revisions WHERE target_type="article" AND target_id=? ORDER BY id DESC LIMIT 10',
            [$id]
        );
        if (count($keep) >= 10) {
            $drop = array_slice(array_column($keep, 'id'), 10);
            if ($drop) {
                Database::run('DELETE FROM revisions WHERE id IN (' . implode(',', array_map('intval', $drop)) . ')');
            }
        }
    }

    public function toggle(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $a = Database::fetch('SELECT status, published_at FROM articles WHERE id=?', [$id]);
        if ($a) {
            $new = $a['status'] === 'published' ? 'draft' : 'published';
            $upd = ['status' => $new];
            if ($new === 'published' && !$a['published_at']) { $upd['published_at'] = date('Y-m-d H:i:s'); }
            Database::update('articles', $upd, 'id=?', [$id]);
            $this->audit('Article.publish', 'articles', $id, ['to' => $new]);
        }
        \app\Core\ResponseRedirect('/admin/articles');
    }

    protected function collect(Request $req): array
    {
        $data = parent::collect($req);
        $data['category_id'] = $req->post('category_id', '') === '' ? null : (int)$req->post('category_id');
        return $data;
    }

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        // slug
        if (empty($data['slug'])) {
            $base = Str::slugify($data['title'] ?? '');
        } else {
            $base = Str::slugify($data['slug']) ?: 'a-' . date('YmdHis');
        }
        $slug = $base;
        $i = 1;
        while (true) {
            $q = 'SELECT COUNT(*) FROM articles WHERE slug=?' . ($id ? ' AND id<>' . (int)$id : '');
            if (Database::value($q, [$slug]) == 0) break;
            $slug = $base . '-' . (++$i);
        }
        $data['slug'] = $slug;
        // 定时发布：状态为发布但发布时间在未来 → 转定时状态
        if (($data['status'] ?? '') === 'published' && !empty($data['published_at'])) {
            if (strtotime((string)$data['published_at']) > time() + 60) {
                $data['status'] = 'scheduled';
            }
        }
        if (($data['status'] ?? '') === 'published' && empty($data['published_at'])) {
            $data['published_at'] = date('Y-m-d H:i:s');
        }
        // 广告法违禁词提示
        $words = ['最有效', '治愈', '根治', '保证', '第一', '国家级', '唯一', '首选', '彻底解决', '百分百'];
        foreach ($words as $w) {
            if (mb_stripos(($data['title'] ?? '') . ($data['summary'] ?? ''), $w) !== false) {
                Session::flash('warning', '提醒：标题/摘要中含广告法敏感词「' . $w . '」，请确认表述合规');
                break;
            }
        }
    }

    protected function afterSave(array $data, int $id, string $mode): void
    {
        \app\Core\Cache::forget('home_data');
        // 钩子：发布/定时事件（未来插件挂载点）
        \app\Core\Hook::fire(($data['status'] ?? '') === 'published' ? 'article.published' : 'article.scheduled', $id);
        // 发布时主动推送搜索引擎（静默失败）
        if (($data['status'] ?? '') === 'published') {
            try {
                $u = [\url('/news/' . $data['slug'])];
                \app\Services\SeoService::pushBaidu($u);
                \app\Services\SeoService::pushIndexNow($u);
            } catch (\Throwable $t) {
                \app\Core\Logger::error('seo-push', $t->getMessage());
            }
        }
    }

    protected function afterDelete(int $id): void
    {
        \app\Core\Cache::forget('home_data');
    }
}
