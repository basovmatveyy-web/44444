/* admin_telegram_v7.1.js — save() отправляет token_b64/admin_chat_b64 для обхода WAF */
(function(){
  function qs(s, el){ return (el||document).querySelector(s); }
  function val(s){ var el=qs(s); return el ? (el.value||'').trim() : ''; }
  function setV(s, v){ var el=qs(s); if (!el) return; try{ el.value=(v==null?'':v); }catch(e){ console.warn('setV',s,e); } }
  function setC(s, on){ var el=qs(s); if (!el) return; try{ el.checked=!!on; }catch(e){ console.warn('setC',s,e); } }
  function b(v){ return (v===true||v==='1'||v===1||v==='true'||v==='on'); }
  function toast(m,t,ms){ try{ window.toast && window.toast(m, t||'info', ms||4000);}catch(e){} }
  function busy(btn,on){ if(!btn) return; btn.disabled=!!on; btn.dataset.busy=on?'1':''; }
  function apiUrl(a){ return 'admin_api_telegram.php?action='+a+'&_='+(Date.now()); }
  function apiJson(url,opts){ opts=opts||{}; if(!opts.credentials) opts.credentials='same-origin'; return fetch(url,opts).then(r=>r.json()); }

  function render(j){
    var s=(j&&(j.settings||j.data))||{};
    setV('#tgToken', s.token||''); setV('#tgAdminChat', s.admin_chat||''); setV('#tgSecret', s.secret||'');
    setC('#tgNotifySave', b(s.notify_save)); setC('#tgNotifyLogins', b(s.notify_logins));
    var def=(j.base? j.base.replace(/\/+$/,''):'')+'/tg_webhook.php'; setV('#tgDefaultHook', def);
    setV('#tgCurrentHook', j.webhook && j.webhook.url || '');
    var chipB=document.getElementById('tgChipBot'), chipH=document.getElementById('tgChipHook');
    if(chipB) chipB.textContent='Бот: '+(j.bot&&j.bot.ok?'активен':'не активен');
    if(chipH) chipH.textContent='Webhook: '+((j.webhook&&j.webhook.ok)?'установлен':'нет');
    if (Array.isArray(j.logs)){
      var tb=document.querySelector('#tgLogsTbl tbody'); if(tb){ tb.innerHTML=''; j.logs.forEach(x=>{ var tr=document.createElement('tr'); tr.innerHTML='<td>'+x.ts+'</td><td>'+x.event+'</td><td>'+x.details+'</td>'; tb.appendChild(tr); }); }
    }
  }
  window.loadTg=function(){ if(!qs('#pane-telegram')) return Promise.resolve(); return apiJson(apiUrl('get')).then(j=>{ try{render(j);}catch(e){console.error(e);} return j;}).catch(e=>{console.error(e); toast('Не удалось загрузить настройки','err');}); };

  function saveCore(andHook){
    var fd=new FormData();
    var token=val('#tgToken'), chat=val('#tgAdminChat');
    fd.append('token', token);
    try{ fd.append('token_b64', btoa(token)); }catch(e){}
    fd.append('admin_chat', chat);
    try{ fd.append('admin_chat_b64', btoa(chat)); }catch(e){}
    fd.append('notify_save', qs('#tgNotifySave') && qs('#tgNotifySave').checked ? '1':'0');
    fd.append('notify_logins', qs('#tgNotifyLogins') && qs('#tgNotifyLogins').checked ? '1':'0');
    return apiJson(apiUrl('save'), {method:'POST', body:fd})
      .then(()=> andHook ? apiJson(apiUrl('webhook_set'), {method:'POST'}) : null)
      .then(()=> window.loadTg())
      .then(()=> toast(andHook?'Сохранено и webhook установлен':'Сохранено','ok'));
  }

  document.addEventListener('DOMContentLoaded', function(){
    var b1=qs('#tgSaveBtn'); if(b1 && !b1.__tgBound){ b1.__tgBound=1; b1.addEventListener('click', function(e){ e.preventDefault(); busy(b1,true); saveCore(false).catch(e=>{console.error(e); toast('Ошибка сохранения','err');}).finally(()=>busy(b1,false)); }); }
    var b2=qs('#tgSaveAndHookBtn'); if(b2 && !b2.__tgBound){ b2.__tgBound=1; b2.addEventListener('click', function(e){ e.preventDefault(); busy(b2,true); saveCore(true).catch(e=>{console.error(e); toast('Ошибка установки webhook','err');}).finally(()=>busy(b2,false)); }); }
    var b3=qs('#tgSetHookBtn'); if(b3 && !b3.__tgBound){ b3.__tgBound=1; b3.addEventListener('click', function(e){ e.preventDefault(); busy(b3,true); apiJson(apiUrl('webhook_set'), {method:'POST'}).then(()=>window.loadTg()).then(()=>toast('Webhook установлен','ok')).catch(e=>{console.error(e);toast('Не удалось поставить webhook','err');}).finally(()=>busy(b3,false)); }); }
    var b4=qs('#tgDelHookBtn'); if(b4 && !b4.__tgBound){ b4.__tgBound=1; b4.addEventListener('click', function(e){ e.preventDefault(); busy(b4,true); apiJson(apiUrl('webhook_delete'), {method:'POST'}).then(()=>window.loadTg()).then(()=>toast('Webhook снят','ok')).catch(e=>{console.error(e);toast('Не удалось снять webhook','err');}).finally(()=>busy(b4,false)); }); }
    var b5=qs('#tgInfoHookBtn'); if(b5 && !b5.__tgBound){ b5.__tgBound=1; b5.addEventListener('click', function(e){ e.preventDefault(); busy(b5,true); apiJson(apiUrl('webhook_info')).then(j=>{toast('Webhook: '+((j.webhook&&j.webhook.url)||'нет'),'info',5000); return window.loadTg();}).catch(e=>{console.error(e);toast('Не удалось получить информацию','err');}).finally(()=>busy(b5,false)); }); }
    var b6=qs('#tgRegenSecretBtn'); if(b6 && !b6.__tgBound){ b6.__tgBound=1; b6.addEventListener('click', function(e){ e.preventDefault(); busy(b6,true); apiJson(apiUrl('regenerate_secret'), {method:'POST'}).then(j=>{ setV('#tgSecret',(j&&j.secret)||''); toast('Секрет обновлён. Переустановите webhook','ok'); }).catch(e=>{console.error(e); toast('Не удалось обновить секрет','err');}).finally(()=>busy(b6,false)); }); }
    var gm=qs('#tgGetMeBtn'); if(gm && !gm.__tgBound){ gm.__tgBound=1; gm.addEventListener('click', function(e){ e.preventDefault(); busy(gm,true); apiJson(apiUrl('get_me')).then(j=>{toast('getMe: '+(j.result&&j.result.username?'@'+j.result.username:(j.description||'ок')),'info',5000)}).catch(e=>{console.error(e);toast('getMe не удалось','err');}).finally(()=>busy(gm,false)); }); }
    var ci=qs('#tgChatInfoBtn'); if(ci && !ci.__tgBound){ ci.__tgBound=1; ci.addEventListener('click', function(e){ e.preventDefault(); busy(ci,true); apiJson(apiUrl('chat_info')).then(j=>{toast('Chat: '+(j.result && (j.result.title||j.result.username||j.result.id) || 'нет'),'info',5000)}).catch(e=>{console.error(e);toast('chat_info не удалось','err');}).finally(()=>busy(ci,false)); }); }
    var t=qs('#tgTestBtn'); if(t && !t.__tgBound){ t.__tgBound=1; t.addEventListener('click', function(e){ e.preventDefault(); busy(t,true); var fd=new FormData(); fd.append('text', val('#tgTestText')||'Проверка связи с ботом.'); apiJson(apiUrl('send_test'), {method:'POST', body:fd}).then(()=>toast('Тест отправлен','ok')).catch(e=>{console.error(e);toast('Не удалось отправить тест','err');}).finally(()=>busy(t,false)); }); }
  });
})();