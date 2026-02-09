(function(){
  function qs(s, el){ return (el||document).querySelector(s); }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
  function toast(msg,type){ type=type||'info'; const t=qs('#toasts'); const d=document.createElement('div'); d.className='toast '+(type==='err'?'err':(type==='ok'?'ok':'info')); d.textContent=msg; t.appendChild(d); setTimeout(()=>d.remove(),4000); }

  const modal = qs('#modalRegister');
  const openReg = ()=> modal.hidden=false;
  const closeReg = ()=> modal.hidden=true;
  ['openRegister','openRegister2'].forEach(id=> qs('#'+id)?.addEventListener('click', e=>{e.preventDefault(); openReg();}));
  qs('[data-close="#modalRegister"]')?.addEventListener('click', closeReg);
  document.addEventListener('keydown', e=>{ if(e.key==='Escape' && !modal.hidden) closeReg(); });

  const formLogin = qs('#formLogin');
  formLogin?.addEventListener('submit', async e=>{
    e.preventDefault();
    const fd = new FormData(formLogin);
    fd.append('csrf', document.querySelector('meta[name="csrf-token"]').content||'');
    try{
      const r = await fetch('login.php',{method:'POST', body:fd});
      const j = await r.json();
      if(j.ok){ toast('Вы успешно авторизованы','ok'); setTimeout(()=>location.href='modules.php', 350); }
      else{ toast(j.error||'Ошибка входа','err'); }
    }catch{ toast('Ошибка запроса','err'); }
  });

  const formRegister = qs('#formRegister');
  formRegister?.addEventListener('submit', async e=>{
    e.preventDefault();
    const fd = new FormData(formRegister);
    fd.append('csrf', document.querySelector('meta[name="csrf-token"]').content||'');
    try{
      const r = await fetch('register.php',{method:'POST', body:fd});
      const j = await r.json();
      if(j.ok){ toast('Аккаунт создан. Войдите, используя указанные данные.','ok'); closeReg(); }
      else{ toast(j.error||'Ошибка регистрации','err'); }
    }catch{ toast('Ошибка запроса','err'); }
  });

  const formQuick = qs('#formQuick');
  const quickResult = qs('#quickResult');
  formQuick?.addEventListener('submit', async e=>{
    e.preventDefault();
    const code = (new FormData(formQuick).get('code')||'').toString().replace(/\D/g,'').slice(0,4);
    if(code.length<3 || code.length>4){ toast('Введите 3–4 цифры','err'); return; }
    try{
      const r = await fetch('field_get.php?code='+encodeURIComponent(code));
      const j = await r.json();
      if(j.ok && j.field){
        const f=j.field;
        quickResult.innerHTML = `<div class="glass pane">
          <div class="row gap" style="justify-content:space-between;">
            <h4>Участок ${esc(f.field_code)}</h4>
            <span class="badge">${esc(f.culture||'—')}</span>
          </div>
          <div class="muted sm">Площадь: ${esc(f.area_ha||'—')} га</div>
          <div class="muted sm">Сев: ${esc(f.sow_date||'—')}</div>
          <div class="muted sm">Полив: ${esc(f.last_water_date||'—')}</div>
          <div class="muted sm">Уборка: ${esc(f.harvest_date||'—')}</div>
          <div class="muted sm">Валовый сбор: ${esc(f.gross_yield||'—')}</div>
          <div class="muted sm">Средняя урожайность: ${esc(f.avg_yield||'—')}</div>
          <div class="muted sm">Заметки: ${esc(f.notes||'—')}</div>
        </div>`;
      }else{
        quickResult.innerHTML = `<div class="glass pane"><b>Данных нет.</b> Участок ${esc(code)} отсутствует.</div>`;
      }
    }catch{ quickResult.innerHTML = `<div class="glass pane">Ошибка запроса</div>`; }
  });

  // --- Mobile UX fix: prevent unwanted auto keyboard on first paint ---
  // iOS Safari/некоторые Android браузеры могут восстанавливать фокус на input при заходе,
  // из-за чего самопроизвольно открывается клавиатура. Блокируем авто-фокус до первого жеста.
  (function(){
    try{
      var isCoarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
      if(!isCoarse) return;
      var userInteracted=false;
      ['touchstart','pointerdown','mousedown','keydown'].forEach(function(evt){
        window.addEventListener(evt, function(){ userInteracted=true; }, {once:true,capture:true,passive:true});
      });
      function blurActive(){
        var ae=document.activeElement;
        if(!ae) return;
        var tag=(ae.tagName||'').toUpperCase();
        if(tag==='INPUT' || tag==='TEXTAREA' || ae.isContentEditable){ try{ ae.blur(); }catch(_){ } }
      }
      window.addEventListener('pageshow', function(){
        setTimeout(function(){ if(!userInteracted) blurActive(); }, 0);
      });
      document.addEventListener('focusin', function(e){
        if(userInteracted) return;
        var t=e && e.target;
        if(!t) return;
        var tag=(t.tagName||'').toUpperCase();
        if(tag==='INPUT' || tag==='TEXTAREA') setTimeout(function(){ try{ t.blur(); }catch(_){ } }, 0);
      }, true);
    }catch(_){ }
  })();
})();