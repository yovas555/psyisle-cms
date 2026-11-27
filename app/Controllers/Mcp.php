<?php
declare(strict_types=1);

namespace app\Controllers;

use app\Core\Database;
use app\Core\RateLimiter;
use app\Core\Request;

/**
 * MCP Server（v2.18 · Model Context Protocol over Streamable HTTP）
 * ------------------------------------------------------------------
 * 让 AI Agent（Claude/ChatGPT/自建 agent）可以直接「查询机构信息/服务/咨询师/
 * 可约时段/预约规则」并「代提交预约申请」。零依赖实现（JSON-RPC 2.0 over POST）。
 *
 * 开关：settings mcp_enabled=1 才启用（默认关）。限流 30 次/分钟/IP。
 * 隐私：只暴露公开信息；create_booking_request 落 bookings（status=pending，
 * 走申请通道+booking.validate 钩子+限流 5 次/小时/IP），回显手机号脱敏。
 * 安全：本端点绝不输出会员数据；预约内容与人工申请同库同审。
 */
class Mcp
{
    public function handle(Request $req): void
    {
        if (!(bool)setting('mcp_enabled', '0')) {
            // 默认关闭：403 + JSON-RPC 错误（200 会误导监控以为端点可用，也便于扫描器识别为禁用）
            http_response_code(403);
            $this->rpc(null, -32601, 'MCP server disabled');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            header('Allow: POST');
            $this->rpc(null, -32000, 'POST only (Streamable HTTP)');
        }
        if (!RateLimiter::hit('mcp', $req->ip(), 30, 60)) {
            $this->rpc(null, -32000, 'rate limited');
        }
        $raw = file_get_contents('php://input');
        $msg = json_decode((string)$raw, true);
        if (!is_array($msg) || !isset($msg['method'])) {
            $this->rpc(null, -32700, 'Parse error');
        }
        $id = $msg['id'] ?? null;
        $method = (string)$msg['method'];
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];

