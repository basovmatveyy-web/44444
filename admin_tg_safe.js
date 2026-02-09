// admin_tg_safe.js — безошибочный фронт Telegram (v3)
(function () {
  const qs = (s, r = document) => r.querySelector(s);
  const pick = (...sels) => sels.map(s => qs(s)).find(Boolean) || null;
  const csrf = qs('meta[name="csrf-token"]')?.getAttribute('content') || '';

  // ——— утилиты, НИКОГДА не пишут в null ———
  const setVal = (elOrSel, v) => {
    const el = typeof elOrSel === 'string' ? pick(elOrSel) : elOrSel;
    if (el && typeof el.value !== 'undefined') el.value = v ?? '';
  };
  const setChk = (elOrSel, on) => {
    const el = typeof elOrSel === 'string' ? pick(elOrSel) : elOrSel;
    if (el && typeof el.checked !== 'undefined') {
      el.checked = on === true || on === 1 || on === '1' || on === 'true';
    }
  };
  const getVal = (elOrSel) => {
    const el = typeof elOrSel === 'string' ? pick(elOrSel) : elOrSel;
    return el && typeof el.value !== 'undefined' ? el.value : '';
  };
  const getChk = (elOrSel) => {
    const el = typeof elOrSel === 'string' ? pick(elOrSel) : elOrSel;
    return el && typeof el.checked !== 'undefined' ? (el.checked ? '1' : '0') : '0';
  };
  const toast = (type, text) => {
    const wrap = qs('#toasts');
    if (!wrap) { console[type === 'err' ? 'error' : 'log'](text); return; }
    const d = document.createElement('div');
    d.className = 'toast ' + (type === 'err' ? 'err' : type === 'ok' ? 'ok' : 'info');
    d.textContent = text;
    wrap.appendChild(d);
    setTimeout(() => d.remove(), 3800);
  };

  // Если на странице вообще нет панели Telegram — тихо выходим.
  if (!qs('#tgPane')) return;

  // Ссылки на элементы c фолбэками
  const EL = {
    token:      pick('#tgToken','input[name="tg_token"]','[data-tg="token"]'),
    adminChat:  pick('#tgAdminChat','input[name="tg_admin_chat"]','[data-tg="admin_chat"]'),
    secret:     pick('#tgSecret','input[name="tg_secret"]','[data-tg="secret"]'),
    save:       pick('#tgNotifySave','input[name="tg_notify_save"]','[data-tg="notify_save"]'),
    logins:     pick('#tgNotifyLogins','input[name="tg_notify_logins"]','[data-tg="notify_logins"]'),
    meta:       pick('#tgBotMeta','[data-tg="meta"]'),
    btnSave:    qs('#tgSaveBtn'),
    btnTest:    qs('#tgTestBtn'),
    btnRegen:   qs('#tgRegenSecretBtn'),
    testText:   pick('#tgTestText','[data-tg="test_text"]'),
  };

  async function api(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    if (data) Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch('admin_api_telegram.php', {
      method: 'POST',
      body: fd,
      headers: csrf ? { 'X-CSRF': csrf } : {}
    });
    let j = null;
    try { j = await r.json(); } catch (e) { j = { ok: false, error: 'bad json' }; }
    return j;
  }

  async function load() {
    try {
      const j = await api('load');
      if (!j.ok) { toast('err', 'Ошибка загрузки: ' + (j.error || '')); return; }

      const s = j.settings || {};
      setVal(EL.token, s.token || '');
      setVal(EL.adminChat, s.admin_chat || '');
      setVal(EL.secret, s.secret || '');
      setChk(EL.save, s.notify_save);
      setChk(EL.logins, s.notify_logins);

      if (EL.meta) {
        if (j.bot && j.bot.ok && j.bot.result) {
          const me = j.bot.result;
          EL.meta.innerHTML = `Бот: <b>${me.first_name || ''}</b> @${me.username || ''} (id ${me.id})`;
        } else if ((s.token || '') !== '') {
          EL.meta.textContent = 'Токен задан, но getMe не вернулся ok.';
        } else {
          EL.meta.textContent = 'Токен не задан.';
        }
      }
    } catch (e) {
      toast('err', 'Ошибка загрузки: ' + e.message);
    }
  }

  async function save() {
    const j = await api('save', {
      token: getVal(EL.token).trim(),
      admin_chat: getVal(EL.adminChat).trim(),
      notify_save: getChk(EL.save),
      notify_logins: getChk(EL.logins)
    });
    if (!j.ok) { toast('err', 'Не удалось сохранить: ' + (j.error || '')); return; }
    toast('ok', 'Сохранено');
    load();
  }

  async function sendTest() {
    const text = (getVal(EL.testText) || 'Проверка связи с ботом.');
    const j = await api('send_test', { text });
    if (!j.ok) { toast('err', 'Тест не отправлен: ' + (j.error || '')); return; }
    toast('ok', 'Сообщение отправлено');
  }

  document.addEventListener('click', (e) => {
    if (e.target === EL.btnSave) { e.preventDefault(); save(); }
    if (e.target === EL.btnTest) { e.preventDefault(); sendTest(); }
    if (e.target === EL.btnRegen) {
      e.preventDefault();
      api('regenerate_secret').then(j => {
        if (j.ok) { setVal(EL.secret, j.secret || ''); toast('ok', 'Секрет обновлён'); }
        else { toast('err', j.error || 'Ошибка'); }
      });
    }
  });

  load();
})();
