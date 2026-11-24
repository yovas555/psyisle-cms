<?php /** 经营驾驶舱（P2-B） */ ?>
<?php
$f = $d['funnel']; $ch = $d['channels']; $cs = $d['counselors']; $rv = $d['revenue']; $mb = $d['members'];
$yuan = function (int $cents): string { return '¥' . number_format($cents / 100, 2); };
$from = $d['from']; $to = $d['to'];
?>
<div class="panel">
  <h2>经营驾驶舱 <span class="muted" style="font-weight:400;font-size:13px">口径见《经营指标字典》，纯读聚合不写数据</span></h2>
  <form class="form" method="get" action="/admin/report" style="margin:8px 0 0">
    <div class="form-grid">
      <label class="field"><span class="label">开始日期</span>
        <input type="date" name="from" value="<?= e($from) ?>">
      </label>
      <label class="field"><span class="label">结束日期</span>
        <input type="date" name="to" value="<?= e($to) ?>">
      </label>
      <div class="field" style="align-self:end">
        <button class="btn btn-primary" type="submit">查询</button>
        <a class="btn btn-ghost" href="/admin/report">近 30 天</a>
      </div>
    </div>
  </form>
  <form method="post" action="/admin/report/export" style="margin-top:6px">
    <?= csrf_field() ?>
    <input type="hidden" name="from" value="<?= e($from) ?>">
    <input type="hidden" name="to" value="<?= e($to) ?>">
    <button class="btn btn-sm btn-ghost" type="submit">导出 CSV（<?= e($from) ?> ~ <?= e($to) ?>）</button>
  </form>
</div>

<div class="dash-grid">
  <div class="todo-cards">
    <div class="todo-card"><strong><?= $yuan($rv['total_cents']) ?></strong><span>总收入</span></div>
    <div class="todo-card"><strong><?= (int)$f['created'] ?></strong><span>发起预约</span></div>
    <div class="todo-card"><strong><?= (int)$f['completed'] ?></strong><span>完成咨询</span></div>
    <div class="todo-card"><strong><?= (int)$f['rebuy'] ?></strong><span>复购会员</span></div>
    <div class="todo-card"><strong><?= (int)$mb['new'] ?></strong><span>新增会员</span></div>
  </div>
</div>

<div class="dash-grid">
  <div class="panel">
    <h2>预约漏斗</h2>
    <table class="table">
      <tr><th>环节</th><th>数量</th><th>转化率</th></tr>
      <tr><td>站点访问（PV / UV）</td><td><?= (int)$f['pv'] ?> / <?= (int)$f['uv'] ?></td><td>—</td></tr>
      <tr><td>发起预约</td><td><?= (int)$f['created'] ?></td><td><?= e($f['rates']['created_to_pv']) ?></td></tr>
      <tr><td>确认预约</td><td><?= (int)$f['confirmed'] ?></td><td><?= e($f['rates']['confirmed_to_created']) ?></td></tr>
      <tr><td>到访完成</td><td><?= (int)$f['completed'] ?></td><td><?= e($f['rates']['completed_to_confirmed']) ?></td></tr>
      <tr><td>复购会员</td><td><?= (int)$f['rebuy'] ?></td><td><?= e($f['rates']['rebuy_rate']) ?></td></tr>
    </table>
  </div>

  <div class="panel">
    <h2>收入构成</h2>
    <table class="table">
      <tr><th>项目</th><th style="text-align:right">金额</th></tr>
      <tr><td>线上咨询（已支付）</td><td style="text-align:right"><?= $yuan($rv['online_cents']) ?></td></tr>
      <tr><td>课时包销售</td><td style="text-align:right"><?= $yuan($rv['package_sale_cents']) ?></td></tr>
      <tr><td>课时包退款</td><td style="text-align:right">-<?= $yuan($rv['package_refund_cents']) ?></td></tr>
      <tr><td><b>总收入</b></td><td style="text-align:right"><b><?= $yuan($rv['total_cents']) ?></b></td></tr>
    </table>
    <p class="hint">面对面咨询多为线下付（free），线上收入仅统计 pay_status=paid。</p>
  </div>
</div>

<div class="dash-grid">
  <div class="panel">
    <h2>预约渠道</h2>
    <?php if (!$ch['source']): ?><p class="muted">暂无数据。</p>
    <?php else: ?>
    <table class="table">
      <tr><th>来源</th><th style="text-align:right">预约数</th></tr>
      <?php foreach ($ch['source'] as $s): ?>
      <tr><td><?= e((string)$s['k']) ?></td><td style="text-align:right"><?= (int)$s['c'] ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>流量来源（UV）</h2>
    <?php if (!$ch['referer']): ?><p class="muted">暂无数据。</p>
    <?php else: ?>
    <table class="table">
      <tr><th>来源</th><th style="text-align:right">UV</th></tr>
      <?php foreach ($ch['referer'] as $r): ?>
      <tr><td><?= e((string)$r['k']) ?></td><td style="text-align:right"><?= (int)$r['c'] ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <h2>咨询师产能 <span class="muted" style="font-weight:400;font-size:13px">共 <?= (int)$cs['total_counselors'] ?> 名在岗 · 完成会次 <?= (int)$cs['total_held'] ?> · 人均 <?= e((string)$cs['avg_held']) ?></span></h2>
  <table class="table">
    <tr><th>咨询师</th><th style="text-align:right">完成会次</th><th style="text-align:right">确认预约</th><th style="text-align:right">完成预约</th><th style="text-align:right">到访率</th></tr>
    <?php foreach ($cs['rows'] as $c): ?>
    <tr><td><?= e((string)$c['name']) ?></td>
      <td style="text-align:right"><?= (int)$c['held'] ?></td>
      <td style="text-align:right"><?= (int)$c['confirmed'] ?></td>
      <td style="text-align:right"><?= (int)$c['completed'] ?></td>
      <td style="text-align:right"><?= e((string)$c['show_rate']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel">
  <h2>会员</h2>
  <table class="table">
    <tr><th>指标</th><th style="text-align:right">数值</th></tr>
    <tr><td>新增会员（窗口内）</td><td style="text-align:right"><?= (int)$mb['new'] ?></td></tr>
    <tr><td>活跃会员（窗口内完成预约）</td><td style="text-align:right"><?= (int)$mb['active'] ?></td></tr>
    <tr><td>累计会员</td><td style="text-align:right"><?= (int)$mb['total'] ?></td></tr>
  </table>
</div>
