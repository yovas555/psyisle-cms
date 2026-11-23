<?php /** 咨询师工作台 · 个案管理（P1-A） */ ?>
<div class="dash-grid">
  <div class="panel">
    <h2>个案概览</h2>
    <div class="toolbar">
      <span class="chip">进行中 <b><?= (int)$stats['active'] ?></b></span>
      <span class="chip">已结案 <?= (int)$stats['closed'] ?></span>
      <span class="chip<?= $stats['high_risk'] ? ' err-text' : '' ?>">高风险 <?= (int)$stats['high_risk'] ?></span>
      <span class="chip">本月会次 <?= (int)$stats['sessions_month'] ?></span>
    </div>
    <p class="hint">个案只对主责咨询师可见。一个来访者同时只能有一个进行中的个案，结案后如需继续请另开新案。</p>
  </div>
</div>

<div class="panel" style="margin-top:14px">
  <h2>新建个案</h2>
  <?php if (!$members): ?>
  <p class="muted">暂无可开案的来访者。只有与您有过预约的来访者才能建档。</p>
  <?php else: ?>
  <form class="form" method="post" action="/admin/counselor-home/cases">
    <?= csrf_field() ?>
    <div class="form-grid">
      <label class="field"><span class="label">来访者 <em>*</em></span>
        <select name="member_id" required>
          <option value="">选择来访者</option>
          <?php foreach ($members as $m): ?>
          <option value="<?= (int)$m['id'] ?>"><?= e(\app\Services\MemberService::maskPhone((string)$m['phone'])) ?><?= ($m['name'] ?? '') !== '' ? '（' . e((string)$m['name']) . '）' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span class="label">个案名称 <em>*</em></span>
        <input type="text" name="title" required maxlength="120" placeholder="去标识，如：大一适应-初诊 / 亲子冲突">
        <span class="hint">不要写真实姓名、单位等可识别信息</span>
      </label>
      <label class="field"><span class="label">风险分级</span>
        <select name="risk_level">
          <?php foreach ($riskLabels as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= $k === 'low' ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field"><span class="label">开案日期</span>
        <input type="date" name="opened_at" value="<?= date('Y-m-d') ?>">
      </label>
      <label class="field field-full"><span class="label">主诉概述</span>
        <textarea name="presenting" rows="3" maxlength="500" placeholder="来访者带来的主要困扰、持续时间、影响范围（去标识）"></textarea>
      </label>
      <label class="field field-full"><span class="label">咨询目标与计划</span>
        <textarea name="goals" rows="3" maxlength="1000" placeholder="如：8 次为一段，前 3 次建立关系与评估，中期处理回避模式"></textarea>
      </label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">建立个案</button></div>
  </form>
  <?php endif; ?>
</div>

<div class="panel" style="margin-top:14px">
  <h2>我的个案（<?= count($rows) ?>）</h2>
  <form class="toolbar" method="get" action="/admin/counselor-home/cases" style="gap:8px">
    <select name="status">
      <option value="">全部状态</option>
      <?php foreach ($statusLabels as $k => $v): ?>
      <option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($v) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="q" value="<?= e($kw) ?>" placeholder="个案名 / 编号 / 手机 / 姓名" style="min-width:220px">
    <button class="btn btn-sm" type="submit">筛选</button>
    <?php if ($status !== '' || $kw !== ''): ?><a class="btn btn-sm btn-ghost" href="/admin/counselor-home/cases">清空</a><?php endif; ?>
  </form>
  <?php if ($rows): ?>
  <table class="table">
    <tr><th>编号</th><th>个案</th><th>来访者</th><th>风险</th><th>会次</th><th>最近一次</th><th>状态</th><th>操作</th></tr>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td class="muted"><?= e((string)$r['case_no']) ?></td>
      <td><b><?= e((string)$r['title']) ?></b></td>
      <td><?= e(\app\Services\MemberService::maskPhone((string)($r['m_phone'] ?? ''))) ?><?= ($r['m_name'] ?? '') !== '' ? '（' . e((string)$r['m_name']) . '）' : '' ?></td>
      <td><?= e($riskLabels[(string)$r['risk_level']] ?? (string)$r['risk_level']) ?></td>
      <td><?= (int)$r['session_count'] ?></td>
      <td class="muted"><?= $r['last_session_at'] ? e((string)$r['last_session_at']) : '—' ?></td>
      <td><?= e($statusLabels[(string)$r['status']] ?? (string)$r['status']) ?></td>
      <td><a class="btn btn-sm" href="/admin/counselor-home/cases/<?= (int)$r['id'] ?>">查看</a></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php else: ?>
  <p class="muted">暂无符合条件的个案。</p>
  <?php endif; ?>
  <p class="hint">保密提醒：个案名称与主诉请保持去标识；查阅与修改均写入审计日志。</p>
</div>
