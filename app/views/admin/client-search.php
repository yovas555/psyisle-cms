<?php /** 全局客户查找 */ ?><?php
if (!function_exists('bookingStatusText')) {
    function bookingStatusText(string $s): string {
        return ['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'][$s] ?? $s;
    }
}
?>
<div class="panel">
  <h2>客户查找</h2>
  <form method="get" class="search-inline" style="gap:8px">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="手机号 / 查询码 / 姓名 / 微信号" style="min-width:320px" autofocus>
    <button class="btn btn-primary" type="submit">查找</button>
    <span class="hint">来电快速定位：输入客户手机号即可同时看到其全部预约与留言</span>
  </form>
</div>

<?php if ($q !== ''): ?>
<div class="detail-grid">
  <div class="panel">
    <h2>预约（<?= count($bookings) ?>）</h2>
    <table class="table table-hover">
      <tr><th>#</th><th>姓名/手机</th><th>服务/咨询师</th><th>期望时间</th><th>状态</th><th>操作</th></tr>
      <?php if (!$bookings): ?><tr><td colspan="6" class="empty">无匹配预约</td></tr><?php endif; ?>
      <?php foreach ($bookings as $b): ?>
      <tr>
        <td><?= (int)$b['id'] ?></td>
        <td><?= e($b['name']) ?><br><a href="tel:<?= e($b['phone']) ?>"><?= e($b['phone']) ?></a></td>
        <td><?= e($b['svc'] ?: '—') ?><?= $b['cname'] ? ' / ' . e($b['cname']) : '' ?></td>
        <td><?= e(($b['prefer_date'] ?: '不限') . ' ' . str_take((string)$b['prefer_slot'], 10, '')) ?></td>
        <td><?= e(bookingStatusText((string)$b['status'])) ?></td>
        <td class="ops"><a class="btn btn-sm btn-primary" href="/admin/bookings/<?= (int)$b['id'] ?>/edit">处理</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <div class="panel">
    <h2>留言（<?= count($messages) ?>）</h2>
    <table class="table table-hover">
      <tr><th>#</th><th>联系人</th><th>主题</th><th>状态</th><th>操作</th></tr>
      <?php if (!$messages): ?><tr><td colspan="5" class="empty">无匹配留言</td></tr><?php endif; ?>
      <?php foreach ($messages as $m): ?>
      <tr>
        <td><?= (int)$m['id'] ?></td>
        <td><?= e($m['name'] ?: '匿名') ?><br><span class="muted"><?= e($m['contact']) ?></span></td>
        <td><?= e(str_take((string)$m['subject'], 18, '')) ?></td>
        <td><?= e($m['status'] === 'pending' ? '待回复' : ($m['status'] === 'replied' ? '已回复' : '已关闭')) ?></td>
        <td class="ops"><a class="btn btn-sm btn-primary" href="/admin/messages/<?= (int)$m['id'] ?>/edit">查看</a></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</div>
<?php endif; ?>
<?php
if (!function_exists('bookingStatusText')) {
    function bookingStatusText(string $s): string {
        return ['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'][$s] ?? $s;
    }
}
