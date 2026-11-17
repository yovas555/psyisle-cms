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

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        if (empty($data['slug'])) {
            $data['slug'] = \app\Core\Str::slugify($data['name'] ?? '') ?: 's-' . date('YmdHis');
        }
    }
}
