/* admin_telegram.js v3 — null-safe + graceful UI */
(function(){
  function qs(s, el){ return (el||document).querySelector(s); }
  function setV(id, v){ var el=qs(id); if (!el) return; try{ el.value=(v==null?'':v); }catch(e){ console.warn('setV',id,e); } }
  function setC(id, on){ var el=qs(id); if (!el) return; try{ el.checked=!!on; }catch(e){ console.warn('setC',id,e); } }
  function b(v){ return (v===true||v==='1'||v===1||v==='true'||v==='on'); }
  function toastMsg(m,t){ try{ window.toast && window.toast(m, t||'info', 4000); }catch(e){} }

  function apiJson(url, opts){
    if (typeof window.api==='function') return window.api(url, opts);
    opts=opts||{}; if (!opts.credentials) opts.credentials='same-origin';
    return fetch(url, opts).then(function(r){ return r.json(); });
  }

  function render(j){
    try {
      var s=(j && (j.settings||j.data)) || {};
      setV('#tgToken', s.token || s.bot_token || '');
      setV('#tgAdminChat', s.admin_chat || s.admin_chat_id || '');
      setV('#tgSecret', s.secret || '');
      setC('#tgNotifySave', b(s.notify_save) || b(s.notify_on_save));
      setC('#tgNotifyLogins', b(s.notify_logins) || b(s.notify_on_login));
      var meta=qs('#tgBotMeta');
      if (meta) {
        var parts=[];
        if (j && j.bot && typeof j.bot.ok!=='undefined') parts.push(j.bot.ok?'Бот: активен':'Бот: не активен');
        if (j && j.webhook && typeof j.webhook.ok!=='undefined') parts.push(j.webhook.ok?'Webhook: установлен':'Webhook: не установлен');
        meta.textContent=parts.join(' · ');
      }
    } catch(e){
      console.error('[tg] render error', e);
      toastMsg('Не удалось загрузить настройки Telegram: '+e.message, 'err');
    }
  }

  window.loadTg = function(){
    if (!qs('#pane-telegram')) return Promise.resolve();
    return apiJson('admin_api_telegram.php?action=get')
      .then(function(j){ render(j); return j; })
      .catch(function(e){ console.error('[tg] load err', e); toastMsg('Не удалось загрузить настройки Telegram: '+(e&&e.message||e),'err'); });
  };

  function onceBind(sel, fn){
    var el=qs(sel); if (!el) return;
    if (el.__tgBound) return; el.__tgBound = true;
    el.addEventListener('click', fn);
  }
  function val(sel){ var el=qs(sel); return el ? (el.value||'').trim() : ''; }

  document.addEventListener('DOMContentLoaded', function(){
    onceBind('#tgSaveBtn', function(e){
      e.preventDefault();
      var fd=new FormData();
      fd.append('token', val('#tgToken'));
      fd.append('admin_chat', val('#tgAdminChat'));
      fd.append('notify_save', qs('#tgNotifySave') && qs('#tgNotifySave').checked ? '1':'0');
      fd.append('notify_logins', qs('#tgNotifyLogins') && qs('#tgNotifyLogins').checked ? '1':'0');
      apiJson('admin_api_telegram.php?action=save', {method:'POST', body:fd})
        .then(function(){ toastMsg('Настройки сохранены','ok'); return window.loadTg(); })
        .catch(function(e){ console.error(e); toastMsg('Ошибка сохранения','err'); });
    });
    onceBind('#tgSaveAndHookBtn', function(e){
      e.preventDefault();
      var fd=new FormData();
      fd.append('token', val('#tgToken'));
      fd.append('admin_chat', val('#tgAdminChat'));
      fd.append('notify_save', qs('#tgNotifySave') && qs('#tgNotifySave').checked ? '1':'0');
      fd.append('notify_logins', qs('#tgNotifyLogins') && qs('#tgNotifyLogins').checked ? '1':'0');
      apiJson('admin_api_telegram.php?action=save', {method:'POST', body:fd})
        .then(function(){ return apiJson('admin_api_telegram.php?action=webhook_set', {method:'POST'}); })
        .then(function(){ toastMsg('Сохранено и webhook установлен','ok'); return window.loadTg(); })
        .catch(function(e){ console.error(e); toastMsg('Ошибка установки webhook','err'); });
    });
    onceBind('#tgSetHookBtn', function(e){
      e.preventDefault();
      apiJson('admin_api_telegram.php?action=webhook_set', {method:'POST'})
        .then(function(){ toastMsg('Webhook установлен','ok'); return window.loadTg(); })
        .catch(function(e){ console.error(e); toastMsg('Не удалось поставить webhook','err'); });
    });
    onceBind('#tgDelHookBtn', function(e){
      e.preventDefault();
      apiJson('admin_api_telegram.php?action=webhook_delete', {method:'POST'})
        .then(function(){ toastMsg('Webhook снят','ok'); return window.loadTg(); })
        .catch(function(e){ console.error(e); toastMsg('Не удалось снять webhook','err'); });
    });
    onceBind('#tgInfoHookBtn', function(e){
      e.preventDefault();
      apiJson('admin_api_telegram.php?action=webhook_info')
        .then(function(j){ toastMsg('Webhook: '+ ((j.webhook && j.webhook.url) || 'нет'), 'info', 5000); })
        .catch(function(e){ console.error(e); toastMsg('Не удалось получить информацию','err'); });
    });
    onceBind('#tgRegenSecretBtn', function(e){
      e.preventDefault();
      apiJson('admin_api_telegram.php?action=regenerate_secret', {method:'POST'})
        .then(function(j){ setV('#tgSecret', (j&&j.secret)||''); toastMsg('Секрет обновлён','ok'); })
        .catch(function(e){ console.error(e); toastMsg('Не удалось обновить секрет','err'); });
    });
    onceBind('#tgTestBtn', function(e){
      e.preventDefault();
      var fd=new FormData(); fd.append('text', val('#tgTestText') || 'Проверка связи с ботом.');
      apiJson('admin_api_telegram.php?action=send_test', {method:'POST', body:fd})
        .then(function(){ toastMsg('Тест отправлен','ok'); })
        .catch(function(e){ console.error(e); toastMsg('Не удалось отправить тест','err'); });
    });
  });
})();