<?php /** 会员中心 · 首页（v2.8.1 重构：待办条 + 徽标 + 卡片按序分组） */ ?>
<div class="container member-wrap">
  <?php foreach ($flashes as $f): ?><div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div><?php endforeach; ?>
  <div class="member-head">
    <h1>您好，<?= e($m['name']) ?></h1>
    <p class="muted">
      手机号 <?= e(\app\Services\MemberService::maskPhone($m['phone'])) ?>
      <?php foreach (($badges ?? []) as $bg): ?> · <span class="chip"><?= e((string)$bg) ?></span><?php endforeach; ?>
      · <a href="<?= url('/member/settings') ?>">设置</a>
      · <a href="<?= url('/member/logout') ?>">退出登录</a>
    </p>
  </div>

  <?php if (!empty($todo)): ?>
  <div class="member-todo" style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 14px;margin:12px 0">
    <b style="color:#92400e">📌 待办</b>
    <?php foreach ($todo as $t): ?>
      <a href="<?= url((string)$t['url']) ?>" style="display:inline-block;margin:6px 10px 0 0;background:#fff;border:1px solid #fde68a;border-radius:8px;padding:6px 12px;color:#92400e;text-decoration:none">
        <?= e((string)$t['label']) ?><?= isset($t['count']) && (int)$t['count'] > 0 ? '（' . (int)$t['count'] . '）' : '' ?> ›
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="member-grid">
    <a class="member-card" href="<?= url('/member/bookings') ?>">
      <b>我的预约</b>
      <span class="muted"><?= (int)($upcomingCount ?? 0) ?> 条待赴约 / 共 <?= (int)$bookingTotal ?> 条</span>
      <em>查看 ›</em>
    </a>
    <a class="member-card" href="<?= url('/member/assessments') ?>">
      <b>我的测评</b>
      <span class="muted">已存档 <?= (int)$assessCount ?> 次量表记录</span>
      <em>查看 ›</em>
    </a>
    <?php foreach ($extraCards as $ec): ?>
    <a class="member-card" href="<?= url($ec['url']) ?>">
      <b><?= e($ec['title']) ?></b>
      <span class="muted"><?= e($ec['desc']) ?></span>
      <em>查看 ›</em>
    </a>
    <?php endforeach; ?>
    <a class="member-card" href="<?= url('/member/notes') ?>">
      <b>咨询记录</b>
      <span class="muted">咨询师发布的小结与作业</span>
      <em>查看 ›</em>
    </a>
    <a class="member-card" href="<?= url('/member/privacy') ?>">
      <b>隐私中心</b>
      <span class="muted">导出我的数据 / 申请注销</span>
      <em>查看 ›</em>
    </a>
    <a class="member-card" href="<?= url('/member/settings') ?>">
      <b>会员设置</b>
      <span class="muted">密码 / 邮箱 / 通知偏好</span>
      <em>查看 ›</em>
    </a>
  </div>

  <details class="member-redeem" style="margin:14px 0">
    <summary style="cursor:pointer;color:var(--primary,#0d9488)">使用兑换码 / 邀请码</summary>
    <form method="post" action="<?= url('/member/redeem') ?>" style="display:flex;gap:8px;margin-top:8px">
      <?= csrf_field() ?>
      <input type="text" name="code" placeholder="兑换码" required style="padding:10px 12px;border:1px solid var(--border,#e2e8f0);border-radius:8px">
      <button class="btn btn-primary" type="submit">兑换</button>
    </form>
    <p class="hint">咨询套餐兑换码、朋友分享的转介绍邀请码都在这里使用。</p>
  </details>

  <?php if ($upcoming): ?>
  <h2 class="section-title">近期预约</h2>
  <table class="table member-table">
    <tr><th>日期</th><th>时段</th><th>状态</th></tr>
    <?php foreach ($upcoming as $b): ?>
    <tr>
      <td><?= e((string)$b['prefer_date']) ?></td>
      <td><?= e((string)$b['prefer_slot']) ?></td>
      <td><span class="chip"><?= e(['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期'][$b['status']] ?? $b['status']) ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint">机构确认后会电话联系您；如需取消或改期请在「我的预约」中操作。</p>
  <?php else: ?>
  <p class="muted">暂无待赴约的预约。<a href="<?= url('/booking') ?>">重新预约</a></p>
  <?php endif; ?>
</div>
