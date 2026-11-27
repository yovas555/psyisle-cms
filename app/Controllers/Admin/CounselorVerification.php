<?php
declare(strict_types=1);

namespace app\Controllers\Admin;

use app\Core\Csrf;
use app\Core\Database;
use app\Core\Request;
use app\Core\Session;
use app\Services\CounselorTrust;
use app\Services\CounselorProfileReview;

class CounselorVerification extends Base
{
    protected string $permNode = 'counselors';
    protected string $title = '咨询师信任信息核验';

    public function show(Request $req, array $p): void
    {
        $id = (int)($p['id'] ?? 0);
        $counselor = Database::fetch('SELECT * FROM counselors WHERE id=?', [$id]);
        if (!$counselor) {
            Session::flash('error', '咨询师不存在');
            \app\Core\ResponseRedirect('/admin/counselors');
        }
        if ($req->isPost()) {
            Csrf::guard();
            $actor = Session::get('admin_user');
            try {
                $verificationId = Database::transaction(function () use ($id, $counselor, $req, $actor): int {
                    $locked = Database::fetch('SELECT * FROM counselors WHERE id=? FOR UPDATE', [$id]);
                    if (!$locked) throw new \InvalidArgumentException('咨询师不存在');
                    $data = CounselorTrust::record(
                        $id, $locked, $req->posts(), (int)$actor['id'],
                        (string)(($actor['display_name'] ?? '') ?: ($actor['username'] ?? '管理员'))
                    );
                    return Database::insert('counselor_verifications', $data);
                });
                $this->audit('counselor.verification_recorded', 'counselors', $id, [
                    'verification_id' => $verificationId,
                    'item_key' => (string)$req->post('item_key', ''),
                    'status' => (string)$req->post('status', ''),
                ]);
                Session::flash('success', '核验记录已追加；公开状态由系统按记录、有效期与资料快照计算');
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            }
            \app\Core\ResponseRedirect('/admin/counselors/' . $id . '/verification');
        }
        $latest = CounselorTrust::latestFor($id);
        $states = [];
        foreach (array_keys(CounselorTrust::ITEMS) as $key) {
            $states[$key] = CounselorTrust::evaluate($counselor, $latest[$key] ?? null, null, $key);
        }
        $this->view('counselor-verification', [
            'counselor' => $counselor,
            'states' => $states,
            'latest' => $latest,
            'history' => CounselorTrust::history($id),
        ]);
    }

    public function reviews(Request $req, array $p): void
    {
        $rows = Database::fetchAll(
            "SELECT s.*, c.name counselor_name, c.slug, u.username submitter_name
             FROM counselor_profile_submissions s
             INNER JOIN counselors c ON c.id=s.counselor_id
             LEFT JOIN users u ON u.id=s.submitted_by
             WHERE s.status='pending' ORDER BY s.id ASC"
        );
        $this->view('counselor-profile-reviews', [
            'title' => '资质资料审核队列',
            'rows' => $rows,
            'pendingCount' => count($rows),
        ]);
    }

    public function review(Request $req, array $p): void
    {
        $id = (int)($p['id'] ?? 0);
        $submission = Database::fetch(
            "SELECT s.*, c.name counselor_name, c.slug, u.username submitter_name
             FROM counselor_profile_submissions s
             INNER JOIN counselors c ON c.id=s.counselor_id
             LEFT JOIN users u ON u.id=s.submitted_by WHERE s.id=?",
            [$id]
        );
        if (!$submission) {
            Session::flash('error', '资质审核单不存在');
            \app\Core\ResponseRedirect('/admin/counselor-profile-reviews');
        }
        $counselor = Database::fetch('SELECT * FROM counselors WHERE id=?', [(int)$submission['counselor_id']]);
        if (!$counselor) {
            Session::flash('error', '咨询师不存在');
            \app\Core\ResponseRedirect('/admin/counselor-profile-reviews');
        }
        $this->view('counselor-profile-review', [
            'title' => '资质资料审核',
            'submission' => $submission,
            'counselor' => $counselor,
            'draft' => CounselorProfileReview::decodeDraft((string)$submission['payload'], $counselor),
            'current' => CounselorProfileReview::publicFields($counselor),
            'attachments' => CounselorProfileReview::attachments((int)$counselor['id'], $id),
            'conflict' => !hash_equals((string)$submission['base_hash'], CounselorProfileReview::baseHash($counselor)),
        ]);
    }

    public function reviewAction(Request $req, array $p): void
    {
        Csrf::guard();
        $id = (int)($p['id'] ?? 0);
        $actor = Session::get('admin_user');
        $action = (string)$req->post('action', '');
        try {
            CounselorProfileReview::review($id, $action, (int)$actor['id'], (string)$req->post('review_note', ''));
            $this->audit('counselor.profile_reviewed', 'counselor_profile_submissions', $id, ['action' => $action]);
            Session::flash('success', $action === 'approve' ? '资质资料已批准发布' : '资质资料已驳回并通知咨询师修改');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            Session::flash('error', '审核操作失败，请稍后重试');
        }
        \app\Core\ResponseRedirect('/admin/counselor-profile-reviews/' . $id);
    }

    public function evidenceDownload(Request $req, array $p): void
    {
        $attachment = CounselorProfileReview::evidenceForViewer((int)($p['id'] ?? 0), 0, true);
        if (!$attachment) { http_response_code(404); echo 'not found'; exit; }
        CounselorProfileReview::streamEvidence($attachment);
    }
}
