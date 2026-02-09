(function(){
function qs(s, el){ return (el||document).querySelector(s); }
  function ce(tag, cls){ var d=document.createElement(tag); if(cls) d.className=cls; return d; }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];}); }
  var toasts = qs('#toasts');
  function toast(msg,type,timeout){ type=type||'info'; timeout=timeout||3500; var d=ce('div','toast '+(type==='err'?'err':(type==='ok'?'ok':'info'))); d.textContent=msg; toasts.appendChild(d); var tm=setTimeout(function(){d.remove();}
var form=qs('#formCode'), codeInput=qs('#code'), fieldView=qs('#fieldView'), listWrap=qs('#listWrap'), btnList=qs('#btnList'), btnPrint=qs('#btnPrintCard'); var lastCode=null, currentField=null;
  codeInput.addEventListener('input',()=>{ codeInput.value=codeInput.value.replace(/\D/g,'').slice(0,4); });
  form.addEventListener('submit',e=>{ e.preventDefault(); var code=(codeInput.value||'').replace(/\D/g,''); if(code.length<3 || code.length>4){ toast('Введите 3–4 цифры','err'); return; } loadField(code); });
  btnList.addEventListener('click',()=>{ fetch('field_list.php').then(r=>r.json()).then(j=>{ if(j.ok){ listWrap.innerHTML=renderTable(j.items||[]); fieldView.innerHTML=''; btnPrint.disabled=true; } else { listWrap.innerHTML='<div class="glass pane">Ошибка получения списка</div>'; } }).catch(()=>{ listWrap.innerHTML='<div class="glass pane">Ошибка запроса</div>'; }); });
  btnPrint.addEventListener('click',()=>{ if(!lastCode) return; window.open('print_field.php?code='+encodeURIComponent(lastCode),'_blank'); });

  function loadField(code){
    fetch('field_get.php?code='+encodeURIComponent(code)).then(r=>r.json()).then(j=>{
      lastCode=code;
      if(j.ok && j.field){
        currentField=j.field; btnPrint.disabled=false;
        fieldView.innerHTML=renderFieldCard(j.field, USER_ROLE==='admin');
        listWrap.innerHTML=''; pulse(); bindEdit();
      }else{
        currentField=null; btnPrint.disabled=true;
        fieldView.innerHTML=renderEmptyCard(code, USER_ROLE==='admin');
        listWrap.innerHTML=''; bindAdd(code);
      }
    }).catch(()=> toast('Ошибка запроса','err'));
  }

  function renderFieldCard(f, canEdit){
    return '<div class="glass pane">'
      + '<div class="row gap" style="justify-content:space-between; align-items:center;">'
      +   '<h4>Участок '+esc(f.field_code)+'</h4>'
      +   (canEdit ? '<button class="btn primary" id="btnEdit">Редактировать</button>' : '')
      + '</div>'
      + '<div class="muted sm">Культура: '+esc(f.culture || '—')+'</div>'
      + '<div class="muted sm">Площадь: '+esc(f.area_ha || '—')+' га</div>'
      + '<div class="muted sm">Вспашка: '+esc(f.plow_date || '—')+'</div>'
      + '<div class="muted sm">Сев: '+esc(f.sow_date || '—')+'</div>'
      + '<div class="muted sm">Обработка: '+esc(f.treatment_date || '—')+(f.treatment_desc?(' — '+esc(f.treatment_desc)):'')+'</div>'
      + '<div class="muted sm">Последний полив: '+esc(f.last_water_date || '—')+'</div>'
      + '<div class="muted sm">Уборка: '+esc(f.harvest_date || '—')+'</div>'
      + '<div class="muted sm">Валовый сбор: '+esc(f.gross_yield || '—')+'</div>'
      + '<div class="muted sm">Средняя урожайность: '+esc(f.avg_yield || '—')+'</div>'
      + '<div class="muted sm">Заметки: '+esc(f.notes || '—')+'</div>'
      + '</div>';
  }

  function renderEmptyCard(code, canAdd){
    return '<div class="glass pane">'
      + '<div class="row gap" style="justify-content:space-between; align-items:center;">'
      +   '<div><b>Данных нет.</b> Участок '+esc(code)+' отсутствует.</div>'
      +   (canAdd ? '<button class="btn primary" id="btnAdd">Добавить</button>' : '')
      + '</div>'
      + '<div class="muted sm">Нет данных</div>'
      + '</div>';
  }

  function renderTable(rows){
    if(!rows.length) return '<div class="glass pane">Нет данных</div>';
    var h='<div class="glass pane"><div class="row gap" style="justify-content:space-between;"><h4>Все участки</h4><div><a class="btn ghost" href="export_fields_csv.php" target="_blank">Экспорт CSV (все)</a></div></div><div class="space" style="overflow:auto; max-height:55vh"><table class="table"><thead><tr><th>Код</th><th>Культура</th><th>Площадь</th><th>Сев</th><th>Уборка</th></tr></thead><tbody>';
    rows.forEach(function(r){ h+='<tr><td>'+esc(r.field_code)+'</td><td>'+esc(r.culture||'—')+'</td><td>'+esc(r.area_ha||'—')+'</td><td>'+esc(r.sow_date||'—')+'</td><td>'+esc(r.harvest_date||'—')+'</td></tr>'; });
    h+='</tbody></table></div></div>'; return h;
  }

  var modal=qs('#modalEdit'), formEdit=qs('#formEdit');
  function openEdit(){ modal.hidden=false; document.body.classList.add('no-scroll'); }
  function closeEdit(){ modal.hidden=true; document.body.classList.remove('no-scroll'); }
  qs('[data-close="#modalEdit"]').addEventListener('click', closeEdit);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && !modal.hidden) closeEdit(); });

  function preloadCultures(sel){
    return fetch('cultures_list.php').then(function(r){return r.json();}).then(function(j){
      sel.innerHTML = '<option value="">— не выбрано —</option>';
      (j.items||[]).forEach(function(c){ var o=document.createElement('option'); o.value=c.id; o.textContent=c.title; sel.appendChild(o); });
    });
  }

  function bindEdit(){
    var b=qs('#btnEdit', fieldView); if(!b) return;
    b.addEventListener('click', function(){
      if(!currentField) return;
      var sel=qs('#edit_culture_id');
      preloadCultures(sel).then(function(){
        qs('#editTitle').textContent='Редактирование участка '+currentField.field_code;
        qs('#edit_field_code').value=currentField.field_code||'';
        qs('#edit_area_ha').value=currentField.area_ha||'';
        qs('#edit_plow_date').value=currentField.plow_date||'';
        qs('#edit_sow_date').value=currentField.sow_date||'';
        qs('#edit_last_water_date').value=currentField.last_water_date||'';
        qs('#edit_harvest_date').value=currentField.harvest_date||'';
        qs('#edit_treatment_date').value=currentField.treatment_date||'';
        qs('#edit_treatment_desc').value=currentField.treatment_desc||'';
        qs('#edit_gross_yield').value=currentField.gross_yield||'';
        qs('#edit_avg_yield').value=currentField.avg_yield||'';
        qs('#edit_notes').value=currentField.notes||'';
        if(currentField.culture_id) sel.value=String(currentField.culture_id);
        openEdit();
      }).catch(function(){ toast('Ошибка загрузки культур','err'); });
    });
  }

  function bindAdd(code){
    var b=qs('#btnAdd', fieldView); if(!b) return;
    b.addEventListener('click', function(){
      var sel=qs('#edit_culture_id');
      preloadCultures(sel).then(function(){
        qs('#editTitle').textContent='Добавить участок '+code;
        qs('#edit_field_code').value=code;
        ['edit_area_ha','edit_plow_date','edit_sow_date','edit_last_water_date','edit_harvest_date','edit_treatment_date','edit_treatment_desc','edit_gross_yield','edit_avg_yield','edit_notes'].forEach(function(id){ var el=qs('#'+id); if(el) el.value=''; });
        sel.value='';
        openEdit();
      }).catch(function(){ toast('Ошибка загрузки культур','err'); });
    });
  }

  formEdit.addEventListener('submit', function(e){
    e.preventDefault();
    var fd=new FormData(formEdit);
    if(!/^\d{3,4}$/.test(fd.get('field_code'))) { toast('Кадастровый номер — 3–4 цифры','err'); return; }
    fetch('field_save.php',{method:'POST', body:fd}).then(function(r){return r.json();}).then(function(j){
      if(j.ok){ toast('Сохранено','ok'); closeEdit(); loadField(fd.get('field_code')); }
      else{ toast(j.error||'Ошибка сохранения','err'); }
    }).catch(function(){ toast('Ошибка запроса','err'); });
  });

  function pulse(){ var p=qs('#pulse'); if(!p) return; var rect=viewport.getBoundingClientRect(); var cx=rect.width/2, cy=rect.height/2; p.style.left=cx+'px'; p.style.top=cy+'px'; p.style.display='block'; var a=p.animate([{opacity:1, transform:'translate(-50%,-50%) scale(1)'},{opacity:0, transform:'translate(-50%,-50%) scale(2.2)'}],{duration:900,easing:'ease-out'}); a.onfinish=function(){p.style.display='none';}; }
})();
})();
