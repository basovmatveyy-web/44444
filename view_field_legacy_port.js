
/*! View Field - Legacy Port (from old working archive). 
 *  Drop-in module that wires the exact mechanics for "Просмотр участка".
 *  Non-invasive: only touches selectors listed below and endpoints:
 *  - field_get.php?code=XXXX
 *  - field_list.php
 *  - print_field.php?code=XXXX
 *  - export_field_csv.php?code=XXXX
 *  - field_save.php (admin modal submit)
 *  - cultures_list.php (admin preload select)
 *
 * Expected DOM (must exist on dashboard page):
 *  - form#formCode  + input#code (4 digits), submit button "Показать"
 *  - #fieldView (card container), #listWrap (list container)
 *  - #btnList (show all list), #btnPrintCard (print card)
 *  - Optional admin modal: #formEdit, and inputs with ids:
 *    #editTitle, #edit_field_code, #edit_area_ha, #edit_plow_date, #edit_sow_date,
 *    #edit_last_water_date, #edit_harvest_date, #edit_treatment_date,
 *    #edit_treatment_desc, #edit_gross_yield, #edit_avg_yield, #edit_notes, #edit_culture_id
 *    plus meta[name="csrf-token"] for CSRF.
 *
 * It safely no-ops if required elements are missing.
 */
