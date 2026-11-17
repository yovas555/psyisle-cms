<?php /** 预约详情 */ ?>
<div class="detail-grid">
  <div class="panel">
    <h2>预约信息</h2>
    <table class="table">
      <tr><th>查询码</th><td><b><?= e($b['code']) ?></b></td></tr>
      <tr><th>姓名</th><td><?= e($b['name']) ?>（<?= e(['1' => '男', '2' => '女'][$b['gender'] ?? ''] ?? '未填') ?> · <?= e($b['age_range'] ?: '未填年龄段') ?>）</td></tr>
      <tr><th>手机</th><td><a href="tel:<?= e($b['phone']) ?>"><?= e($b['phone']) ?></a></td></tr>
      <tr><th>邮箱</th><td><?= e($b['email'] ?: '—') ?></td></tr>
      <tr><th>微信</th><td><?= e($b['wechat'] ?: '—') ?></td></tr>
      <tr><th>服务</th><td><?= e($b['svc'] ?: '未选择') ?></td></tr>
      <tr><th>指定咨询师</th><td><?= e($b['cname'] ?: '由机构推荐') ?></td></tr>
      <tr><th>方式</th><td><?= e($b['method'] ?: '未填') ?></td></tr>
      <tr><th>期望时间</th><td><?= e(($b['prefer_date'] ?: '不限') . ' ' . ($b['prefer_slot'] ?: '')) ?></td></tr>
      <tr><th>紧急</th><td><?= $b['urgency'] ? '<b class="badge warn">紧急</b>' : '常规' ?></td></tr>
      <tr><th>来源 IP</th><td class="muted"><?= e($b['ip'] ?: '—') ?> · <?= e($b['created_at']) ?></td></tr>
      <?php $detailExtras = \app\Core\Hook::fire('booking.detail.info', ['booking' => $b, 'html' => ''])['html'] ?? '';
      if ($detailExtras !== ''): ?>
      <tr><th>扩展信息</th><td><?= $detailExtras ?></td></tr>
      <?php endif; ?>
    </table>
    <h2>主诉概述</h2>
    <blockquote class="msg-content"><?= nl2br(e((string)$b['issue'])) ?: '<span class="muted">（未填写）</span>' ?></blockquote>
    <p class="muted">处理时请遵守保密原则：信息仅用于安排咨询，不得外传。</p>
  </div>

  <div class="panel">
    <h2>状态处理</h2>
    <form method="post" action="/admin/bookings/status/<?= (int)$b['id'] ?>">
      <?= csrf_field() ?>
      <label class="field">
        <span class="label">状态</span>
        <select name="status">
          <?php foreach (['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $b['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="field field-full">
        <span class="label">机构备注（用户可在进度查询页看到）</span>
        <textarea name="admin_note" rows="3" maxlength="500" placeholder="如：已安排咨询师于周六 10:00 首次会谈"><?= e((string)$b['admin_note']) ?></textarea>
      </label>
      <button class="btn btn-primary" type="submit">保存状态</button>
    </form>
    <form method="post" action="/admin/bookings/delete/<?= (int)$b['id'] ?>" data-confirm="确认删除该预约？" style="margin-top:16px">
      <?= csrf_field() ?>
      <button class="btn btn-danger" type="submit">删除预约</button>
    </form>
  </div>
</div>
