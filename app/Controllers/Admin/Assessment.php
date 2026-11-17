<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Database;
use app\Core\Request;

class Assessment extends Base
{
    protected string $permNode = 'assessments';
    protected string $title = '心理量表';
    protected string $baseSeg = 'assessments';

    public function index(Request $req, array $p): void
    {
        $rows = Database::fetchAll('SELECT id, slug, name, status FROM assessments ORDER BY sort, id');
        // 近 30 日提交统计
        $stats = [];
        foreach ($rows as &$r) {
            $r['submits_30d'] = (int)Database::value(
                'SELECT COUNT(*) FROM assessment_results WHERE assessment_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
                [$r['id']]
            );
            $dist = Database::fetchAll(
                'SELECT level, COUNT(*) c FROM assessment_results WHERE assessment_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY level ORDER BY c DESC LIMIT 5',
                [$r['id']]
            );
            $r['dist'] = $dist;
        }
        unset($r);
        $this->view('assessment/index', [
            'rows' => $rows,
            'title' => $this->title,
        ]);
    }

    /** 结果导出（隐私：不含 IP/答案明细） */
    public function export(Request $req, array $p): void
    {
        $rows = Database::fetchAll(
            'SELECT ar.id, a.name aname, ar.score, ar.level, ar.created_at
             FROM assessment_results ar LEFT JOIN assessments a ON a.id=ar.assessment_id
             ORDER BY ar.id DESC LIMIT 5000'
        );
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="assessment-results-' . date('Ymd-Hi') . '.csv"');
        echo "\xEF\xBB\xBF";
        $f = fopen('php://output', 'w');
        fputcsv($f, ['ID', '量表', '分数', '分级', '提交时间']);
        foreach ($rows as $r) { fputcsv($f, [$r['id'], $r['aname'], $r['score'], $r['level'], $r['created_at']]); }
        fclose($f);
        $this->audit('assessment.export');
        exit;
    }

    public function toggle(Request $req, array $p): void
    {
        \app\Core\Csrf::guard();
        $id = (int)$p['id'];
        Database::run('UPDATE assessments SET status = 1 - status WHERE id=?', [$id]);
        $this->audit('assessment.toggle', 'assessments', $id);
        \app\Core\ResponseRedirect('/admin/assessments');
    }
}
