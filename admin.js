
function eventRu(e){
  var map = {
    set_role: 'Изменение роли',
    create_culture: 'Добавлена культура',
    update_culture: 'Изменена культура',
    delete_culture: 'Удалена культура',
    create_user: 'Создан пользователь',
    update_user: 'Изменён пользователь',
    delete_user: 'Удалён пользователь',
    login_ok: 'Вход в систему',
    login_fail: 'Ошибка входа',
    field_create: 'Добавлен участок',
    field_update: 'Изменены данные участка',
    field_treatment: 'Добавлена обработка участка',
    tg_save: 'Сохранение изм. TG',
    tg_set_hook: 'Включён вебхук TG',
    tg_del_hook: 'Отключён вебхук TG',
    tg_test: 'Тест TG',
    tg_broadcast: 'Рассылка TG'
  };
  if(!e) return '';
  return map[e] || e.replace(/_/g,' ');
}
(function(){
  function qs(s, el){ return (el||document).querySelector(s); }
  function qsa(s, el){ return Array.prototype.slice.call((el||document).querySelectorAll(s)); }
  function ce(tag, cls){ var d=document.createElement(tag); if(cls) d.className=cls; return d; }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]; }); }

  function api(url, opts){
    opts = opts || {};
    if (!opts.credentials) opts.credentials = 'same-origin';
    return fetch(url, opts).then(function(r){
      return r.text().then(function(text){
        var data = null;
        try{ data = JSON.parse(text); }catch(e){}
        if (!r.ok || (data && data.ok===false)){
          var msg = (data && (data.error || data.message)) || ('HTTP '+r.status);
          throw new Error(msg);
        }
        return data || { ok:true, raw:text };
      });
    });
  }

  var toasts = qs('#toasts');
  function toast(msg, type, timeout){
    type = type || 'info'; timeout = timeout || 3500;
    var d = ce('div','toast '+(type||'')); d.textContent = msg;
    toasts.appendChild(d);
    var tm = setTimeout(function(){ try{ d.remove(); }catch(e){} }, timeout);
    d.addEventListener('mouseenter', function(){ clearTimeout(tm); });
  }

  var navToggle = qs('#navToggle'), scrim = qs('#scrim');
  function closeNav(){ document.body.classList.remove('nav-open'); }
  navToggle && navToggle.addEventListener('click', function(){ document.body.classList.toggle('nav-open'); });
  scrim && scrim.addEventListener('click', closeNav);

  qsa('.menu-item').forEach(function(btn){
  btn.addEventListener('click', function(){
    qsa('.menu-item').forEach(function(x){ x.classList.toggle('active', x===btn); });
    var tab = btn.dataset.tab;

    // Show only the requested content pane (main tabs)
    qsa('.panes > .pane').forEach(function(p){
      var show = (p.id === 'pane-' + tab);
      p.classList.toggle('show', show);
      p.style.display = show ? 'block' : 'none';
    });

    // Telegram pane lives outside .panes
    (function(){
      var tg = qs('#pane-telegram');
      if (tg){ tg.style.display = (tab === 'telegram') ? 'block' : 'none'; }
    })();

    // KPI grid only on Overview
    (function(){
      var kpi = qs('.kpi-grid');
      if (kpi){ kpi.style.display = (tab === 'overview') ? '' : 'none'; }
    })();

    closeNav();

    // Axenta fullscreen mode should only live on its own tab (mobile)
    if (tab !== 'axenta') {
      try{ if (window.__axentaExit) window.__axentaExit(); }catch(e){}
      document.body.classList.remove('axenta-mobile');
      document.documentElement.classList.remove('axenta-mobile');
    }

    if (tab==='overview') loadOverview();
    if (tab==='users') loadUsers();
    if (tab==='cultures') loadCultures();
    if (tab==='logs') loadLogs();
    if (tab==='history') loadHistory(true);
    if (tab==='telegram') { if (window.loadTg) window.loadTg(); else loadTg(); }
    if (tab==='axenta') initAxenta();
  });
});

  function setKpi(id, val){ var el = qs(id); if(el) el.textContent = val; }
  function logCard(item){
    var d = ce('div','log-item');
    var dot = ce('div','dot');
    var text = ce('div','text');
    var time = ce('div','time');
    time.textContent = item.created_at || '';
    text.textContent = eventRu(item.event||'') + (item.username ? ' — ' + item.username : '');
    d.appendChild(dot); d.appendChild(text); d.appendChild(time);
    return d;
  }
  function loadOverview(){
    var stream = qs('#logStream'); if(stream){ stream.innerHTML = ''; }
    Promise.all([
      api('admin_api_users.php?action=list').catch(()=>({ok:false,items:[]})),
      api('admin_api_cultures.php?action=list').catch(()=>({ok:false,items:[]})),
      api('admin_api_logs.php?action=list&limit=200').catch(()=>({ok:false,items:[]}))
    ]).then(function([u,c,l]){
      setKpi('#kpiUsers', (u.ok && u.items && u.items.length) || 0);
      setKpi('#kpiCultures', (c.ok && c.items && c.items.length) || 0);
      setKpi('#kpiLogs', (l.ok && l.items && l.items.length) || 0);

      if (stream){
        ((l.ok && l.items) ? l.items.slice(0,8) : []).forEach(function(x){ stream.appendChild(logCard(x)); });
      }

      var lastUsers = (u.ok && u.items) ? u.items.slice(-7) : [];
      var tbU = qs('#tblLastUsers tbody'); if (tbU){ tbU.innerHTML=''; }
	      lastUsers.forEach(function(x){
        var tr = document.createElement('tr');
	        tr.innerHTML = '<td>'+x.id+'</td><td>'+esc(x.username)+'</td><td>'+esc(roleLabel(x.role||'user'))+'</td>';
        tbU.appendChild(tr);
      });
    });
  }

  var tblUsers = qs('#tblUsers tbody');
  function roleLabel(r){
    if (r==='admin') return 'Админ';
    if (r==='buh') return 'Бухгалтер';
    return 'Пользователь';
  }
  function loadUsers(){
    api('admin_api_users.php?action=list')
      .then(function(j){
        tblUsers.innerHTML = '';
        (j.items||[]).forEach(function(u){
          var tr = ce('tr');
	          tr.innerHTML = '<td data-label="ID">'+u.id+'</td>'+'<td data-label="Логин">'+esc(u.username)+'</td>'+'<td data-label="Фамилия">'+esc(u.surname||'')+'</td>'+'<td data-label="Имя">'+esc(u.name||'')+'</td>'+'<td data-label="Роль"><span class="role-badge">'+esc(roleLabel(u.role||'user'))+'</span></td>'+'<td data-label="Действие">'+roleButtons(u)+'</td>';
          tblUsers.appendChild(tr);
          bindUserButtons(tr, u);
        });
      })
      .catch(function(err){ toast('Не удалось загрузить пользователей: '+err.message,'err'); });
  }
	  function roleButtons(u){
    var r = u.role || 'user';
    if (r==='admin'){
      // админ и так имеет доступ ко всему, не предлагаем "сделать бухгалтером" (это демот)
      return '<button class="btn" data-role="user" data-id="'+u.id+'">Снять админа</button>';
    }
    if (r==='buh'){
      return '<button class="btn" data-role="user" data-id="'+u.id+'">Снять бухгалтера</button>'+
             '<button class="btn primary" data-role="admin" data-id="'+u.id+'">Сделать админом</button>';
    }
    return '<button class="btn" data-role="buh" data-id="'+u.id+'">Сделать бухгалтером</button>'+
           '<button class="btn primary" data-role="admin" data-id="'+u.id+'">Сделать админом</button>';
  }

  function bindUserButtons(tr, u){
    qsa('button[data-id="'+u.id+'"]', tr).forEach(function(b){
      b.addEventListener('click', function(){
        var role = b.dataset.role;
        var fd = new FormData(); fd.append('id', u.id); fd.append('role', role);
        api('admin_api_users.php?action=set_role', {method:'POST', body: fd})
          .then(function(){ toast('Роль обновлена','ok'); loadUsers(); loadOverview(); })
          .catch(function(err){ toast('Ошибка обновления роли: '+err.message,'err'); });
      });
    });
  }

  // -------------------- КУЛЬТУРЫ --------------------
  var tblCultures = qs('#tblCultures tbody');
  var culturesAll = [];
  var cultureFilter = '';

  function setCulturesCount(n){
    var el = qs('#culturesCount');
    if (el) el.textContent = String(n);
  }
  function setCulturesEmpty(show){
    var el = qs('#culturesEmpty');
    if (!el) return;
    el.hidden = !show;
    el.setAttribute('aria-hidden', show ? 'false' : 'true');
  }
  function normStr(s){ return (s||'').toString().toLowerCase().trim(); }

  function renderCultures(){
    if (!tblCultures) return;
    var q = normStr(cultureFilter);
    var all = culturesAll || [];
    var items = all;

    setCulturesCount(all.length);

    if (q){
      items = all.filter(function(c){
        return normStr(c.title).indexOf(q) !== -1 || String(c.id).indexOf(q) !== -1;
      });
    }

    tblCultures.innerHTML = '';

    if (!items.length){
      setCulturesEmpty(true);
      return;
    }
    setCulturesEmpty(false);

    items.forEach(function(c){
      var tr = ce('tr');
      tr.className = 'culture-row';
      tr.dataset.id = String(c.id);
      tr.innerHTML = '<td data-label="Название">'+
                       '<input class="inlineTitle culture-input" value="'+esc(c.title)+'" aria-label="Название культуры">'+
                     '</td>'+
                     '<td data-label="Действие">'+
                       '<div class="culture-actions">'+
                         '<button class="btn" data-act="save" data-id="'+c.id+'">Сохранить</button>'+
                         '<button class="btn danger" data-act="del" data-id="'+c.id+'">Удалить</button>'+
                       '</div>'+
                     '</td>';
      tblCultures.appendChild(tr);
      bindCultureRow(tr, c);
    });
  }

  function loadCultures(){
    api('admin_api_cultures.php?action=list')
      .then(function(j){
        culturesAll = (j.items||[]);
        renderCultures();
        loadOverview();
      })
      .catch(function(err){ toast('Не удалось загрузить культуры: '+err.message,'err'); });
  }

  function bindCultureRow(tr, c){
    var input = qs('.inlineTitle', tr);
    var btnSave = qs('button[data-act="save"]', tr);

    if (input && btnSave){
      input.addEventListener('input', function(){
        tr.classList.add('is-dirty');
        btnSave.classList.add('primary');
      });
      input.addEventListener('keydown', function(e){
        if (e.key === 'Enter'){
          e.preventDefault();
          btnSave.click();
        }
      });
    }

    qsa('button[data-id="'+c.id+'"]', tr).forEach(function(b){
      b.addEventListener('click', function(){
        var act = b.dataset.act;

        if (act==='save'){
          var title = (input ? input.value : '').trim();
          if (!title){
            toast('Введите название культуры','err');
            try { input && input.focus(); } catch(_){}
            return;
          }
        }

        if (act==='del'){
          var name = (input ? input.value : (c.title||'')).trim();
          if (!confirm('Удалить культуру «'+name+'»?')) return;
        }

        var fd = new FormData(); fd.append('id', c.id);
        if (act==='save'){ fd.append('title', input.value.trim()); }

        api('admin_api_cultures.php?action='+(act==='save'?'update':'delete'), {method:'POST', body: fd})
          .then(function(){
            toast(act==='save'?'Сохранено':'Удалено','ok');
            loadCultures();
          })
          .catch(function(err){ toast('Ошибка операции: '+err.message,'err'); });
      });
    });
  }

  // Добавление культуры — фикс Safari
  (function () {
    var formAdd = document.getElementById('formAddCulture');
    if (!formAdd) return;
    formAdd.addEventListener('submit', function (e) {
      e.preventDefault();
      var form = e.currentTarget;
      var fd   = new FormData(form);
      api('admin_api_cultures.php?action=create', { method: 'POST', body: fd })
        .then(function () {
          try { form.reset(); } catch(_) {}
          toast('Культура добавлена', 'ok');
          loadCultures();
          try {
            var inp = document.getElementById('cultureAddTitle');
            inp && inp.focus();
          } catch(_){}
        })
        .catch(function (err) { toast('Ошибка добавления: ' + err.message, 'err'); });
    });
  })();

  // UI для культур: поиск/сброс/обновление
  (function(){
    var s = document.getElementById('cultureSearch');
    if (s){
      s.addEventListener('input', function(){
        cultureFilter = s.value;
        renderCultures();
      });
      s.addEventListener('keydown', function(e){
        if (e.key === 'Escape'){
          s.value = '';
          cultureFilter = '';
          renderCultures();
        }
      });
    }
    var btnR = document.getElementById('btnCulturesRefresh');
    if (btnR) btnR.addEventListener('click', function(){ loadCultures(); });

    var btnC = document.getElementById('btnCulturesClear');
    if (btnC) btnC.addEventListener('click', function(){
      if (s) s.value = '';
      cultureFilter = '';
      renderCultures();
      try { s && s.focus(); } catch(_){}
    });
  })();

  var tblLogs = qs('#tblLogs tbody');
  function loadLogs(){
    api('admin_api_logs.php?action=list&limit=500')
      .then(function(j){
        tblLogs.innerHTML = '';
        (j.items||[]).forEach(function(l){
          var tr = ce('tr');
          tr.innerHTML = '<td>'+esc(l.created_at||'')+'</td>'+
                         '<td>'+esc(eventRu(l.event||''))+'</td>'+
                         '<td>'+esc(l.username||'')+'</td>'+
                         '<td>'+esc(l.details||'')+'</td>';
          while (tr.children && tr.children.length > 3) { tr.removeChild(tr.lastElementChild); }
        tblLogs.appendChild(tr);
        });
        loadOverview();
      })
      .catch(function(err){ toast('Не удалось загрузить журнал: '+err.message,'err'); });
  }

  /* ---------------- История изменений полей ---------------- */
  var histInited = false;
  var histPage = 0;
  var histLimit = 40;

  function fmtUser(u){
    var parts = [];
    if (u.surname) parts.push(u.surname);
    if (u.name) parts.push(u.name);
    var fio = parts.join(' ').trim();
    if (u.username && fio) return fio + ' ('+u.username+')';
    return fio || u.username || '—';
  }

  function initHistory(){
    if (histInited) return;
    histInited = true;

    var btnApply = qs('#histApply');
    var btnReset = qs('#histReset');
    var btnMore  = qs('#histMore');
    var modal    = qs('#histModal');
    var modalClose = qs('#histModalClose');

    btnApply && btnApply.addEventListener('click', function(){ loadHistory(true); });
    btnReset && btnReset.addEventListener('click', function(){
      var f = qs('#histFieldCode'); if (f) f.value='';
      var u = qs('#histUser');
      if (u){
        u.value='';
        try{ u.dispatchEvent(new Event('change')); }catch(_){ }
      }
      var d1 = qs('#histFrom'); if (d1) d1.value='';
      var d2 = qs('#histTo'); if (d2) d2.value='';
      loadHistory(true);
    });
    btnMore && btnMore.addEventListener('click', function(){ loadHistory(false); });

    // Enter в поле кода участка = применить
    var codeIn = qs('#histFieldCode');
    codeIn && codeIn.addEventListener('keydown', function(e){
      if (e.key === 'Enter'){ e.preventDefault(); loadHistory(true); }
    });

    // фирменный выпадающий список пользователей (без влияния на остальные select)
    setupHistUserDropdown();

    // закрытие модалки
    function closeModal(){
      if(!modal) return;
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden','true');
      modal.hidden = true;
    }
    function openModal(){
      if(!modal) return;
      modal.hidden = false;
      modal.classList.add('show');
      modal.setAttribute('aria-hidden','false');
    }

    modalClose && modalClose.addEventListener('click', closeModal);
    modal && modal.addEventListener('click', function(e){
      if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
    });

    // мета (пользователи/поля/счётчик)
    api('admin_api_field_history.php?action=meta')
      .then(function(j){
        var sel = qs('#histUser');
        if (sel && j.users){
          j.users.forEach(function(u){
            var o = document.createElement('option');
            o.value = u.id;
            o.textContent = fmtUser(u);
            sel.appendChild(o);
          });
          refreshHistUserDropdown();
        }
      })
      .catch(function(err){ /* тихо */ });
  }

  // --- Custom dropdown for History: #histUser ---
  var histUserDD = { inited:false, wrap:null, btn:null, label:null, pop:null, search:null, list:null };

  function setupHistUserDropdown(){
    if (histUserDD.inited) return;
    var sel = qs('#histUser');
    if (!sel) return;
    histUserDD.inited = true;

    // hide native select but keep it for value
    sel.classList.add('h-native-hidden');

    var wrap = document.createElement('div');
    wrap.className = 'h-select';
    wrap.id = 'histUserDD';

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'h-select-btn';
    btn.setAttribute('aria-haspopup','listbox');
    btn.setAttribute('aria-expanded','false');

    var label = document.createElement('span');
    label.className = 'h-select-label';
    label.textContent = 'Все';

    var caret = document.createElement('span');
    caret.className = 'h-select-caret';
    caret.textContent = '▾';

    btn.appendChild(label);
    btn.appendChild(caret);

    var pop = document.createElement('div');
    pop.className = 'h-select-pop';
    pop.hidden = true;

    var search = document.createElement('input');
    search.className = 'h-select-search';
    search.type = 'text';
    search.placeholder = 'Поиск пользователя…';
    search.autocomplete = 'off';

    var list = document.createElement('div');
    list.className = 'h-select-list';
    list.setAttribute('role','listbox');

    pop.appendChild(search);
    pop.appendChild(list);

    // insert right after select
    sel.insertAdjacentElement('afterend', wrap);
    wrap.appendChild(btn);
    wrap.appendChild(pop);

    histUserDD.wrap = wrap;
    histUserDD.btn = btn;
    histUserDD.label = label;
    histUserDD.pop = pop;
    histUserDD.search = search;
    histUserDD.list = list;

    function close(){
      if (!pop || pop.hidden) return;
      pop.hidden = true;
      btn.setAttribute('aria-expanded','false');
      wrap.classList.remove('open');
      try{ search.value=''; filter(''); }catch(_){ }
    }
    function open(){
      if (!pop || !pop.hidden) return;
      pop.hidden = false;
      btn.setAttribute('aria-expanded','true');
      wrap.classList.add('open');
      setTimeout(function(){ try{ search.focus(); }catch(_){ } }, 0);
    }
    function toggle(){ pop.hidden ? open() : close(); }

    btn.addEventListener('click', function(e){ e.preventDefault(); toggle(); });

    // close on outside click / esc
    document.addEventListener('click', function(e){
      if (!wrap.contains(e.target)) close();
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') close();
    });

    function filter(q){
      q = (q||'').toLowerCase();
      var items = list.querySelectorAll('.h-select-item');
      items.forEach(function(it){
        var t = (it.getAttribute('data-text')||'').toLowerCase();
        it.style.display = (!q || t.indexOf(q) !== -1) ? '' : 'none';
      });
    }
    search.addEventListener('input', function(){ filter(search.value); });

    // sync from native select
    sel.addEventListener('change', function(){
      updateLabelFromSelect();
      // подсветка активного
      var v = (sel.value||'');
      var items = list.querySelectorAll('.h-select-item');
      items.forEach(function(it){ it.classList.toggle('active', it.getAttribute('data-value') === v); });
    });

    refreshHistUserDropdown();
  }

  function updateLabelFromSelect(){
    var sel = qs('#histUser');
    if (!sel || !histUserDD.label) return;
    var txt = 'Все';
    if (sel.value){
      var opt = sel.options[sel.selectedIndex];
      if (opt) txt = opt.textContent;
    }
    histUserDD.label.textContent = txt;
  }

  function refreshHistUserDropdown(){
    var sel = qs('#histUser');
    if (!sel || !histUserDD.list) return;

    // rebuild list from options
    histUserDD.list.innerHTML = '';
    for (var i=0;i<sel.options.length;i++){
      var opt = sel.options[i];
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'h-select-item';
      btn.setAttribute('role','option');
      btn.setAttribute('data-value', opt.value);
      btn.setAttribute('data-text', opt.textContent);
      btn.textContent = opt.textContent;
      if ((sel.value||'') === opt.value) btn.classList.add('active');
      btn.addEventListener('click', function(){
        var v = this.getAttribute('data-value');
        sel.value = v;
        try{ sel.dispatchEvent(new Event('change')); }catch(_){ }
        // закрыть
        if (histUserDD.pop){ histUserDD.pop.hidden = true; }
        if (histUserDD.wrap){ histUserDD.wrap.classList.remove('open'); }
        if (histUserDD.btn){ histUserDD.btn.setAttribute('aria-expanded','false'); }
      });
      histUserDD.list.appendChild(btn);
    }
    updateLabelFromSelect();
  }

  function openHistoryModal(item){
    var modal = qs('#histModal');
    var title = qs('#histModalTitle');
    var sub   = qs('#histModalSub');
    var body  = qs('#histModalBody');
    if (!modal || !body) return;
    if (title) title.textContent = 'Участок ' + (item.field_code || '—') + ' — изменения';
    if (sub) sub.textContent = (item.created_at || '') + (item.user_display ? (' · ' + item.user_display) : '');

    body.innerHTML = '';
    var list = document.createElement('div');
    list.className = 'diff-list';
    (item.changes||[]).forEach(function(ch){
      var row = document.createElement('div');
      row.className = 'diff-row';
      row.innerHTML =
        '<div class="diff-key">'+esc(ch.label||ch.key||'')+'</div>'+
        '<div class="diff-val"><span class="diff-before">'+esc(ch.before||'—')+'</span><span class="diff-arrow">→</span><span class="diff-after">'+esc(ch.after||'—')+'</span></div>';
      list.appendChild(row);
    });
    body.appendChild(list);

    modal.hidden = false;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');
  }

  function renderHistory(items, append){
    var isMobile = window.matchMedia('(max-width: 900px)').matches;
    var tb = qs('#tblHistory tbody');
    var tl = qs('#histTimeline');
    if (!append){
      if (tb) tb.innerHTML='';
      if (tl) tl.innerHTML='';
    }

    if (isMobile){
      if (!tl) return;
      items.forEach(function(it){
        var card = document.createElement('div');
        card.className = 'tl-card';
        card.innerHTML =
          '<div class="tl-dot"></div>'+
          '<div class="tl-box">'+
            '<div class="tl-top">'+
              '<div class="tl-title">Участок <strong>'+esc(it.field_code||'—')+'</strong></div>'+
              '<div class="tl-time">'+esc(it.created_at||'')+'</div>'+
            '</div>'+
            '<div class="tl-user">'+esc(it.user_display||'—')+'</div>'+
            '<div class="tl-changes">'+esc(it.summary||'')+'</div>'+
          '</div>';
        card.addEventListener('click', function(){ openHistoryModal(it); });
        tl.appendChild(card);
      });
    } else {
      if (!tb) return;
      items.forEach(function(it){
        var tr = document.createElement('tr');
        tr.className = 'hist-row';
        tr.innerHTML =
          '<td data-label="Время">'+esc(it.created_at||'')+'</td>'+
          '<td data-label="Участок">'+esc(it.field_code||'—')+'</td>'+
          '<td data-label="Пользователь">'+esc(it.user_display||'—')+'</td>'+
          '<td data-label="Изменения">'+esc(it.summary||'')+'</td>';
        tr.addEventListener('click', function(){ openHistoryModal(it); });
        tb.appendChild(tr);
      });
    }
  }

  function loadHistory(reset){
    initHistory();
    if (reset){ histPage = 0; }
    var code = (qs('#histFieldCode') && qs('#histFieldCode').value || '').trim();
    code = code.replace(/\D+/g,'').slice(0,4);
    if (qs('#histFieldCode')) qs('#histFieldCode').value = code;
    var uid  = (qs('#histUser') && qs('#histUser').value || '').trim();
    var from = (qs('#histFrom') && qs('#histFrom').value || '').trim();
    var to   = (qs('#histTo') && qs('#histTo').value || '').trim();

    var url = 'admin_api_field_history.php?action=list'
            + '&limit=' + encodeURIComponent(histLimit)
            + '&offset=' + encodeURIComponent(histPage*histLimit);
    if (code) url += '&field_code=' + encodeURIComponent(code);
    if (uid)  url += '&user_id=' + encodeURIComponent(uid);
    if (from) url += '&date_from=' + encodeURIComponent(from);
    if (to)   url += '&date_to=' + encodeURIComponent(to);

    api(url)
      .then(function(j){
        var items = j.items || [];
        items.forEach(function(it){
          it.user_display = it.user_display || it.username || '';
        });
        renderHistory(items, !reset && histPage>0);

        var cnt = qs('#histCount');
        if (cnt){
          var total = (j.total != null) ? ('Всего записей: ' + j.total) : ('Записей: ' + ((j.items||[]).length));
          cnt.textContent = total;
        }

        var more = qs('#histMore');
        if (more){
          var hasMore = !!j.has_more;
          more.style.display = hasMore ? '' : 'none';
        }
        if (items.length){ histPage++; }
      })
      .catch(function(err){ toast('Не удалось загрузить историю: '+err.message,'err'); });
  }



  /* ---------------- Axenta (iframe) ---------------- */
