<?php /** 仪表盘 */ if (!function_exists('bookingStatus')):
function bookingStatus(string $s): string {
    return ['pending' => '待确认', 'confirmed' => '已确认', 'rescheduled' => '已改期', 'completed' => '已完成', 'cancelled' => '已取消'][$s] ?? $s;
}
endif;
$maxPv = 1;
foreach ($stats as $s0) { $maxPv = max($maxPv, (int)$s0['pv']); }
?>
<div class="page-guide" data-guide-key="dashboard">
  <button type="button" class="guide-close" title="7 天内不再显示">✕</button>
  <b>欢迎使用一二三后台</b> —— 新站快速上手 5 步：
  <ol>
    <li>改掉默认密码：<a href="/admin/users">用户与角色</a> → 编辑 admin → 设置新密码；顺手看看<a href="/admin/users/roles">角色权限</a></li>
    <li>完善站点信息：<a href="/admin/settings">系统设置</a> → 站点名称/联系方式/备案号，选一套喜欢的主题风格</li>
    <li>接上智能客服与邮件：<a href="/admin/chat">AI 客服</a> 填 API Key 并点「测试连通性」；<a href="/admin/settings">设置-邮件通知</a> 填 SMTP 并发测试邮件（重要事件将实时推送到你的邮箱）</li>
    <li>发布内容：<a href="/admin/articles">文章</a>、<a href="/admin/counselors">咨询师</a>、<a href="/admin/services">服务项目</a>（都支持媒体库插图，前台即时生效）</li>
    <li>日常运营：<a href="/admin/bookings">预约</a> 处理状态流转、<a href="/admin/messages">留言</a> 回复、<a href="/admin/chat">AI 会话</a> 人工接管——仪表盘待办卡会提醒你</li>
  </ol>
</div>
<div class="dash-grid">
  <div class="todo-cards">
    <a class="todo-card" href="/admin/bookings">
      <strong><?= (int)$todo['bookings'] ?></strong><span>待处理预约</span>
    </a>
    <a class="todo-card" href="/admin/messages">
      <strong><?= (int)$todo['messages'] ?></strong><span>未回复留言</span>
    </a>
    <a class="todo-card" href="/admin/chat">
      <strong><?= (int)$todo['chats'] ?></strong><span>AI 转人工</span>
    </a>
    <a class="todo-card" href="/admin/notifications">
      <strong><?= (int)$todo['notifications'] ?></strong><span>未读通知</span>
    </a>
    <a class="todo-card<?= (int)$todo['crisis'] > 0 ? ' todo-crisis' : '' ?>" href="/admin/chat">
      <strong><?= (int)$todo['crisis'] ?></strong><span>危机预警会话</span>
    </a>
  </div>

  <div class="panel">
    <h2>近 7 日访问</h2>
    <?php if (!$stats): ?><p class="muted">暂无数据（统计按日聚合，明天可见）</p>
    <?php else: ?>
    <div class="mini-chart">
      <?php foreach ($stats as $s): $h = round((int)$s['pv'] / $maxPv * 100); ?>
      <div class="mc-col" title="<?= e($s['date']) ?>：PV <?= (int)$s['pv'] ?> / UV <?= (int)$s['uv'] ?>">
        <i style="height:<?= max(4, $h) ?>%"></i>
        <span><?= e(substr((string)$s['date'], 5)) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>最新预约</h2>
    <?php if (!$recentBookings): ?><p class="muted">暂无预约</p><?php else: ?>
    <table class="table">
      <tr><th>姓名</th><th>期望</th><th>状态</th><th>时间</th></tr>
      <?php foreach ($recentBookings as $b): ?>
      <tr>
        <td><a href="/admin/bookings/<?= $b['id'] ?>/edit"><?= e($b['name']) ?><?= $b['urgency'] ? ' <b class="badge warn">急</b>' : '' ?></a></td>
        <td><?= e(($b['prefer_date'] ?: '不限') . ' ' . str_take((string)$b['prefer_slot'], 10, '')) ?></td>
        <td><?= e(bookingStatus($b['status'])) ?></td>
        <td class="muted"><?= e($b['created_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>最新留言</h2>
    <?php if (!$recentMessages): ?><p class="muted">暂无留言</p><?php else: ?>
    <table class="table">
      <tr><th>联系人</th><th>主题</th><th>状态</th></tr>
      <?php foreach ($recentMessages as $m): ?>
      <tr>
        <td><a href="/admin/messages/<?= $m['id'] ?>/edit"><?= e($m['name'] ?: '匿名') ?><br><span class="muted"><?= e(mask_phone($m['contact'])) ?></span></a></td>
        <td><?= e(str_take((string)$m['subject'], 20, '')) ?></td>
        <td><?= e($m['status'] === 'pending' ? '待回复' : ($m['status'] === 'replied' ? '已回复' : '已关闭')) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>近 7 日预约来源（转化归因）</h2>
    <?php if (!$sources): ?><p class="muted">暂无预约</p><?php else: ?>
    <div class="chip-row">
      <?php foreach ($sources as $s): ?>
      <span class="chip"><?= e(['assessment' => '量表转化', 'chat' => 'AI 客服', 'counselor' => '咨询师页', 'web' => '官网直接'][(string)$s['source']] ?? $s['source']) ?> · <b><?= (int)$s['c'] ?></b></span>
      <?php endforeach; ?>
    </div>
    <p class="hint">来源在预约提交时自动记录（量表结果页/AI 客服/咨询师页 CTA 携带归因参数）。</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>系统状态</h2>
    <table class="table">
      <tr><th>版本 / PHP</th><td><?= e($sys['version']) ?> · PHP <?= e($sys['php']) ?></td></tr>
      <tr><th>磁盘剩余</th><td><?= $sys['disk_free_gb'] ?> GB<?= $sys['disk_free_gb'] < 2 ? ' <b class="badge warn">偏低</b>' : '' ?></td></tr>
      <tr><th>待发邮件</th><td><?= (int)$sys['queue'] ?> 封（每分钟 cron 发出）</td></tr>
      <tr><th>最近登录</th><td><?php foreach ($sys['last_logins'] as $l): ?><?= e($l['username']) ?>（<?= e($l['last_login_at']) ?>）<br><?php endforeach; ?></td></tr>
    </table>
  </div>

  <div class="panel">
    <h2>AI 客服 · 需人工跟进</h2>
    <?php if (!$unanswered): ?><p class="muted">暂无需要跟进的会话</p><?php else: ?>
    <ul class="chat-todo">
      <?php foreach ($unanswered as $s): ?>
      <li><a href="/admin/chat/<?= $s['id'] ?>/edit"><?= e(str_take((string)$s['content'], 40, '…')) ?><span class="muted"><?= e($s['created_at']) ?></span></a></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
