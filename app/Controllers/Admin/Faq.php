<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

class Faq extends Base
{
    protected string $table = 'faqs';
    protected string $permNode = 'faqs';
    protected string $title = '常见问题管理';
    protected string $orderBy = 'sort, id';
    protected string $baseSeg = 'faqs';
    protected array $searchFields = ['question'];
    protected string $toggleCol = 'status';
    protected array $columns = [
        'question' => ['问题', 'text'],
        'sort' => ['排序', 'number'],
        'status' => ['展示', 'yesno'],
    ];
    protected array $fields = [
        'question' => ['问题', 'text', ['required' => true]],
        'answer' => ['回答', 'richtext', []],
        'sort' => ['排序（小在前）', 'number', []],
        'status' => ['展示中', 'switch', []],
    ];

    protected function afterSave(array $data, int $id, string $mode): void
    {
        // FAQ 变化 → 同步知识库
        if (!empty($data['question']) && !empty($data['answer'])) {
            $exist = \app\Core\Database::fetch('SELECT id FROM ai_kb WHERE question=?', [$data['question']]);
            $row = [
                'question' => $data['question'],
                'answer' => trim(strip_tags((string)$data['answer'])),
                'keywords' => $data['question'],
                'source' => 'auto_faq',
                'enabled' => 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($exist) {
                \app\Core\Database::update('ai_kb', $row, 'id=?', [$exist['id']]);
            } else {
                $row['created_at'] = date('Y-m-d H:i:s');
                \app\Core\Database::insert('ai_kb', $row);
            }
        }
    }

    protected function afterDelete(int $id): void {}
}
