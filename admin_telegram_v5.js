/* admin_telegram_v5.js — enhanced drop-in (keeps old name).
   UI is redesigned in admin.php/admin.css; mechanics unchanged.
*/
(function(){
  function qs(s, el){ return (el||document).querySelector(s); }
  function qsa(s, el){ return Array.prototype.slice.call((el||document).querySelectorAll(s)); }
  function val(s){ var el=qs(s); return el ? (el.value||'').trim() : ''; }
  function setV(s,v){ var el=qs(s); if(el) el.value = (v==null?'':v); }
  function setT(s,v){ var el=qs(s); if(el) el.textContent = (v==null?'':v); }
  function setC(s,on){ var el=qs(s); if(el) el.checked = !!(on===true||on==='1'||on===1||on==='true'||on==='on'); }

  function pageBase(){
    // Keep correct base even if the site is installed in a subfolder.
    try {
      var u = new URL('.', window.location.href);
      return String(u.href).replace(/\/$/, '');
    } catch(e) {
      return (window.location && window.location.origin) ? window.location.origin : '';
    }
  }

  function esc(s){
    return String(s==null?'':s).replace(/[&<>"']/g,function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
    });
  }

  function toast(t,cls){
    var c=qs('#toasts');
    if(!c){ console.log(t); return; }
    var d=document.createElement('div');
    d.className='toast '+(cls||'info');
    d.textContent=t;
    c.appendChild(d);
    setTimeout(function(){ try{ d.remove(); }catch(e){} }, 3800);
  }

  function api(a,data,method){
    method=method||(data?'POST':'GET');
    var fd=null;
    if(data){
      fd=new FormData();
      Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    }
    var meta=document.querySelector('meta[name="csrf-token"]');
    var hdr={};
    if(meta&&meta.content) hdr['X-CSRF-Token']=meta.content;
    return fetch('admin_api_telegram.php?action='+encodeURIComponent(a), {
      method:method,
      body:fd,
      headers:hdr,
      credentials:'same-origin'
    }).then(function(r){
      return r.text().then(function(text){
        var j=null;
        try{ j = JSON.parse(text); }catch(e){}
        if(!j){
          throw new Error('Сервер вернул не‑JSON ответ');
        }
        return j;
      });
    });
  }

  function apiErr(j, fallback){
    if(!j) return fallback || 'Ошибка';
    return (j.error || j.description || j.message || (j.error_code ? ('Ошибка '+j.error_code) : '')) || (fallback || 'Ошибка');
  }

  function badge(el, ok, txt){
    if(!el) return;
    el.textContent = txt;
    // Keep a subtle visual hint for status; rest is handled by CSS.
    el.style.borderColor = ok ? '#2ecc71' : 'var(--border)';
    el.style.background  = ok ? 'rgba(46,204,113,.08)' : '';
  }

  function normalizeData(j){
    // API returns {settings:{...}}; some older builds returned {data:{...}}.
    if(j && !j.data && j.settings) j.data = j.settings;
    if(j && !j.settings && j.data) j.settings = j.data;
    return j || {};
  }

  function renderSettings(j){
    j = normalizeData(j);

    var botOk = !!(j.bot && (j.bot.ok===true));
    var hook = j.webhook && (j.webhook.result || j.webhook);
    var hookUrl = hook && hook.url ? String(hook.url) : '';
    var hookOk = hookUrl !== '';

    badge(qs('#tgBotBadge'), botOk, botOk ? 'Бот: активен' : 'Бот: не активен');
    badge(qs('#tgHookBadge'), hookOk, hookOk ? ('Webhook: '+hookUrl) : 'Webhook: не установлен');

    if (qs('#tgHookInfo')){
      var meta = (hook && hook.ip_address)
        ? ('IP: '+hook.ip_address+', pending: '+(hook.pending_update_count||0)+', max: '+(hook.max_connections||40))
        : (hookOk ? 'Webhook активен' : '—');
      setT('#tgHookInfo', meta);
    }

    var d = j.data || {};

    // Token is intentionally not auto-filled.
    setV('#tgToken','');
    setV('#tgAdminChat', d.admin_chat || '');
    setC('#tgNotifySave', d.notify_save);
    setC('#tgNotifyLogins', d.notify_logins);
    setC('#tgNotifyUsers', d.notify_users);
    setC('#tgNotifyErrors', d.notify_errors);
    setV('#tgSecret', d.secret || '');

    var tok = (d.token || '').trim();
    var tail = tok ? tok.slice(Math.max(0, tok.length - 6)) : '';
    setT('#tgTokenTail', tok ? ('Токен: …'+tail) : 'Токен: —');
  }

  function setSubsBadge(count){
    var b = qs('#tgSubsBadge');
    if (b) b.textContent = 'Подписчики: '+(count||0);
  }

  function setSubsEmpty(msg, show){
    var e = qs('#tgSubsEmpty');
    if(!e) return;
    if (msg != null) e.textContent = msg;
    e.style.display = show ? 'block' : 'none';
  }

  function filterSubs(){
    var q = (val('#tgSubsSearch') || '').toLowerCase();
    var rows = qsa('#tgSubsList tbody tr');
    if (!rows.length){
      setSubsEmpty('Список пуст', true);
      return;
    }

    var shown = 0;
    rows.forEach(function(tr){
      var hit = !q || (tr.textContent || '').toLowerCase().indexOf(q) >= 0;
      tr.style.display = hit ? '' : 'none';
      if(hit) shown++;
    });

    if (!q){
      setSubsEmpty('', false);
      return;
    }

    setSubsEmpty(shown ? '' : 'Ничего не найдено', !shown);
  }

  window.loadTg = function loadTg(){
    api('get').then(function(j){
      if(!j || j.ok===false){ toast(apiErr(j,'Ошибка загрузки'),'err'); return; }
      renderSettings(j);
    }).catch(function(e){ toast('Ошибка загрузки: ' + ((e&&e.message)||'Ошибка запроса'),'err'); });
  };

  function save(setHook){
    var payload = {
      token: val('#tgToken'),
      admin_chat: val('#tgAdminChat'),
      notify_save:   (qs('#tgNotifySave')&&qs('#tgNotifySave').checked)   ? '1' : '0',
      notify_logins: (qs('#tgNotifyLogins')&&qs('#tgNotifyLogins').checked) ? '1' : '0',
      notify_users:  (qs('#tgNotifyUsers')&&qs('#tgNotifyUsers').checked)  ? '1' : '0',
      notify_errors: (qs('#tgNotifyErrors')&&qs('#tgNotifyErrors').checked) ? '1' : '0'
    };

    api('save', payload, 'POST').then(function(j){
      if(j && j.ok===false){ toast(apiErr(j,'Не удалось сохранить'),'err'); return; }
      if(setHook){
        api('webhook_set', {base: pageBase()}, 'POST').then(function(x){
          toast((x && x.ok===false) ? apiErr(x,'Webhook не установлен') : 'Сохранено и webhook установлен', (x && x.ok===false) ? 'err' : 'ok');
          window.loadTg();
        }).catch(function(e){
          toast('Webhook не установлен: ' + (e && e.message ? e.message : 'Ошибка'), 'err');
          window.loadTg();
        });
      } else {
        toast('Сохранено','ok');
        window.loadTg();
      }
    }).catch(function(e){ toast('Ошибка сохранения: ' + ((e&&e.message)||'Ошибка запроса'),'err'); });
  }

  function sendTest(){
    var t = val('#tgTestText') || 'Проверка связи с ботом.';
    api('send_test', {text:t}, 'POST').then(function(j){
      toast((j && j.ok===false) ? apiErr(j,'Тест не отправлен') : 'Сообщение отправлено', (j && j.ok===false) ? 'err' : 'ok');
    }).catch(function(e){
      toast('Ошибка отправки: ' + ((e&&e.message)||'Ошибка'), 'err');
    });
  }

  function regen(){
    api('regenerate_secret', null, 'POST').then(function(j){
      if(j && j.ok===false){ toast(apiErr(j,'Ошибка'),'err'); return; }
      setV('#tgSecret', (j && j.secret) || '');
      toast('Секрет обновлён','ok');
    }).catch(function(e){
      toast('Ошибка обновления секрета: ' + ((e&&e.message)||'Ошибка'), 'err');
    });
  }

  function setHook(){
    api('webhook_set', {base: pageBase()}, 'POST').then(function(j){
      toast((j && j.ok===false) ? apiErr(j,'Ошибка') : 'Webhook установлен', (j && j.ok===false) ? 'err' : 'ok');
      window.loadTg();
    }).catch(function(e){
      toast('Ошибка установки webhook: ' + (e && e.message ? e.message : 'Ошибка'), 'err');
    });
  }

  function delHook(){
    api('webhook_delete', null, 'POST').then(function(j){
      toast((j && j.ok===false) ? apiErr(j,'Ошибка') : 'Webhook снят', (j && j.ok===false) ? 'err' : 'ok');
      window.loadTg();
    }).catch(function(e){
      toast('Ошибка снятия webhook: ' + (e && e.message ? e.message : 'Ошибка'), 'err');
    });
  }

  function infoHook(){
    api('webhook_info').then(function(j){
      if (j && j.ok===false){ toast(apiErr(j,'Ошибка'), 'err'); return; }
      var w = j && (j.result || j);
      toast('Webhook: ' + (w && w.url ? w.url : 'нет'), 'info');
    }).catch(function(e){
      toast('Ошибка проверки webhook: ' + (e && e.message ? e.message : 'Ошибка'), 'err');
    });
  }

  function loadSubs(){
    var box = qs('#tgSubsList');
    if(!box) return;

    setSubsEmpty('Загрузка…', true);

    api('subscribers_list').then(function(j){
      if(j && j.ok===false){ toast(j.error||'Ошибка','err'); setSubsEmpty('Не удалось загрузить список', true); return; }

      var items = (j && j.items) || [];
      setSubsBadge(items.length);

      if(!items.length){
        box.innerHTML = '';
        setSubsEmpty('Список пуст', true);
        return;
      }

      var h = '<table class="table"><thead><tr>'+
        '<th>Chat</th><th>Имя</th><th>Тип</th><th>Админ</th><th>Активность</th>'+
        '</tr></thead><tbody>';

      items.forEach(function(s){
        var name = (s.title || ((s.first_name||'')+' '+(s.last_name||'')).trim());
        h += '<tr>'+
          '<td>'+esc(s.chat_id||'')+'</td>'+
          '<td>'+esc(name||'—')+'</td>'+
          '<td>'+esc(s.type||'')+'</td>'+
          '<td>'+(s.is_admin?'да':'нет')+'</td>'+
          '<td>'+esc(s.last_seen_at||'')+'</td>'+
        '</tr>';
      });

      h += '</tbody></table>';
      box.innerHTML = h;
      setSubsEmpty('', false);
      filterSubs();
    }).catch(function(){
      toast('Ошибка запроса','err');
      setSubsEmpty('Не удалось загрузить список', true);
    });
  }

  function broadcast(){
    var text = val('#tgBroadcastText');
    if(!text){ toast('Введите текст рассылки','err'); return; }

    api('broadcast', {text:text}, 'POST').then(function(j){
      if(j && j.ok===false){ toast(j.error||'Ошибка рассылки','err'); return; }

      setV('#tgBroadcastText','');

      // tg_broadcast() returns {sent, failed, fails[]}
      var sent = (j && (j.sent!=null ? j.sent : (j.ok_count||0))) || 0;
      var failed = (j && (j.failed!=null ? j.failed : (j.fail_count||0))) || 0;
      var total = sent + failed;

      setT('#tgBroadcastMeta', 'Отправлено: '+sent+' · Ошибок: '+failed+' · Всего: '+total);
      toast('Рассылка завершена','ok');

      // After broadcast the subscribers count may change (admin chat added), reload.
      loadSubs();
    }).catch(function(){ toast('Ошибка запроса','err'); });
  }

  function init(){
    if(!(qs('#pane-telegram')||qs('#tgToken')||qs('#tgAdminChat'))) return;

    qs('#tgSaveBtn') && qs('#tgSaveBtn').addEventListener('click', function(e){ e.preventDefault(); save(false); });
    qs('#tgSaveAndHookBtn') && qs('#tgSaveAndHookBtn').addEventListener('click', function(e){ e.preventDefault(); save(true); });

    qs('#tgSetHookBtn') && qs('#tgSetHookBtn').addEventListener('click', function(e){ e.preventDefault(); setHook(); });
    qs('#tgDelHookBtn') && qs('#tgDelHookBtn').addEventListener('click', function(e){ e.preventDefault(); delHook(); });
    qs('#tgInfoHookBtn') && qs('#tgInfoHookBtn').addEventListener('click', function(e){ e.preventDefault(); infoHook(); });

    qs('#tgRegenSecretBtn') && qs('#tgRegenSecretBtn').addEventListener('click', function(e){ e.preventDefault(); regen(); });
    qs('#tgTestBtn') && qs('#tgTestBtn').addEventListener('click', function(e){ e.preventDefault(); sendTest(); });

    qs('#tgLoadSubsBtn') && qs('#tgLoadSubsBtn').addEventListener('click', function(e){ e.preventDefault(); loadSubs(); });
    qs('#tgBroadcastBtn') && qs('#tgBroadcastBtn').addEventListener('click', function(e){ e.preventDefault(); broadcast(); });
    qs('#tgBroadcastClearBtn') && qs('#tgBroadcastClearBtn').addEventListener('click', function(e){ e.preventDefault(); setV('#tgBroadcastText',''); setT('#tgBroadcastMeta',''); });

    qs('#tgRefreshBtn') && qs('#tgRefreshBtn').addEventListener('click', function(e){ e.preventDefault(); window.loadTg(); loadSubs(); });

    var search = qs('#tgSubsSearch');
    if(search){
      search.addEventListener('input', function(){ filterSubs(); });
    }

    // Initial
    window.loadTg();
    loadSubs();
  }

  document.addEventListener('DOMContentLoaded', init);
})();
