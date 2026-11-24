<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Database;
use app\Core\Request;

class Counselor extends Base
{
    protected string $table = 'counselors';
    protected string $permNode = 'counselors';
    protected string $title = '咨询师管理';
    protected string $orderBy = 'is_featured DESC, sort, id';
    protected string $baseSeg = 'counselors';
    protected array $searchFields = ['name', 'specialties'];
    protected string $toggleCol = 'status';
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
        'avatar' => ['头像', 'image', []],
        'video_url' => ['视频介绍链接', 'text', ['hint' => '支持 B 站（bilibili.com/video/BV…）与腾讯视频（v.qq.com）链接，留空不显示']],
        'orientation' => ['咨询流派', 'text', []],
        'specialties' => ['擅长方向（顿号分隔）', 'text', ['hint' => '如：情绪困扰、亲子关系、职场压力']],
        'introduction' => ['个人简介', 'richtext', []],
        'education' => ['教育背景', 'richtext', []],
        'experience' => ['受训与从业经历', 'richtext', []],
        'style' => ['咨询风格', 'richtext', []],
        'hours' => ['累计咨询时长（小时）', 'number', []],
        'fee_from' => ['收费起（元）', 'decimal', []],
        'fee_to' => ['收费止（元）', 'decimal', []],
        'fee_unit' => ['计费单位', 'text', ['hint' => '默认 50分钟']],
        'languages' => ['咨询语言', 'text', []],
        'tags' => ['标签', 'text', []],
        'is_featured' => ['首页推荐', 'switch', []],
        'sort' => ['排序（小在前）', 'number', []],
        'status' => ['展示中', 'switch', []],
        'user_id' => ['关联后台账号（工作台登录）', 'user_select', []],
        'seo_title' => ['SEO 标题', 'text', []],
        'seo_keywords' => ['SEO 关键词', 'text', []],
        'seo_description' => ['SEO 描述', 'textarea', ['rows' => 2]],
    ];

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        if (empty($data['slug'])) {
            $data['slug'] = \app\Core\Str::slugify($data['name'] ?? '') ?: 'c-' . date('YmdHis');
        }
    }

    protected function afterSave(array $data, int $id, string $mode): void
    {
        // 保存可约时段（v2.17.0：每行「星期|开始|结束[|单次分钟]」，第 4 列可选默认 50，范围 10-240）
        $slots = (string)($_POST['slots_text'] ?? '');
        Database::delete('counselor_slots', 'counselor_id=?', [$id]);
        foreach (preg_split('/[\r\n]+/', trim($slots)) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) < 3 || !isset($parts[0], $parts[1], $parts[2])) continue;
            [$wd, $st, $et] = $parts;
            if (!isset($wd[0]) || (int)$wd < 1 || (int)$wd > 7) continue;
            $slotMin = (int)($parts[3] ?? 50);
            if ($slotMin < 10 || $slotMin > 240) $slotMin = 50;
            if (!preg_match('/^\d{1,2}:\d{2}$/', $st) || !preg_match('/^\d{1,2}:\d{2}$/', $et)) continue;
            Database::insert('counselor_slots', [
                'counselor_id' => $id, 'weekday' => (int)$wd,
                'start_time' => $st . ':00', 'end_time' => $et . ':00',
                'slot_minutes' => $slotMin, 'status' => 1,
            ]);
        }
    }

    protected function collect(Request $req): array
    {
        $data = parent::collect($req);
        $data['gender'] = in_array($req->post('gender', ''), ['1','2'], true) ? (int)$req->post('gender') : null;
        // v2.20.2 工作台账号绑定：空值→NULL；同账号重复绑定其他咨询师时提示（一账号一咨询师）
        $uid = (int)$req->post('user_id', 0);
        if ($uid > 0) {
            $dup = Database::fetch('SELECT id FROM counselors WHERE user_id=? AND id<>?', [$uid, (int)($req->post('id', 0) ?: 0)]);
            if ($dup) { \app\Core\Session::flash('error', '该后台账号已绑定其他咨询师（一个账号仅可绑定一位），已清空本次绑定'); $data['user_id'] = null; }
            else { $data['user_id'] = $uid; }
        } else {
            $data['user_id'] = null;
        }
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
