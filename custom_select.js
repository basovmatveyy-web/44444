/* custom_select.js v3 — широкий клик (по всей области label) и устойчивость */
(function(){
  function qs(s,el){return (el||document).querySelector(s);}

  function buildListFromSelect(sel, listEl, labelEl){
    listEl.innerHTML = '';
    var opts = sel.options;
    for(var i=0;i<opts.length;i++){
      var o=opts[i], it=document.createElement('div');
      it.className='select-item'; it.textContent=o.text; it.dataset.value=o.value;
      it.addEventListener('click', function(e){
        e.stopPropagation();
        var v=e.currentTarget.dataset.value;
        sel.value=v; labelEl.textContent=e.currentTarget.textContent;
        listEl.parentNode.classList.remove('open');
        sel.dispatchEvent(new Event('change', {bubbles:true}));
      });
      listEl.appendChild(it);
    }
  }

  function enhance(sel){
    if(!sel || sel.dataset.enhanced) return;
    sel.dataset.enhanced='1';

    var wrap=document.createElement('div'); wrap.className='select-wrap';
    var head=document.createElement('div'); head.className='select-head'; head.setAttribute('role','button'); head.tabIndex=0;
    var label=document.createElement('span');
    label.textContent= sel.options[sel.selectedIndex]?.text || '— не выбрано —';
    var caret=document.createElement('i'); caret.className='select-caret';
    head.appendChild(label); head.appendChild(caret);
    var list=document.createElement('div'); list.className='select-list';
    buildListFromSelect(sel, list, label);

    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(head); wrap.appendChild(list);

    // Широкий клик: по всей области label, где лежит селект
    var labelEl = sel.closest('label');
    var container = labelEl || wrap;

    // iOS: клик по <label> с вложенным <select> открывает нативный picker.
    // После улучшения переносим сам <select> за пределы label,
    // чтобы не вызывать системный список.
    if (labelEl && sel.parentNode === labelEl){
      labelEl.parentNode.insertBefore(sel, labelEl.nextSibling);
    }

    container.addEventListener('click', function(e){
      // игнор клика по айтемам списка
      if(e.target.closest('.select-item')) return;
      // если клик по полю "чем обрабатывалось" справа — не трогаем
      if(!container.contains(wrap)) return;
      wrap.classList.toggle('open');
      e.preventDefault();
      e.stopPropagation();
    });

    // Клавиатура: space/enter
    head.addEventListener('keydown', function(e){
      if(e.key===' ' || e.key==='Enter'){ e.preventDefault(); wrap.classList.toggle('open'); }
    });

    // Клик вне — закрыть
    document.addEventListener('click', function(e){ if(!wrap.contains(e.target)) wrap.classList.remove('open'); });

    // Перестройка при динамической подгрузке options
    var mo = new MutationObserver(function(muts){
      for(var m of muts){ if(m.type==='childList'){ buildListFromSelect(sel, list, label); break; } }
    });
    mo.observe(sel, { childList:true });

    // Синхронизация заголовка при программном изменении value
    sel.addEventListener('change', function(){
      var opt = sel.options[sel.selectedIndex];
      label.textContent = opt ? opt.text : '— не выбрано —';
    });
  }

  function initOnce(){
    var sels = document.querySelectorAll('select[data-ui="custom-select"]');
    for(var i=0;i<sels.length;i++){ enhance(sels[i]); }
  }

  if(document.readyState==='complete' || document.readyState==='interactive'){ setTimeout(initOnce,0); }
  else { document.addEventListener('DOMContentLoaded', initOnce); }

  var modal = qs('#modalEdit');
  if(modal){
    var mo = new MutationObserver(function(){
      if(!modal.hasAttribute('hidden')){
        setTimeout(initOnce, 30);
        setTimeout(initOnce, 120);
      }
    });
    mo.observe(modal, { attributes:true, attributeFilter:['hidden'] });
  }

  window.__enhanceCultureSelect = initOnce;
})();