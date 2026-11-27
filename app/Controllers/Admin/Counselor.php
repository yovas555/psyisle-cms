<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Uploader;
use app\Services\CounselorProfile;
use app\Services\BookingSlots;

class Counselor extends Base
{
    protected string $table = 'counselors';
    protected string $permNode = 'counselors';
    protected string $title = '咨询师管理';
    protected string $orderBy = 'is_featured DESC, sort, id';
    protected string $baseSeg = 'counselors';
    protected array $searchFields = ['name', 'specialties'];
    protected string $toggleCol = 'status';
    private array $serviceIds = [];
    private array $servicePrices = [];
    private ?array $slotRows = null;
    private string $relationError = '';
    protected array $columns = [
        'name' => ['姓名', 'text'],
        'title' => ['头衔', 'text'],
        'specialties' => ['擅长', 'text'],
        'is_featured' => ['推荐', 'yesno'],
        'status' => ['展示', 'yesno'],
        'updated_at' => ['更新', 'datetime'],
    ];
    protected array $fields = [
        'name' => ['姓名', 'text', ['required' => true]],
        'slug' => ['URL 别名', 'text', ['hint' => '留空自动生成']],
        'gender' => ['性别', 'gender_select', []],
        'title' => ['头衔（如：国家二级心理咨询师）', 'text', []],
        'avatar' => ['头像', 'image', ['crop' => 'square', 'upload' => '/admin/counselors/avatar/upload', 'hint' => '上传后可拖拽、缩放和旋转，保存为 960×960 头像']],
        'video_url' => ['视频介绍链接', 'text', ['hint' => '支持 B 站（bilibili.com/video/BV…）与腾讯视频（v.qq.com）链接，留空不显示']],
        'orientation' => ['咨询流派', 'text', []],
        'specialties' => ['擅长方向（顿号分隔）', 'text', ['hint' => '如：情绪困扰、亲子关系、职场压力']],
        'introduction' => ['个人简介', 'richtext', []],
        'education' => ['教育背景', 'richtext', []],
        'experience' => ['受训与从业经历', 'richtext', []],
        'style' => ['咨询风格', 'richtext', []],
        'credentials' => ['资质与注册信息（咨询师自述）', 'textarea', ['rows' => 3, 'hint' => '管理员保存会直接公开；咨询师工作台提交需审核。公开自述不代表平台已核验']],
        'supervision' => ['督导与专业支持（咨询师自述）', 'textarea', ['rows' => 3, 'hint' => '管理员保存会直接公开；咨询师工作台提交需审核。公开自述不代表平台已核验']],
        'audiences' => ['服务对象（机构维护）', 'textarea', ['rows' => 2, 'hint' => '例如成人、青少年、伴侣；仅机构管理员维护']],
        'unsuitable_topics' => ['不适合议题与情形（机构维护）', 'textarea', ['rows' => 3, 'hint' => '明确暂不适合接访的范围与转介建议，避免访客误约']],
        'personal_therapy' => ['个人体验与自我觉察（咨询师自述）', 'textarea', ['rows' => 3, 'hint' => '例如持续个人体验、受督导情况；不填写证书编号等敏感信息']],
        'hours' => ['累计咨询时长（小时）', 'number', []],
        'service_ids' => ['可提供的服务项目', 'service_multi', ['hint' => '只勾选该咨询师实际提供的服务；预约价格以所选服务为基准，分时定价可覆盖。未关联服务不会出现在该咨询师档案与预约选项中']],
        'fee_from' => ['通用收费起（元）', 'decimal', ['hint' => '仅用于未选择服务项目的预约；已关联服务按服务项目价格']],
        'fee_to' => ['通用收费止（元）', 'decimal', ['hint' => '仅用于未选择服务项目的展示']],
        'fee_unit' => ['计费单位', 'text', ['hint' => '默认 50分钟']],
        'languages' => ['咨询语言', 'text', []],
        'service_modes' => ['服务方式', 'mode_multi', ['hint' => '全部不勾选表示沿用预约表单的全部方式；勾选后前台筛选与预约提交都会按此限制']],
        'tags' => ['标签', 'text', []],
        'is_featured' => ['首页推荐', 'switch', []],
        'sort' => ['排序（小在前）', 'number', []],
        'status' => ['展示中', 'switch', []],
        'user_id' => ['关联后台账号（工作台登录）', 'user_select', []],
        'seo_title' => ['SEO 标题', 'text', []],
        'seo_keywords' => ['SEO 关键词', 'text', []],
        'seo_description' => ['SEO 描述', 'textarea', ['rows' => 2]],
    ];

