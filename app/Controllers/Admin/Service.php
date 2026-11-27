<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

class Service extends Base
{
    protected string $table = 'services';
    protected string $permNode = 'services';
    protected string $title = '服务项目管理';
    protected string $orderBy = 'is_featured DESC, sort, id';
    protected string $baseSeg = 'services';
    protected array $searchFields = ['name'];
    protected string $toggleCol = 'status';
    protected array $columns = [
        'name' => ['名称', 'text'],
        'format' => ['形式', 'text'],
        'price_from' => ['收费起', 'text'],
        'is_featured' => ['推荐', 'yesno'],
        'status' => ['展示', 'yesno'],
    ];
    protected array $fields = [
        'name' => ['名称', 'text', ['required' => true]],
        'slug' => ['URL 别名', 'text', []],
        'cover' => ['封面图', 'image', []],
        'summary' => ['摘要', 'textarea', ['rows' => 3]],
        'content' => ['详细介绍', 'richtext', []],
        'format' => ['咨询形式', 'text', ['hint' => '如：面对面 / 视频 / 电话']],
        'duration' => ['单次时长', 'text', ['hint' => '如：50分钟']],
        'price_from' => ['收费起（元）', 'decimal', []],
        'price_to' => ['收费止（元）', 'decimal', []],
        'price_note' => ['收费说明', 'text', []],
        'is_featured' => ['首页推荐', 'switch', []],
        'sort' => ['排序（小在前）', 'number', []],
        'status' => ['展示中', 'switch', []],
        'seo_title' => ['SEO 标题', 'text', []],
        'seo_keywords' => ['SEO 关键词', 'text', []],
        'seo_description' => ['SEO 描述', 'textarea', ['rows' => 2]],
    ];

    protected function collect(\app\Core\Request $req): array
    {
        $data = parent::collect($req);
        foreach (['price_from', 'price_to'] as $field) {
            $raw = $req->post($field, '');
            if ($raw !== '' && !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', (string)$raw)) $data[$field] = -1.0;
        }
        return $data;
    }

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            \app\Core\Session::flash('error', '服务项目名称不能为空');
            \app\Core\ResponseRedirect('/admin/services' . ($id ? '/' . $id . '/edit' : '/create'));
        }
        $data['name'] = $name;
        foreach (['price_from', 'price_to'] as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && $value !== '' && !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', (string)$value)) {
                \app\Core\Session::flash('error', '收费必须是 0 到 9999999.99 之间的数字');
                \app\Core\ResponseRedirect('/admin/services' . ($id ? '/' . $id . '/edit' : '/create'));
            }
            $data[$field] = $value === null || $value === '' ? null : round((float)$value, 2);
        }
        if ($data['price_from'] !== null && $data['price_to'] !== null && $data['price_from'] > $data['price_to']) {
            \app\Core\Session::flash('error', '收费起不能高于收费止');
            \app\Core\ResponseRedirect('/admin/services' . ($id ? '/' . $id . '/edit' : '/create'));
        }
        if (empty($data['slug'])) {
            $data['slug'] = \app\Core\Str::slugify($data['name']) ?: 's-' . date('YmdHis');
        }
        $data['slug'] = mb_substr((string)$data['slug'], 0, 60);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', (string)$data['slug'])) {
            \app\Core\Session::flash('error', 'URL 别名只能使用字母、数字、连字符或下划线');
            \app\Core\ResponseRedirect('/admin/services' . ($id ? '/' . $id . '/edit' : '/create'));
        }
    }

    protected function afterDelete(int $id): void
    {
        \app\Core\Database::delete('counselor_services', 'service_id=?', [$id]);
        parent::afterDelete($id);
    }
}
