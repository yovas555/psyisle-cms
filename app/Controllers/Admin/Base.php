<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Logger;
use app\Core\Request;
use app\Core\Session;
use app\Core\View;

/** 后台通用基类：登录校验 + 审计 + 通用 CRUD（子类声明字段配置） */
class Base
{
    protected string $table = '';
    protected string $title = '';
    protected string $orderBy = 'id DESC';
    protected string $listWhere = '1=1';
    /** 列表列：name => [label, renderer?](renderer: text/datetime/status/chip) */
    protected array $columns = [];
    private static array $tableCols = [];

    /** 表列存在性（缓存） */
    protected static function hasCol(string $table, string $col): bool
    {
        if (!isset(self::$tableCols[$table])) {
            self::$tableCols[$table] = array_column(Database::fetchAll("SHOW COLUMNS FROM `$table`"), 'Field');
        }
        return in_array($col, self::$tableCols[$table], true);
    }

    /** 表单字段：name => [label, type, attrs]  type: text/textarea/richtext/select/number/date/switch/image/multiline */
    protected array $fields = [];
    protected string $permNode = '';
    /** 审计用的短类名 */
    protected function shortName(): string { return substr(static::class, (int)strrpos(static::class, chr(92)) + 1); }

    public function __construct(bool $checkPerm = true)
    {
        $u = Session::get('admin_user');
        if (!$u) {
            if (PHP_SAPI !== 'cli') {
                header('Location: /admin/login'); exit;
            }
            return;
        }
        // v2.11.0：演示模式只读拦截——非 GET 请求一律拒绝（含 POST/PUT/DELETE）
        if (Session::get('admin_demo') && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            Session::flash('error', '演示站为只读模式，不可修改数据');
            \app\Core\ResponseRedirect('/admin');
            exit;
        }
        if (!$checkPerm) return;
        // RBAC：按角色 permissions（JSON 数组，"*"=全部）检查当前控制器节点
        $node = $this->permNode !== '' ? $this->permNode : strtolower($this->shortName());
        // 概览类页面对所有角色开放
        $openNodes = ['dashboard', 'notification', 'help'];
        if (in_array($node, $openNodes, true)) return;
        try {
            $role = Database::fetch('SELECT permissions FROM roles WHERE id=?', [$u['role_id']]);
            $perms = $role ? (json_decode((string)$role['permissions'], true) ?: []) : [];
        } catch (\Throwable $t) { $perms = ['*']; }
        if (!in_array('*', $perms, true) && !in_array($node, $perms, true)) {
            // v2.20.2 咨询师身份特例：绑定 counselors.user_id 的账号放行咨询师工作台前缀（身份即授权）
            $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
            if (str_starts_with($path, '/admin/counselor-home')) {
                $isCsl = (bool)Database::value('SELECT id FROM counselors WHERE user_id=? AND status=1', [(int)$u['id']]);
                if ($isCsl) return;
            }
            http_response_code(403);
            $this->view('no-permission', ['title' => '无权限', 'node' => $node]);
            exit;
        }
    }

    /** 审计记录 */
    public function audit(string $action, ?string $targetType = null, $targetId = null, array $detail = []): void
    {
        $u = Session::get('admin_user');
        try {
            // 链式哈希：每条记录绑定前一条，任何删除/篡改都会断链
            $prev = (string)(Database::value('SELECT row_hash FROM audit_logs ORDER BY id DESC LIMIT 1') ?? '');
            $createdAt = date('Y-m-d H:i:s');
            $detailJson = $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : '';
            $payload = $prev . '|' . $action . '|' . ($u['username'] ?? '?') . '|' . $targetType . '|'
                . (string)$targetId . '|' . $detailJson . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $createdAt;
            $rowHash = hash('sha256', $payload);
            Database::insert('audit_logs', [
                'user_id' => $u['id'] ?? null,
                'username' => $u['username'] ?? '?',
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => (string)$targetId,
                'detail' => $detailJson ?: null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'prev_hash' => $prev,
                'row_hash' => $rowHash,
                'created_at' => $createdAt,
            ]);
        } catch (\Throwable $t) {
            Logger::error('audit', $t->getMessage());
        }
    }

    public function view(string $tpl, array $data = []): void
    {
        $data['title'] = $data['title'] ?? $this->title;
        View::admin($tpl, $data);
    }

    // ==================== 通用 CRUD ====================