var axInited = false;
var axMobileBound = false;
function initAxenta(){
  var frame = qs('#axFrame');
  var fb = qs('#axFallback');
  var reloadBtn = qs('#axReload');
  var hideBtn = qs('#axHide');
  var hideBtn2 = qs('#axHide2');
  var exitBtn = qs('#axExit');
  var dataBtn = qs('#axDataBtn');
  var copyLoginBtn = qs('#axCopyLogin');
  var copyPassBtn = qs('#axCopyPass');
  var secret = qs('#axSecret');
  var secretInput = qs('#axSecretInput');
  var secretOk = qs('#axSecretOk');
  var secretCancel = qs('#axSecretCancel');
  var secretClose = qs('#axSecretClose');
  var secretMsg = qs('#axSecretMsg');
  var lock = qs('#axLock');
  var lockTimer = qs('#axLockTimer');
  var pane = document.getElementById('pane-axenta');
  var wrap = document.querySelector('#pane-axenta .axenta-frame-wrap');
  if (!frame) return;

  // --- Secret data / lockout (server-side) ---
  // Данные и попытки хранятся на сервере (PHP session), чтобы их нельзя было
  // вытащить из исходников. Клиент только запрашивает значение по кнопке.
  var AX_SECRET_API = 'admin_api_axenta_secret.php';
  var granted = false;
  var lockUntilMs = 0;
  var lockTicker = null;
  var cachedLogin = null;
  var cachedPass = null;

  function now(){ return Date.now ? Date.now() : (+new Date()); }

  function lockRemaining(){
    var rem = (lockUntilMs || 0) - now();
    return rem > 0 ? rem : 0;
  }
  function isLocked(){ return lockRemaining() > 0; }

  function formatMMSS(ms){
    var s = Math.ceil(ms/1000);
    if (s < 0) s = 0;
    var m = Math.floor(s/60);
    var r = s % 60;
    return String(m) + ':' + (r < 10 ? '0' + r : String(r));
  }

  function setSecretMessage(text, kind){
    if (!secretMsg) return;
    secretMsg.textContent = text || '';
    secretMsg.classList.remove('err','ok');
    if (kind) secretMsg.classList.add(kind);
  }

  function updateAxentaButtons(){
    if (dataBtn) dataBtn.hidden = !!granted;
    if (copyLoginBtn) copyLoginBtn.hidden = !granted;
    if (copyPassBtn) copyPassBtn.hidden = !granted;
  }

  function axFetch(url, opts){
    opts = opts || {};
    if (!opts.credentials) opts.credentials = 'same-origin';
    return fetch(url, opts).then(function(r){
      return r.text().then(function(text){
        var j = null;
        try{ j = JSON.parse(text); }catch(e){}
        if (!j) j = { ok:false, error:'bad_json' };
        j.http_status = r.status;
        return j;
      });
    });
  }

  function fetchSecretStatus(){
    return axFetch(AX_SECRET_API + '?action=status').then(function(j){
      if (j && j.ok){
        granted = !!j.granted;
        lockUntilMs = (j.lock_until ? (j.lock_until * 1000) : 0);
        if (!granted){
          cachedLogin = null;
          cachedPass = null;
        }
      }
      return j;
    });
  }

  function prefetchSecrets(){
    if (!granted || isLocked()) return Promise.resolve();
    var tasks = [];
    if (!cachedLogin){
      tasks.push(axFetch(AX_SECRET_API + '?action=get&what=login').then(function(j){
        if (j && j.ok) cachedLogin = j.value;
      }));
    }
    if (!cachedPass){
      tasks.push(axFetch(AX_SECRET_API + '?action=get&what=pass').then(function(j){
        if (j && j.ok) cachedPass = j.value;
      }));
    }
    return Promise.all(tasks).then(function(){ return true; });
  }

  function showSecret(){
    if (!secret) return;
    setSecretMessage('', '');
    if (secretInput){ secretInput.value = ''; }
    secret.hidden = false;
    setTimeout(function(){ try{ secretInput && secretInput.focus(); }catch(e){} }, 40);
  }
  function hideSecret(){ if (secret) secret.hidden = true; }

  function copyText(text){
    return new Promise(function(resolve, reject){
      try{
        if (navigator.clipboard && navigator.clipboard.writeText){
          navigator.clipboard.writeText(text).then(resolve).catch(function(){
            // fallback
            try{
              var ta = document.createElement('textarea');
              ta.value = text;
              ta.setAttribute('readonly','');
              ta.style.position='fixed';
              ta.style.left='-9999px';
              ta.style.top='-9999px';
              document.body.appendChild(ta);
              ta.select();
              ta.setSelectionRange(0, ta.value.length);
              var ok = document.execCommand('copy');
              document.body.removeChild(ta);
              ok ? resolve() : reject(new Error('copy_failed'));
            }catch(e){ reject(e); }
          });
          return;
        }
      }catch(e){}

      // Fallback
      try{
        var ta2 = document.createElement('textarea');
        ta2.value = text;
        ta2.setAttribute('readonly','');
        ta2.style.position='fixed';
        ta2.style.left='-9999px';
        ta2.style.top='-9999px';
        document.body.appendChild(ta2);
        ta2.select();
        ta2.setSelectionRange(0, ta2.value.length);
        var ok2 = document.execCommand('copy');
        document.body.removeChild(ta2);
        ok2 ? resolve() : reject(new Error('copy_failed'));
      }catch(e2){ reject(e2); }
    });
  }

  function applyLockUI(){
    var rem = lockRemaining();
    var locked = rem > 0;

    if (dataBtn) dataBtn.disabled = locked;
    if (copyLoginBtn) copyLoginBtn.disabled = locked;
    if (copyPassBtn) copyPassBtn.disabled = locked;
    if (reloadBtn) reloadBtn.disabled = locked;

    if (wrap) wrap.style.pointerEvents = locked ? 'none' : '';
    if (pane) pane.classList.toggle('axenta-locked', locked);

    if (lock){
      lock.hidden = !locked;
      if (locked && lockTimer) lockTimer.textContent = formatMMSS(rem);
    }

    // start/stop ticker
    if (locked){
      if (!lockTicker){
        lockTicker = setInterval(function(){
          var r = lockRemaining();
          if (r <= 0){
            if (lockTicker){ clearInterval(lockTicker); lockTicker = null; }
            lockUntilMs = 0;
            // Обновим статус на сервере (сессия могла быть очищена)
            fetchSecretStatus().then(function(){
              applyLockUI();
              updateAxentaButtons();

              // Если вкладка открыта и iframe ещё не загружен — загрузим
              try{
                var shown = pane && pane.classList.contains('show') && pane.style.display !== 'none';
                if (shown && (!frame.src || frame.src.indexOf('about:blank') === 0)){
                  frame.src = frame.dataset.src || 'https://w.avtoscan.com/';
                  armFallbackTimer();
                }
              }catch(e){}
            }).catch(function(){
              applyLockUI();
              updateAxentaButtons();
            });
            // If the tab is still open and iframe wasn't loaded yet, load it now
            try{
              var shown = pane && pane.classList.contains('show') && pane.style.display !== 'none';
              if (shown && (!frame.src || frame.src.indexOf('about:blank') === 0)){
                frame.src = frame.dataset.src || 'https://w.avtoscan.com/';
                armFallbackTimer();
              }
            }catch(e){}
          } else {
            if (lockTimer) lockTimer.textContent = formatMMSS(r);
          }
        }, 300);
      }
    } else {
      if (lockTicker){ clearInterval(lockTicker); lockTicker = null; }
    }

    return locked;
  }

  function isPhone(){
    try{ return window.matchMedia && window.matchMedia('(max-width: 820px)').matches; }catch(e){ return false; }
  }

  // iOS Safari auto-zooms on form fields when it thinks text is too small.
  // Axenta lives inside an iframe, so we can't change its input font-size.
  // When Axenta is opened in our mobile fullscreen mode, we temporarily
  // lock viewport scaling to prevent the annoying "jump-zoom" on focus.
  var __vpMeta = null;
  var __vpOrig = null;
  try{
    __vpMeta = document.querySelector('meta[name="viewport"]');
    __vpOrig = __vpMeta ? (__vpMeta.getAttribute('content') || '') : null;
  }catch(e){}
  function setViewportNoZoom(enable){
    try{
      if (!__vpMeta) return;
      if (enable){
        if (__vpOrig == null) __vpOrig = __vpMeta.getAttribute('content') || '';
        __vpMeta.setAttribute('content','width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no');
      } else {
        if (__vpOrig != null) __vpMeta.setAttribute('content', __vpOrig);
      }
    }catch(e){}
  }

  // Axenta sometimes renders a "desktop" layout inside iframe. On phones we
  // auto-fit it to screen width by scaling the iframe element itself.
  function applyMobileFit(){
    try{
      var on = document.body.classList.contains('axenta-mobile');
      if (!on){
        frame.style.transform = '';
        frame.style.transformOrigin = '';
        frame.style.width = '100%';
        frame.style.height = '100%';
        frame.style.maxWidth = '';
        frame.style.maxHeight = '';
        return;
      }

      if (!wrap) wrap = document.querySelector('#pane-axenta .axenta-frame-wrap');
      var rect = wrap ? wrap.getBoundingClientRect() : null;
      var vw = rect && rect.width ? rect.width : (window.innerWidth || document.documentElement.clientWidth || 360);
      var vh = rect && rect.height ? rect.height : (window.innerHeight || document.documentElement.clientHeight || 640);

      // Base width chosen for typical desktop dashboards.
      var baseW = 1280;
      var scale = vw / baseW;
      if (scale > 1) scale = 1;
      if (scale < 0.55) scale = 0.55; // keep things readable

      frame.style.transformOrigin = '0 0';
      frame.style.transform = 'scale(' + scale.toFixed(4) + ')';
      frame.style.width = Math.ceil(vw / scale) + 'px';
      frame.style.height = Math.ceil(vh / scale) + 'px';
      frame.style.maxWidth = 'none';
      frame.style.maxHeight = 'none';
    }catch(e){}
  }

  function setMobileMode(on){
    try{
      if (on){
        document.body.classList.add('axenta-mobile');
        document.documentElement.classList.add('axenta-mobile');
      } else {
        document.body.classList.remove('axenta-mobile');
        document.documentElement.classList.remove('axenta-mobile');
      }
    }catch(e){}

    // Prevent iOS "focus zoom" inside the Axenta iframe
    setViewportNoZoom(!!on);

    // Recalculate sizes after layout changes
    setTimeout(applyMobileFit, 0);
  }

  // Enable fullscreen only on phones
  setMobileMode(isPhone());

  // Allow other parts of admin (tab switcher) to cleanly exit Axenta mobile mode
  // and restore viewport settings.
  try{
    window.__axentaExit = function(){
      try{ setMobileMode(false); }catch(e){}
    };
  }catch(e){}

  var dismissed = false;
  try { dismissed = sessionStorage.getItem('ax_hint_dismissed') === '1'; } catch(e){}

  function hideHint(persist){
    if (fb) fb.hidden = true;
    if (persist){
      try { sessionStorage.setItem('ax_hint_dismissed','1'); } catch(e){}
    }
  }

  function armFallbackTimer(){
  if (!fb) return;
  // Always start hidden; show a lightweight loader shortly after, unless user dismissed it.
  fb.hidden = true;
  frame.dataset.loaded = '';
  if (dismissed) return;

  setTimeout(function(){
    if (dismissed) return;
    if (frame.dataset.loaded !== '1' && fb) fb.hidden = false;
  }, 650);
}


  if (!axInited){
    // Exit from mobile fullscreen back to admin panel
    if (exitBtn){
      exitBtn.addEventListener('click', function(){
        setMobileMode(false);
        try{ document.body.classList.remove('nav-open'); }catch(e){}
        var b = document.querySelector('.menu-item[data-tab="overview"]');
        if (b) b.click();
      });
    }

    // Secret code UI (Данные -> copy login/pass)
    if (dataBtn){
      dataBtn.addEventListener('click', function(){
        if (applyLockUI()) return;
        showSecret();
      });
    }
    if (secretCancel){ secretCancel.addEventListener('click', hideSecret); }
    if (secretClose){ secretClose.addEventListener('click', hideSecret); }
    if (secret){
      // click on backdrop closes
      secret.addEventListener('click', function(e){
        if (e.target === secret) hideSecret();
      });
    }
    if (secretInput){
      secretInput.addEventListener('keydown', function(e){
        if (e.key === 'Enter'){
          e.preventDefault();
          try{ secretOk && secretOk.click(); }catch(_e){}
        }
      });
    }
    if (secretOk){
      secretOk.addEventListener('click', function(){
        if (applyLockUI()) return;
        var val = (secretInput && secretInput.value ? String(secretInput.value) : '').trim();

        if (!val){
          setSecretMessage('Введите код', 'err');
          return;
        }

        setSecretMessage('Проверяем…', '');
        try{ secretOk.disabled = true; }catch(e){}

        axFetch(AX_SECRET_API + '?action=verify', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: 'code=' + encodeURIComponent(val)
        }).then(function(j){
          try{ secretOk.disabled = false; }catch(e){}

          if (j && j.ok){
            granted = true;
            lockUntilMs = 0;
            cachedLogin = j.login || cachedLogin;
            cachedPass = j.pass || cachedPass;
            updateAxentaButtons();
            applyLockUI();
            setSecretMessage('Доступ открыт.', 'ok');
            setTimeout(function(){ hideSecret(); }, 550);
            try{ toast('Доступ открыт','ok'); }catch(e){}
            return;
          }

          // ошибки
          if (j && j.error === 'wrong_code'){
            setSecretMessage('Неверный код. Осталось попыток: ' + (j.attempts_left != null ? j.attempts_left : '?'), 'err');
            return;
          }

          if (j && j.error === 'locked'){
            granted = false;
            cachedLogin = null; cachedPass = null;
            lockUntilMs = (j.lock_until ? (j.lock_until * 1000) : (now() + 2*60*1000));
            hideSecret();
            updateAxentaButtons();
            applyLockUI();
            try{ toast('Доступ заблокирован на 2 минуты','err'); }catch(e){}
            return;
          }

          setSecretMessage('Не удалось проверить код', 'err');
        }).catch(function(){
          try{ secretOk.disabled = false; }catch(e){}
          setSecretMessage('Ошибка сети. Попробуйте ещё раз', 'err');
        });
      });
    }

    if (copyLoginBtn){
      copyLoginBtn.addEventListener('click', function(){
        if (applyLockUI()) return;
        var doCopy = function(v){
          if (!v){ try{ toast('Нет данных для копирования','err'); }catch(e){}; return; }
          copyText(v)
            .then(function(){ try{ toast('Логин скопирован','ok'); }catch(e){} })
            .catch(function(){ try{ toast('Не удалось скопировать','err'); }catch(e){} });
        };

        if (cachedLogin){ doCopy(cachedLogin); return; }
        prefetchSecrets().then(function(){ doCopy(cachedLogin); });
      });
    }
    if (copyPassBtn){
      copyPassBtn.addEventListener('click', function(){
        if (applyLockUI()) return;
        var doCopy2 = function(v){
          if (!v){ try{ toast('Нет данных для копирования','err'); }catch(e){}; return; }
          copyText(v)
            .then(function(){ try{ toast('Пароль скопирован','ok'); }catch(e){} })
            .catch(function(){ try{ toast('Не удалось скопировать','err'); }catch(e){} });
        };

        if (cachedPass){ doCopy2(cachedPass); return; }
        prefetchSecrets().then(function(){ doCopy2(cachedPass); });
      });
    }

    // Keep fullscreen responsive on orientation change
    if (!axMobileBound){
      axMobileBound = true;
      window.addEventListener('resize', function(){
        var pane = document.getElementById('pane-axenta');
        if (!pane) return;
        var shown = pane.classList.contains('show') && pane.style.display !== 'none';
        if (shown){
          setMobileMode(isPhone());
          applyMobileFit();
        }
      });

      // Mobile browsers (especially iOS) change the visual viewport when the
      // address bar collapses/expands. Keep Axenta fitted to width.
      if (window.visualViewport){
        window.visualViewport.addEventListener('resize', function(){
          var pane = document.getElementById('pane-axenta');
          if (!pane) return;
          var shown = pane.classList.contains('show') && pane.style.display !== 'none';
          if (shown && isPhone()) applyMobileFit();
        });
      }
    }

    frame.addEventListener('load', function(){
      frame.dataset.loaded = '1';
      hideHint(false);
      // Give the iframe a moment to paint before fitting
      setTimeout(applyMobileFit, 120);
    });

    if (reloadBtn){
      reloadBtn.addEventListener('click', function(){
        var base = frame.dataset.src || 'https://w.avtoscan.com/';
        var sep = base.indexOf('?') >= 0 ? '&' : '?';
        dismissed = false;
        try { sessionStorage.removeItem('ax_hint_dismissed'); } catch(e){}
        armFallbackTimer();
        frame.src = base + sep + '_t=' + Date.now();
      });
    }

    if (hideBtn){
      hideBtn.addEventListener('click', function(){
        dismissed = true;
        hideHint(true);
      });
    }
    if (hideBtn2){
      hideBtn2.addEventListener('click', function(){
        dismissed = true;
        hideHint(true);
      });
    }

    axInited = true;
  }

  // Всегда обновляем статус на сервере при открытии вкладки
  fetchSecretStatus()
    .then(function(){
      updateAxentaButtons();
      if (granted) prefetchSecrets();
      if (applyLockUI()){
        // Пока заблокировано — не грузим iframe
        if (fb) fb.hidden = true;
        return;
      }

      // Lazy load only when tab opened
      if (!frame.src || frame.src.indexOf('about:blank') === 0){
        frame.src = frame.dataset.src || 'https://w.avtoscan.com/';
        armFallbackTimer();
      } else {
        if (dismissed) hideHint(false);
      }
    })
    .catch(function(){
      // Если по какой-то причине статус не получили — просто покажем iframe
      granted = false;
      cachedLogin = null; cachedPass = null;
      updateAxentaButtons();

      if (applyLockUI()){
        if (fb) fb.hidden = true;
        return;
      }
      if (!frame.src || frame.src.indexOf('about:blank') === 0){
        frame.src = frame.dataset.src || 'https://w.avtoscan.com/';
        armFallbackTimer();
      } else {
        if (dismissed) hideHint(false);
      }
    });
  return;
}

