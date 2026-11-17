<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

class Banner extends Base
{
    protected string $table = 'banners';
    protected string $permNode = 'banners';
    protected string $title = '轮播图管理';
    protected string $orderBy = 'sort, id';
    protected string $baseSeg = 'banners';
    protected string $toggleCol = 'status';
    protected array $columns = [
        'title' => ['标题', 'text'],
        'image' => ['图片', 'image_cell'],
        'link' => ['链接', 'text'],
        'sort' => ['排序', 'number'],
        'status' => ['展示', 'yesno'],
    ];
    protected array $fields = [
        'title' => ['标题（也作图片 alt）', 'text', []],
        'image' => ['图片', 'image', ['required' => true]],
        'link' => ['跳转链接', 'text', []],
        'sort' => ['排序（小在前）', 'number', []],
        'status' => ['展示中', 'switch', []],
    ];
}
