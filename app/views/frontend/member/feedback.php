<?php /** 会员中心 · 咨询后反馈 */ ?>
<div class="container member-wrap">
  <div class="member-head">
    <h1>咨询后反馈</h1>
    <p class="muted"><a href="<?= url('/member/bookings') ?>">返回我的预约</a></p>
  </div>
  <div class="panel">
    <h2><?= e((string)$booking['prefer_date']) ?> <?= e((string)$booking['prefer_slot']) ?></h2>
    <?php if ((string)$feedback['status'] === 'submitted'): ?>
      <p><b>您的评分：<?= (int)$feedback['rating'] ?> / 5</b></p>
      <?php if ((string)$feedback['comment'] !== ''): ?>
        <p><?= nl2br(e((string)$feedback['comment'])) ?></p>
      <?php else: ?><p class="muted">未填写文字反馈。</p><?php endif; ?>
      <p class="muted">随访需求：<?= (int)$feedback['wants_followup'] === 1 ? '希望机构继续跟进' : '暂不需要' ?></p>
      <?php if ((string)$feedback['bridge_status'] === 'waiting_case'): ?>
        <p class="hint">您希望继续随访。机构关联有效疗程后将安排回访，请留意站内通知。</p>
      <?php elseif ((string)$feedback['bridge_status'] === 'created'): ?>
        <p class="hint">机构已安排咨询后随访，将通过站内通知或您选择的联系方式与您确认。</p>
      <?php endif; ?>
      <p class="hint">反馈已提交，不能重复修改；如需补充情况，请联系机构。</p>
    <?php elseif ((string)$feedback['status'] === 'pending'): ?>
      <form method="post" action="<?= url('/member/bookings/' . (int)$booking['id'] . '/feedback') ?>" class="form">
        <?= csrf_field() ?>
        <label>本次咨询评分 <span class="err-text">*</span></label>
        <div class="toolbar">
          <?php for ($i = 1; $i <= 5; $i++): ?>
          <label><input type="radio" name="rating" value="<?= $i ?>" required> <?= $i ?> 分</label>
          <?php endfor; ?>
        </div>
        <label for="feedback-comment">想对咨询师或机构说的话</label>
        <textarea id="feedback-comment" name="comment" rows="6" maxlength="2000" placeholder="可填写本次咨询的感受、收获或需要改进的地方（最多 2000 字）"></textarea>
        <label><input type="checkbox" name="wants_followup" value="1"> 我希望机构在本次咨询后继续联系我</label>
        <p class="hint">提交后会通知咨询师。选择继续联系仅表示您有随访需求，不代表已经预约新的咨询服务。</p>
        <button class="btn btn-primary" type="submit">提交反馈</button>
      </form>
    <?php else: ?>
      <p class="muted">该反馈当前不可用。如需帮助，请联系机构。</p>
    <?php endif; ?>
  </div>
</div>
