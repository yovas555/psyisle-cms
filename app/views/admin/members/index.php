<?php /** 会员管理 */ ?>
<div class="page-guide" data-guide-key="members">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>会员管理说明</b>
  <ol>
    <li>会员通过「预约号登录」或「手机验证码」进入前台自助中心，手机号是唯一身份</li>
    <li>会员可查看/取消/申请改期自己的预约，查看测评趋势，导出个人数据</li>
    <li>「注销申请」需要您确认：确认删除将移除其测评记录与会员档案（预约记录作为业务留痕保留）</li>
    <li>手机号一律脱敏显示；点击可与预约列表互相核对</li>
  </ol>
</div>
<div class="toolbar">
  <span class="chip">有效会员 <b><?= (int)$total ?></b></span>
  <?php if ($pendingDelete): ?><span class="chip" style="background:#fef2f2;color:#b91c1c">待处理注销申请 <b><?= (int)$pendingDelete ?></b></span><?php endif; ?>
  <a class="btn btn-ghost btn-sm <?= $filter === '' ? 'active' : '' ?>" href="/admin/members">全部</a>
  <a class="btn btn-ghost btn-sm <?= $filter === 'active' ? 'active' : '' ?>" href="/admin/members?status=active">有效</a>
  <a class="btn btn-ghost btn-sm <?= $filter === 'delete_requested' ? 'active' : '' ?>" href="/admin/members?status=delete_requested">注销待处理</a>
</div>
<table class="table table-hover">
  <thead><tr><th>手机号</th><th>状态</th><th>预约数</th><th>测评数</th><th>注册时间</th><th>最近登录</th><th>操作</th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="empty">暂无会员</td></tr><?php endif; ?>
  <?php foreach ($rows as $m): ?>
  <tr>
    <td><b><?= e(\app\Services\MemberService::maskPhone((string)$m['phone'])) ?></b></td>
    <td>
      <?php if ($m['status'] === 'active'): ?><span class="ok-text">正常</span>
      <?php else: ?><span class="err-text">注销申请</span><?php endif; ?>
    </td>
    <td><?= (int)$m['booking_count'] ?></td>
    <td><?= (int)$m['assess_count'] ?></td>
    <td class="muted"><?= e((string)$m['created_at']) ?></td>
    <td class="muted"><?= e((string)$m['last_login_at']) ?></td>
    <td>
      <form method="post" action="/admin/members/bookings/<?= (int)$m['id'] ?>" style="display:inline">
        <a class="btn btn-ghost btn-sm" href="/admin/bookings?phone=<?= e($m['phone']) ?>">查预约</a>
      </form>
      <?php if ($m['status'] === 'delete_requested'): ?>
      <form method="post" action="/admin/members/confirm-delete/<?= (int)$m['id'] ?>" style="display:inline" data-confirm="确认注销：将删除其测评记录与会员档案，不可恢复。确认？">
        <?= csrf_field() ?>
        <button class="btn btn-ghost btn-sm" type="submit">确认注销</button>
      </form>
      <form method="post" action="/admin/members/restore/<?= (int)$m['id'] ?>" style="display:inline">
        <?= csrf_field() ?>
        <button class="btn btn-ghost btn-sm" type="submit">恢复</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
