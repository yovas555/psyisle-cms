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

  function bindAvatarCropper(pick) {
    var input = pick.querySelector('input[type=text]');
    var preview = pick.querySelector('.img-preview');
    var uploadBtn = pick.querySelector('.js-crop');
    var recropBtn = pick.querySelector('.js-recrop');
    if (!input || !uploadBtn) return;

    function syncPreview() {
      var hasImage = /^\/uploads\//.test(input.value.trim());
      if (preview) {
        preview.hidden = !hasImage;
        if (hasImage) preview.src = input.value.trim();
      }
      if (recropBtn) recropBtn.hidden = !hasImage;
    }
    input.addEventListener('change', syncPreview);

    function open(source) {
      var previousFocus = document.activeElement;
      var modal = document.createElement('div');
      modal.className = 'avatar-crop-modal';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.setAttribute('aria-label', '裁剪咨询师头像');
      modal.innerHTML = '<div class="avatar-crop-dialog">' +
        '<div class="avatar-crop-head"><div><b>裁剪咨询师头像</b><span>拖动图片调整位置，输出 960×960 方形头像</span></div>' +
        '<button type="button" class="avatar-crop-close" aria-label="关闭">×</button></div>' +
        '<div class="avatar-crop-body"><div class="avatar-crop-canvas-wrap"><canvas class="avatar-crop-canvas" width="560" height="560" aria-label="头像裁剪区域"></canvas>' +
        '<span class="avatar-crop-empty">请选择图片</span></div><div class="avatar-crop-controls">' +
        '<label class="avatar-crop-file">选择图片<input type="file" accept="image/jpeg,image/png,image/webp"></label>' +
        '<label class="avatar-crop-range">缩放<input type="range" min="1" max="3" step="0.01" value="1"><output>100%</output></label>' +
        '<div class="avatar-crop-rotate"><button type="button" data-rotate="-90" aria-label="向左旋转">↶ 左转</button>' +
        '<button type="button" data-rotate="90" aria-label="向右旋转">↷ 右转</button><button type="button" data-reset>复位</button></div>' +
        '<div class="avatar-crop-previews"><div><img alt="列表预览"><span>列表 210</span></div><div><img alt="圆形预览"><span>圆形 96</span></div></div>' +
        '<p class="avatar-crop-error" role="alert" hidden></p></div></div>' +
        '<div class="avatar-crop-foot"><span>原图不会被覆盖；取消不会改动当前头像。</span>' +
        '<div><button type="button" class="btn btn-ghost avatar-crop-cancel">取消</button>' +
        '<button type="button" class="btn btn-primary avatar-crop-apply" disabled>应用并上传</button></div></div></div>';
      document.body.appendChild(modal);

      var canvas = modal.querySelector('canvas');
      var ctx = canvas.getContext('2d');
      var fileInput = modal.querySelector('input[type=file]');
      var range = modal.querySelector('input[type=range]');
      var output = modal.querySelector('output');
      var applyBtn = modal.querySelector('.avatar-crop-apply');
      var error = modal.querySelector('.avatar-crop-error');
      var empty = modal.querySelector('.avatar-crop-empty');
      var previewImages = modal.querySelectorAll('.avatar-crop-previews img');
      var image = null;
      var objectUrl = null;
      var zoom = 1;
      var rotation = 0;
      var offsetX = 0;
      var offsetY = 0;
      var drag = null;
      var onKey = null;
      var view = canvas.width;

      function metrics() {
        if (!image) return null;
        var turned = Math.abs(rotation % 180) === 90;
        var rw = turned ? image.naturalHeight : image.naturalWidth;
        var rh = turned ? image.naturalWidth : image.naturalHeight;
        var scale = Math.max(view / rw, view / rh) * zoom;
        return { rw: rw, rh: rh, scale: scale };
      }
      function clampOffsets() {
        var m = metrics();
        if (!m) return;
        var maxX = Math.max(0, (m.rw * m.scale - view) / 2);
        var maxY = Math.max(0, (m.rh * m.scale - view) / 2);
        offsetX = Math.max(-maxX, Math.min(maxX, offsetX));
        offsetY = Math.max(-maxY, Math.min(maxY, offsetY));
      }
      function drawTo(target, size) {
        var targetCtx = target.getContext('2d');
        var m = metrics();
        targetCtx.setTransform(1, 0, 0, 1, 0, 0);
        targetCtx.clearRect(0, 0, size, size);
        targetCtx.fillStyle = '#fff';
        targetCtx.fillRect(0, 0, size, size);
        if (!image || !m) return;
        var ratio = size / view;
        targetCtx.translate((view / 2 + offsetX) * ratio, (view / 2 + offsetY) * ratio);
        targetCtx.rotate(rotation * Math.PI / 180);
        targetCtx.scale(m.scale * ratio, m.scale * ratio);
        targetCtx.imageSmoothingEnabled = true;
        targetCtx.imageSmoothingQuality = 'high';
        targetCtx.drawImage(image, -image.naturalWidth / 2, -image.naturalHeight / 2);
      }
      function render() {
        clampOffsets();
        drawTo(canvas, view);
        empty.hidden = !!image;
        range.value = String(zoom);
        output.value = Math.round(zoom * 100) + '%';
        applyBtn.disabled = !image;
        previewImages.forEach(function (img) {
          if (image) img.src = canvas.toDataURL('image/jpeg', 0.82);
          else img.removeAttribute('src');
        });
      }
      function showError(message) {
        error.textContent = message;
        error.hidden = false;
      }
      function load(src, isObject) {
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        objectUrl = isObject ? src : null;
        error.hidden = true;
        var next = new Image();
        next.onload = function () {
          image = next;
          zoom = 1; rotation = 0; offsetX = 0; offsetY = 0;
          render();
        };
        next.onerror = function () { image = null; render(); showError('图片读取失败，请换一张 JPG、PNG 或 WebP 图片'); };
        next.src = src;
      }
      function close() {
        if (onKey) document.removeEventListener('keydown', onKey);
        if (objectUrl) URL.revokeObjectURL(objectUrl);
        modal.remove();
        if (previousFocus && previousFocus.focus) previousFocus.focus();
      }

      modal.querySelector('.avatar-crop-close').addEventListener('click', close);
      modal.querySelector('.avatar-crop-cancel').addEventListener('click', close);
      modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
      onKey = function (e) {
        if (e.key === 'Escape') { e.preventDefault(); close(); }
      };
      document.addEventListener('keydown', onKey);
      fileInput.addEventListener('change', function () {
        var file = fileInput.files[0];
        if (!file) return;
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { showError('仅支持 JPG、PNG 或 WebP 图片'); return; }
        if (file.size > 20 * 1024 * 1024) { showError('图片大小不能超过 20MB'); return; }
        load(URL.createObjectURL(file), true);
        fileInput.value = '';
      });
      range.addEventListener('input', function () { zoom = Number(range.value); render(); });
      modal.querySelectorAll('[data-rotate]').forEach(function (btn) {
        btn.addEventListener('click', function () { rotation = (rotation + Number(btn.getAttribute('data-rotate')) + 360) % 360; render(); });
      });
      modal.querySelector('[data-reset]').addEventListener('click', function () { zoom = 1; rotation = 0; offsetX = 0; offsetY = 0; render(); });
      canvas.addEventListener('pointerdown', function (e) {
        if (!image) return;
        drag = { id: e.pointerId, x: e.clientX, y: e.clientY, ox: offsetX, oy: offsetY };
        canvas.setPointerCapture(e.pointerId);
        canvas.classList.add('is-dragging');
      });
      canvas.addEventListener('pointermove', function (e) {
        if (!drag || drag.id !== e.pointerId) return;
        var rect = canvas.getBoundingClientRect();
        offsetX = drag.ox + (e.clientX - drag.x) * view / rect.width;
        offsetY = drag.oy + (e.clientY - drag.y) * view / rect.height;
        render();
      });
      function endDrag(e) {
        if (drag && drag.id === e.pointerId) { drag = null; canvas.classList.remove('is-dragging'); }
      }
      canvas.addEventListener('pointerup', endDrag);
      canvas.addEventListener('pointercancel', endDrag);
      canvas.addEventListener('wheel', function (e) {
        if (!image) return;
        e.preventDefault();
        zoom = Math.max(1, Math.min(3, zoom * (e.deltaY < 0 ? 1.08 : 0.92)));
        render();
      }, { passive: false });

      applyBtn.addEventListener('click', function () {
        if (!image) return;
        error.hidden = true;
        applyBtn.disabled = true;
        applyBtn.textContent = '上传中…';
        var outputCanvas = document.createElement('canvas');
        outputCanvas.width = 960; outputCanvas.height = 960;
        drawTo(outputCanvas, 960);
        outputCanvas.toBlob(function (blob) {
          if (!blob) { applyBtn.disabled = false; applyBtn.textContent = '应用并上传'; showError('头像生成失败，请重试'); return; }
          var fd = new FormData();
          fd.append('file', blob, 'avatar.jpg');
          fd.append('module', 'counselor');
          fd.append('_token', CSRF_TOKEN);
          fetch(pick.getAttribute('data-upload'), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (!d.ok) throw new Error(d.msg || '上传失败');
              input.value = d.path;
              syncPreview();
              if (preview) preview.src = d.path + '?v=' + Date.now();
              close();
            })
            .catch(function (err) {
              applyBtn.disabled = false;
              applyBtn.textContent = '应用并上传';
              showError(err.message || '上传失败，请重试');
            });
        }, 'image/jpeg', 0.92);
      });

      render();
      modal.querySelector('.avatar-crop-close').focus();
      if (source) load(source, false);
    }

    uploadBtn.addEventListener('click', function () { open(''); });
    if (recropBtn) recropBtn.addEventListener('click', function () {
      if (/^\/uploads\//.test(input.value.trim())) open(input.value.trim());
      else { input.focus(); alert('请选择已上传的 /uploads/ 图片'); }
    });
    syncPreview();
  }
  document.querySelectorAll('.img-pick').forEach(function (pick) {
    if (pick.getAttribute('data-crop') === 'square') bindAvatarCropper(pick);
    else bindUpload(pick);
  });

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
  // v2.20.1 未查阅兜底：手动补发短信（含查阅短链）
  document.querySelectorAll('.js-resend-sms').forEach(function (btn) {
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      if (!window.confirm('确认补发该会员兜底短信？短信含查阅链接，会员点击即回执。')) return;
      btn.disabled = true; btn.textContent = '发送中…';
      fetch('/admin/notifications/resend-sms/' + btn.getAttribute('data-id'), {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: '_token=' + encodeURIComponent(CSRF_TOKEN)
      }).then(function (r) { return r.json(); })
        .then(function (d) {
          btn.disabled = false; btn.textContent = '补发短信';
          window.alert(d.message || (d.ok ? '补发成功' : '补发失败'));
          if (d.ok) window.location.reload();
        })
        .catch(function () { btn.disabled = false; btn.textContent = '补发短信'; window.alert('网络异常，请重试'); });
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
