/* 一二三心理咨询网站系统 前台交互 */
(function () {
  'use strict';

  // 移动端导航
  var toggle = document.getElementById('navToggle');
  var nav = document.querySelector('.main-nav');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      nav.classList.toggle('open');
    });
  }

  // 首页轮播
  var slider = document.getElementById('heroSlider');
  if (slider) {
    var slides = slider.querySelectorAll('.hero-slide');
    var dots = document.getElementById('heroDots');
    var cur = 0, timer = null;
    function show(i) {
      slides.forEach(function (s, idx) { s.classList.toggle('active', idx === i); });
      if (dots) {
        dots.querySelectorAll('button').forEach(function (d, idx) { d.classList.toggle('active', idx === i); });
      }
      cur = i;
    }
    if (slides.length > 1) {
      slides.forEach(function (_, i) {
        var b = document.createElement('button');
        b.setAttribute('aria-label', '第' + (i + 1) + '张');
        b.addEventListener('click', function () { show(i); restart(); });
        if (dots) dots.appendChild(b);
      });
      show(0);
      function restart() { clearInterval(timer); timer = setInterval(function () { show((cur + 1) % slides.length); }, 5000); }
      restart();
    }
  }

  // 未成年人 → 显示监护人同意勾选
  var ageSel = document.getElementById('ageRange');
  var guardianRow = document.getElementById('guardianRow');
  if (ageSel && guardianRow) {
    var toggleGuardian = function () {
      var minor = ageSel.value === '18岁以下';
      guardianRow.hidden = !minor;
      var cb = document.getElementById('guardianConsent');
      if (cb) cb.required = minor;
    };
    ageSel.addEventListener('change', toggleGuardian);
    toggleGuardian();
  }

  // 预约时段选择器：选了咨询师+日期后拉取可约时段
  var slotPicker = document.getElementById('slotPicker');
  if (slotPicker) {
    var cSel = slotPicker.closest('form').querySelector('select[name=counselor_id]');
    var dInp = slotPicker.closest('form').querySelector('input[name=prefer_date]');
    var slotChips = document.getElementById('slotChips');
    var slotHint = document.getElementById('slotHint');
    var slotInput = document.getElementById('preferSlot');
    function fetchSlots() {
      var cid = cSel ? cSel.value : '';
      var date = dInp ? dInp.value : '';
      if (!cid || !date) { slotPicker.hidden = true; return; }
      fetch(SITE_BASE + '/booking/slots?counselor_id=' + cid + '&date=' + date, { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          slotPicker.hidden = false;
          slotChips.innerHTML = '';
          if (!d.ok || !d.slots.length) {
            slotHint.textContent = (d.msg || '该咨询师这一天暂无排班，可自由填写期望时段，或换一天试试。');
            return;
          }
          slotHint.textContent = '';
          d.slots.forEach(function (t) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'chip';
            b.textContent = t;
            b.addEventListener('click', function () {
              slotInput.value = t;
              slotChips.querySelectorAll('.chip').forEach(function (x) { x.classList.remove('active'); });
              b.classList.add('active');
            });
            slotChips.appendChild(b);
          });
        }).catch(function () { slotPicker.hidden = true; });
    }
    if (cSel) cSel.addEventListener('change', fetchSlots);
    if (dInp) dInp.addEventListener('change', fetchSlots);
  }

  // 确认弹窗（前台防御性提交）
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-confirm]');
    if (f && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  });
})();
