<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;

class User extends Base
{
    protected string $table = 'users';
    protected string $permNode = 'users';
    protected string $title = '用户与角色';
    protected string $baseSeg = 'users';
    protected array $columns = [
        'username' => ['用户名', 'text'],
        'display_name' => ['显示名', 'text'],
        'role_id' => ['角色', 'role'],
        'status' => ['启用', 'yesno'],
        'last_login_at' => ['最近登录', 'datetime'],
    ];
    protected array $fields = [
        'username' => ['用户名', 'text', ['required' => true]],
        'display_name' => ['显示名', 'text', []],
        'email' => ['邮箱', 'text', []],
        'role_id' => ['角色', 'role_select', []],
        'password' => ['密码', 'password', ['hint' => \app\Core\PasswordPolicy::RULE_TEXT . '；编辑时留空表示不修改']],
        'status' => ['启用', 'switch', []],
    ];

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        $pwd = (string)($_POST['password'] ?? '');
        unset($data['password']);
        if ($pwd !== '') {
            self::assertStrongPassword($pwd);
            if ($mode === 'update') {
                self::assertNotReused((int)$id, $pwd);
            }
            $data['password_hash'] = password_hash($pwd, PASSWORD_DEFAULT);
            if ($mode === 'update') {
                self::pushPasswordHistory((int)$id);
            }
        } elseif ($mode === 'create') {
            throw new \RuntimeException('新建用户必须设置密码');
        }
        if ($mode === 'create') {
            if (Database::value('SELECT COUNT(*) FROM users WHERE username=?', [$data['username']]) > 0) {
                throw new \RuntimeException('用户名已存在');
            }
        }
        // 防自杀式操作：不能禁用/删除自己
        $me = Session::get('admin_user');
        if ($id && $me && (int)$id === (int)$me['id'] && empty($data['status'])) {
            throw new \RuntimeException('不能禁用当前登录账号');
        }
    }

    public function delete(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)$p['id'];
        $me = Session::get('admin_user');
        if ($me && (int)$id === (int)$me['id']) {
            Session::flash('error', '不能删除当前登录账号');
            \app\Core\ResponseRedirect('/admin/users');
        }
        if (Database::count('users') <= 1) {
            Session::flash('error', '至少保留一个管理员账号');
            \app\Core\ResponseRedirect('/admin/users');
        }
        Database::delete('users', 'id=?', [$id]);
        $this->audit('user.delete', 'users', $id);
        Session::flash('success', '已删除');
        \app\Core\ResponseRedirect('/admin/users');
    }

    /** 两步验证：生成新密钥（待确认） */
    public function totpSetup(Request $req, array $p): void
    {
        $me = Session::get('admin_user');
        $secret = \app\Core\Totp::generateSecret();
        Session::set('totp_setup', $secret);
        $this->view('user/totp-setup', [
            'secret' => $secret,
            'uri' => \app\Core\Totp::uri($secret, (string)setting('site.name', 'PsyIsle'), (string)$me['username']),
            'title' => '开启两步验证',
        ]);
    }

    /** 确认开启：验证一次动态码后才落库 */
    public function totpEnable(Request $req, array $p): void
    {
        Csrf::guard();
        $me = Session::get('admin_user');
        $secret = (string)Session::get('totp_setup');
        $code = (string)$req->post('code', '');
        if ($secret === '' || !\app\Core\Totp::verify($secret, $code)) {
            Session::flash('error', '动态码不正确，请确认验证器时间与录入的密钥');
            \app\Core\ResponseRedirect('/admin/users/totp-setup');
        }
        Database::update('users', ['totp_secret' => $secret], 'id=?', [$me['id']]);
        Session::forget('totp_setup');
        $this->audit('user.totp.enable');
        Session::flash('success', '两步验证已开启：下次登录需输入验证器动态码');
        \app\Core\ResponseRedirect('/admin/users');
    }

    public function totpDisable(Request $req, array $p): void
    {
        Csrf::guard();
        $me = Session::get('admin_user');
        Database::update('users', ['totp_secret' => null], 'id=?', [$me['id']]);
        $this->audit('user.totp.disable');
        Session::flash('success', '两步验证已关闭');
        \app\Core\ResponseRedirect('/admin/users');
    }

    /** 角色权限说明（写死与 seed 一致，权限节点生效于 Base 构造器） */
    private function roleDocs(): array
    {
        return [
            ['admin', '超级管理员', '["*"]', '全部功能，含授权/升级/审计/用户管理'],
            ['editor', '内容编辑', 'articles, pages, categories, faqs, banners, navs, links, media, counselors, services, testimonials', '管理内容与机构资料，不能处理预约/留言/AI，不能进系统设置'],
            ['assistant', '预约助理', 'bookings, messages, chat, assessments', '处理预约/留言/AI 会话/量表记录，不能改内容'],
            ['viewer', '只读观察', '（无模块权限）', '仅仪表盘与通知中心'],
        ];
    }

    public function roles(Request $req, array $p): void
    {
        $this->view('user/index', [
            'roleDocs' => $this->roleDocs(),
            'roles' => Database::fetchAll('SELECT * FROM roles ORDER BY id'),
            'title' => '角色权限说明',
        ]);
    }

    public function changePassword(Request $req, array $p): void
    {
        Csrf::guard();
        $me = Session::get('admin_user');
        $old = (string)$req->post('old_password', '');
        $new = (string)$req->post('new_password', '');
        $u = Database::fetch('SELECT * FROM users WHERE id=?', [$me['id']]);
        if (!$u || !password_verify($old, (string)$u['password_hash'])) {
            Session::flash('error', '原密码不正确');
            \app\Core\ResponseRedirect('/admin/users');
        }
        try { self::assertStrongPassword($new); }
        catch (\RuntimeException $e) { Session::flash('error', $e->getMessage()); \app\Core\ResponseRedirect('/admin/users'); }
        self::assertNotReused((int)$u['id'], $new);
        self::pushPasswordHistory((int)$u['id']);
        Database::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id=?', [$u['id']]);
        $this->audit('user.password');
        Session::flash('success', '密码已修改');
        \app\Core\ResponseRedirect('/admin/users');
    }

    /** 统一密码策略：至少 8 位，四类字符任选两类或以上 */
    private static function assertStrongPassword(string $pwd): void
    {
        $err = \app\Core\PasswordPolicy::check($pwd);
        if ($err !== null) {
            throw new \RuntimeException($err);
        }
    }

    /** 密码历史（最近 5 次不可重用） */
    private static function assertNotReused(int $uid, string $pwd): void
    {
        $u = Database::fetch('SELECT password_hash, password_history FROM users WHERE id=?', [$uid]);
        if (!$u) return;
        if (password_verify($pwd, (string)$u['password_hash'])) {
            throw new \RuntimeException('新密码不能与当前密码相同');
        }
        $hist = json_decode((string)$u['password_history'], true) ?: [];
        foreach ($hist as $h) {
            if (password_verify($pwd, (string)$h)) {
                throw new \RuntimeException('新密码与最近使用过的密码重复');
            }
        }
    }

    private static function pushPasswordHistory(int $uid): void
    {
        $u = Database::fetch('SELECT password_hash, password_history FROM users WHERE id=?', [$uid]);
        if (!$u) return;
        $hist = json_decode((string)$u['password_history'], true) ?: [];
        $hist[] = (string)$u['password_hash'];
        $hist = array_slice($hist, -5);
        Database::update('users', ['password_history' => json_encode($hist)], 'id=?', [$uid]);
    }

    /** 角色列表页复用 index 视图（简化：角色表固定 4 个系统角色 + 自定义） */
    public function rolesJson(): array
    {
        return Database::fetchAll('SELECT * FROM roles');
    }
}
