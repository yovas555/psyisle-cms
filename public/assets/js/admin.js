/* 一二三后台交互 */
(function () {
  'use strict';

  // 使用引导条：可关闭并按页面记忆（7 天不再显示）
  document.querySelectorAll('.page-guide[data-guide-key]').forEach(function (g) {
    var key = 'psy_guide_' + g.getAttribute('data-guide-key');
    try {
      var dismissed = JSON.parse(localStorage.getItem(key) || 'null');
      if (dismissed && dismissed.t > Date.now() - 7 * 86400 * 1000) {
        g.classList.add('guide-dismissed');
      }
    } catch (e) {}
    var btn = g.querySelector('.guide-close');
    if (btn) {
      btn.addEventListener('click', function () {
        g.classList.add('guide-dismissed');
        try { localStorage.setItem(key, JSON.stringify({ t: Date.now() })); } catch (e) {}
      });
    }
  });

  // 会话批量选择
  var selAll = document.getElementById('selAll');
  if (selAll) {
    var syncCount = function () {
      var n = document.querySelectorAll('.sel-row:checked').length;
      var c = document.getElementById('selCount');
      var b = document.getElementById('batchDeleteBtn');
      if (c) c.textContent = n;
      if (b) b.disabled = n === 0;
    };
    selAll.addEventListener('change', function () {
      document.querySelectorAll('.sel-row').forEach(function (cb) { cb.checked = selAll.checked; });
      syncCount();
    });
    document.querySelectorAll('.sel-row').forEach(function (cb) {
      cb.addEventListener('change', syncCount);
    });
    syncCount();
  }

  // 确认弹窗
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-confirm]');
    if (f && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  });

  // 真富文本编辑器：contentEditable + 工具栏 + 媒体库选图
  var mediaListCache = null;
  function openMediaPicker(cb) {
    // 模态框
    var modal = document.createElement('div');
    modal.className = 'media-picker-modal';
    modal.innerHTML = '<div class="media-picker-box"><div class="media-picker-head"><b>从媒体库选择图片</b>' +
      '<button type="button" class="mp-close">✕</button></div>' +
      '<div class="media-picker-upload"><button type="button" class="btn btn-sm btn-primary mp-upload">＋ 上传新图片</button>' +
      '<input type="file" accept="image/*" class="mp-file" hidden></div>' +
      '<div class="media-picker-grid"><span class="muted">加载中…</span></div>' +
      '<div class="media-picker-foot"><span class="muted">点击图片插入（自动带 alt）</span></div></div>';
    document.body.appendChild(modal);
    var grid = modal.querySelector('.media-picker-grid');
    function close() { modal.remove(); }
    modal.querySelector('.mp-close').addEventListener('click', close);
    modal.addEventListener('click', function (e) { if (e.target === modal) close(); });

    var fi = modal.querySelector('.mp-file');
    modal.querySelector('.mp-upload').addEventListener('click', function () { fi.click(); });
    fi.addEventListener('change', function () {
      var file = fi.files[0];
      if (!file) return;
      var fd = new FormData();
      fd.append('file', file);
      fd.append('module', 'article');
      fd.append('_token', CSRF_TOKEN);
      grid.innerHTML = '<span class="muted">上传中…</span>';
      fetch('/admin/media/upload', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.ok) { cb(d.path); close(); }
          else { grid.innerHTML = '<span class="err-text">' + (d.msg || '上传失败') + '</span>'; }
        });
    });

    function render(rows) {
      grid.innerHTML = '';
      if (!rows.length) { grid.innerHTML = '<span class="muted">媒体库为空，请先上传</span>'; return; }
      rows.forEach(function (r) {
        if (!r.mime || r.mime.indexOf('image/') !== 0) return;
        var im = document.createElement('img');
        im.src = r.path;
        im.title = r.original_name;
        im.alt = r.original_name || '';
        im.addEventListener('click', function () { cb(r.path); close(); });
        grid.appendChild(im);
      });
    }
    if (mediaListCache) { render(mediaListCache); }
    else {
      fetch('/admin/media/list', { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { mediaListCache = d.rows || []; render(mediaListCache); });
    }
  }

  document.querySelectorAll('textarea.richtext').forEach(function (ta) {
    var toolbar = ta.parentNode.querySelector('.rt-toolbar');
    var ed = document.createElement('div');
    ed.className = 'rt-editor';
    ed.contentEditable = 'true';
    ed.setAttribute('data-placeholder', '开始撰写正文…');
    ed.innerHTML = ta.value || '<p><br></p>';
    ta.style.display = 'none';
    if (toolbar) { ta.parentNode.insertBefore(toolbar, ta); } // 工具栏贴在编辑器上方
    ta.parentNode.insertBefore(ed, ta);

    function sync() { ta.value = ed.innerHTML; }
    ed.addEventListener('input', sync);
    ed.addEventListener('blur', sync);
    ed.addEventListener('paste', function (e) {
      e.preventDefault();
      var text = (e.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, text);
      sync();
    });
    var form = ta.closest('form');
    if (form) form.addEventListener('submit', sync);

    // 自动暂存（30s 节流）：意外关闭可恢复
    var draftKey = 'psy_draft_' + location.pathname + '_' + ta.name;
    var savedRaw = null;
    try { savedRaw = localStorage.getItem(draftKey); } catch (e) {}
    if (savedRaw) {
      try {
        var saved = JSON.parse(savedRaw);
        if (saved.content && saved.content !== ta.value && saved.content !== ed.innerHTML &&
            (Date.now() - saved.t) < 48 * 3600 * 1000 &&
            window.confirm('检测到未保存的编辑暂存（' + new Date(saved.t).toLocaleString() + '），是否恢复？')) {
          ed.innerHTML = saved.content;
          sync();
        } else {
          try { localStorage.removeItem(draftKey); } catch (e2) {}
        }
      } catch (e) {}
    }
    var draftTimer = null;
    ed.addEventListener('input', function () {
      clearTimeout(draftTimer);
      draftTimer = setTimeout(function () {
        try { localStorage.setItem(draftKey, JSON.stringify({ t: Date.now(), content: ed.innerHTML })); } catch (e) {}
      }, 800);
    });

    if (toolbar) {
      toolbar.querySelectorAll('button[data-cmd]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var cmd = btn.getAttribute('data-cmd');
          ed.focus();
          if (cmd === 'insertImage') {
            openMediaPicker(function (path) {
              var alt = '';
              var titleInput = form.querySelector('input[name=title]');
              if (titleInput) alt = titleInput.value;
              document.execCommand('insertHTML', false, '<img src="' + path + '" alt="' + alt.replace(/"/g, '') + '">');
              sync();
            });
          } else if (cmd === 'createLink') {
            var u = window.prompt('链接地址（https://…）：');
            if (u && /^https?:\/\//.test(u)) {
              document.execCommand('createLink', false, u);
              sync();
            } else if (u) { alert('请输入 http(s):// 开头的地址'); }
          } else if (cmd === 'removeFormat') {
            document.execCommand('removeFormat', false, null);
            document.execCommand('unlink', false, null);
            sync();
          } else {
            document.execCommand(cmd, false, btn.getAttribute('data-val') || null);
            sync();
          }
        });
      });
    }
  });

  // 图片选择器上传
  function bindUpload(pick) {
    var btn = pick.querySelector('.js-upload');
    if (!btn) return;
    var input = pick.querySelector('input[type=text]');
    var preview = pick.querySelector('.img-preview');
    btn.addEventListener('click', function () {
      var fi = document.createElement('input');
      fi.type = 'file'; fi.accept = 'image/*';
      fi.onchange = function () {
        var file = fi.files[0];
        if (!file) return;
        var fd = new FormData();
        fd.append('file', file);
        fd.append('module', 'common');
        fd.append('_token', CSRF_TOKEN);
        btn.textContent = '上传中…';
        fetch('/admin/media/upload', { method: 'POST', body: fd, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            btn.textContent = '上传';
            if (d.ok) {
              input.value = d.path;
              if (preview) { preview.src = d.path; preview.hidden = false; }
            } else { alert(d.msg || '上传失败'); }
          })
          .catch(function () { btn.textContent = '上传'; alert('上传失败'); });
      };
      fi.click();
    });
  }
  document.querySelectorAll('.img-pick').forEach(bindUpload);

  // 设置页 tab
  document.querySelectorAll('#settingTabs .tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
      document.querySelectorAll('#settingTabs .tab').forEach(function (t) { t.classList.remove('active'); });
      document.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
      tab.classList.add('active');
      var pane = document.getElementById(tab.getAttribute('data-tab'));
      if (pane) pane.classList.add('active');
    });
  });

  // AI 连通测试
  var testAi = document.getElementById('testAiBtn');
  if (testAi) {
    testAi.addEventListener('click', function () {
      var out = document.getElementById('aiTestResult');
      out.hidden = false; out.textContent = '测试中…';
      fetch('/admin/ai/test', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_token=' + encodeURIComponent(CSRF_TOKEN)
      }).then(function (r) { return r.json(); })
        .then(function (d) {
          out.textContent = (d.ok ? '✓ ' : '✗ ') + (d.msg || '');
          out.className = d.ok ? 'ok-text' : 'err-text';
        });
    });
  }

  // SMTP 测试
  var testMail = document.getElementById('testMailBtn');
  if (testMail) {
    testMail.addEventListener('click', function () {
      var out = document.getElementById('mailTestResult');
      var to = window.prompt('输入接收测试邮件的邮箱：');
      if (!to) return;
      out.hidden = false; out.textContent = '发送中…';
      fetch('/admin/settings/testmail', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_token=' + encodeURIComponent(CSRF_TOKEN) + '&to_email=' + encodeURIComponent(to)
      }).then(function (r) { return r.json(); })
        .then(function (d) {
          out.textContent = (d.ok ? '✓ ' : '✗ ') + (d.msg || '');
        });
    });
  }

  // 留言回复 AJAX
  var replyForm = document.getElementById('replyForm');
  if (replyForm) {
    replyForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var mid = replyForm.getAttribute('data-message');
      var fd = new FormData(replyForm);
      fd.append('_token', CSRF_TOKEN);
      fetch('/admin/messages/reply/' + mid, { method: 'POST', body: new URLSearchParams(fd), credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) window.location.reload(); else alert(d.msg || '失败'); });
    });
  }

  // AI 会话人工回复（AJAX 追加，不刷新页面）
  var chatForm = document.getElementById('chatReplyForm');
  if (chatForm) {
    var transcript = document.querySelector('.chat-transcript');
    function scrollBottom() {
      if (transcript) transcript.scrollTop = transcript.scrollHeight;
    }
    scrollBottom(); // 打开即定位到最新消息
    var banner = document.getElementById('takeoverBanner');
    var ta = chatForm.querySelector('textarea');
    ta.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); chatForm.dispatchEvent(new Event('submit')); }
    });
    chatForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var content = ta.value.trim();
      if (!content) return;
      var sid = chatForm.getAttribute('data-session');
      var fd = new FormData(chatForm);
      fd.append('_token', CSRF_TOKEN);
      var btn = chatForm.querySelector('button[type=submit]');
      btn.disabled = true;
      fetch('/admin/chat/reply/' + sid, { method: 'POST', body: new URLSearchParams(fd), credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          btn.disabled = false;
          if (d.ok) {
            // 追加到会话记录（不刷新）
            var row = document.createElement('div');
            row.className = 'ct-row ct-assistant';
            row.innerHTML = '<span class="ct-role">【人工客服】<time>' + d.time + '</time> <i class="muted">human</i></span>' +
              '<div class="ct-body">' + d.content + '</div>';
            transcript.appendChild(row);
            ta.value = '';
            scrollBottom();
            if (banner) banner.hidden = false; // 首次回复即接管
          } else { alert(d.msg || '发送失败'); }
        })
        .catch(function () { btn.disabled = false; alert('网络异常，请重试'); });
      ta.focus();
    });
  }

  // 通知标已读
  document.querySelectorAll('.js-mark').forEach(function (btn) {
    btn.addEventListener('click', function () {
      fetch('/admin/notifications/read', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_token=' + encodeURIComponent(CSRF_TOKEN) + '&id=' + btn.getAttribute('data-id')
      }).then(function () { window.location.reload(); });
    });
  });
  // 通知删除（单条）
  document.querySelectorAll('.js-del-ntf').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (!window.confirm('删除该通知？')) return;
      fetch('/admin/notifications/delete/' + btn.getAttribute('data-id'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_token=' + encodeURIComponent(CSRF_TOKEN)
      }).then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) window.location.reload(); });
    });
  });
  // 清空全部通知
  var clearAll = document.getElementById('clearAllNtf');
  if (clearAll) {
    clearAll.addEventListener('click', function () {
      fetch('/admin/notifications/clear-all', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_token=' + encodeURIComponent(CSRF_TOKEN)
      }).then(function (r) { return r.json(); })
        .then(function (d) { if (d.ok) window.location.reload(); });
    });
  }

  var markAll = document.getElementById('markAllRead');
  if (markAll) {
    markAll.addEventListener('click', function () {
      fetch('/admin/notifications/read', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_token=' + encodeURIComponent(CSRF_TOKEN)
      }).then(function () { window.location.reload(); });
    });
  }

  // 媒体库复制路径
  document.querySelectorAll('.media-item img').forEach(function (img) {
    img.addEventListener('click', function () {
      var path = img.getAttribute('data-path');
      if (navigator.clipboard) {
        navigator.clipboard.writeText(path).then(function () {
          img.title = '已复制：' + path;
        });
      }
    });
  });

  // 媒体库上传按钮
  var upBtn = document.getElementById('mediaUploadBtn');
  var upInput = document.getElementById('mediaFileInput');
  if (upBtn && upInput) {
    upBtn.addEventListener('click', function () { upInput.click(); });
    upInput.addEventListener('change', function () {
      var files = upInput.files;
      var done = 0;
      for (var i = 0; i < files.length; i++) {
        (function (file) {
          var fd = new FormData();
          fd.append('file', file);
          fd.append('module', 'common');
          fd.append('_token', CSRF_TOKEN);
          fetch('/admin/media/upload', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { done++; if (done === files.length) window.location.reload(); });
        })(files[i]);
      }
    });
  }
})();