    /** 各模块使用引导（baseSeg => [key, title, steps]） */
    protected function segGuides(): array
    {
        return [
            'articles' => ['articles', '文章管理怎么用', [
                '新建：填标题→选栏目→写正文（支持 H2/加粗/插图）→「摘要」建议手写（SEO 与 AI 客服共用）→状态选「发布」',
                'SEO 三件套不填时自动用标题/标签/摘要兜底',
                '含广告法敏感词时保存会提醒，请自查表述',
                '「置顶」文章会出现在首页与 llms.txt 重点文章区']],
            'counselors' => ['counselors', '咨询师资料怎么填', [
                '头衔写规范资质；擅长方向用顿号分隔（列表筛选标签来源于此）',
                '可预约时段每行一条：星期|开始|结束（如 6|09:00|12:00）',
                '勾「首页推荐」将出现在首页咨询师区块']],
            'services' => ['services', '服务项目怎么填', [
                '「咨询形式」「单次时长」会同步给 AI 客服作答复依据',
                '收费起止留空显示「面议」；勾「首页推荐」出现在首页服务区块']],
            'pages' => ['pages', '单页管理（前台独立页面都在这里改）', [
                '关于我们 = about、收费标准 = pricing、咨询流程 = process、隐私政策 = privacy、知情同意 = consent、危机资源 = crisis —— 点「编辑」即可改前台对应页面的正文',
                '正文用富文本编辑器（标题/列表/加粗/插图），保存后前台即时生效',
                '勾「显示在底部导航」自动出现在页脚；「查看」按钮可预览前台效果']],
            'faqs' => ['faqs', '常见问题管理', [
                'FAQ 自动同步进 AI 客服知识库，并生成 FAQPage 结构化数据',
                '问题写成用户口吻，回答 3-5 句最有效']],
            'banners' => ['banners', '轮播图说明', [
                '建议尺寸 1600×600；标题同时作为图片 alt',
                '排序小的先展示']],
            'navs' => ['navs', '导航菜单', [
                '顶部导航建议 ≤7 项；页脚导航放关于/收费/流程/隐私政策']],
            'links' => ['links', '友情链接', [
                '只加正规站点，避免牵连降权']],
            'categories' => ['categories', '栏目管理', [
                '文章发布时从文章/新闻栏目中选择；栏目生成独立列表页并进 sitemap']],
            'testimonials' => ['testimonials', '会员心声', [
                '展示前必须获会员授权并去标识（化名）',
                '内容真实克制，避免疗效承诺']],
        ];
    }

