/* AI 客服浮窗 */
(function () {
  'use strict';
  var fab = document.getElementById('chatFab');
  var panel = document.getElementById('chatPanel');
  if (!fab || !panel) return;
  var body = document.getElementById('chatBody');
  var form = document.getElementById('chatForm');
  var input = document.getElementById('chatInput');
  var closeBtn = document.getElementById('chatClose');
  var escalateBtn = document.getElementById('chatEscalate');
  var leaveBtn = document.getElementById('chatLeave');
  var badge = document.getElementById('chatBadge');
  var token = null;
  var sending = false;
  var lastMsgId = 0;
  var pollTimer = null;
  var humanMode = false;

  function append(role, html, isHuman) {
    var div = document.createElement('div');
    div.className = 'chat-msg ' + (role === 'user' ? 'chat-msg-user' : 'chat-msg-ai') + (isHuman ? ' chat-msg-human' : '');
    var badge = isHuman ? '<span class="human-badge">人工客服</span>' : '';
    div.innerHTML = badge + '<div class="msg-bubble">' + html + '</div>';
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
  }
  function setHumanMode(on) {
    humanMode = on;
    if (on) {
      escalateBtn.textContent = '已转人工';
      escalateBtn.disabled = true;
      input.placeholder = '人工客服处理中，可继续留言…';
    } else {
      escalateBtn.textContent = '转人工';
      escalateBtn.disabled = false;
      input.placeholder = '输入您的问题…';
    }
  }
  function pollOnce() {
    if (!token) return;
    fetch(SITE_BASE + '/chat/poll?token=' + token + '&after=' + lastMsgId, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        if (d.escalated) setHumanMode(true);
        d.messages.forEach(function (m) {
          if (m.id > lastMsgId) {
            lastMsgId = m.id;
            append(m.role, m.content, m.human);
          }
        });
      }).catch(function () {});
  }

  function open() {
    panel.hidden = false;
    fab.classList.add('hidden-fab');
    input.focus();
    if (token === null) start();
  }
  function close() {
    panel.hidden = true;
    fab.classList.remove('hidden-fab');
  }
  fab.addEventListener('click', open);
  closeBtn.addEventListener('click', close);

  function start() {
    fetch(SITE_BASE + '/chat/start', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) return;
        token = d.token;
        body.innerHTML = '';
        d.messages.forEach(function (m) {
          lastMsgId = Math.max(lastMsgId, m.id || 0);
          append(m.role, m.content, false);
        });
        input.focus();
        if (!pollTimer) pollTimer = setInterval(pollOnce, 4000);
      })
      .catch(function () { append('assistant', '网络异常，请稍后再试'); });
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || sending || !token) return;
    append('user', escapeHtml(text));
    input.value = '';
    sending = true;
    append('assistant', '<span class="typing">正在输入…</span>');
    fetch(SITE_BASE + '/chat/send', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'token=' + encodeURIComponent(token) + '&msg=' + encodeURIComponent(text) + '&_token=' + encodeURIComponent(CSRF_TOKEN)
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        body.removeChild(body.lastChild);
        if (d.ok) {
          if (d.escalated) setHumanMode(true);
          // 记录本条 AI/提示消息 id，防止轮询重复展示同一内容
          if (d.msg_id) lastMsgId = Math.max(lastMsgId, d.msg_id);
          append('assistant', d.reply, d.type === 'human-waiting');
        } else {
          append('assistant', escapeHtml(d.msg || '出错了，请稍后再试'));
        }
      })
      .catch(function () {
        body.removeChild(body.lastChild);
        append('assistant', '网络异常，请稍后再试');
      })
      .finally(function () { sending = false; input.focus(); });
  });

  escalateBtn.addEventListener('click', function () {
    if (!token) return;
    fetch(SITE_BASE + '/chat/escalate', {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'token=' + encodeURIComponent(token) + '&_token=' + encodeURIComponent(CSRF_TOKEN)
    }).then(function (r) { return r.json(); })
      .then(function (d) {
        append('assistant', escapeHtml(d.msg || '已通知人工'));
        setHumanMode(true);
        pollOnce();
      });
  });

  leaveBtn.addEventListener('click', function () {
    window.location.href = SITE_BASE + '/message';
  });

  // 有升级会话未读时显示角标（每 60 秒检查一次）
  function pollBadge() {
    try {
      var t = document.cookie.match(/psy_chat=([a-f0-9]{32})/);
      if (!t) return;
      fetch(SITE_BASE + '/chat/start?token=' + t[1], { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok && token === null) { /* 会话恢复数据就绪 */ }
        }).catch(function () {});
    } catch (e) {}
  }
  setTimeout(pollBadge, 5000);

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
