<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

class Link extends Base
{
    protected string $table = 'links';
    protected string $permNode = 'links';
    protected string $title = '友情链接';
    protected string $orderBy = 'sort, id';
    protected string $baseSeg = 'links';
    protected string $toggleCol = 'status';
    protected array $columns = [
        'name' => ['名称', 'text'],
        'url' => ['链接', 'text'],
        'sort' => ['排序', 'number'],
        'status' => ['展示', 'yesno'],
    ];
    protected array $fields = [
        'name' => ['名称', 'text', ['required' => true]],
        'url' => ['链接', 'text', ['required' => true]],
        'logo' => ['LOGO 图', 'image', []],
        'sort' => ['排序', 'number', []],
        'status' => ['展示中', 'switch', []],
    ];
}