    public function index(Request $req, array $p): void
    {
        $page = max(1, (int)$req->get('page', 1));
        $kw = trim((string)$req->get('q', ''));
        $where = $this->listWhere;
        $params = [];
        if ($kw !== '' && ($this->searchFields ?? null)) {
            $parts = [];
            foreach ($this->searchFields as $f) {
                $parts[] = "`$f` LIKE ?";
                $params[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
            }
            $where .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        $size = 15;
        $offset = ($page - 1) * $size;
        $total = Database::count($this->table, $where, $params);
        $rows = $this->listRows($where, $params, $size, $offset);
        $this->view('crud/list', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int)ceil($total / $size)),
            'columns' => $this->columns,
            'kw' => $kw,
            'baseSeg' => $this->baseSeg ?? preg_replace('/^Admin\\\\/', '', static::class),
        ]);
    }

    /** 列表查询（子类可覆盖以补充派生列，如活动报名数） */
    protected function listRows(string $where, array $params, int $size, int $offset): array
    {
        return Database::fetchAll(
            "SELECT * FROM `{$this->table}` WHERE $where ORDER BY {$this->orderBy} LIMIT $size OFFSET $offset",
            $params
        );
    }

    public function create(Request $req, array $p): void
    {
        $this->view('crud/form', [
            'fields' => $this->fields,
            'row' => null,
            'baseSeg' => $this->baseSeg ?? preg_replace('/^Admin\\\\/', '', static::class),
        ]);
    }

    public function store(Request $req, array $p): void
    {
        Csrf::guard();
        $data = $this->collect($req);
        $this->beforeSave($data, null, 'create');
        if (self::hasCol($this->table, 'created_at')) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        try {
            $id = Database::insert($this->table, $data);
        } catch (\Throwable $t) {
            Session::flash('error', '保存失败：' . $t->getMessage());
            \app\Core\ResponseRedirect('/admin/' . ($this->baseSeg ?? ''));
        }
        $this->afterSave($data, $id, 'create');
        // v2.8.0 插件扩展点：stores-pro 等在表单外补充的字段（如咨询师门店）保存后写库
        \app\Core\Hook::fire('admin.form.saved', ['baseSeg' => ($this->baseSeg ?? ''), 'id' => $id, 'req' => $req, 'data' => $data]);
        $this->audit($this->shortName() . '.create', $this->table, $id, ['title' => $data['title'] ?? ($data['name'] ?? '')]);
        Session::flash('success', '已创建');
        if (!empty($_POST['_stay'])) {
            \app\Core\ResponseRedirect('/admin/' . ($this->baseSeg ?? '') . '/' . $id . '/edit');
        }
        \app\Core\ResponseRedirect('/admin/' . ($this->baseSeg ?? ''));
    }

    public function edit(Request $req, array $p): void
    {
        $row = Database::fetch("SELECT * FROM `{$this->table}` WHERE id=?", [(int)$p['id']]);
        if (!$row) { Session::flash('error', '记录不存在'); \app\Core\ResponseRedirect('/admin/' . ($this->baseSeg ?? '')); }
        $this->view('crud/form', [
            'fields' => $this->fields,
            'row' => $row,
            'baseSeg' => $this->baseSeg ?? preg_replace('/^Admin\\\\/', '', static::class),
        ]);
    }

    public function update(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $data = $this->collect($req);
        $this->beforeSave($data, $id, 'update');
        if (self::hasCol($this->table, 'updated_at')) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        try {
            Database::update($this->table, $data, 'id=?', [$id]);
        } catch (\Throwable $t) {
            Session::flash('error', '保存失败：' . $t->getMessage());
            \app\Core\ResponseRedirect('/admin/' . ($this->baseSeg ?? ''));
        }
        $this->afterSave($data, $id, 'update');
        \app\Core\Hook::fire('admin.form.saved', ['baseSeg' => ($this->baseSeg ?? ''), 'id' => $id, 'req' => $req, 'data' => $data]);
        $this->audit($this->shortName() . '.update', $this->table, $id, ['title' => $data['title'] ?? ($data['name'] ?? '')]);
        Session::flash('success', '已保存');
        if (!empty($_POST['_stay'])) {
            \app\Core\ResponseRedirect('/admin/' . ($this->baseSeg ?? '') . '/' . $id . '/edit');
        }
        \app\Core\ResponseRedirect('/admin/' . ($this->baseSeg ?? ''));
    }

    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        Database::delete($this->table, 'id=?', [$id]);
        $this->afterDelete($id);
        $this->audit($this->shortName() . '.delete', $this->table, $id);
        Session::flash('success', '已删除');
        \app\Core\ResponseRedirect('/admin/' . ($this->baseSeg ?? ''));
    }

    public function toggle(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $col = $this->toggleCol ?? 'status';
        $row = Database::fetch("SELECT `$col` FROM `{$this->table}` WHERE id=?", [$id]);
        if ($row) {
            Database::update($this->table, [$col => ((int)$row[$col] && (int)$row[$col] > 0) ? 0 : 1], 'id=?', [$id]);
            $this->audit($this->shortName() . '.toggle', $this->table, $id, ['col' => $col]);
        }
        \app\Core\ResponseRedirect('/admin/' . ($this->baseSeg ?? ''));
    }

    /** 从 POST 收集白名单字段 */
    protected function collect(Request $req): array
    {
        $data = [];
        foreach ($this->fields as $name => $cfg) {
            [$label, $type] = [$cfg[0], $cfg[1]];
            $v = $req->post($name, '');
            if (in_array($type, ['textarea', 'multiline', 'richtext'], true)) {
                $v = (string)$v;
                if ($type === 'richtext') { $v = \app\Core\Purifier::clean($v); }
                if (in_array($name, $this->rawFields ?? [], true)) {
                    // 允许保留（已净化）
                }
            } elseif ($type === 'switch') {
                $v = !empty($_POST[$name]) ? 1 : 0;
            } elseif ($type === 'datetime') {
                // datetime-local 提交 'Y-m-d\TH:i'，转 MySQL 'Y-m-d H:i:s'
                $v = is_string($v) ? trim($v) : $v;
                if ($v !== '' && $v !== null) {
                    $v = str_replace('T', ' ', $v);
                    if (strlen($v) === 16) { $v .= ':00'; }
                }
            } elseif ($type === 'number') {
                if ($v === '' || $v === null) {
                    // NOT NULL 的序数类字段空值落 0（否则新建必失败）；可空统计字段保持 null
                    $v = (str_contains($name, 'sort') || str_contains($name, 'capacity') || str_contains($name, 'people')) ? 0 : null;
                } else {
                    $v = (int)$v;
                }
            } elseif ($type === 'decimal') {
                $v = $v === '' ? null : (float)$v;
            } else {
                $v = is_string($v) ? trim($v) : $v;
            }
            $data[$name] = $v;
        }
        return $data;
    }

    /** 保存前钩子（slug 自动生成等） */
    protected function beforeSave(array &$data, ?int $id, string $mode): void {}

    protected function afterSave(array $data, int $id, string $mode): void
    {
        \app\Core\Cache::forget('home_data'); // 内容变化即时反映首页（缓存 300s）
        \app\Core\Cache::forget('sitemap_xml');
        \app\Core\Cache::forget('rss_xml');
        \app\Core\Cache::forget('llms_txt');
    }

    protected function afterDelete(int $id): void
    {
        \app\Core\Cache::forget('home_data');
    }
}
