/* admin_tg_override.js — v2: replaces legacy window.loadTg with a null-safe version */
(function(){
  function qs(s, el){ return (el||document).querySelector(s); }
  function qid(id){ return document.getElementById(id); }
  function setVal(idOrSel, v){ var el=qid(idOrSel)||qs(idOrSel); if(el && 'value' in el) el.value=(v==null?'':v); }
  function setChk(idOrSel, on){ var el=qid(idOrSel)||qs(idOrSel); if(el) el.checked=!!(on===true||on==='1'||on===1||on==='true'); }
  function badge(el, ok, txt){ if(!el) return; el.textContent=txt; el.style.borderColor=ok?'#2ecc71':'var(--border)'; el.style.background=ok?'rgba(46,204,113,.08)':'rgba(255,255,255,.03)'; }
  function toast(t,cls){ var c=qs('#toasts'); if(!c){ console.log(t); return; } var d=document.createElement('div'); d.className='toast '+(cls||'info'); d.textContent=t; c.appendChild(d); setTimeout(function(){ d.remove(); }, 3800); }
  function ensureAdvancedBlocks(){
    var root = qs('#pane-telegram') || qs('#tgPane') || document;
    if (!qs('#tgBotBadge')){
      var div=document.createElement('div');
      div.className='glass';
      div.style='display:flex;gap:12px;align-items:center;padding:10px 12px;margin-bottom:12px';
      div.innerHTML='<div id="tgBotBadge" class="badge" style="padding:6px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.03)">Бот: —</div>\
      <div id="tgHookBadge" class="badge" style="padding:6px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.03)">Webhook: —</div>\
      <div id="tgSubsBadge" class="badge" style="padding:6px 10px;border-radius:10px;border:1px solid var(--border);background:rgba(255,255,255,.03)">Подписчики: 0</div>\
      <div id="tgTokenTail" class="muted" style="margin-left:auto">Токен: —</div>';
      var h2 = root.querySelector('h2'); (h2&&h2.parentNode)?h2.parentNode.insertBefore(div,h2.nextSibling):root.prepend(div);
    }
    if (!qs('#tgSubsList')){
      var wrap=document.createElement('div');
      wrap.style='display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:12px;margin-top:12px';
      wrap.innerHTML='<div class="glass" style="padding:12px"><div class="row" style="justify-content:space-between; align-items:center"><h3 style="margin:0">Подписчики</h3><button class="btn ghost" id="tgLoadSubsBtn" type="button">Обновить</button></div><div id="tgSubsList" style="max-height:280px; overflow:auto; margin-top:8px"></div></div>\
      <div class="glass" style="padding:12px"><h3 style="margin:0 0 8px 0">Широковещательная рассылка</h3><textarea id="tgBroadcastText" class="input" placeholder="Текст для всех подписчиков" style="width:100%; height:140px; resize:vertical"></textarea><div class="row" style="margin-top:8px; gap:8px"><button class="btn" id="tgBroadcastBtn" type="button">Отправить всем</button></div><div class="muted" id="tgBroadcastMeta" style="margin-top:6px"></div></div>\
      <div class="glass" style="padding:12px"><h3 style="margin:0 0 8px 0">Информация о webhook</h3><div id="tgHookInfo" class="muted">—</div></div>';
      root.appendChild(wrap);
    }
  }
  function api(a,data,method){ method=method||(data?'POST':'GET'); var fd=null; if(data){ fd=new FormData(); Object.keys(data).forEach(function(k){ fd.append(k,data[k]); }); } var meta=document.querySelector('meta[name=\"csrf-token\"]'); var hdr={}; if(meta&&meta.content) hdr['X-CSRF-Token']=meta.content; return fetch('admin_api_telegram.php?action='+encodeURIComponent(a), {method:method, body:fd, headers:hdr, credentials:'same-origin'}).then(function(r){return r.json();}); }
  function render(j){
    var botOk=j&&j.bot&&(j.bot.ok===true); var hook=j&&j.webhook&&(j.webhook.result||j.webhook); var hookOk=!!(hook&&((hook.url||'')!==''));
    badge(document.querySelector('#tgBotBadge'),botOk,botOk?'Бот: активен':'Бот: не активен');
    badge(document.querySelector('#tgHookBadge'),hookOk,hookOk?('Webhook: '+(hook.url||'—')):'Webhook: не установлен');
    if (document.querySelector('#tgHookInfo')){ var meta=(hook&&(hook.ip_address?('IP: '+hook.ip_address+', pending: '+(hook.pending_update_count||0)+', max: '+(hook.max_connections||40)):'—'))||'—'; document.querySelector('#tgHookInfo').textContent=meta; }
    var d = (j&&j.data) || (j&&j.settings) || {};
    setVal('#tgToken',''); setVal('#tgAdminChat', d.admin_chat||''); setVal('tgAdminChat', d.admin_chat||''); setVal('admin_chat', d.admin_chat||'');
    setChk('#tgNotifySave', d.notify_save); setChk('#tgNotifyLogins', d.notify_logins); setChk('#tgNotifyUsers', d.notify_users); setChk('#tgNotifyErrors', d.notify_errors);
    setVal('#tgSecret', d.secret||''); setVal('tgSecret', d.secret||''); setVal('secret', d.secret||'');
    var sb=document.querySelector('#tgSubsBadge'); if(sb) sb.textContent='Подписчики: '+(d.subs_count||0);
    var tt=document.querySelector('#tgTokenTail'); if(tt) tt.textContent=(d.has_token?('Токен: …'+(d.token_tail||'')):'Токен: —');
  }
  window.loadTg = function(){ ensureAdvancedBlocks(); api('get').then(function(j){ if(!j||j.ok===false){ var err=(j&&j.error)||'Ошибка загрузки'; return (function(){var c=document.querySelector('#toasts'); if(!c){console.log(err);return;} var d=document.createElement('div'); d.className='toast err'; d.textContent=err; c.appendChild(d); setTimeout(function(){ d.remove(); },3800);}()); } if(!j.data&&j.settings) j.data=j.settings; render(j); }).catch(function(e){ var err='Не удалось загрузить настройки Telegram: '+(e&&e.message||'Ошибка'); var c=document.querySelector('#toasts'); if(!c){console.log(err);return;} var d=document.createElement('div'); d.className='toast err'; d.textContent=err; c.appendChild(d); setTimeout(function(){ d.remove(); },3800); }); };
  function loadSubs(){ api('subscribers').then(function(j){ if(j.ok===false){ return; } var h='<table class=\"table\"><thead><tr><th>Chat</th><th>Имя</th><th>Тип</th><th>Админ</th><th>Активность</th></tr></thead><tbody>'; (j.items||[]).forEach(function(s){ var name=(s.title||(s.first_name||'')+' '+(s.last_name||'')).trim(); h+='<tr><td>'+(s.chat_id||'')+'</td><td>'+(name||'—')+'</td><td>'+(s.type||'')+'</td><td>'+(s.is_admin?'да':'нет')+'</td><td>'+(s.last_seen_at||'')+'</td></tr>'; }); h+='</tbody></table>'; var box=document.querySelector('#tgSubsList'); if(box) box.innerHTML=h; var b=document.querySelector('#tgSubsBadge'); if(b) b.textContent='Подписчики: '+(j.count||0); }); }
  function broadcast(){ var t=(document.querySelector('#tgBroadcastText')||{}).value||''; if(!t){ return; } api('broadcast',{text:t},'POST').then(function(j){ if(j.ok===false){ return; } var a=document.querySelector('#tgBroadcastText'); if(a) a.value=''; var r=j.result||{}; var m=document.querySelector('#tgBroadcastMeta'); if(m) m.textContent='Отправлено: '+(r.ok_count||0)+' · Ошибок: '+(r.fail_count||0)+' · Всего: '+(r.total||0); }); }
  document.addEventListener('DOMContentLoaded', function(){ ensureAdvancedBlocks(); var b=document.querySelector('#tgLoadSubsBtn'); if(b) b.addEventListener('click', function(e){e.preventDefault(); loadSubs();}); var s=document.querySelector('#tgBroadcastBtn'); if(s) s.addEventListener('click', function(e){e.preventDefault(); broadcast();}); window.loadTg(); loadSubs(); });
  setTimeout(function(){ if(typeof window.loadTg==='function') window.loadTg(); }, 800);
})();
