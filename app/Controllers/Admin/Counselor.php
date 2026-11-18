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
        return $data;
    }
}
