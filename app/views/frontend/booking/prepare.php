<?php /** 咨询前准备与预约规则 */ ?>
<section class="section">
  <div class="container narrow">
    <header class="directory-head">
      <span class="eyebrow">首次咨询指南</span>
      <h1 class="page-title">咨询前，你可以这样准备</h1>
      <p class="page-sub">不需要组织得很完整，也不必先解决所有问题。带着此刻最想谈的事来就可以。</p>
    </header>

    <div class="prepare-grid">
      <section class="card prepare-card">
        <span class="prepare-num">01</span>
        <h2>开始前</h2>
        <ul class="prepare-list">
          <li>确认预约日期、时段与咨询方式；改期申请发出后，以机构最终确认的时间为准。</li>
          <li>找一个安静、不被打断的地方。视频咨询请提前测试网络、麦克风与摄像头。</li>
          <li>简单记下最近最困扰你的事、持续多久，以及你希望获得怎样的帮助。</li>
          <li>未成年人由监护人代为预约，并需要监护人的知情同意。</li>
        </ul>
      </section>
      <section class="card prepare-card">
        <span class="prepare-num">02</span>
        <h2>咨询中</h2>
        <ul class="prepare-list">
          <li>第一次见面主要用于了解你的处境和咨询目标，咨询师也会说明咨询方式与设置。</li>
          <li>你可以决定哪些内容暂时不谈，也可以随时提出疑问或不同的感受。</li>
          <li>咨询不是审讯，也不需要勉强给出“标准答案”。</li>
        </ul>
      </section>
      <section class="card prepare-card">
        <span class="prepare-num">03</span>
        <h2>结束后</h2>
        <ul class="prepare-list">
          <li>给自己留一点缓冲时间，留意咨询后的感受。</li>
          <li>如果机构安排了后续预约、练习或随访，请在会员中心查看消息并确认。</li>
          <li>对咨询安排有疑问时，优先通过机构公布的联系方式沟通。</li>
        </ul>
      </section>
    </div>

    <section class="card rule-card">
      <h2>预约、支付与改期规则</h2>
      <div class="rule-list">
        <div><b>可约时段</b><p>从咨询师日历选择的时段，提交时会再次核验；如已被他人先预约，系统会提示重新选择。</p></div>
        <div><b>待支付</b><p>有在线收费的视频咨询，支付完成后自动生效；未完成支付的预约请按会员中心提示操作。</p></div>
        <div><b>待确认</b><p>普通预约申请需要机构确认；站内消息、微信或短信会通知状态变化。</p></div>
        <div><b>取消与改期</b><p>待确认、已确认或已改期的预约，可在咨询开始前至少 24 小时在线取消或申请改期。不足 24 小时请直接联系机构。</p></div>
        <div><b>改期不是即时生效</b><p>提交改期申请不会释放原时段，也不会自动锁定新时段；机构确认后再以最终通知为准。</p></div>
      </div>
      <p class="hint">机构联系方式：<?= e(setting('contact.phone', '请查看网站联系页面')) ?> · <a href="<?= url('/contact') ?>">查看联系我们</a></p>
    </section>

    <section class="card boundary-section">
      <h2>隐私与危机支持</h2>
      <p>咨询信息会按网站的隐私政策和知情同意书处理。若存在伤害自己或他人的紧迫风险，心理咨询不能替代急救；请立即联系当地急救或报警，并告知可信任的人。</p>
      <p class="text-links">
        <a href="<?= url('/p/privacy') ?>" target="_blank" rel="noopener">隐私政策</a>
        <a href="<?= url('/p/consent') ?>" target="_blank" rel="noopener">咨询知情同意书</a>
        <a href="<?= url('/p/crisis') ?>" target="_blank" rel="noopener">危机支持资源</a>
      </p>
    </section>
    <p class="center"><a class="btn btn-primary" href="<?= url('/booking') ?>">返回预约</a></p>
  </div>
</section>