    public function store(Request $req, array $p): void
    {
        Csrf::guard();
        $data = $this->collect($req);
        $this->beforeSave($data, null, 'create');
        if (self::hasCol($this->table, 'created_at')) $data['created_at'] = date('Y-m-d H:i:s');
        try {
            $id = Database::transaction(function () use ($data): int {
                $id = (int)Database::insert($this->table, $data);
                $this->afterSave($data, $id, 'create');
                return $id;
            });
        } catch (\Throwable $t) {
            \app\Core\Session::flash('error', '保存失败：' . $t->getMessage());
            \app\Core\ResponseRedirect('/admin/' . $this->baseSeg);
        }
        $this->finishSave($id, $data, $req, 'create');
    }

    public function update(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $data = $this->collect($req);
        $this->beforeSave($data, $id, 'update');
        if (self::hasCol($this->table, 'updated_at')) $data['updated_at'] = date('Y-m-d H:i:s');
        try {
            Database::transaction(function () use ($data, $id): void {
                Database::update($this->table, $data, 'id=?', [$id]);
                $this->afterSave($data, $id, 'update');
            });
        } catch (\Throwable $t) {
            \app\Core\Session::flash('error', '保存失败：' . $t->getMessage());
            \app\Core\ResponseRedirect('/admin/' . $this->baseSeg);
        }
        $this->finishSave($id, $data, $req, 'update');
    }

    private function finishSave(int $id, array $data, Request $req, string $mode): void
    {
        \app\Core\Hook::fire('admin.form.saved', ['baseSeg' => $this->baseSeg, 'id' => $id, 'req' => $req, 'data' => $data]);
        $this->audit($this->shortName() . '.' . ($mode === 'create' ? 'create' : 'update'), $this->table, $id, ['title' => $data['title'] ?? ($data['name'] ?? '')]);
        \app\Core\Session::flash('success', $mode === 'create' ? '已创建' : '已保存');
        if (!empty($_POST['_stay'])) \app\Core\ResponseRedirect('/admin/' . $this->baseSeg . '/' . $id . '/edit');
        \app\Core\ResponseRedirect('/admin/' . $this->baseSeg);
    }

