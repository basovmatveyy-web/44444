// admin_tg.js v2.4 — Telegram tab logic (safe to DOM)
(function(){
  const api = (a,p)=>TG.json(`admin_api_telegram.php?action=${encodeURIComponent(a)}`, p);

  const E = {
    token: TG.$('#tg_token'),
    admin_chat: TG.$('#tg_admin_chat'),
    secret: TG.$('#tg_secret'),
    notify_save: TG.$('#tg_notify_save'),
    notify_logins: TG.$('#tg_notify_logins'),
    notify_users: TG.$('#tg_notify_users'),
    notify_errors: TG.$('#tg_notify_errors'),
    badge_bot: TG.$('[data-tg="bot-status"]'),
    badge_hook: TG.$('[data-tg="hook-status"]'),
    health: TG.$('[data-tg="health"]'),
    btn: {
      save: TG.$('[data-tg="save"]'),
      saveHook: TG.$('[data-tg="save+webhook"]'),
      setHook: TG.$('[data-tg="set-webhook"]'),
      chkHook: TG.$('[data-tg="check-webhook"]'),
      delHook: TG.$('[data-tg="delete-webhook"]'),
      genSecret: TG.$('[data-tg="gen-secret"]'),
      test: TG.$('[data-tg="test"]')
    },
    testText: TG.$('#tg_test')
  };

  const safeSet = (el, val)=>{
    if(!el) return;
    if (Object.prototype.hasOwnProperty.call(el, 'checked')) el.checked = (val==='1'||val===1||val===true);
    else el.value = (val==null) ? '' : String(val);
  };

  async function load(){
    try{
      const r = await api('load');
      if(!r || !r.ok){ throw new Error((r && r.message) ? r.message : "load failed"); }
      const s = r.settings || {};
      safeSet(E.token, s.token);
      safeSet(E.admin_chat, s.admin_chat);
      safeSet(E.secret, s.secret);
      safeSet(E.notify_save, s.notify_save);
      safeSet(E.notify_logins, s.notify_logins);
      safeSet(E.notify_users, s.notify_users);
      safeSet(E.notify_errors, s.notify_errors);
      TG.setBadge(E.badge_bot, r.bot && r.bot.ok, (r.bot && r.bot.result && r.bot.result.username) ? `Бот: @${r.bot.result.username}` : 'Бот: ок', 'Бот: нет');
      TG.setBadge(E.badge_hook, r.webhook && r.webhook.ok, 'Webhook: ок', 'Webhook: нет');
      if (E.health) E.health.textContent = (r.health && r.health.summary) ? r.health.summary : '';
    }catch(e){
      TG.toast(`Не удалось загрузить настройки Telegram: ${e.message}`, 'error');
    }
  }

  async function save(push){
    try{
      const payload = {
        token: E.token ? (E.token.value || '').trim() : '',
        admin_chat: E.admin_chat ? (E.admin_chat.value || '').trim() : '',
        secret: E.secret ? (E.secret.value || '').trim() : '',
        notify_save: E.notify_save && E.notify_save.checked ? 1 : 0,
        notify_logins: E.notify_logins && E.notify_logins.checked ? 1 : 0,
        notify_users: E.notify_users && E.notify_users.checked ? 1 : 0,
        notify_errors: E.notify_errors && E.notify_errors.checked ? 1 : 0,
        push_hook: push ? 1 : 0
      };
      const r = await api('save', payload);
      if(!r || !r.ok){ throw new Error((r && r.message) ? r.message : "save failed"); }
      TG.toast('Сохранено', 'success');
      await load();
    }catch(e){
      TG.toast(`Ошибка сохранения: ${e.message}`, 'error');
    }
  }

  async function setHook(){ try{ const r = await api('set_webhook'); if(!r.ok) throw new Error(); TG.toast('Webhook установлен','success'); await load(); } catch(e){ TG.toast('Ошибка webhook','error'); } }
  async function chkHook(){ try{ const r = await api('check_webhook'); if(!r.ok) throw new Error(); TG.toast('Webhook активен','success'); } catch(e){ TG.toast('Ошибка проверки','error'); } }
  async function delHook(){ try{ const r = await api('delete_webhook'); if(!r.ok) throw new Error(); TG.toast('Webhook снят','success'); await load(); } catch(e){ TG.toast('Ошибка удаления','error'); } }
  async function genSecret(){ try{ const r = await api('gen_secret'); if(!r.ok) throw new Error(); if (E.secret) E.secret.value = r.secret || ''; TG.toast('Секрет обновлён','success'); } catch(e){ TG.toast('Ошибка генерации','error'); } }
  async function test(){ try{ const txt = E.testText ? (E.testText.value || '') : ''; const r = await api('test', { text: txt }); if(!r.ok) throw new Error(); TG.toast('Отправлено','success'); } catch(e){ TG.toast('Ошибка теста','error'); } }

  if (E.btn.save) E.btn.save.addEventListener('click', ()=>save(false));
  if (E.btn.saveHook) E.btn.saveHook.addEventListener('click', ()=>save(true));
  if (E.btn.setHook) E.btn.setHook.addEventListener('click', setHook);
  if (E.btn.chkHook) E.btn.chkHook.addEventListener('click', chkHook);
  if (E.btn.delHook) E.btn.delHook.addEventListener('click', delHook);
  if (E.btn.genSecret) E.btn.genSecret.addEventListener('click', genSecret);
  if (E.btn.test) E.btn.test.addEventListener('click', test);

  document.addEventListener('DOMContentLoaded', load);
})();