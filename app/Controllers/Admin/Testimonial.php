<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

class Testimonial extends Base
{
    protected string $table = 'testimonials';
    protected string $permNode = 'testimonials';
    protected string $title = '会员心声';
    protected string $orderBy = 'sort, id';
    protected string $baseSeg = 'testimonials';
    protected string $toggleCol = 'status';
    protected array $columns = [
        'alias' => ['化名', 'text'],
        'content' => ['内容', 'text'],
        'meta' => ['备注', 'text'],
        'status' => ['展示', 'yesno'],
    ];
    protected array $fields = [
        'alias' => ['化名（脱敏）', 'text', ['required' => true, 'hint' => '如：L 女士 / 匿名。展示前必须获会员授权并去标识。']],
        'content' => ['心声内容', 'textarea', ['required' => true]],
        'meta' => ['备注（如：情绪困扰 · 咨询 12 次）', 'text', []],
        'sort' => ['排序（小在前）', 'number', []],
        'status' => ['展示中', 'switch', []],
    ];
}