    public function avatarUpload(Request $req, array $p): void
    {
        Csrf::guard();
        $file = $req->file('file');
        if (!$file) json_out(['ok' => false, 'msg' => '未选择文件']);
        $info = @getimagesize($file['tmp_name']);
        if (!$info || (int)$info[2] !== IMAGETYPE_JPEG || (int)$info[0] !== 960 || (int)$info[1] !== 960) {
            json_out(['ok' => false, 'msg' => '头像必须为裁剪后的 960×960 JPG 图片']);
        }
        [$ok, $path, $name, $err] = Uploader::image($file, 'counselor');
        if (!$ok) json_out(['ok' => false, 'msg' => $err]);
        $this->audit('counselor.avatar_upload', 'attachments', null, ['path' => $path]);
        json_out(['ok' => true, 'path' => $path, 'name' => $name]);
    }

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        if ((string)($_POST['_counselor_form'] ?? '') === '1') {
            if ($this->relationError !== '') {
                \app\Core\Session::flash('error', $this->relationError . '；本次资料、项目与排班均未修改');
                \app\Core\ResponseRedirect('/admin/counselors' . ($id ? '/' . $id . '/edit' : '/create'));
            }
            $validIds = array_map('intval', array_column(Database::fetchAll('SELECT id FROM services WHERE status=1'), 'id'));
            $this->serviceIds = array_values(array_intersect(array_unique($this->serviceIds), $validIds));
            if ($this->slotRows !== null) {
                [$rows, $error] = BookingSlots::validateSlotRows($this->slotRows, (int)($id ?? 0), $this->serviceIds);
                if ($error !== '') {
                    \app\Core\Session::flash('error', $error . '；本次资料、项目与排班均未修改');
                    \app\Core\ResponseRedirect('/admin/counselors' . ($id ? '/' . $id . '/edit' : '/create'));
                }
                $this->slotRows = $rows;
            }
            foreach ($this->servicePrices as $serviceId => $cents) {
                if (!in_array($serviceId, $this->serviceIds, true)) unset($this->servicePrices[$serviceId]);
            }
        }
        $uid = (int)($data['user_id'] ?? 0);
        if ($uid > 0) {
            $dup = Database::fetch('SELECT id FROM counselors WHERE user_id=? AND id<>?', [$uid, (int)($id ?? 0)]);
            if ($dup) {
                \app\Core\Session::flash('error', '该后台账号已绑定其他咨询师（一个账号仅可绑定一位），已清空本次绑定');
                $data['user_id'] = null;
            }
        } else {
            $data['user_id'] = null;
        }
        if (empty($data['slug'])) {
            $data['slug'] = \app\Core\Str::slugify($data['name'] ?? '') ?: 'c-' . date('YmdHis');
        }
        $data['slug'] = mb_substr((string)$data['slug'], 0, 60);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', (string)$data['slug'])) {
            \app\Core\Session::flash('error', 'URL 别名只能使用字母、数字、连字符或下划线');
            \app\Core\ResponseRedirect('/admin/counselors' . ($id ? '/' . $id . '/edit' : '/create'));
        }
    }

    protected function afterSave(array $data, int $id, string $mode): void
    {
        if ((string)($_POST['_counselor_form'] ?? '') === '1') {
            Database::delete('counselor_services', 'counselor_id=?', [$id]);
            foreach ($this->serviceIds as $serviceId) {
                Database::insert('counselor_services', [
                    'counselor_id' => $id, 'service_id' => $serviceId,
                    'price_cents' => $this->servicePrices[$serviceId] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
            }
            if ($this->slotRows !== null) {
                Database::delete('counselor_slots', 'counselor_id=?', [$id]);
                foreach ($this->slotRows as $row) {
                    $row['counselor_id'] = $id;
                    $row['start_time'] .= ':00';
                    $row['end_time'] .= ':00';
                    Database::insert('counselor_slots', $row);
                }
            }
        }
        parent::afterSave($data, $id, $mode);
    }

    protected function afterDelete(int $id): void
    {
        Database::transaction(static function () use ($id): void {
            Database::delete('counselor_services', 'counselor_id=?', [$id]);
            Database::delete('counselor_slots', 'counselor_id=?', [$id]);
        });
        parent::afterDelete($id);
    }

    protected function collect(Request $req): array
    {
        $data = parent::collect($req);
        $this->serviceIds = [];
        $this->servicePrices = [];
        $this->slotRows = null;
        $this->relationError = '';
        foreach ((array)$req->post('service_ids', []) as $serviceId) {
            $serviceId = (int)$serviceId;
            if ($serviceId > 0) $this->serviceIds[] = $serviceId;
        }
        foreach ((array)$req->post('service_prices', []) as $serviceId => $price) {
            $serviceId = (int)$serviceId;
            $price = trim((string)$price);
            if ($serviceId <= 0 || $price === '') continue;
            if (!is_numeric($price) || (float)$price < 0 || (float)$price > 1000000) {
                $this->relationError = '项目价格须为 0–1,000,000 之间的数字';
                continue;
            }
            $cents = (int)round((float)$price * 100);
            if ($cents > 0) $this->servicePrices[$serviceId] = $cents;
        }
        if (array_key_exists('slots', $_POST)) {
            if (is_array($_POST['slots'])) $this->slotRows = array_values($_POST['slots']);
            else $this->relationError = '排班数据格式不正确';
        } elseif (array_key_exists('slots_text', $_POST)) {
            [$this->slotRows, $this->relationError] = BookingSlots::parseSlotText((string)$_POST['slots_text']);
        }
        unset($data['service_ids']);
        $data['gender'] = in_array($req->post('gender', ''), ['1','2'], true) ? (int)$req->post('gender') : null;
        if (self::hasCol('counselors', 'service_modes')) {
            $data['service_modes'] = implode(',', CounselorProfile::modes($req->post('service_modes', [])));
        } else {
            unset($data['service_modes']);
        }
        foreach (['credentials' => 2000, 'supervision' => 2000, 'audiences' => 500, 'unsuitable_topics' => 1000, 'personal_therapy' => 2000] as $field => $limit) {
            if (self::hasCol('counselors', $field)) {
                $data[$field] = mb_substr((string)($data[$field] ?? ''), 0, $limit);
            } else {
                unset($data[$field]);
            }
        }
        $data['user_id'] = (int)$req->post('user_id', 0) > 0 ? (int)$req->post('user_id', 0) : null;
        return $data;
    }

    /**
     * 从会员提升为咨询师（咨询师入驻闭环）：
     * 咨询师本人前台注册会员 → 绑定微信 → 管理员在此输入其手机号一键建档（上线展示）。
     * 会员已绑微信时同步写 counselor_wx（unionid/mp_openid），微信/小程序登录即识别为咨询师。
     */
    public function promote(Request $req): void
    {
        if ($req->isPost()) {
            \app\Core\Csrf::guard();
            $phone = trim((string)$req->post('phone', ''));
            if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
                \app\Core\Session::flash('error', '手机号格式不正确');
                \app\Core\ResponseRedirect('/admin/counselors/promote');
            }
            $m = Database::fetch('SELECT * FROM members WHERE phone=? AND status="active"', [$phone]);
            if (!$m) {
                \app\Core\Session::flash('error', '该手机号无会员记录——请让咨询师先在前台注册会员并绑定微信，再来此处建档');
                \app\Core\ResponseRedirect('/admin/counselors/promote');
            }
            $dup = Database::fetch('SELECT id, name FROM counselors WHERE phone=?', [$phone]);
            if ($dup) {
                \app\Core\Session::flash('error', '该手机号已是咨询师（' . $dup['name'] . '），无需重复添加');
                \app\Core\ResponseRedirect('/admin/counselors');
            }
            $unionid = trim((string)($m['unionid'] ?? ''));
            $mpOpenid = trim((string)($m['mp_openid'] ?? ''));
            foreach ([['unionid', $unionid], ['mp_openid', $mpOpenid]] as [$col, $val]) {
                if ($val === '') continue;
                $wxDup = Database::fetch("SELECT c.id, c.name FROM counselor_wx cw JOIN counselors c ON c.id=cw.counselor_id WHERE cw.`{$col}`=?", [$val]);
                if ($wxDup) {
                    \app\Core\Session::flash('error', "该会员的微信已绑定咨询师「{$wxDup['name']}」，请先解绑或更换微信号");
                    \app\Core\ResponseRedirect('/admin/counselors/promote');
                }
            }
            $name = trim((string)($m['name'] ?? '')) !== '' ? mb_substr((string)$m['name'], 0, 40) : '咨询师' . substr($phone, -4);
            $slug = \app\Core\Str::slugify($name) ?: 'c-' . date('YmdHis');
            if (Database::fetch('SELECT id FROM counselors WHERE slug=?', [$slug])) {
                $slug .= '-' . bin2hex(random_bytes(2));
            }
            $cid = (int)Database::insert('counselors', [
                'name' => $name, 'slug' => $slug, 'phone' => $phone,
                'status' => 1, 'sort' => 100,
                'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            // 同步创建工作台账号（username=手机号、密码与会员一致）：账号密码/微信登录均直进工作台
            $roleId = (int)Database::value("SELECT id FROM roles WHERE permissions LIKE '%counselor_workspace%' ORDER BY id LIMIT 1", []);
            if ($roleId <= 0) { $roleId = 5; }
            $uid = 0;
            if (!Database::fetch('SELECT id FROM users WHERE username=?', [$phone])) {
                $uid = (int)Database::insert('users', [
                    'username' => $phone,
                    'password_hash' => (string)($m['password_hash'] ?? ''),
                    'display_name' => $name,
                    'role_id' => $roleId,
                    'status' => 1,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                Database::run('UPDATE counselors SET user_id=? WHERE id=?', [$uid, $cid]);
            }
            $wxSynced = false;
            if ($unionid !== '' || $mpOpenid !== '') {
                Database::insert('counselor_wx', [
                    'counselor_id' => $cid,
                    'unionid' => $unionid !== '' ? $unionid : null,
                    'mp_openid' => $mpOpenid !== '' ? $mpOpenid : null,
                    'nickname' => mb_substr(trim((string)($m['name'] ?? '')), 0, 80) ?: null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $wxSynced = true;
            }
            $this->audit('counselor.promote', 'counselor', $cid, ['name' => $name, 'phone' => $phone, 'wx' => $wxSynced ? 'synced' : 'none']);
            \app\Core\Session::flash('success', "已添加咨询师「{$name}」{$phone}" . ($wxSynced
                ? '，微信已同步——其微信/小程序登录将直进咨询师工作台'
                : '。该会员尚未绑定微信：请其先在会员中心绑定微信（绑定后小程序登录自动识别）'));
            \app\Core\ResponseRedirect('/admin/counselors');
        }
        $this->view('counselor/promote');
    }
}
