/*
  Field Editor PRO — UI-логика для модалки "Редактирование участка"
  ---------------------------------------------------------------
  Никаких изменений бизнес-логики/сохранения.
  Только: вкладки, состояния, хоткеи, "живые" бейджи.
*/

(function(){
  function qs(sel, root){ return (root||document).querySelector(sel); }
  function qsa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

  var modal = qs('#modalEdit');
  if (!modal) return;

  var root = qs('[data-fe-edit]', modal) || qs('.fe-edit', modal) || modal;
  var navButtons = qsa('[data-fe-tab]', modal);
  var panels = qsa('[data-fe-panel]', modal);
  var form = qs('#formEdit', modal);

  var navList = qs('.fe-edit__navlist', modal);
  var bottomBar = qs('.fe-edit__bottombar', modal);

  var badgeState = qs('#feBadgeState', modal);
  var badgeCode = qs('#feBadgeCode', modal);
  var badgeArea = qs('#feBadgeArea', modal);

  var inputCode = qs('#edit_field_code', modal);
  var inputArea = qs('#edit_area_ha', modal);
  var selectCulture = qs('#edit_culture_id', modal);

  var scrollEl = qs('#feEditScroll', modal);
  var topbar = qs('#feEditTopbar', modal);

  if (!form || !navButtons.length || !panels.length) return;

  // ---------------- Phone UX v2 (полная переработка) ----------------
  // Цель: на телефонах редактор ощущается как нативное приложение:
  //   - контент всегда скроллится
  //   - разделы = компактная горизонтальная таб-панель
  //   - действия (закрыть/сохранить) = стабильная нижняя панель
  // На ПК/планшетах внешний вид не трогаем.

  function isPhone(){
    return window.matchMedia && (
      window.matchMedia('(max-width: 560px)').matches ||
      window.matchMedia('(pointer: coarse) and (max-width: 980px)').matches
    );
  }

  var main = qs('.fe-edit__main', modal);
  var mtabs = null;

  function cleanupOldPhoneDock(){
    // если пользователь открыл модалку со старым JS (dock), аккуратно раскатываем обратно
    if (!bottomBar) return;
    var oldTabs = qs('.fe-phone-tabs', bottomBar);
    var oldActions = qs('.fe-phone-actions', bottomBar);
    if (!oldTabs && !oldActions) return;

    // вернуть табы в navList
    if (oldTabs && navList) {
      while (oldTabs.firstChild) navList.appendChild(oldTabs.firstChild);
      oldTabs.remove();
    }
    // вернуть экшены обратно в bottomBar
    if (oldActions) {
      var kids = Array.prototype.slice.call(oldActions.children);
      kids.forEach(function(ch){ bottomBar.appendChild(ch); });
      oldActions.remove();
    }
  }

  function mountPhoneLayout(){
    if (!isPhone()) return;
    if (!main || !navList || !scrollEl) return;

    cleanupOldPhoneDock();

    modal.classList.add('fe-phone');

    // создать таббар сразу под верхним хедером (не внутри скролла) — стабильнее на iOS
    mtabs = qs('.fe-mtabs', main);
    if (!mtabs) {
      mtabs = document.createElement('div');
      mtabs.className = 'fe-mtabs';
      mtabs.setAttribute('role', 'tablist');
      mtabs.setAttribute('aria-label', 'Разделы карточки');

      // Переносим таб-кнопки (listeners сохраняются)
      while (navList.firstChild) {
        mtabs.appendChild(navList.firstChild);
      }
      main.insertBefore(mtabs, scrollEl);
    }
  }

  function unmountPhoneLayout(){
    if (!main || !navList) return;
    if (mtabs && mtabs.parentNode) {
      while (mtabs.firstChild) {
        navList.appendChild(mtabs.firstChild);
      }
      mtabs.remove();
      mtabs = null;
    }
    modal.classList.remove('fe-phone');
  }

  function applyResponsiveLayout(){
    // Критично: не монтируем мобильный лейаут, пока модалка скрыта.
    // Иначе класс .fe-phone может сделать модалку видимой через CSS.
    if (modal.hidden) {
      unmountPhoneLayout();
      modal.classList.remove('fe-phone');
      return;
    }
    if (isPhone()) mountPhoneLayout();
    else unmountPhoneLayout();
  }

  // Prevent map gestures from eating scroll while modal is open on iOS
  // (безопасно: не меняет логику, только изоляция событий)
  ['touchstart','touchmove','touchend','gesturestart','gesturechange','gestureend','wheel'].forEach(function(ev){
    modal.addEventListener(ev, function(e){
      if (modal.hidden) return;
      // don't block natural scrolling inside inputs/content
      // but stop propagation so underlying map viewport handlers don't interfere
      e.stopPropagation();
    }, { passive: true });
  });

  function setBadgeState(state){
    if (!badgeState) return;
    badgeState.setAttribute('data-state', state);
    var txt = badgeState.querySelector('span');
    if (!txt) return;
    if (state === 'dirty') txt.textContent = 'Есть изменения';
    else if (state === 'saving') txt.textContent = 'Сохранение…';
    else txt.textContent = 'Без изменений';
  }

  function fmtArea(v){
    if (v == null) return '—';
    var s = String(v).trim();
    if (!s) return '—';
    var n = Number(s.replace(',', '.'));
    if (!isFinite(n)) return s;
    // показываем аккуратно, но без навязчивого форматирования
    var out = (Math.round(n * 100) / 100).toString();
    return out.replace('.', ',');
  }

  function updateBadges(){
    if (badgeCode && inputCode) {
      var c = (inputCode.value || '').toString().trim();
      badgeCode.textContent = 'Код: ' + (c ? c : '—');
    }
    if (badgeArea && inputArea) {
      var a = fmtArea(inputArea.value);
      badgeArea.textContent = 'Площадь: ' + a + (a !== '—' ? ' га' : '');
    }
    // доп. метки можно расширить позже (культура, даты и т.п.)
  }

  function activateTab(name){
    navButtons.forEach(function(b){
      var on = (b.getAttribute('data-fe-tab') === name);
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    panels.forEach(function(p){
      var on = (p.getAttribute('data-fe-panel') === name);
      p.classList.toggle('is-active', on);
    });
    if (scrollEl) {
      scrollEl.scrollTop = 0;
    }
  }

  // tab clicks
  navButtons.forEach(function(b){
    b.addEventListener('click', function(){
      var name = b.getAttribute('data-fe-tab');
      if (!name) return;
      activateTab(name);
    });
  });

  // “dirty” tracking
  var dirty = false;
  function markDirty(){
    if (!dirty) {
      dirty = true;
      setBadgeState('dirty');
    }
  }
  function markClean(){
    dirty = false;
    setBadgeState('clean');
  }

  // Touch highlight (красиво, но ненавязчиво)
  function markTouched(el){
    var wrap = el && el.closest ? el.closest('.fe-field') : null;
    if (wrap) wrap.classList.add('is-touched');
  }

  // Input listeners
  qsa('input, textarea, select', form).forEach(function(el){
    el.addEventListener('input', function(){
      markTouched(el);
      markDirty();
      updateBadges();
    });
    el.addEventListener('change', function(){
      markTouched(el);
      markDirty();
      updateBadges();
    });
  });

  // Submit -> “saving”
  form.addEventListener('submit', function(){
    setBadgeState('saving');
  }, true);

  // Hotkeys: Ctrl+S save, Alt+1..5 tabs
  document.addEventListener('keydown', function(e){
    if (modal.hidden) return;

    var isMac = /Mac|iPhone|iPad/.test(navigator.platform);
    var mod = isMac ? e.metaKey : e.ctrlKey;

    if (mod && e.key.toLowerCase() === 's') {
      e.preventDefault();
      if (form.requestSubmit) form.requestSubmit();
      else form.submit();
      return;
    }

    if (e.altKey && /^[1-9]$/.test(String(e.key || ''))) {
      var idx = parseInt(e.key, 10) - 1;
      if (idx >= 0 && navButtons[idx]) {
        e.preventDefault();
        navButtons[idx].click();
      }
    }
  });

  // Swipe left/right to switch tabs (phones)
  function getActiveIndex(){
    for (var i=0;i<navButtons.length;i++){
      if (navButtons[i].classList.contains('is-active')) return i;
    }
    return 0;
  }
  function switchBy(delta){
    var i = getActiveIndex();
    var next = Math.max(0, Math.min(navButtons.length-1, i + delta));
    if (next === i) return;
    navButtons[next].click();
  }

  if (scrollEl){
    var sx=0, sy=0, tracking=false, consumed=false;
    scrollEl.addEventListener('touchstart', function(e){
      if (modal.hidden || !isPhone()) return;
      if (!e.touches || e.touches.length !== 1) return;
      var t = e.touches[0];
      sx = t.clientX; sy = t.clientY;
      tracking = true; consumed = false;
    }, {passive:true});

    scrollEl.addEventListener('touchmove', function(e){
      if (!tracking || consumed || modal.hidden || !isPhone()) return;
      if (!e.touches || e.touches.length !== 1) return;

      var target = e.target;
      var tag = (target && target.tagName) ? target.tagName.toLowerCase() : '';
      if (tag === 'input' || tag === 'textarea' || tag === 'select') return;

      var t = e.touches[0];
      var dx = t.clientX - sx;
      var dy = t.clientY - sy;

      if (Math.abs(dx) < 52) return;
      if (Math.abs(dx) < Math.abs(dy) * 1.2) return;

      consumed = true;
      // prevent horizontal page bounce
      try { e.preventDefault(); } catch(_){ }
      switchBy(dx < 0 ? 1 : -1);
    }, {passive:false});

    scrollEl.addEventListener('touchend', function(){ tracking=false; }, {passive:true});
    scrollEl.addEventListener('touchcancel', function(){ tracking=false; }, {passive:true});
  }

  // Topbar “depth” on scroll
  if (scrollEl && topbar) {
    scrollEl.addEventListener('scroll', function(){
      topbar.style.boxShadow = (scrollEl.scrollTop > 6)
        ? '0 16px 38px rgba(0,0,0,.35)'
        : 'none';
    });
  }

  // Observe open/close to reset state and focus
  var mo = new MutationObserver(function(){
    if (!modal.hidden) {
      // open
      applyResponsiveLayout();
      activateTab('main');
      markClean();
      updateBadges();
      // фокус на код — удобно
      setTimeout(function(){
        if (inputCode) {
          try { inputCode.focus(); inputCode.select && inputCode.select(); } catch(_){ }
        }
      }, 80);
    } else {
      // close
      markClean();
    }
  });
  mo.observe(modal, { attributes:true, attributeFilter:['hidden'] });

  // initial
  updateBadges();
  applyResponsiveLayout();

  // Перестраиваем лейаут при повороте/ресайзе
  window.addEventListener('resize', function(){
    if (modal.hidden) return;
    applyResponsiveLayout();
  }, { passive: true });

})();