function loadTg(){
    api('admin_api_telegram.php?action=get')
      .then(function(j){
        qs('#tg_token').value = (j.data && j.data.bot_token) || '';
        qs('#tg_chat').value  = (j.data && j.data.admin_chat_id) || '';
        qs('#tg_on_save').checked  = !!(j.data && j.data.notify_on_save);
        qs('#tg_on_login').checked = !!(j.data && j.data.notify_on_login);
        var canTest = !!(j.data && j.data.bot_token && j.data.admin_chat_id);
        var btn = qs('#tgTest'); if (btn) btn.disabled = !canTest;
      })
      .catch(function(err){ toast('Не удалось загрузить настройки Telegram: '+err.message,'err'); });
  }
  qs('#formTg') && qs('#formTg').addEventListener('submit', function(e){
    e.preventDefault();
    var fd = new FormData(e.currentTarget);
    api('admin_api_telegram.php?action=save', {method:'POST', body: fd})
      .then(function(){ toast('Настройки сохранены','ok'); loadTg(); })
      .catch(function(err){ toast('Ошибка сохранения: '+err.message,'err'); });
  });
  qs('#tgTest') && qs('#tgTest').addEventListener('click', function(){
    api('admin_api_telegram.php?action=test_send')
      .then(function(){ toast('Тест отправлен','ok'); })
      .catch(function(err){ toast('Не удалось отправить тест: '+err.message,'err'); });
  });

  // старт
  loadOverview();
})();