<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Database;

class Category extends Base
{
    protected string $table = 'categories';
    protected string $permNode = 'categories';
    protected string $title = '栏目管理';
    protected string $orderBy = 'type, sort, id';
    protected string $baseSeg = 'categories';
    protected array $columns = [
        'name' => ['名称', 'text'],
        'type' => ['类型', 'text'],
        'slug' => ['URL', 'text'],
        'sort' => ['排序', 'number'],
        'status' => ['状态', 'yesno'],
    ];
    protected array $fields = [
        'name' => ['名称', 'text', ['required' => true]],
        'type' => ['类型', 'type_select', []],
        'slug' => ['URL 别名', 'text', []],
        'description' => ['描述', 'textarea', ['rows' => 2]],
        'seo_title' => ['SEO 标题', 'text', []],
        'seo_keywords' => ['SEO 关键词', 'text', []],
        'seo_description' => ['SEO 描述', 'textarea', ['rows' => 2]],
        'sort' => ['排序（小在前）', 'number', []],
        'status' => ['启用', 'switch', []],
    ];

    protected function beforeSave(array &$data, ?int $id, string $mode): void
    {
        if (empty($data['slug'])) {
            $data['slug'] = \app\Core\Str::slugify($data['name'] ?? '') ?: 'cat-' . date('YmdHis');
        }
    }
}
