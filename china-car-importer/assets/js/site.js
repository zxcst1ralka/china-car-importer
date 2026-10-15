/* China Car Importer — frontend 2.0 */
(function () {
  'use strict';
  var D = window.cciData || {};
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return [].slice.call((c || document).querySelectorAll(s)); };
  var fine = matchMedia('(hover:hover) and (pointer:fine)').matches;
  var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
  var fmt = function (n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '\u00A0'); };
  var clamp = function (x, a, b) { return Math.min(b, Math.max(a, x)); };

  /* ---------- header ---------- */
  var hdr = $('.hdr');
  function onScroll() { if (hdr) hdr.classList.toggle('is-scrolled', scrollY > 20); }
  onScroll(); addEventListener('scroll', onScroll, { passive: true });
  var burger = $('.burger');
  if (burger) {
    burger.addEventListener('click', function () { document.body.classList.toggle('menu-open'); });
    $$('.mobile-menu a').forEach(function (a) { a.addEventListener('click', function () { document.body.classList.remove('menu-open'); }); });
  }

  /* ---------- reveal ---------- */
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) {
      if (!e.isIntersecting) return;
      e.target.classList.add('in'); io.unobserve(e.target);
      $$('[data-count]', e.target).concat(e.target.hasAttribute('data-count') ? [e.target] : []).forEach(countUp);
    });
  }, { threshold: .15, rootMargin: '0px 0px -6% 0px' }) : null;
  $$('.rv').forEach(function (el, i) { if (io) io.observe(el); else el.classList.add('in'); });

  function countUp(el) {
    if (el._done) return; el._done = true;
    var to = parseFloat(el.getAttribute('data-count')) || 0, t0 = performance.now(), dur = 1400;
    (function step(t) {
      var p = clamp((t - t0) / dur, 0, 1), e = 1 - Math.pow(1 - p, 4);
      el.textContent = fmt(to * e);
      if (p < 1) requestAnimationFrame(step);
    })(t0);
  }

  /* ---------- hero: scroll-scrubbed video ---------- */
  (function () {
    var hero = $('[data-hero]'); if (!hero) return;
    var v = $('video', hero), stages = $$('.stage', hero);
    var fill = $('.route-line i', hero), dot = $('.route-line b', hero), day = $('[data-day]', hero);
    var cur = 0, target = 0, dur = 8, ready = false, last = -1, lite = false;
    function meta() { dur = v.duration || 8; ready = true; v.pause(); }
    if (v) {
      v.muted = true;
      var conn = navigator.connection || {};
      var slow = conn.saveData || /(^|-)(2g|3g)$/.test(conn.effectiveType || '');
      if (slow) {
        // экономим трафик: без видео, только постер и обычная прокрутка
        lite = true; hero.classList.add('lite'); v.style.display = 'none'; v.removeAttribute('src');
      } else {
        var startVideo = function () { v.preload = 'auto'; v.load(); };
        if (document.readyState === 'complete') startVideo(); else addEventListener('load', startVideo, { once: true });
      }
      if (lite) { /* без видео */ }
      else if (reduce) { v.loop = true; v.play().catch(function () {}); }
      else {
        v.addEventListener('loadedmetadata', meta);
        if (v.readyState >= 1) meta();
        var unlock = function () { v.play().then(function () { v.pause(); }).catch(function () {}); };
        ['touchstart', 'pointerdown', 'scroll'].forEach(function (ev) { addEventListener(ev, unlock, { once: true, passive: true }); });
      }
    }
    function prog() { var r = hero.getBoundingClientRect(); return clamp(-r.top / (hero.offsetHeight - innerHeight), 0, 1); }
    function ui(p) {
      var n = stages.length, i = Math.min(n - 1, Math.floor(p * n * .999));
      if (i !== last) { stages.forEach(function (s, k) { s.classList.toggle('on', k === i); }); last = i; }
      if (fill) fill.style.width = (p * 100) + '%';
      if (dot) dot.style.left = (p * 100) + '%';
      if (day) day.textContent = 'день ' + Math.round(p * 25);
      hero.classList.toggle('moved', p > .02);
    }
    if (lite) { ui(0); return; }
    (function loop() {
      var r = hero.getBoundingClientRect();
      if (r.bottom > -100 && r.top < innerHeight + 100) {
        target = reduce ? 0 : prog();
        cur += (target - cur) * .14; if (Math.abs(target - cur) < .0005) cur = target;
        if (ready && v && !reduce) { var t = cur * (dur - .05); if (Math.abs(v.currentTime - t) > .02) v.currentTime = t; }
        ui(cur);
      }
      requestAnimationFrame(loop);
    })();
  })();

  /* ---------- tilt + button glow ---------- */
  if (fine) {
    $$('.card').forEach(function (c) {
      c.addEventListener('pointermove', function (e) {
        var r = c.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - .5, y = (e.clientY - r.top) / r.height - .5;
        c.style.setProperty('--ry', (x * 6).toFixed(2) + 'deg'); c.style.setProperty('--rx', (-y * 6).toFixed(2) + 'deg');
      });
      c.addEventListener('pointerleave', function () { c.style.setProperty('--ry', '0deg'); c.style.setProperty('--rx', '0deg'); });
    });
    $$('.btn').forEach(function (b) {
      b.addEventListener('pointermove', function (e) {
        var r = b.getBoundingClientRect();
        b.style.setProperty('--mx', (e.clientX - r.left) + 'px'); b.style.setProperty('--my', (e.clientY - r.top) + 'px');
      });
    });
  }

  /* ---------- showcase: drag + progress ---------- */
  $$('.showcase').forEach(function (s) {
    var bar = s.parentNode.querySelector('.sc-bar i');
    function upd() {
      if (!bar) return;
      var w = s.clientWidth / s.scrollWidth;
      bar.style.width = clamp(w * 100, 8, 100) + '%';
      bar.style.left = (s.scrollLeft / s.scrollWidth * 100) + '%';
    }
    s.addEventListener('scroll', upd, { passive: true }); addEventListener('resize', upd); upd();
    var down = false, sx = 0, sl = 0, moved = false;
    s.addEventListener('pointerdown', function (e) { if (e.pointerType !== 'mouse') return; down = true; moved = false; sx = e.clientX; sl = s.scrollLeft; });
    addEventListener('pointermove', function (e) {
      if (!down) return; var dx = e.clientX - sx; if (Math.abs(dx) > 4) { moved = true; s.classList.add('drag'); }
      s.scrollLeft = sl - dx;
    });
    addEventListener('pointerup', function () { down = false; setTimeout(function () { s.classList.remove('drag'); }, 30); });
    s.addEventListener('click', function (e) { if (moved) { e.preventDefault(); e.stopPropagation(); moved = false; } }, true);
  });

  /* ---------- lead modal ---------- */
  var modal = $('#cciModal');
  function openModal(d) {
    if (!modal) return;
    $('#mLot').value = d.lot || '';
    $('#mTitle').textContent = d.title || '';
    $('#mPrice').textContent = d.price || '';
    var img = $('#mPhoto'); if (d.photo) { img.src = d.photo; img.style.display = ''; } else img.style.display = 'none';
    modal.classList.add('open'); document.body.style.overflow = 'hidden';
    setTimeout(function () { var f = $('input[name=client_name]', modal); f && f.focus(); }, 350);
  }
  function closeModal() { if (!modal) return; modal.classList.remove('open'); document.body.style.overflow = ''; }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-open-lead]');
    if (b) { e.preventDefault(); openModal({ lot: b.dataset.lot, title: b.dataset.title, price: b.dataset.price, photo: b.dataset.photo }); }
    if (e.target.closest('[data-close-modal]')) closeModal();
  });
  addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

  /* ---------- toast ---------- */
  var toast = $('[data-toast]');
  if (toast) {
    setTimeout(function () { toast.classList.add('show'); }, 300);
    setTimeout(function () { toast.classList.remove('show'); }, 9000);
    toast.addEventListener('click', function () { toast.classList.remove('show'); });
    if (history.replaceState) {
      var u = new URL(location.href); ['cci_notice', 'cci_flash'].forEach(function (k) { u.searchParams.delete(k); });
      history.replaceState(null, '', u.toString());
    }
  }

  /* ---------- catalog filters: auto-submit ---------- */
  $$('form[data-auto]').forEach(function (f) {
    var t; var go = function () { clearTimeout(t); t = setTimeout(function () { f.submit(); }, 120); };
    $$('input[type=radio],select', f).forEach(function (el) { el.addEventListener('change', go); });
    $$('input[type=number]', f).forEach(function (el) { el.addEventListener('change', go); });
  });

  /* ---------- calculator ---------- */
  (function () {
    var box = $('[data-calc]'); if (!box) return;
    var res = $('.res', box), big = $('[data-total]', box), stack = $('[data-stack]', box), legend = $('[data-legend]', box);
    var inP = $('#c-price'), inC = $('#c-cc'), inH = $('#c-hp'), timer, shown = 0, last = null;
    function paintRange(r) { r.style.setProperty('--p', ((r.value - r.min) / (r.max - r.min) * 100) + '%'); }
    function vals() {
      var age = $('input[name=age]:checked', box).value, eng = $('input[name=eng]:checked', box).value;
      return { price: +inP.value, cc: +inC.value, hp: +inH.value, age: age, eng: eng };
    }
    function labels() {
      var v = vals(), ev = v.eng === 'ev';
      $('[data-o-price]', box).textContent = fmt(v.price) + ' ¥';
      $('[data-o-cc]', box).textContent = ev ? '—' : (v.cc / 1000).toFixed(1) + ' л';
      $('[data-o-hp]', box).textContent = v.hp + ' л.с.';
      inC.disabled = ev; inC.closest('.fld').style.opacity = ev ? .35 : 1;
      [inP, inC, inH].forEach(paintRange);
    }
    function animateTo(n) {
      var from = shown, t0 = performance.now();
      (function s(t) { var p = clamp((t - t0) / 700, 0, 1), e = 1 - Math.pow(1 - p, 3); shown = from + (n - from) * e; big.textContent = fmt(shown) + '\u00A0₽'; if (p < 1) requestAnimationFrame(s); })(t0);
    }
    function render(c) {
      last = c;
      var parts = [
        ['Автомобиль в Китае', c.car_price_rub], ['Логистика до Уссурийска', c.logistics_rub], ['Таможенная пошлина и налоги', c.duty_rub],
        ['Утильсбор и таможенный сбор', c.util_fee_rub + c.customs_fee_rub], ['СВХ, лаборатория, ЭПТС', c.ussuriysk_rub], ['Комиссия', c.commission_rub]
      ];
      stack.innerHTML = parts.map(function (p, i) { return '<i class="c' + i + '" style="width:' + (p[1] / c.total_rub * 100).toFixed(2) + '%"></i>'; }).join('');
      legend.innerHTML = parts.map(function (p, i) { return '<li class="c' + i + '"><span>' + p[0] + '</span><b>' + fmt(p[1]) + '\u00A0₽</b></li>'; }).join('');
      animateTo(c.total_rub); res.classList.remove('loading');
    }
    function run() {
      labels(); res.classList.add('loading');
      var v = vals(), fd = new FormData();
      fd.append('action', 'cci_calc'); fd.append('price_cny', v.price); fd.append('cc', v.cc); fd.append('hp', v.hp); fd.append('age', v.age); fd.append('eng', v.eng);
      fetch(D.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); })
        .then(function (j) { if (j && j.success) render(j.data); }).catch(function () { res.classList.remove('loading'); });
    }
    $$('input', box).forEach(function (el) { el.addEventListener('input', function () { labels(); clearTimeout(timer); timer = setTimeout(run, 160); }); });
    var fix = $('[data-calc-fix]', box);
    if (fix) fix.addEventListener('click', function () {
      var v = vals(), ta = $('#sel-wishes'); if (!ta || !last) return;
      var ageT = { new: 'до 3 лет', mid: '3–5 лет', old: 'старше 5 лет' }[v.age], engT = { petrol: 'бензин', diesel: 'дизель', hybrid: 'гибрид', ev: 'электро' }[v.eng];
      ta.value = 'Расчёт с сайта: авто ' + fmt(v.price) + ' ¥, возраст ' + ageT + ', ' + engT + (v.eng === 'ev' ? '' : ', ' + (v.cc / 1000).toFixed(1) + ' л') + ', ' + v.hp + ' л.с. Итого под ключ ≈ ' + fmt(last.total_rub) + ' ₽.';
      document.dispatchEvent(new CustomEvent('cci:quiz-step', { detail: 3 }));
      var s = $('#selection'); if (s) s.scrollIntoView({ behavior: 'smooth' });
    });
    labels(); run();
  })();

  /* ---------- journey map ---------- */
  (function () {
    var box = $('[data-journey]'); if (!box) return;
    var fg = $('.fg', box), car = $('.jcar', box), nodes = $$('.node', box), nls = $$('.nl', box), steps = $$('.step', box);
    var len = fg.getTotalLength(); fg.style.strokeDasharray = len;
    nodes.forEach(function (n, i) {
      var pt = fg.getPointAtLength(len * (+n.dataset.at));
      n.setAttribute('cx', pt.x); n.setAttribute('cy', pt.y);
      if (nls[i]) { nls[i].setAttribute('x', pt.x); nls[i].setAttribute('y', pt.y + (i % 2 ? -26 : 42)); }
    });
    function upd() {
      var r = box.getBoundingClientRect(), pin = $('.journey-pin', box), p;
      if (pin && getComputedStyle(pin).position === 'sticky') {
        // блок закреплён: прогресс = путь скролла внутри «трека» (финал держится ещё ~15%)
        p = clamp(-r.top / ((r.height - innerHeight) * .85), 0, 1);
      } else {
        var m = $('.jmap', box).getBoundingClientRect();
        p = clamp((innerHeight * .85 - m.top) / (innerHeight * .55), 0, 1);
      }
      fg.style.strokeDashoffset = len * (1 - p);
      var a = fg.getPointAtLength(len * p), b = fg.getPointAtLength(Math.min(len, len * p + 2));
      car.setAttribute('transform', 'translate(' + a.x + ' ' + a.y + ') rotate(' + (Math.atan2(b.y - a.y, b.x - a.x) * 180 / Math.PI) + ')');
      nodes.forEach(function (n, i) {
        var on = p >= (+n.dataset.at) - .002; n.classList.toggle('on', on);
        if (nls[i]) nls[i].classList.toggle('on', on); if (steps[i]) steps[i].classList.toggle('on', on && (i === nodes.length - 1 || p < (+nodes[i + 1].dataset.at) - .002));
      });
    }
    addEventListener('scroll', upd, { passive: true }); addEventListener('resize', upd); upd();
  })();

  /* ---------- VIN page ---------- */
  (function () {
    var box = $('[data-vin]'); if (!box) return;
    var inp = $('#vin-q', box), cells = $('.vcells', box), mode = $('.mode', box), modeTxt = $('[data-mode-txt]', box), modePrice = $('[data-mode-price]', box), btn = $('[data-pay-label]', box);
    var pv = +box.dataset.vin, pu = +box.dataset.url;
    for (var i = 0; i < 17; i++) cells.appendChild(document.createElement('i'));
    function upd() {
      var v = inp.value.trim().toUpperCase(), isVin = /^[A-HJ-NPR-Z0-9]{17}$/.test(v), urlLike = /[\/.:]/.test(v) || v.length > 17;
      $$('i', cells).forEach(function (c, k) { var ch = !urlLike && v[k]; c.textContent = ch || ''; c.className = ch ? 'f' : (!urlLike && k === v.length ? 'n' : ''); });
      cells.classList.toggle('ok', isVin); cells.style.opacity = urlLike ? .25 : 1;
      var price = urlLike ? pu : pv;
      mode.classList.toggle('hold', urlLike);
      modePrice.textContent = fmt(price) + '\u00A0₽';
      modeTxt.textContent = urlLike ? 'Ссылка на объявление: деньги замораживаются (холд) и списываются только после того, как менеджер подтвердит, что отчёт возможен.' : (isVin ? 'VIN корректный. Оплата проходит сразу, отчёт формируется автоматически.' : 'Введите 17 символов VIN (без букв I, O, Q) или вставьте ссылку на лот.');
      if (box.dataset.nopay) {
        modeTxt.textContent = 'Стоимость проверки. Менеджер свяжется с вами, выставит счёт и после оплаты отправит отчёт на e-mail.';
        btn.textContent = 'Отправить заявку';
      } else {
        btn.textContent = (urlLike ? 'Заморозить ' : 'Оплатить ') + fmt(price) + '\u00A0₽';
      }
    }
    inp.addEventListener('input', upd); upd();
  })();

  /* ---------- цели аналитики (Яндекс.Метрика) ---------- */
  var goalsDone = {};
  function goal(name, once) {
    if (once && goalsDone[name]) return; goalsDone[name] = 1;
    try { if (window.ym && D.ym) { window.ym(D.ym, 'reachGoal', name); } } catch (e) {}
  }
  document.addEventListener('click', function (e) {
    var g = e.target.closest('[data-goal]'); if (g) goal(g.getAttribute('data-goal'));
    var t = e.target.closest('a[href^="tel:"]'); if (t) goal('phone_click');
    if (e.target.closest('[data-open-lead]')) goal('lead_modal_open');
  });
  var calcBox = $('[data-calc]'); if (calcBox) calcBox.addEventListener('input', function () { goal('calc_used', true); });
  var vinBox = $('[data-vin]'); if (vinBox) {
    vinBox.addEventListener('focusin', function () { goal('vin_started', true); });
    var vf = $('form', vinBox); if (vf) vf.addEventListener('submit', function () { goal('vin_submit'); });
  }
  var mf = $('#cciModal form'); if (mf) mf.addEventListener('submit', function () { goal('lead_submit'); });

  /* ---------- квиз (форма подбора из 3 шагов) ---------- */
  $$('form[data-quiz]').forEach(function (f) {
    var steps = $$('.quiz-step', f), bar = $('.quiz-progress i', f), cur = 0;
    f.classList.add('js-quiz');
    function show(n) {
      cur = Math.max(0, Math.min(steps.length - 1, n));
      steps.forEach(function (s, i) { s.classList.toggle('on', i === cur); });
      if (bar) bar.style.width = ((cur + 1) / steps.length * 100) + '%';
      if (cur === 1) goal('quiz_step_2', true);
    }
    f.addEventListener('click', function (e) {
      if (e.target.closest('[data-next]')) { e.preventDefault(); show(cur + 1); }
      if (e.target.closest('[data-prev]')) { e.preventDefault(); show(cur - 1); }
    });
    f.addEventListener('submit', function () { goal('quiz_submit'); });
    document.addEventListener('cci:quiz-step', function (e) { show((e.detail || 1) - 1); });
    show(0);
  });

  /* ---------- галерея на странице автомобиля ---------- */
  $$('[data-gallery]').forEach(function (g) {
    var main = $('[data-gal-main]', g);
    $$('[data-gal-thumb]', g).forEach(function (b) {
      b.addEventListener('click', function () {
        if (main) main.src = b.getAttribute('data-src');
        $$('[data-gal-thumb]', g).forEach(function (x) { x.classList.toggle('on', x === b); });
      });
    });
  });

  /* ---------- уведомление о cookie ---------- */
  var ck = $('[data-cookie]');
  if (ck) {
    var seen = false; try { seen = localStorage.getItem('cci_cookie_ok') === '1'; } catch (e) {}
    if (!seen) setTimeout(function () { ck.hidden = false; ck.classList.add('show'); }, 1200);
    var ok = $('[data-cookie-ok]', ck);
    if (ok) ok.addEventListener('click', function () { try { localStorage.setItem('cci_cookie_ok', '1'); } catch (e) {} ck.classList.remove('show'); setTimeout(function () { ck.hidden = true; }, 400); });
  }
})();