(function(){
  'use strict';

  // -------- helpers --------
  const d = document;
  const qs = (s, p=d) => p.querySelector(s);
  const qsa = (s, p=d) => Array.from(p.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const byId = (id) => d.getElementById(id);
  const getCSRF = () => (qs('meta[name="csrf-token"]')?.content || '');

  const toast = (msg, type) => {
    // use site toast if present, else alert fallback
    if (window.toast) { window.toast(msg, type); }
    else { console[(type==='err'?'error':'log')](msg); }
  };

  const fmtDate = (iso) => {
    if (!iso) return '';
    const t = iso.split('T')[0];
    const [y,m,da] = t.split('-');
    if (!y || !m || !da) return esc(iso);
    return `${da}.${m}.${y}`;
  };

  const getJSON = (url) => fetch(url, {credentials: 'same-origin'}).then(r=>{
    if (!r.ok) throw new Error('HTTP '+r.status);
    return r.json();
  });

  const postForm = (url, dataObj) => {
    const fd = new FormData();
    Object.entries(dataObj || {}).forEach(([k,v])=> fd.append(k, v==null? '' : v));
    const csrf = getCSRF();
    if (csrf) fd.append('csrf', csrf);
    return fetch(url, { method:'POST', body: fd, credentials:'same-origin' }).then(r=>{
      if (!r.ok) throw new Error('HTTP '+r.status);
      return r.json();
    });
  };

  const onReady = (fn) => {
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', fn, {once:true});
    else fn();
  };

  // -------- state --------
  let lastCode = null;
  let currentField = null;
  const IS_ADMIN = (window.USER_ROLE === 'admin' || window.IS_ADMIN === true);

  // -------- UI builders (kept close to old archive behavior) --------
  function renderFieldCard(f, canEdit){
    const rows = [
      ['Культура', esc(f.culture || '')],
      ['Площадь, га', esc(f.area_ha || '')],
      ['Вспашка', fmtDate(f.plow_date)],
      ['Посев', fmtDate(f.sow_date)],
      ['Обработка', fmtDate(f.treatment_date) + (f.treatment_desc ? (' — '+esc(f.treatment_desc)) : '')],
      ['Полив (последний)', fmtDate(f.last_water_date)],
      ['Уборка', fmtDate(f.harvest_date)],
      ['Урожай валовый', esc(f.gross_yield || '')],
      ['Урожай средний', esc(f.avg_yield || '')],
      ['Заметки', esc(f.notes || '')],
    ].map(([k,v])=> `<tr><th>${k}</th><td>${v}</td></tr>`).join('');

    const controls = `
      <div class="vf-actions" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        ${canEdit ? `<button type="button" id="btnEdit" class="btn btn-sm btn-primary">Редактировать</button>` : ''}
        <a class="btn btn-sm" id="btnCsv" href="export_field_csv.php?code=${encodeURIComponent(f.field_code)}" target="_blank" rel="noopener">CSV</a>
        <button type="button" id="btnPrintInline" class="btn btn-sm">Печать</button>
      </div>`;

    return `
      <div class="card field-card">
        <div class="card-header"><strong>Участок ${esc(f.field_code)}</strong></div>
        <div class="card-body">
          <table class="table table-sm field-table">${rows}</table>
          ${controls}
        </div>
      </div>`;
  }

  function renderEmptyCard(code, canAdd){
    return `
      <div class="card field-card empty">
        <div class="card-header"><strong>Участок ${esc(code)}</strong></div>
        <div class="card-body">
          <div>Участок не найден.</div>
          ${canAdd ? `<div style="margin-top:8px"><button type="button" id="btnAdd" class="btn btn-sm btn-primary">Добавить</button></div>` : ''}
        </div>
      </div>`;
  }

  function renderList(items){
    if (!Array.isArray(items) || !items.length){
      return '<div class="muted">Список пуст</div>';
    }
    const rows = items.map(it=>{
      const code = esc(it.field_code);
      const culture = esc(it.culture || '');
      const sow = fmtDate(it.sow_date);
      const harv = fmtDate(it.harvest_date);
      const area = esc(it.area_ha || '');
      return `<tr class="vf-row" data-code="${code}">
        <td class="vf-col-code"><button type="button" class="link-like" data-code="${code}">${code}</button></td>
        <td>${culture}</td><td>${area}</td><td>${sow}</td><td>${harv}</td>
      </tr>`;
    }).join('');
    return `<div class="card"><div class="card-header"><strong>Список участков</strong></div>
      <div class="card-body">
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>Код</th><th>Культура</th><th>Площадь</th><th>Посев</th><th>Уборка</th></tr></thead>
          <tbody>${rows}</tbody>
        </table></div>
      </div></div>`;
  }

  function pulse(){
    const p = byId('pulse');
    if (!p) return;
    p.classList.add('active');
    setTimeout(()=> p.classList.remove('active'), 800);
  }

  // -------- admin helpers --------
  function preloadCultures(sel){
    return getJSON('cultures_list.php').then(j=>{
      if (!j || !Array.isArray(j.items)) return;
      sel.innerHTML = j.items.map(c=>`<option value="${esc(c.id)}">${esc(c.name)}</option>`).join('');
    }).catch(()=>{});
  }

  function bindEdit(){
    const btn = byId('btnEdit');
    if (!btn) return;
    btn.addEventListener('click', ()=>{
      const f = currentField;
      if (!f) return;
      const form = byId('formEdit');
      if (!form) { toast('Не найдена форма редактирования (#formEdit)','err'); return; }
      // preload cultures
      const sel = byId('edit_culture_id'); if (sel) preloadCultures(sel);
      // fill fields
      const set = (id, val) => { const el = byId(id); if (el) el.value = (val ?? ''); };
      const setDate = (id, val) => set(id, (val||'').split('T')[0]);
      const t = byId('editTitle'); if (t) t.textContent = 'Редактировать участок '+ (f.field_code||'');
      set('edit_field_code', f.field_code);
      set('edit_area_ha', f.area_ha);
      setDate('edit_plow_date', f.plow_date);
      setDate('edit_sow_date', f.sow_date);
      setDate('edit_last_water_date', f.last_water_date);
      setDate('edit_harvest_date', f.harvest_date);
      setDate('edit_treatment_date', f.treatment_date);
      set('edit_treatment_desc', f.treatment_desc);
      set('edit_gross_yield', f.gross_yield);
      set('edit_avg_yield', f.avg_yield);
      set('edit_notes', f.notes);
      // open modal (support Bootstrap data-bs-target or custom)
      // Try Bootstrap 5
      if (window.bootstrap && byId('editModal')){
        try { new bootstrap.Modal('#editModal').show(); } catch(e){}
      } else {
        // fallback: show element with id=editPanel
        const panel = byId('editPanel');
        if (panel) panel.style.display = 'block';
      }
    });
  }

  function bindAdd(code){
    const btn = byId('btnAdd');
    if (!btn) return;
    btn.addEventListener('click', ()=>{
      const form = byId('formEdit');
      if (!form) { toast('Не найдена форма редактирования (#formEdit)','err'); return; }
      const sel = byId('edit_culture_id'); if (sel) preloadCultures(sel);
      const set = (id, val) => { const el = byId(id); if (el) el.value = (val ?? ''); };
      const t = byId('editTitle'); if (t) t.textContent = 'Добавить участок '+ (code||'');
      set('edit_field_code', code || '');
      set('edit_area_ha', '');
      set('edit_plow_date', '');
      set('edit_sow_date', '');
      set('edit_last_water_date', '');
      set('edit_harvest_date', '');
      set('edit_treatment_date', '');
      set('edit_treatment_desc', '');
      set('edit_gross_yield', '');
      set('edit_avg_yield', '');
      set('edit_notes', '');
      if (window.bootstrap && byId('editModal')){
        try { new bootstrap.Modal('#editModal').show(); } catch(e){}
      } else {
        const panel = byId('editPanel');
        if (panel) panel.style.display = 'block';
      }
    });
  }

  function wireEditFormSubmit(){
    const form = byId('formEdit');
    if (!form) return;
    form.addEventListener('submit', (e)=>{
      e.preventDefault();
      const fd = new FormData(form);
      const data = Object.fromEntries(fd.entries());
      postForm('field_save.php', data).then(j=>{
        if (j && j.ok){
          toast('Сохранено');
          const c = data.edit_field_code || byId('edit_field_code')?.value || lastCode;
          if (c) loadField(String(c).padStart(4,'0').slice(-4));
          // close modal if bootstrap
          if (window.bootstrap && byId('editModal')){
            try { bootstrap.Modal.getInstance(byId('editModal'))?.hide(); } catch(e){}
          } else {
            const panel = byId('editPanel'); if (panel) panel.style.display = 'none';
          }
        } else {
          toast(j?.error || 'Ошибка сохранения','err');
        }
      }).catch(()=> toast('Ошибка сохранения','err'));
    });
  }

  // -------- core mechanics --------
  function loadField(code){
    if (!code) return;
    const fieldView = byId('fieldView');
    const listWrap = byId('listWrap');
    const btnPrint = byId('btnPrintCard');
    getJSON('field_get.php?code='+encodeURIComponent(code)).then(j=>{
      lastCode = code;
      if (j && j.ok && j.field){
        currentField = j.field;
        if (btnPrint) btnPrint.disabled = false;
        if (fieldView) fieldView.innerHTML = renderFieldCard(j.field, IS_ADMIN);
        if (listWrap) listWrap.innerHTML = '';
        pulse();
        bindEdit();
        const btnPrintInline = byId('btnPrintInline');
        if (btnPrintInline){
          btnPrintInline.addEventListener('click', ()=>{
            window.open('print_field.php?code='+encodeURIComponent(lastCode), '_blank', 'noopener');
          }, {once:true});
        }
      } else {
        currentField = null;
        if (btnPrint) btnPrint.disabled = true;
        if (fieldView) fieldView.innerHTML = renderEmptyCard(code, IS_ADMIN);
        if (listWrap) listWrap.innerHTML = '';
        bindAdd(code);
      }
    }).catch(()=> toast('Ошибка запроса','err'));
  }

  function wireListButton(){
    const btnList = byId('btnList');
    if (!btnList) return;
    btnList.addEventListener('click', ()=>{
      const listWrap = byId('listWrap');
      if (!listWrap) return;
      listWrap.innerHTML = '<div class="muted">Загрузка…</div>';
      getJSON('field_list.php').then(j=>{
        listWrap.innerHTML = renderList(j.items || []);
        qsa('[data-code]', listWrap).forEach(el=>{
          el.addEventListener('click', (e)=>{
            const code = e.currentTarget.getAttribute('data-code');
            if (code) {
              const input = byId('code'); if (input) input.value = code;
              loadField(code);
              listWrap.innerHTML = '';
            }
          });
        });
      }).catch(()=> listWrap.innerHTML = '<div class="text-danger">Ошибка загрузки списка</div>');
    });
  }

  function wirePrintButton(){
    const btnPrint = byId('btnPrintCard');
    if (!btnPrint) return;
    btnPrint.disabled = !lastCode;
    btnPrint.addEventListener('click', ()=>{
      if (!lastCode) return;
      window.open('print_field.php?code='+encodeURIComponent(lastCode), '_blank', 'noopener');
    });
  }

  function wireForm(){
    const form = byId('formCode');
    const codeInput = byId('code');
    if (!form || !codeInput) return;
    // sanitize input to 4 digits
    codeInput.addEventListener('input', ()=>{
      const v = codeInput.value.replace(/\D/g,'').slice(0,4);
      if (codeInput.value !== v) codeInput.value = v;
    });
    // stop reload + validate + load
    form.addEventListener('submit', (e)=>{
      e.preventDefault();
      const code = (codeInput.value || '').trim();
      if (!/^\d{3,4}$/.test(code)){
        toast('Введите 3–4 цифры','err');
        return;
      }
      loadField(code);
    });
  }

  // -------- boot --------
  onReady(()=>{
    // do nothing if key containers aren't present (wrong page)
    if (!byId('fieldView') || !byId('formCode')) return;
    wireForm();
    wireListButton();
    wirePrintButton();
    wireEditFormSubmit();
    // If input already has 4 digits, autoload (nice UX)
    const codeInput = byId('code');
    if (codeInput && /^\d{3,4}$/.test(codeInput.value||'')){
      loadField(codeInput.value);
    }
  });
})();
