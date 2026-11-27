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

  // 咨询师服务方式：前台过滤，提交时仍由服务端复核
  var bookingForm = document.querySelector('form[action$="/booking"]');
  if (bookingForm) {
    var counselorSel = bookingForm.querySelector('select[name=counselor_id]');
    var serviceSel = document.getElementById('serviceSel');
    var serviceHint = document.getElementById('serviceHint');
    var methodSel = document.getElementById('methodSel');
    var methodHint = document.getElementById('methodHint');
    var preferDate = bookingForm.querySelector('input[name=prefer_date]');
    var summaryTitle = document.getElementById('bookingSummaryTitle');
    var summaryMethod = document.getElementById('bookingSummaryMethod');
    var summaryService = document.getElementById('bookingSummaryService');
    var summaryDate = document.getElementById('bookingSummaryDate');
    function syncSummary() {
      var counselor = counselorSel && counselorSel.options[counselorSel.selectedIndex];
      var service = serviceSel && serviceSel.options[serviceSel.selectedIndex];
      if (summaryTitle) summaryTitle.textContent = counselor && counselor.value ? counselor.textContent : '由机构推荐咨询师';
      if (summaryMethod && methodSel) summaryMethod.textContent = methodSel.value;
      if (summaryService) summaryService.textContent = service && service.value ? service.textContent : '暂不确定';
      if (summaryDate) {
        var value = preferDate ? preferDate.value : '';
        var parts = value ? value.split('-') : [];
        summaryDate.textContent = parts.length === 3 ? Number(parts[1]) + '月' + Number(parts[2]) + '日' : '待选择';
      }
    }
    function syncServices() {
      if (!counselorSel || !serviceSel) return;
      var counselor = counselorSel.options[counselorSel.selectedIndex];
      var allowed = counselor && counselor.hasAttribute('data-services')
        ? counselor.getAttribute('data-services').split(',').filter(Boolean) : null;
      var previous = serviceSel.value;
      Array.prototype.forEach.call(serviceSel.options, function (option) {
        option.hidden = !!allowed && option.value !== '' && allowed.indexOf(option.value) === -1;
        option.disabled = option.hidden;
      });
      if (previous && serviceSel.querySelector('option[value="' + previous + '"]').disabled) serviceSel.value = '';
      var selected = serviceSel.options[serviceSel.selectedIndex];
      if (serviceHint) serviceHint.textContent = allowed && allowed.length === 0
        ? '该咨询师尚未关联服务，请选择其他咨询师或提交后由机构协助安排。'
        : (selected && selected.value ? '服务标价：' + selected.getAttribute('data-price') + '；分时定价优先。' : '仅可选择该咨询师实际提供的服务；分时定价优先于服务标价');
    }
    function syncMethods() {
      if (!counselorSel || !methodSel) return;
      var selected = counselorSel.options[counselorSel.selectedIndex];
      var modes = selected && selected.getAttribute('data-modes')
        ? selected.getAttribute('data-modes').split(',').filter(Boolean) : [];
      var previous = methodSel.value;
      Array.prototype.forEach.call(methodSel.options, function (option) {
        option.hidden = modes.length > 0 && modes.indexOf(option.value) === -1;
        option.disabled = option.hidden;
      });
      if (modes.length > 0 && modes.indexOf(previous) === -1) methodSel.value = modes[0];
      if (methodHint) methodHint.textContent = modes.length > 0
        ? '该咨询师支持：' + modes.join(' / ')
        : '该咨询师未单独设置方式，可选择全部咨询方式';
    }
    if (counselorSel) {
      counselorSel.addEventListener('change', function () { syncServices(); syncMethods(); syncSummary(); });
    }
    if (serviceSel) serviceSel.addEventListener('change', syncSummary);
    if (methodSel) methodSel.addEventListener('change', syncSummary);
    if (preferDate) preferDate.addEventListener('change', syncSummary);
    syncServices();
    syncMethods();
    syncSummary();
  }

  // 预约时段选择器：选了咨询师+日期后拉取可约时段
  var slotPicker = document.getElementById('slotPicker');
  if (slotPicker) {
    var cSel = slotPicker.closest('form').querySelector('select[name=counselor_id]');
    var dInp = slotPicker.closest('form').querySelector('input[name=prefer_date]');
    var slotChips = document.getElementById('slotChips');
    var slotHint = document.getElementById('slotHint');
    var slotRequest = 0;
    function fetchSlots() {
      var requestId = ++slotRequest;
      var cid = cSel ? cSel.value : '';
      var date = dInp ? dInp.value : '';
      var form = slotPicker.closest('form');
      var methodSel = form.querySelector('select[name=method]');
      var method = methodSel ? methodSel.value : '';
      if (!cid || !date || !method) { slotPicker.hidden = true; return; }
      fetch(SITE_BASE + '/booking/slots?counselor_id=' + encodeURIComponent(cid) + '&date=' + encodeURIComponent(date) + '&method=' + encodeURIComponent(method), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (requestId !== slotRequest) return;
          slotPicker.hidden = false;
          slotChips.innerHTML = '';
          var availableSlots = (d.slots_full || []).filter(function (s) { return s.available; });
          if (!d.ok || !availableSlots.length) {
            slotHint.textContent = d.closed_note || d.msg || '该咨询师这一天暂无可约时段，可换一天或用文字填写期望时间。';
            return;
          }
          var service = form.querySelector('select[name=service_id]');
          slotHint.textContent = '点击具体时段进入锁定预约；已约满时段不会显示。';
          availableSlots.forEach(function (s) {
            var href = d.confirm_url + '?counselor=' + encodeURIComponent(cid) + '&date=' + encodeURIComponent(date) + '&start=' + encodeURIComponent(s.start);
            if (service && service.value) href += '&service=' + encodeURIComponent(service.value);
            href += '&method=' + encodeURIComponent(method);
            var a = document.createElement('a');
            a.className = 'chip';
            a.href = href;
            a.textContent = s.label;
            slotChips.appendChild(a);
          });
        }).catch(function () { if (requestId === slotRequest) slotPicker.hidden = true; });
    }
    if (cSel) cSel.addEventListener('change', fetchSlots);
    if (dInp) dInp.addEventListener('change', fetchSlots);
    var methodSlotSel = slotPicker.closest('form').querySelector('select[name=method]');
    if (methodSlotSel) methodSlotSel.addEventListener('change', fetchSlots);
    var serviceSlotSel = slotPicker.closest('form').querySelector('select[name=service_id]');
    if (serviceSlotSel) serviceSlotSel.addEventListener('change', fetchSlots);
    fetchSlots();
  }

  // 确认弹窗（前台防御性提交）
  document.addEventListener('submit', function (e) {
    var f = e.target.closest('form[data-confirm]');
    if (f && !window.confirm(f.getAttribute('data-confirm'))) e.preventDefault();
  });
})();
