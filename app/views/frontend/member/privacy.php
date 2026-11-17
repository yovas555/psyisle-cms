<?php /** 会员中心 · 隐私中心 */ ?>
<div class="container member-wrap">
  <div class="member-head">
    <h1>隐私中心</h1>
    <p class="muted"><a href="<?= url('/member') ?>">返回会员中心</a></p>
  </div>

  <div class="panel member-panel">
    <h2>我的数据</h2>
    <p>系统中与您相关的数据：预约记录（手机号匹配）、测评记录（登录后提交的量表）。</p>
    <ul class="member-privacy-list">
      <li>手机号在页面上一律脱敏显示，不会展示给其他访客</li>
      <li>测评只保存分数与分级，不保存答题内容；IP 记录为哈希</li>
      <li>机构备注仅机构内部与您可见</li>
    </ul>
    <form method="post" action="<?= url('/member/privacy/export') ?>">
      <?= csrf_field() ?>
      <button class="btn btn-ghost" type="submit">导出我的数据（JSON）</button>
    </form>
  </div>

  <div class="panel member-panel">
    <h2>申请注销</h2>
    <p>提交后您的账号将暂停登录，机构会在核实后删除您的预约与测评记录（法律要求留存的必要记录除外）。此操作可在删除前联系机构撤回。</p>
    <form method="post" action="<?= url('/member/privacy/delete') ?>" data-confirm="确认申请注销会员并删除个人数据？提交后需联系机构撤回。">
      <?= csrf_field() ?>
      <button class="btn btn-danger" type="submit">申请注销会员</button>
    </form>
  </div>
</div>