        switch ($method) {
            case 'initialize':
                $this->rpc($id, null, [
                    'protocolVersion' => '2025-03-26',
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => [
                        'name' => (string)setting('site.name', '') . ' · PsyIsle MCP',
                        'version' => CMS_VERSION,
                    ],
                ]);
                break;
            case 'notifications/initialized':
                http_response_code(202);
                echo '{}';
                exit;
            case 'ping':
                $this->rpc($id, null, new \stdClass());
                break;
            case 'tools/list':
                $this->rpc($id, null, ['tools' => self::toolsSpec()]);
                break;
            case 'tools/call':
                $name = (string)($params['name'] ?? '');
                $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
                $this->rpc($id, null, self::callTool($name, $args, $req));
                break;
            default:
                $this->rpc($id, -32601, 'Method not found: ' . $method);
        }
    }

    /** 工具结果统一封装（MCP content 类型） */
    private static function tool(string $text, bool $isError = false): array
    {
        $r = ['content' => [['type' => 'text', 'text' => $text]]];
        if ($isError) $r['isError'] = true;
        return $r;
    }

    private static function callTool(string $name, array $args, Request $req): array
    {
        try {
            switch ($name) {
                case 'site_info':
                    return self::tool(json_encode([
                        'name' => setting('site.name', ''),
                        'description' => setting('seo.description', ''),
                        'phone' => setting('contact.phone', ''),
                        'address' => setting('contact.address', ''),
                        'hours' => setting('contact.hours', ''),
                        'booking_url' => url('/booking'),
                        'crisis_hotline' => '12356',
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                case 'list_services':
                    $rows = Database::fetchAll("SELECT name, slug, summary, format, duration, price_from, price_to FROM services WHERE status=1 ORDER BY sort LIMIT 30");
                    $list = array_map(fn($s) => [
                        'name' => $s['name'],
                        'url' => url('/services/' . $s['slug']),
                        'summary' => str_take((string)$s['summary'], 120, ''),
                        'format' => $s['format'], 'duration' => $s['duration'],
                        'price_from' => (float)$s['price_from'] ?: null, 'price_to' => (float)$s['price_to'] ?: null,
                    ], $rows);
                    return self::tool(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                case 'list_counselors':
                    $rows = Database::fetchAll("SELECT id, name, slug, title, specialties, credentials, supervision, audiences, unsuitable_topics, personal_therapy FROM counselors WHERE status=1 ORDER BY sort LIMIT 30");
                    $latest = \app\Services\CounselorTrust::latestForMany($rows);
                    $list = array_map(function ($c) use ($latest) {
                        $trust = \app\Services\CounselorTrust::publicProfile($c, $latest[(int)$c['id']] ?? []);
                        return [
                            'name' => $c['name'], 'title' => $c['title'],
                            'specialties' => $c['specialties'], 'url' => url('/counselors/' . $c['slug']),
                            'service_boundaries' => ['audiences' => $trust['audiences'], 'unsuitable_topics' => $trust['unsuitable_topics']],
                            'verification_status' => array_combine(array_keys($trust['verifications']), array_map(fn($v) => $v['status'], $trust['verifications'])),
                        ];
                    }, $rows);
                    return self::tool(json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                case 'booking_rules':
                    return self::tool(json_encode([
                        'how' => '在线表单 ' . url('/booking') . '；或从咨询师页面的可约时段直接选择（无需等待机构确认）',
                        'lead_hours' => (int)setting('sched.lead_hours', '2'),
                        'cancel_policy' => '距咨询开始 24 小时以上可在会员自助中心取消/改期',
                        'require_member' => (bool)setting('booking.require_member', '1'),
                        'note' => '提交预约申请后以机构最终确认信息为准；危机情况请直接拨打 12356',
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                case 'list_slots':
                    $cid = (int)($args['counselor_id'] ?? 0);
                    $slug = trim((string)($args['counselor_slug'] ?? ''));
                    if ($cid <= 0 && $slug !== '') {
                        $cid = (int)(Database::value('SELECT id FROM counselors WHERE slug=? AND status=1', [$slug]) ?? 0);
                    }
                    if ($cid <= 0) return self::tool('需要 counselor_id 或 counselor_slug 参数', true);
                    $days = min(14, max(1, (int)($args['days'] ?? 7)));
                    $cal = \app\Services\BookingSlots::days($cid, $days, 1, true);
                    $out = array_map(fn($d) => [
                        'date' => $d['ymd'], 'week' => $d['week'],
                        'closed_note' => $d['closed_note'],
                        'slots' => array_map(fn($s) => ['start' => $s['start'], 'end' => $s['end'], 'available' => $s['available']], $d['slots']),
                    ], $cal);
                    return self::tool(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                case 'create_booking_request':
                    $name = trim((string)($args['name'] ?? ''));
                    $phone = trim((string)($args['phone'] ?? ''));
                    $date = trim((string)($args['prefer_date'] ?? ''));
                    $slot = trim((string)($args['prefer_slot'] ?? ''));
                    $issue = trim((string)($args['issue'] ?? ''));
                    if ($name === '' || $phone === '' || $date === '') return self::tool('name/phone/prefer_date 为必填', true);
                    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) return self::tool('手机号格式不正确', true);
                    if (!RateLimiter::hit('mcp_booking', $req->ip() . '|' . $phone, 5, 3600)) {
                        return self::tool('预约提交过于频繁（每小时 5 次上限），请稍后再试', true);
                    }
                    // 走人工确认的申请通道（与前台表单同库同审，机构确认后成立）
                    $data = [
                        'name' => $name, 'phone' => $phone,
                        'method' => in_array((string)($args['method'] ?? ''), ['面对面', '视频', '电话'], true) ? (string)$args['method'] : '面对面',
                        'prefer_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '',
                        'prefer_slot' => mb_substr($slot, 0, 59),
                        'issue' => mb_substr($issue, 0, 999),
                        'source' => 'web',
                    ];
                    $hookErr = \app\Core\Hook::fire('booking.validate', ['data' => $data, 'error' => null])['error'] ?? null;
                    if ($hookErr) return self::tool('提交被拒：' . $hookErr, true);
                    $code = gen_code(8);
                    $id = Database::insert('bookings', [
                        'code' => $code,
                        'counselor_id' => (int)($args['counselor_id'] ?? 0) ?: null,
                        'name' => $name, 'phone' => $phone,
                        'method' => $data['method'],
                        'prefer_date' => $data['prefer_date'] ?: null,
                        'prefer_slot' => $data['prefer_slot'],
                        'issue' => $data['issue'],
                        'status' => 'pending',
                        'pay_status' => 'free',
                        'source' => 'web',
                        'ip' => $req->ip(),
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                    \app\Services\BookingLifecycle::ensureSnapshot((int)$id);
                    \app\Services\NotifyService::push('booking', '收到新预约（AI Agent 代提交）',
                        "姓名：{$name}\n手机：" . mask_phone($phone) . "\n期望：{$date} {$slot}\n方式：{$data['method']}\n查询码：{$code}\n来源：MCP（AI 助手代提交，以机构确认为准）",
                        ['type' => 'booking', 'id' => $id], true);
                    return self::tool(json_encode([
                        'ok' => true, 'booking_code' => $code,
                        'status' => 'pending（等待机构确认）',
                        'note' => '预约申请已提交，以机构最终确认信息为准。查询进度：登录会员中心「我的预约」',
                        'phone_masked' => mask_phone($phone),
                    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                default:
                    return self::tool('未知工具: ' . $name, true);
            }
        } catch (\Throwable $t) {
            \app\Core\Logger::error('mcp', 'tool call failed: ' . $t->getMessage(), ['tool' => $name]);
            return self::tool('工具执行出错，请稍后再试', true);
        }
    }

    private static function toolsSpec(): array
    {
        $t = function (string $name, string $desc, $props, array $req = []) {
            return [
                'name' => $name, 'description' => $desc,
                'inputSchema' => ['type' => 'object', 'properties' => (object)(array)$props, 'required' => $req],
            ];
        };
        return [
            $t('site_info', '获取机构基本信息（名称/简介/公开电话/地址/营业时间/危机热线）', new \stdClass()),
            $t('list_services', '列出咨询服务（名称/形式/时长/价格区间）', new \stdClass()),
            $t('list_counselors', '列出咨询师（姓名/职称/擅长方向）', new \stdClass()),
            $t('booking_rules', '获取预约规则（方式/提前截止/取消政策/是否要求会员）', new \stdClass()),
            $t('list_slots', '查询某咨询师未来 N 天可约时段', [
                'counselor_id' => ['type' => 'integer', 'description' => '咨询师 ID（与 counselor_slug 二选一）'],
                'counselor_slug' => ['type' => 'string', 'description' => '咨询师 slug（如 zhu-laoshi）'],
                'days' => ['type' => 'integer', 'description' => '未来天数 1-14，默认 7'],
            ]),
            $t('create_booking_request', '代来访者提交预约申请（走人工确认通道；危机情况请引导拨打 12356 而非代办）', [
                'name' => ['type' => 'string', 'description' => '来访者称呼'],
                'phone' => ['type' => 'string', 'description' => '11 位手机号'],
                'prefer_date' => ['type' => 'string', 'description' => '期望日期 YYYY-MM-DD'],
                'prefer_slot' => ['type' => 'string', 'description' => '期望时段描述，如 工作日晚间'],
                'method' => ['type' => 'string', 'enum' => ['面对面', '视频', '电话']],
                'counselor_id' => ['type' => 'integer', 'description' => '可选：指定咨询师'],
                'issue' => ['type' => 'string', 'description' => '想咨询的方向（选填）'],
            ], ['name', 'phone', 'prefer_date']),
        ];
    }

    /** JSON-RPC 2.0 应答并结束 */
    private function rpc($id, ?int $errCode, $resultOrMsg): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Mcp-Protocol-Version: 2025-03-26');
        if ($errCode !== null) {
            echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $errCode, 'message' => (string)$resultOrMsg]], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $resultOrMsg], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}
