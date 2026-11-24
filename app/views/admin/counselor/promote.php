<?php /** 后台 · 从会员添加咨询师（入驻闭环：注册会员→绑微信→管理员一键建档） */ ?>
<div style="max-width:680px">
  <h2>从会员添加咨询师</h2>
  <div class="card" style="padding:16px 20px;margin:14px 0">
    <b>入驻三步</b>
    <ol style="line-height:2;margin:8px 0 0;padding-left:22px;color:var(--muted,#64748b)">
      <li>咨询师本人先在前台「会员中心」<b style="color:var(--text,#1e293b)">注册会员</b>（手机号 + 密码）</li>
      <li>登录会员中心后<b style="color:var(--text,#1e293b)">绑定微信</b>（微信扫码/授权，会员中心首页「微信绑定」卡片）</li>
      <li>管理员在此输入其注册手机号，一键<b style="color:var(--text,#1e293b)">建档为上线咨询师</b>——微信自动同步，其微信/小程序登录将直进咨询师工作台</li>
    </ol>
  </div>
  <form class="form card" method="post" action="<?= url('/admin/counselors/promote') ?>" style="max-width:420px">
    <?= csrf_field() ?>
    <label class="field">
      <span class="label">会员注册手机号</span>
      <input type="tel" name="phone" required maxlength="11" placeholder="咨询师注册会员用的手机号">
    </label>
    <button class="btn btn-primary" type="submit">查询并建档</button>
    <a class="btn btn-ghost" href="<?= url('/admin/counselors') ?>" style="margin-left:8px">返回咨询师列表</a>
  </form>
  <p class="hint" style="margin-top:14px">建档后请到咨询师编辑页完善头像、头衔、擅长方向、可约时段等资料（前台即时展示）。会员未绑定微信时也可先建档，其绑定微信后小程序登录即自动识别为咨询师。</p>
</div>
