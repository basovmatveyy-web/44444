(function(){
  function qs(s, el){ return (el||document).querySelector(s); }
  function ce(tag, cls){ var d=document.createElement(tag); if(cls) d.className=cls; return d; }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g, function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];}); }
  var toasts = qs('#toasts');
  function toast(msg,type,timeout){ type=type||'info'; timeout=timeout||3500; var d=ce('div','toast '+(type==='err'?'err':(type==='ok'?'ok':'info'))); d.textContent=msg; toasts.appendChild(d); var tm=setTimeout(function(){d.remove();},timeout); d.addEventListener('mouseenter',function(){clearTimeout(tm);}); }

  var USER_ROLE = (document.body.getAttribute('data-role')||'user');
  var CSRF = (qs('meta[name="csrf-token"]')||{}).content || '';
  var viewport = qs('#viewport'), mapImg = qs('#mapImg'), mapSvg = qs('#mapSvg');
  var imgW = parseInt(mapImg.getAttribute('width'),10) || 3509;
  var imgH = parseInt(mapImg.getAttribute('height'),10) || 2481;
  var scale=1, pos={x:0,y:0}, dragging=false, start={x:0,y:0}, startPos={x:0,y:0};
  var minScale = 0.3, maxScale = 3;

  var gesturesEnabled = true;
  var gestureTgl = qs('#gestureInput');
  function updateGesturesUI(){ document.body.classList.toggle('gestures-off', !gesturesEnabled); }
  if (gestureTgl){
    gesturesEnabled = !!gestureTgl.checked;
    gestureTgl.addEventListener('change', function(){ gesturesEnabled = !!gestureTgl.checked; updateGesturesUI(); });
    updateGesturesUI();
  }

  function fit(){ var r=viewport.getBoundingClientRect(); var sx=r.width/imgW, sy=r.height/imgH; scale=Math.min(sx,sy); var dw=imgW*scale, dh=imgH*scale; pos.x=(r.width-dw)/2; pos.y=(r.height-dh)/2; apply(); }
  function apply(){
    mapImg.style.transformOrigin='top left';
    mapImg.style.transform='translate('+pos.x+'px,'+pos.y+'px) scale('+scale+')';
    if (mapSvg){
      mapSvg.style.transformOrigin='top left';
      mapSvg.style.transform='translate('+pos.x+'px,'+pos.y+'px) scale('+scale+')';
    }
  }
  function zoom(d){ var r=viewport.getBoundingClientRect(); var cx=r.width/2, cy=r.height/2; var old=scale, next=Math.min(3,Math.max(0.3,scale+d)); if(next===old) return; var ix=(cx-pos.x)/old, iy=(cy-pos.y)/old; scale=next; pos.x=cx-ix*scale; pos.y=cy-iy*scale; apply(); }
  qs('#zoomIn').addEventListener('click',function(){ if(!gesturesEnabled) return; zoom(0.15); }); qs('#zoomOut').addEventListener('click',function(){ if(!gesturesEnabled) return; zoom(-0.15); }); qs('#reset').addEventListener('click',function(){ if(!gesturesEnabled) return; fit(); });
  // В режиме разметки карта не должна «таскаться» мышью/пальцем.
  var mapEditing = false;
  viewport.addEventListener('mousedown',function(e){
    if(!gesturesEnabled || mapEditing) return;
    dragging=true; viewport.classList.add('grabbing');
    start={x:e.clientX,y:e.clientY}; startPos={x:pos.x,y:pos.y};
  });
  window.addEventListener('mouseup',()=>{dragging=false; viewport.classList.remove('grabbing');});
  window.addEventListener('mousemove',function(e){
    if(!dragging || !gesturesEnabled) return;
    pos.x=startPos.x+(e.clientX-start.x); pos.y=startPos.y+(e.clientY-start.y);
    apply();
  });
// На iOS/Safari: если preventDefault() срабатывает на touchstart/touchend,
// то "click" по SVG-полигону не происходит. Поэтому для полигонов
// разрешаем "тап", а жесты (пан/зум) обрабатываем отдельно.
function getPolyCodeFromTarget(t){
  while (t && t !== mapSvg && t !== viewport){
    if (t.getAttribute && t.getAttribute('data-code')) return t.getAttribute('data-code');
    t = t.parentNode;
  }
  return null;
}

var pendingDrag = null;

['touchstart','touchmove','touchend','gesturestart','gesturechange','gestureend'].forEach(function(ev){
  viewport.addEventListener(ev, function(e){
    if(!gesturesEnabled) return;

    // gesture* — всегда блокируем нативные жесты браузера
    if (ev.indexOf('gesture') === 0){
      e.preventDefault();
      return;
    }

    // touchmove блокируем только во время наших жестов
    if (ev === 'touchmove'){
      if (dragging || pinching) e.preventDefault();
      return;
    }

    // touchstart/touchend: если тап по полигону — не трогаем, чтобы родился click.
    var code = getPolyCodeFromTarget(e.target);
    if (code) return;

    // В остальных случаях ничего не блокируем здесь — нужные preventDefault() стоят в обработчиках пан/пинч.
  }, {passive:false});
});

  // Touch pan (one finger)
  viewport.addEventListener('touchstart', function(e){
    if(!gesturesEnabled || mapEditing) return;
    if(e.touches && e.touches.length===1){
      // Если палец лег на полигон — даём сработать "клику" (iOS),
      // но если пользователь начнёт тянуть — включим drag после порога.
      if (getPolyCodeFromTarget(e.target)){
        pendingDrag = {x:e.touches[0].clientX, y:e.touches[0].clientY};
        startPos = {x:pos.x, y:pos.y};
        return;
      }
      pendingDrag = null;
      dragging = true;
      viewport.classList.add('grabbing');
      start = {x:e.touches[0].clientX, y:e.touches[0].clientY};
      startPos = {x:pos.x, y:pos.y};
      e.preventDefault();
    }
  }, {passive:false});
  window.addEventListener('touchmove', function(e){
    if(!gesturesEnabled) return;

    // Если старт был на полигоне, но пошёл явный сдвиг — включаем drag (панорамирование)
    if (!dragging && pendingDrag && e.touches && e.touches.length===1){
      var dx0 = e.touches[0].clientX - pendingDrag.x;
      var dy0 = e.touches[0].clientY - pendingDrag.y;
      if (Math.abs(dx0) + Math.abs(dy0) > 10){
        dragging = true;
        viewport.classList.add('grabbing');
        start = {x:pendingDrag.x, y:pendingDrag.y};
        // startPos уже задан в touchstart
        pendingDrag = null;
      } else {
        return; // считаем это "тапом", не двигаем карту
      }
    }

    if(!(dragging && e.touches && e.touches.length===1)) return;
    pos.x = startPos.x + (e.touches[0].clientX - start.x);
    pos.y = startPos.y + (e.touches[0].clientY - start.y);
    apply();
    e.preventDefault();
  }, {passive:false});
  window.addEventListener('touchend', function(e){
    if(!gesturesEnabled) return;
    pendingDrag = null;
    dragging = false;
    viewport.classList.remove('grabbing');
  }, {passive:false});

  // Pinch zoom (two fingers)
  // Важно: pos.x/pos.y живут в координатах viewport (0..width/height),
  // а clientX/clientY — в координатах окна. Если смешать их, при первом pinch
  // появляется «скачок» (часто вниз). Поэтому приводим координаты к viewport.
  var pinching = false, pinchStart = 1, pinchStartScale = 1;
  function pinchCenterInViewport(t1, t2){
    var r = viewport.getBoundingClientRect();
    return {
      x: ((t1.clientX + t2.clientX) / 2) - r.left,
      y: ((t1.clientY + t2.clientY) / 2) - r.top
    };
  }
  viewport.addEventListener('touchstart', function(e){
    if(!gesturesEnabled || mapEditing) return;
    if(e.touches && e.touches.length===2){
      pendingDrag = null;
      pinching = true; dragging = false;
      var t1 = e.touches[0], t2 = e.touches[1];
      pinchStart = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY) || 1;
      pinchStartScale = scale;
      e.preventDefault();
    }
  }, {passive:false});
  window.addEventListener('touchmove', function(e){
    if(!gesturesEnabled) return;
    if(!(pinching && e.touches && e.touches.length===2)) return;
    var t1 = e.touches[0], t2 = e.touches[1];
    var dist = Math.hypot(t2.clientX - t1.clientX, t2.clientY - t1.clientY) || 1;
    var target = pinchStartScale * (dist / pinchStart);
    var next = Math.min(maxScale, Math.max(minScale, target));
    // Зумим относительно центра pinch (в координатах viewport), чтобы не было скачка.
    var center = pinchCenterInViewport(t1, t2);
    var old = scale || 1;
    var ix = (center.x - pos.x) / old;
    var iy = (center.y - pos.y) / old;
    scale = next;
    pos.x = center.x - ix * scale;
    pos.y = center.y - iy * scale;
    apply();
    e.preventDefault();
  }, {passive:false});
  window.addEventListener('touchend', function(e){
    if(!gesturesEnabled) return;
    if(!e.touches || e.touches.length===0){
      pinching = false;
    }
  }, {passive:false});
  if (mapImg.complete) fit(); else mapImg.addEventListener('load', fit); window.addEventListener('resize', fit);

  /* ---------------- Интерактивная карта: полигоны полей ---------------- */
  var mapData = {version:1, features:[]};
  var editor = qs('#mapEditor');
  var btnMapEdit = qs('#btnMapEdit');
  var btnMapEditClose = qs('#btnMapEditClose');
  var selCode = qs('#mapCode');
  var btnUndo = qs('#btnPolyUndo');
  var btnClear = qs('#btnPolyClear');
  var btnDelete = qs('#btnPolyDelete');
  var btnSave = qs('#btnPolySave');
  var edStatus = qs('#mapEditorStatus');

  // Разметка полей — только на ПК.
  // На телефонах/планшетах убираем кнопку и блокируем открытие редактора.
  function isMapEditorAllowed(){
    try {
      if (window.matchMedia && window.matchMedia('(max-width: 1024px)').matches) return false;
      if (window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches) return false;
      return true;
    } catch(e){
      return true;
    }
  }
  var MAP_EDITOR_ALLOWED = isMapEditorAllowed();
  function enforceMapEditorAccess(){
    MAP_EDITOR_ALLOWED = isMapEditorAllowed();
    if (!MAP_EDITOR_ALLOWED){
      if (btnMapEdit) btnMapEdit.style.display = 'none';
      if (editor){
        editor.hidden = true;
        editor.style.display = 'none';
      }
      if (mapEditing){
        mapEditing = false;
        document.body.classList.remove('map-editing');
      }
    } else {
      if (btnMapEdit) btnMapEdit.style.display = '';
      if (editor) editor.style.display = '';
    }
  }
  enforceMapEditorAccess();
  window.addEventListener('resize', enforceMapEditorAccess);

  var colorWrap = qs('#mapColor');
  var selectedColor = 'blue';
  var ALLOWED_COLORS = ['blue','green','pink','yellow','orange','pale'];
  function normColor(c){
    c = String(c||'').trim();
    if (ALLOWED_COLORS.indexOf(c) === -1) c = 'blue';
    return c;
  }
  function setColor(c){
    selectedColor = normColor(c);
    if (colorWrap){
      var btns = colorWrap.querySelectorAll('[data-map-color]');
      btns.forEach(function(b){
        b.classList.toggle('active', (b.getAttribute('data-map-color')||'') === selectedColor);
      });
    }
    refreshButtons();
    renderMap();
  }

  var draftPts = [];
  var draftClosed = false;
  var selectedCode = null;

  function setStatus(s){ if(edStatus) edStatus.textContent = s; }
  function clamp(v, a, b){ return Math.max(a, Math.min(b, v)); }

  function imgXYFromClient(clientX, clientY){
    var r = viewport.getBoundingClientRect();
    var x = (clientX - r.left - pos.x) / scale;
    var y = (clientY - r.top - pos.y) / scale;
    return {x: x, y: y};
  }

  function dFromPoints(pts){
    if (!pts || pts.length < 2) return '';
    var d = 'M ' + pts[0][0].toFixed(1) + ' ' + pts[0][1].toFixed(1);
    for (var i=1;i<pts.length;i++){
      d += ' L ' + pts[i][0].toFixed(1) + ' ' + pts[i][1].toFixed(1);
    }
    d += ' Z';
    return d;
  }

  function renderMap(){
    if (!mapSvg) return;
    // базовый слой-ловушка кликов
    var ns = 'http://www.w3.org/2000/svg';
    mapSvg.innerHTML = '';
    var hit = document.createElementNS(ns,'rect');
    hit.setAttribute('x','0'); hit.setAttribute('y','0');
    hit.setAttribute('width', String(imgW));
    hit.setAttribute('height', String(imgH));
    hit.setAttribute('fill','transparent');
    hit.setAttribute('pointer-events','all');
    hit.setAttribute('data-hit','1');
    mapSvg.appendChild(hit);

    // сохранённые полигоны
    (mapData.features||[]).forEach(function(f){
      var p = document.createElementNS(ns,'path');
      p.setAttribute('d', dFromPoints(f.points||[]));
      var col = normColor(f.color || 'blue');
      p.setAttribute('class', 'field-poly c-'+col + ((lastCode && f.code===lastCode)?' active':''));
      p.setAttribute('data-code', f.code);
      p.setAttribute('pointer-events','all');
      mapSvg.appendChild(p);
    });

    // черновик
    if (draftPts.length){
      var dp = document.createElementNS(ns,'path');
      dp.setAttribute('d', dFromPoints(draftClosed ? draftPts : (draftPts.length>=2? draftPts.concat([draftPts[draftPts.length-1]]) : draftPts)));
      dp.setAttribute('class','poly-draft c-'+selectedColor);
      dp.setAttribute('pointer-events','none');
      mapSvg.appendChild(dp);
      draftPts.forEach(function(pt){
        var c = document.createElementNS(ns,'circle');
        c.setAttribute('cx', pt[0]);
        c.setAttribute('cy', pt[1]);
        c.setAttribute('r', 7);
        c.setAttribute('class','poly-pt c-'+selectedColor);
        c.setAttribute('pointer-events','none');
        mapSvg.appendChild(c);
      });
    }
  }

  function setEditing(on){
    if (!MAP_EDITOR_ALLOWED) return;
    mapEditing = !!on;
    document.body.classList.toggle('map-editing', mapEditing);
    if (editor) editor.hidden = !mapEditing;
    if (btnMapEdit) btnMapEdit.classList.toggle('active', mapEditing);
    if (!mapEditing){
      // выход: сбрасываем черновик
      draftPts = []; draftClosed = false; selectedCode = null;
      setStatus('—');
      if (btnDelete) btnDelete.disabled = true;
      if (btnSave) btnSave.disabled = true;
    } else {
      setStatus('Выбери код поля и начинай ставить точки.');
    }
    renderMap();
  }

  function refreshButtons(){
    var code = (selCode && selCode.value) ? selCode.value : '';
    var canSave = false;
    if (code && String(code).length===4){
      if (draftPts.length>=3 && draftClosed) canSave = true;
      else {
        var ex = findFeature(code);
        if (ex && normColor(ex.color || 'blue') !== selectedColor) canSave = true;
      }
    }
    if (btnSave) btnSave.disabled = !canSave;
    if (btnSave){
      var ex2 = (code && String(code).length===4) ? findFeature(code) : null;
      btnSave.textContent = (draftPts.length>=3 && draftClosed) ? 'Сохранить' : (ex2 ? 'Сохранить цвет' : 'Сохранить');
    }
    if (btnUndo) btnUndo.disabled = draftPts.length===0;
    if (btnClear) btnClear.disabled = draftPts.length===0;
    var hasExisting = !!findFeature(code);
    if (btnDelete) btnDelete.disabled = !hasExisting;
  }

  function findFeature(code){
    code = String(code||'');
    return (mapData.features||[]).find(function(f){ return f.code===code; }) || null;
  }

  function upsertFeature(code, pts, color){
    code = String(code||'');
    var out = [];
    (mapData.features||[]).forEach(function(f){ if (f.code !== code) out.push(f); });
    out.push({code: code, points: pts, color: normColor(color)});
    out.sort(function(a,b){ return String(a.code).localeCompare(String(b.code)); });
    mapData.features = out;
  }

  function deleteFeature(code){
    code = String(code||'');
    mapData.features = (mapData.features||[]).filter(function(f){ return f.code !== code; });
  }

  function loadMapPolygons(){
    fetch('api_map_polygons_get.php').then(function(r){ return r.json(); }).then(function(j){
      if (j && j.ok && j.data){
        mapData = j.data;
        if (!mapData.features || !Array.isArray(mapData.features)) mapData.features = [];
        mapData.features = mapData.features.map(function(f){
          if (!f || typeof f !== 'object') return f;
          f.color = normColor(f.color || 'blue');
          return f;
        });
      }
      renderMap();
      refreshButtons();
    }).catch(function(){
      // без полигона просто работаем как раньше
      renderMap();
    });
  }

  function saveMapPolygons(){
    if (!CSRF){ toast('CSRF не найден','err'); return; }
    var payload = {csrf: CSRF, data: mapData};
    return fetch('api_map_polygons_save.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token': CSRF},
      body: JSON.stringify(payload)
    }).then(function(r){ return r.json(); }).then(function(j){
      if (j && j.ok){ toast('Сохранено: '+(j.saved||0),'ok'); }
      else toast('Ошибка сохранения: '+(j && j.error ? j.error : 'неизвестно'),'err');
      return j;
    }).catch(function(){ toast('Ошибка запроса сохранения','err'); });
  }

  function populateCodes(){
    if (!selCode) return;
    selCode.innerHTML = '<option value="">— выбери —</option>';
    fetch('field_list.php').then(function(r){ return r.json(); }).then(function(j){
      if (!j || !j.ok || !Array.isArray(j.items)) return;
      var opts = j.items.map(function(it){
        var c = String(it.field_code||'').replace(/\D/g,'').slice(0,4);
        if (c.length<3 || c.length>4) return '';
        return '<option value="'+esc(c)+'">'+esc(c)+'</option>';
      }).filter(Boolean).join('');
      selCode.innerHTML = '<option value="">— выбери —</option>' + opts;
      refreshButtons();
    }).catch(function(){});
  }

  // События редактора
  if (btnMapEdit){
    btnMapEdit.addEventListener('click', function(){ setEditing(!mapEditing); });
  }
  if (btnMapEditClose){
    btnMapEditClose.addEventListener('click', function(){ setEditing(false); });
  }
  if (selCode){
    selCode.addEventListener('change', function(){
      selectedCode = selCode.value || null;
      var f = findFeature(selectedCode);
      if (f){
        setColor(f.color || 'blue');
        setStatus('Поле '+selectedCode+' уже размечено. Можно удалить или перерисовать.');
      } else if (selectedCode){
        setStatus('Поле '+selectedCode+': поставь точки контура и сохрани.');
      } else {
        setStatus('Выбери код поля.');
      }
      refreshButtons();
      renderMap();
    });
  }
  if (colorWrap){
    colorWrap.addEventListener('click', function(e){
      var btn = e.target && e.target.closest ? e.target.closest('[data-map-color]') : null;
      if (!btn) return;
      setColor(btn.getAttribute('data-map-color'));
    });
  }
  if (btnUndo){
    btnUndo.addEventListener('click', function(){
      if (!draftPts.length) return;
      draftPts.pop();
      if (draftPts.length < 3) draftClosed = false;
      refreshButtons();
      renderMap();
    });
  }
  if (btnClear){
    btnClear.addEventListener('click', function(){
      draftPts = []; draftClosed = false;
      refreshButtons();
      renderMap();
    });
  }
  if (btnDelete){
    btnDelete.addEventListener('click', function(){
      var code = selCode ? selCode.value : '';
      if (!code) return;
      if (!confirm('Удалить разметку для поля '+code+'?')) return;
      deleteFeature(code);
      saveMapPolygons().then(function(){ loadMapPolygons(); });
    });
  }
  if (btnSave){
    btnSave.addEventListener('click', function(){
      var code = selCode ? selCode.value : '';
      if (!code){ toast('Сначала выбери код поля','err'); return; }

      if (draftPts.length >= 3 && draftClosed){
        upsertFeature(code, draftPts.slice(), selectedColor);
      } else {
        var ex = findFeature(code);
        if (!ex){ toast('Нарисуй контур (или выбери другое поле)','err'); return; }
        ex.color = selectedColor;
      }

      saveMapPolygons().then(function(){
        draftPts = []; draftClosed = false;
        refreshButtons();
        loadMapPolygons();
      });
    });
  }

  // Взаимодействие по карте
  if (mapSvg){
    mapSvg.addEventListener('click', function(e){
      var t = e.target;
      var code = t && t.getAttribute ? t.getAttribute('data-code') : null;
      if (code){
        // клик по полигону
        if (mapEditing){
          if (selCode) selCode.value = code;
          selectedCode = code;
          var ff = findFeature(code);
          if (ff) setColor(ff.color || 'blue');
          setStatus('Выбрано поле '+code+'. Можно удалить или перерисовать.');
          refreshButtons();
          renderMap();
          return;
        }
        codeInput.value = code;
        loadField(code);
        return;
      }
      // клик по пустому месту — в режиме редактирования добавляем точку
      if (!mapEditing) return;
      // чтобы dblclick не добавлял лишние точки
      if (e.detail && e.detail > 1) return;
      if (!selCode || !selCode.value){ toast('Сначала выбери код поля','err'); return; }
      var xy = imgXYFromClient(e.clientX, e.clientY);
      if (!isFinite(xy.x) || !isFinite(xy.y)) return;
      var x = clamp(xy.x, 0, imgW);
      var y = clamp(xy.y, 0, imgH);
      draftPts.push([x, y]);
      draftClosed = false;
      setStatus('Точек: '+draftPts.length+' (двойной клик — замкнуть контур)');
      refreshButtons();
      renderMap();
    });

    mapSvg.addEventListener('dblclick', function(e){
      if (!mapEditing) return;
      if (draftPts.length < 3){ toast('Нужно минимум 3 точки','err'); return; }
      draftClosed = true;
      setStatus('Контур замкнут. Можно сохранить.');
      refreshButtons();
      renderMap();
      e.preventDefault();
    });
  }

  // Инициализация
  populateCodes();
  loadMapPolygons();

  var form=qs('#formCode'), codeInput=qs('#code'), fieldView=qs('#fieldView'), overviewWrap=qs('#overviewWrap'), listWrap=qs('#listWrap'), btnList=qs('#btnList'), btnOverview=qs('#btnOverview'), btnPrint=qs('#btnPrintCard');
  var lastCode=null, currentField=null;
  codeInput.addEventListener('input',()=>{ codeInput.value=codeInput.value.replace(/\D/g,'').slice(0,4); });
  form.addEventListener('submit',e=>{ e.preventDefault(); var code=(codeInput.value||'').replace(/\D/g,''); if(code.length<3 || code.length>4){ toast('Введите 3–4 цифры','err'); return; } loadField(code); });
  btnList.addEventListener('click',()=>{ fetch('field_list.php').then(r=>r.json()).then(j=>{ if(j.ok){ listWrap.innerHTML=renderTable(j.items||[]); fieldView.innerHTML=''; if(overviewWrap) overviewWrap.innerHTML=''; btnPrint.disabled=true; } else { listWrap.innerHTML='<div class="glass pane">Ошибка получения списка</div>'; } }).catch(()=>{ listWrap.innerHTML='<div class="glass pane">Ошибка запроса</div>'; }); });

  if (btnOverview && overviewWrap){
    btnOverview.addEventListener('click',()=>{
      // toggle
      if ((overviewWrap.innerHTML||'').trim() !== ''){
        overviewWrap.innerHTML='';
        return;
      }
      overviewWrap.innerHTML = '<div class="glass pane">Загрузка сводки…</div>';
      // clear other panes
      fieldView.innerHTML='';
      listWrap.innerHTML='';
      btnPrint.disabled=true;

      fetch('api_fields_overview.php').then(r=>r.json()).then(j=>{
        if (!j || !j.ok){
          overviewWrap.innerHTML = '<div class="glass pane">Ошибка: '+esc(j && j.error ? j.error : 'не удалось получить сводку')+'</div>';
          return;
        }
        overviewWrap.innerHTML = renderOverview(j);
        bindOverviewClicks();
      }).catch(()=>{
        overviewWrap.innerHTML = '<div class="glass pane">Ошибка запроса</div>';
      });
    });
  }
  btnPrint.addEventListener('click',()=>{ if(!lastCode) return; window.open('print_field.php?code='+encodeURIComponent(lastCode),'_blank'); });

  function loadField(code){
    fetch('field_get.php?code='+encodeURIComponent(code)).then(r=>r.json()).then(j=>{
      lastCode=code;
      renderMap();
      if(j.ok && j.field){
        currentField=j.field; btnPrint.disabled=false;
        fieldView.innerHTML=renderFieldCard(j.field, USER_ROLE==='admin');
        listWrap.innerHTML='';
        if (overviewWrap) overviewWrap.innerHTML='';
        pulse(); bindEdit(); mountInlineHistoryView();
      }else{
        currentField=null; btnPrint.disabled=true;
        fieldView.innerHTML=renderEmptyCard(code, USER_ROLE==='admin');
        listWrap.innerHTML='';
        if (overviewWrap) overviewWrap.innerHTML='';
        bindAdd(code);
      }
    }).catch(()=> toast('Ошибка запроса','err'));
  }


  function renderFieldCard(f, canEdit){
    function line(label, value){
      return '<div class="field-meta-row">'
        +   '<div class="field-meta-label">'+label+'</div>'
        +   '<div class="field-meta-value">'+esc(value || '—')+'</div>'
        + '</div>';
    }
    var treatment = (f.treatment_date || '—') + (f.treatment_desc ? (' — '+f.treatment_desc) : '');
    return '<div class="field-card">'
      +   '<div class="card-header field-card-head">'
      +     '<div class="field-card-title-wrap">'
      +       '<div class="field-card-label">Участок</div>'
      +       '<strong class="field-card-title">'+esc(f.field_code)+'</strong>'
      +       '<div class="field-card-sub">Культура: '+esc(f.culture || '—')+'</div>'
      +     '</div>'
      +     (canEdit ? '<button class="btn primary" id="btnEdit">Редактировать</button>' : '')
      +   '</div>'
      +   '<div class="card-body">'
      +     '<div class="field-meta">'
      +       line('Площадь', f.area_ha ? (f.area_ha+' га') : '—')
      +       line('Вспашка', f.plow_date)
      +       line('Сев', f.sow_date)
      +       line('Обработка', treatment)
      +       line('Последний полив', f.last_water_date)
      +       line('Уборка', f.harvest_date)
      +       line('Валовый сбор', f.gross_yield)
      +       line('Средняя урожайность', f.avg_yield)
      +       line('Заметки', f.notes)
      +     '</div>'
      +     '<div class="field-history">'
      +       '<div class="field-history-title">История операций</div>'
      +       '<div class="fe-hist" data-fe-hist="view" aria-label="История операций"></div>'
      +     '</div>'
      +   '</div>'
      + '</div>';
  }

  function renderEmptyCard(code, canAdd){
    return '<div class="field-card empty">'
      +   '<div class="card-header field-card-head">'
      +     '<div class="field-card-title-wrap">'
      +       '<div class="field-card-label">Участок</div>'
      +       '<strong class="field-card-title">'+esc(code)+'</strong>'
      +       '<div class="field-card-sub">Данных пока нет</div>'
      +     '</div>'
      +     (canAdd ? '<button class="btn primary" id="btnAdd">Добавить</button>' : '')
      +   '</div>'
      +   '<div class="card-body">'
      +     '<div class="muted sm">Для этого участка пока нет информации.</div>'
      +   '</div>'
      + '</div>';
  }

  function renderTable(rows){
    if(!rows.length) {
      return '<div class="glass pane">Нет данных</div>';
    }
    var h = ''
      + '<div class="field-card field-list-card">'
      +   '<div class="field-list-header">'
      +     '<div>'
      +       '<div class="field-list-title">Все участки</div>'
      +       '<div class="field-list-sub">Обзор кадастровых полей хозяйства</div>'
      +     '</div>'
      +     '<a class="btn ghost" href="export_fields_csv.php" target="_blank">Экспорт CSV (все)</a>'
      +   '</div>'
      +   '<div class="field-list-scroll">'
      +     '<table class="field-table field-list-table">'
      +       '<thead>'
      +         '<tr>'
      +           '<th>Код</th>'
      +           '<th>Культура</th>'
      +           '<th>Площадь</th>'
      +           '<th>Сев</th>'
      +           '<th>Уборка</th>'
      +         '</tr>'
      +       '</thead>'
      +       '<tbody>';
    rows.forEach(function(r){
      h += '<tr>'
        +   '<td>'+esc(r.field_code || '—')+'</td>'
        +   '<td>'
        +     '<span class="field-list-pill">'
        +       '<span class="field-list-pill-dot"></span>'
        +       '<span>'+esc(r.culture || '—')+'</span>'
        +     '</span>'
        +   '</td>'
        +   '<td>'+(r.area_ha ? esc(r.area_ha) + '&nbsp;га' : '—')+'</td>'
        +   '<td>'+esc(r.sow_date || '—')+'</td>'
        +   '<td>'+esc(r.harvest_date || '—')+'</td>'
        + '</tr>';
    });
    h +=       '</tbody></table>'
      +   '</div>'
      + '</div>';
    return h;
  }

  /* ---------------- Director-style overview ("Сводка хозяйства") ---------------- */
  function fmtNum(v, maxFrac){
    maxFrac = (maxFrac==null ? 2 : maxFrac);
    var n = Number(v);
    if (!isFinite(n)) return esc(v==null?'—':v);
    try {
      return n.toLocaleString('ru-RU', {maximumFractionDigits:maxFrac});
    } catch(e){
      return String(Math.round(n*100)/100);
    }
  }
  function fmtArea(v){
    if (v==null || v==='') return '—';
    return fmtNum(v, 2) + ' га';
  }
  function chip(text, kind){
    return '<span class="ov-chip'+(kind?(' '+kind):'')+'">'+esc(text)+'</span>';
  }

  function renderOverview(j){
    var k = j.kpi || {};
    var totalArea = Number(k.area_total || 0);
    var updated = j.generated_at_human || j.generated_at || '';

    var kpiRows = [
      ['Участков', fmtNum(k.fields_total||0, 0)],
      ['Общая площадь', fmtArea(totalArea)],
      ['Требуют внимания', fmtNum(k.attention_total||0, 0)],
      ['Без культуры', fmtNum(k.missing_culture||0, 0)],
      ['Без даты сева', fmtNum(k.missing_sow_date||0, 0)],
      ['Полив/обработка/уборка: риски', fmtNum(k.ops_alerts||0, 0)],
    ].map(function(r){
      return '<div class="field-meta-row">'
        + '<div class="field-meta-label">'+esc(r[0])+'</div>'
        + '<div class="field-meta-value">'+r[1]+'</div>'
        + '</div>';
    }).join('');

    var cult = Array.isArray(j.cultures) ? j.cultures : [];
    var cultRows = cult.slice(0, 10).map(function(c){
      var title = c.title || '—';
      var area = Number(c.area||0);
      var count = Number(c.count||0);
      var denom = totalArea > 0 ? totalArea : Math.max(1, Number(k.fields_total||0));
      var num = totalArea > 0 ? area : count;
      var pct = Math.max(0, Math.min(100, Math.round((num/denom)*100)));
      return '<div class="ov-bar-row">'
        + '<div class="ov-bar-left">'
        +   '<div class="ov-bar-title">'+esc(title)+'</div>'
        +   '<div class="ov-bar-sub">'+esc(fmtNum(count,0))+' шт · '+esc(fmtArea(area))+'</div>'
        + '</div>'
        + '<div class="ov-bar-track" aria-hidden="true"><div class="ov-bar-fill" style="width:'+pct+'%"></div></div>'
        + '<div class="ov-bar-pct">'+pct+'%</div>'
        + '</div>';
    }).join('');
    if (!cultRows) cultRows = '<div class="muted sm">Нет данных по культурам</div>';

    var risks = Array.isArray(j.risks) ? j.risks : [];
    var riskRows = risks.slice(0, 12).map(function(r){
      var code = r.field_code || '';
      var reasons = Array.isArray(r.reasons) ? r.reasons : [];
      var chips = reasons.slice(0, 5).map(function(x){ return chip(x, 'warn'); }).join(' ');
      if (!chips) chips = chip('требует проверки', 'warn');
      return '<div class="ov-risk-item">'
        + '<button class="link-like" type="button" data-load-code="'+esc(code)+'">'+esc(code)+'</button>'
        + '<div class="ov-risk-chips">'+chips+'</div>'
        + '</div>';
    }).join('');
    if (!riskRows) riskRows = '<div class="muted sm">На данный момент явных рисков не найдено (по заданным правилам).</div>';

    return ''
      + '<div class="field-card">'
      +   '<div class="card-header field-card-head">'
      +     '<div class="field-card-title-wrap">'
      +       '<div class="field-card-label">Сводка</div>'
      +       '<strong class="field-card-title">Хозяйство в цифрах</strong>'
      +       '<div class="field-card-sub">Обновлено: '+esc(updated||'сейчас')+'</div>'
      +     '</div>'
      +     '<a class="btn ghost" href="export_overview_pdf.php" target="_blank" rel="noopener">PDF</a>'
      +   '</div>'
      +   '<div class="card-body">'
      +     '<div class="ov-2col">'
      +       '<div><div class="ov-block-title">Ключевые показатели</div><div class="field-meta">'+kpiRows+'</div></div>'
      +       '<div><div class="ov-block-title">Культуры</div><div class="ov-bars">'+cultRows+'</div></div>'
      +     '</div>'
      +   '</div>'
      + '</div>'
      + '<div class="field-card" style="margin-top:10px">'
      +   '<div class="card-header field-card-head">'
      +     '<div class="field-card-title-wrap">'
      +       '<div class="field-card-label">Внимание</div>'
      +       '<strong class="field-card-title">Участки, где нужны действия</strong>'
      +       '<div class="field-card-sub">Кликни по коду — откроется карточка</div>'
      +     '</div>'
      +   '</div>'
      +   '<div class="card-body"><div class="ov-risk-list">'+riskRows+'</div></div>'
      + '</div>';
  }

  function bindOverviewClicks(){
    if (!overviewWrap) return;
    Array.prototype.slice.call(overviewWrap.querySelectorAll('[data-load-code]')).forEach(function(btn){
      btn.addEventListener('click', function(){
        var code = (btn.getAttribute('data-load-code')||'').replace(/\D/g,'').slice(0,4);
        if (!code) return;
        codeInput.value = code;
        overviewWrap.innerHTML='';
        loadField(code);
      });
    });
  }


  var modal=qs('#modalEdit'), formEdit=qs('#formEdit');
  // Защита от "залипания" мобайл-стилей/кеша: при загрузке дашборда редактор ВСЕГДА закрыт.
  if (modal) modal.hidden = true;
  document.body.classList.remove('no-scroll');

  function openEdit(){
    if (!modal) return;
    modal.hidden=false;
    document.body.classList.add('no-scroll');
  }
  function closeEdit(){
    if (!modal) return;
    modal.hidden=true;
    document.body.classList.remove('no-scroll');
  }
  // Закрытие должно работать и в верхней панели, и в нижней (телефон)
  Array.prototype.slice.call(document.querySelectorAll('[data-close="#modalEdit"]')).forEach(function(btn){
    btn.addEventListener('click', closeEdit);
  });
  document.addEventListener('keydown', function(e){
    if (!modal) return;
    if (e.key==='Escape' && !modal.hidden) closeEdit();
  });

  /* ---------------- История операций по участку ---------------- */
  var mhModal = qs('#modalHistory');
  var mhTitle = qs('#mhTitle');
  var mhSub   = qs('#mhSub');
  var mhTabs  = qs('#mhTabs');
  var mhList  = qs('#mhList');
  var mhAddWrap = qs('#mhAddWrap');
  var mhAddBtn = qs('#mhAddBtn');
  var mhType = 'water';
  var mhCode = '';

  function getCsrf(){
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? (m.getAttribute('content')||'') : '';
  }

  function typeLabel(t){
    return ({
      water:'Полив',
      fert:'Удобрения',
      sow:'Посев',
      treat:'Обработки',
      harvest:'Уборка'
    })[t] || t;
  }

  function fmtDateRu(iso){
    if(!iso) return '—';
    // iso: yyyy-mm-dd
    var m = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/.exec(iso);
    if(!m) return iso;
    return m[3]+'.'+m[2]+'.'+m[1];
  }

  function mhTitleCode(){
    if (currentField && currentField.field_code && String(currentField.field_code).replace(/\D/g,'') === mhCode) {
      return currentField.field_code;
    }
    return mhCode || (currentField && currentField.field_code ? currentField.field_code : '—');
  }

  function openHistory(t, code){
    var c = (code || mhCode || lastCode || (currentField && currentField.field_code) || '');
    c = String(c).replace(/\D/g,'').slice(0,4);
    if (!/^\d{3,4}$/.test(c)) return;
    mhCode = c;
    mhType = t || mhType || 'water';
    mhTitle.textContent = mhTitleCode() + ' — ' + typeLabel(mhType);
    mhSub.textContent = 'Загрузка…';
    mhList.innerHTML = '<div class="mh-empty">Загрузка…</div>';
    mhAddWrap.hidden = true; mhAddWrap.innerHTML='';
    if (mhAddBtn) mhAddBtn.hidden = true;
    renderHistoryTabs();
    mhModal.hidden = false;
    document.body.classList.add('no-scroll');
    loadHistory(mhType, mhCode);
  }

  function closeHistory(){
    if (!mhModal) return;
    mhModal.hidden = true;
    document.body.classList.remove('no-scroll');
  }
  var closeBtn = qs('[data-close="#modalHistory"]');
  if (closeBtn) closeBtn.addEventListener('click', closeHistory);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && mhModal && !mhModal.hidden) closeHistory(); });

  function renderHistoryTabs(){
    if (!mhTabs) return;
    var tabs = ['water','fert','sow','treat','harvest'];
    mhTabs.innerHTML = tabs.map(function(t){
      return '<button type="button" class="mh-tab'+(t===mhType?' active':'')+'" data-mh-tab="'+t+'">'+typeLabel(t)+'</button>';
    }).join('');
    Array.prototype.slice.call(mhTabs.querySelectorAll('[data-mh-tab]')).forEach(function(b){
      b.addEventListener('click', function(){
        var t = b.getAttribute('data-mh-tab');
        if (!t || t===mhType) return;
        mhType = t;
        mhTitle.textContent = mhTitleCode() + ' — ' + typeLabel(mhType);
        renderHistoryTabs();
        loadHistory(mhType, mhCode);
      });
    });
  }

  function renderHistoryItem(it){
    var date = fmtDateRu(it.date || it.created_at);
    var main = '';
    if (mhType === 'water') {
      if (it.amount || it.notes) {
        main = (it.amount ? ('Объём: <b>'+esc(it.amount)+'</b>') : '')
          + (it.amount && it.notes ? ' • ' : '')
          + (it.notes ? esc(it.notes) : '');
      } else if (it.payload) {
        var wd = it.payload.last_water_date || it.date || '';
        main = 'Последний полив → <b>'+esc(fmtDateRu(wd) || wd || '—')+'</b>';
      } else {
        main = 'Полив';
      }
    }
    if (mhType === 'fert') {
      main = (it.fertilizer ? ('<b>'+esc(it.fertilizer)+'</b>') : 'Удобрение')
        + (it.dose ? (' • дозировка: '+esc(it.dose)) : '')
        + (it.notes ? ('<div class="muted sm" style="margin-top:6px">'+esc(it.notes)+'</div>') : '');
    }
    if (mhType === 'sow') {
      if (it.culture || it.culture_id || it.payload) {
        var cult = it.culture || (it.payload && it.payload.culture) || '—';
        var sowd = (it.payload && it.payload.sow_date) ? it.payload.sow_date : (it.date||'');
        main = 'Сев: <b>'+fmtDateRu(sowd)+'</b> • культура: <b>'+esc(cult)+'</b>'
          + (it.notes ? ('<div class="muted sm" style="margin-top:6px">'+esc(it.notes)+'</div>') : '');
      } else {
        main = 'Посев';
      }
    }
    if (mhType === 'treat') {
      main = (it.chemical ? ('<b>'+esc(it.chemical)+'</b>') : 'Обработка')
        + (it.notes ? ('<div class="muted sm" style="margin-top:6px">'+esc(it.notes)+'</div>') : '');
    }
    if (mhType === 'harvest') {
      if (it.payload) {
        var hd = it.payload.harvest_date || it.date || '';
        main = 'Уборка: <b>'+fmtDateRu(hd)+'</b>'
          + (it.payload.gross_yield ? (' • валовый: '+esc(it.payload.gross_yield)) : '')
          + (it.payload.avg_yield ? (' • средняя: '+esc(it.payload.avg_yield)) : '');
      } else {
        main = 'Уборка: <b>'+date+'</b>'
          + (it.gross_yield ? (' • валовый: '+esc(it.gross_yield)) : '')
          + (it.avg_yield ? (' • средняя: '+esc(it.avg_yield)) : '')
          + (it.notes ? ('<div class="muted sm" style="margin-top:6px">'+esc(it.notes)+'</div>') : '');
      }
    }

    var metaParts = [];
    if (it.user) metaParts.push('Кто: <b>'+esc(it.user)+'</b>');
    if (it.created_at) metaParts.push('Запись: '+esc(it.created_at));
    if (it.source && it.source !== 'table') metaParts.push('Источник: '+esc(it.source));

    return ''
      + '<div class="mh-item">'
      +   '<div class="mh-date">'+esc(date)+'</div>'
      +   '<div class="mh-main">'+main+'</div>'
      +   (metaParts.length ? ('<div class="mh-meta">'+metaParts.join(' • ')+'</div>') : '')
      + '</div>';
  }

  function buildAddForm(ctx){
    if (!mhAddWrap || !mhAddBtn) return;
    mhAddBtn.hidden = !(ctx && ctx.is_admin);
    if (!ctx || !ctx.is_admin) return;

    mhAddBtn.onclick = function(){
      mhAddWrap.hidden = !mhAddWrap.hidden;
      if (!mhAddWrap.hidden) {
        mhAddBtn.textContent = 'Скрыть форму';
      } else {
        mhAddBtn.textContent = 'Добавить запись';
      }
    };
    mhAddBtn.textContent = 'Добавить запись';

    var formId = 'mhForm';
    var base = ''
      + '<form id="'+formId+'">'
      +   '<input type="hidden" name="action" value="add">'
      +   '<input type="hidden" name="type" value="'+esc(mhType)+'">'
      +   '<input type="hidden" name="code" value="'+esc(lastCode)+'">'
      +   '<input type="hidden" name="csrf" value="'+esc(getCsrf())+'">';

    if (mhType === 'water') {
      base += ''
        + '<div class="row gap">'
        +   '<div style="flex:1;min-width:180px"><label>Дата полива<input name="date" type="date" required></label></div>'
        +   '<div style="flex:1;min-width:180px"><label>Объём (необязательно)<input name="amount" type="text" placeholder="например: 120 м³"></label></div>'
        + '</div>'
        + '<label>Комментарий<textarea name="notes" rows="2" placeholder="например: дождевание, 6 часов"></textarea></label>';
    }
    if (mhType === 'fert') {
      base += ''
        + '<div class="row gap">'
        +   '<div style="flex:1;min-width:180px"><label>Дата внесения<input name="date" type="date" required></label></div>'
        +   '<div style="flex:1;min-width:200px"><label>Удобрение<input name="fertilizer" type="text" placeholder="например: селитра"></label></div>'
        +   '<div style="flex:1;min-width:160px"><label>Доза<input name="dose" type="text" placeholder="например: 120 кг/га"></label></div>'
        + '</div>'
        + '<label>Комментарий<textarea name="notes" rows="2" placeholder="например: по влажной почве"></textarea></label>';
    }
    if (mhType === 'sow') {
      base += ''
        + '<div class="row gap">'
        +   '<div style="flex:1;min-width:180px"><label>Дата посева<input name="date" type="date" required></label></div>'
        +   '<div style="flex:1;min-width:220px"><label>Культура<select name="culture_id" id="mhCultureSel" required><option value="">Загрузка…</option></select></label></div>'
        + '</div>'
        + '<label>Комментарий<textarea name="notes" rows="2" placeholder="например: норма высева / техника"></textarea></label>';
    }
    if (mhType === 'treat') {
      base += ''
        + '<div class="row gap">'
        +   '<div style="flex:1;min-width:180px"><label>Дата обработки<input name="date" type="date" required></label></div>'
        +   '<div style="flex:2;min-width:220px"><label>Препарат / операция<input name="chemical" type="text" placeholder="например: гербицид"></label></div>'
        + '</div>'
        + '<label>Комментарий<textarea name="notes" rows="2" placeholder="например: против сорняка"></textarea></label>';
    }
    if (mhType === 'harvest') {
      base += ''
        + '<div class="row gap">'
        +   '<div style="flex:1;min-width:180px"><label>Дата уборки<input name="date" type="date" required></label></div>'
        +   '<div style="flex:1;min-width:180px"><label>Валовый сбор<input name="gross_yield" type="text" placeholder="т"></label></div>'
        +   '<div style="flex:1;min-width:180px"><label>Средняя урожайность<input name="avg_yield" type="text" placeholder="ц/га"></label></div>'
        + '</div>'
        + '<label>Комментарий<textarea name="notes" rows="2" placeholder="например: влажность, потери"></textarea></label>';
    }

    base += ''
      + '<div class="row gap" style="justify-content:flex-end">'
      +   '<button class="btn primary" type="submit">Сохранить в историю</button>'
      + '</div>'
      + '</form>';

    mhAddWrap.innerHTML = base;

    // Для посева — подгрузим культуры
    if (mhType === 'sow') {
      var sel = qs('#mhCultureSel', mhAddWrap);
      if (sel) {
        preloadCultures(sel).then(function(){
          if (currentField && currentField.culture_id) sel.value = String(currentField.culture_id);
        }).catch(function(){ sel.innerHTML = '<option value="">Ошибка</option>'; });
      }
    }

    var form = qs('#'+formId, mhAddWrap);
    form.addEventListener('submit', function(e){
      e.preventDefault();
      var fd = new FormData(form);
      fetch('api_field_events.php', { method:'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (j && j.ok){
            toast('Запись добавлена','ok');
            mhAddWrap.hidden = true; mhAddBtn.textContent = 'Добавить запись';
            // обновим карточку (чтобы «последний полив/обработка/посев» обновились)
            loadField(mhCode || lastCode);
            loadHistory(mhType, mhCode);
            // обновим встроенную историю в редакторе (если открыта)
            try { if (editHistInst && editHistInst.refresh) editHistInst.refresh(); } catch(_){ }
          } else {
            toast((j && j.error) ? j.error : 'Ошибка сохранения','err');
          }
        })
        .catch(function(){ toast('Ошибка запроса','err'); });
    });
  }

  function loadHistory(t, code){
    var c = String(code || mhCode || lastCode || '').replace(/\D/g,'').slice(0,4);
    if(!/^\d{3,4}$/.test(c)) return;
    mhCode = c;
    mhList.innerHTML = '<div class="mh-empty">Загрузка…</div>';
    fetch('api_field_events.php?action=list&type='+encodeURIComponent(t)+'&code='+encodeURIComponent(mhCode))
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (!j || !j.ok) {
          mhSub.textContent = 'Ошибка загрузки';
          mhList.innerHTML = '<div class="mh-empty">'+esc(j && j.error ? j.error : 'Не удалось получить историю')+'</div>';
          return;
        }
        var src = (j.source === 'table') ? 'данные истории' : 'журнал изменений';
        mhSub.textContent = 'Источник: '+src;
        var items = j.items || [];
        if (!items.length) {
          mhList.innerHTML = '<div class="mh-empty">Пока нет записей. Добавь первую — и всё будет фиксироваться автоматически.</div>';
        } else {
          mhList.innerHTML = items.map(renderHistoryItem).join('');
        }
        buildAddForm(j);
      })
      .catch(function(){
        mhSub.textContent = 'Ошибка запроса';
        mhList.innerHTML = '<div class="mh-empty">Ошибка запроса</div>';
      });
  }

  /* ---------------- Inline History (last 10) ---------------- */
  function renderInlineHistoryItem(it, t){
    var date = fmtDateRu(it.date || (it.created_at ? String(it.created_at).slice(0,10) : ''));
    var main = '';

    // normalize payload
    var p = it && it.payload && typeof it.payload === 'object' ? it.payload : null;

    if (t === 'water') {
      if (it.amount || it.notes) {
        main = (it.amount ? ('Объём: <b>'+esc(it.amount)+'</b>') : '')
          + (it.amount && it.notes ? ' • ' : '')
          + (it.notes ? esc(it.notes) : '');
      } else if (p) {
        var wd = p.last_water_date || it.date || '';
        main = 'Полив → <b>'+esc(fmtDateRu(wd) || wd || '—')+'</b>';
      } else {
        main = 'Полив';
      }
    }
    if (t === 'fert') {
      main = (it.fertilizer ? ('<b>'+esc(it.fertilizer)+'</b>') : 'Удобрение')
        + (it.dose ? (' • '+esc(it.dose)) : '')
        + (it.notes ? ('<div class="fe-hist__meta">'+esc(it.notes)+'</div>') : '');
    }
    if (t === 'sow') {
      var cult = it.culture || (p && p.culture) || '—';
      var sowd = (p && p.sow_date) ? p.sow_date : (it.date||'');
      main = 'Сев: <b>'+fmtDateRu(sowd)+'</b> • культура: <b>'+esc(cult)+'</b>'
        + (it.notes ? ('<div class="fe-hist__meta">'+esc(it.notes)+'</div>') : '');
    }
    if (t === 'treat') {
      main = (it.chemical ? ('<b>'+esc(it.chemical)+'</b>') : 'Обработка')
        + (it.notes ? ('<div class="fe-hist__meta">'+esc(it.notes)+'</div>') : '');
    }
    if (t === 'harvest') {
      if (p) {
        var hd = p.harvest_date || it.date || '';
        main = 'Уборка: <b>'+fmtDateRu(hd)+'</b>'
          + (p.gross_yield ? (' • валовый: '+esc(p.gross_yield)) : '')
          + (p.avg_yield ? (' • средняя: '+esc(p.avg_yield)) : '');
      } else {
        main = 'Уборка: <b>'+date+'</b>'
          + (it.gross_yield ? (' • валовый: '+esc(it.gross_yield)) : '')
          + (it.avg_yield ? (' • средняя: '+esc(it.avg_yield)) : '');
      }
      if (it.notes) main += '<div class="fe-hist__meta">'+esc(it.notes)+'</div>';
    }

    var metaParts = [];
    if (it.user) metaParts.push('Кто: <b>'+esc(it.user)+'</b>');
    if (it.created_at) metaParts.push('Запись: '+esc(it.created_at));
    return ''
      + '<div class="fe-hist__item">'
      +   '<div class="fe-hist__date">'+esc(date)+'</div>'
      +   '<div class="fe-hist__main">'+main+'</div>'
      +   (metaParts.length ? ('<div class="fe-hist__meta">'+metaParts.join(' • ')+'</div>') : '')
      + '</div>';
  }

  function createInlineHistory(container, opts){
    var tabs = ['water','fert','sow','treat','harvest'];
    var limit = (opts && opts.limit) ? opts.limit : 10;
    var getCode = (opts && typeof opts.getCode === 'function') ? opts.getCode : function(){ return lastCode; };
    var canAdd = !!(opts && opts.canAdd);

    var state = {
      type: (opts && opts.type) ? opts.type : 'water',
      code: '',
      total: 0,
      source: ''
    };

    function renderShell(){
      container.innerHTML = ''
        + '<div class="fe-hist__tabs">'
        +   tabs.map(function(t){
              return '<button type="button" class="fe-hist__tab'+(t===state.type?' is-active':'')+'" data-fe-hist-tab="'+t+'">'+typeLabel(t)+'</button>';
            }).join('')
        + '</div>'
        + '<div class="fe-hist__list" data-fe-hist-list></div>'
        + '<div class="fe-hist__foot">'
        +   '<span class="muted sm" data-fe-hist-meta></span>'
        +   '<div class="fe-hist__foot-actions">'
        +     '<button class="btn ghost sm" type="button" data-fe-hist-full>Все записи</button>'
        +     ((canAdd && state.type !== 'treat') ? '<button class="btn sm" type="button" data-fe-hist-add>Добавить</button>' : '')
        +   '</div>'
        + '</div>';

      Array.prototype.slice.call(container.querySelectorAll('[data-fe-hist-tab]')).forEach(function(b){
        b.addEventListener('click', function(){
          var t = b.getAttribute('data-fe-hist-tab');
          if (!t || t === state.type) return;
          state.type = t;
          renderShell();
          load();
        });
      });

      var fullBtn = container.querySelector('[data-fe-hist-full]');
      if (fullBtn) fullBtn.addEventListener('click', function(){
        if (!state.code) return;
        openHistory(state.type, state.code);
      });

      var addBtn = container.querySelector('[data-fe-hist-add]');
      if (addBtn) addBtn.addEventListener('click', function(){
        if (!state.code) return;
        openHistory(state.type, state.code);
        // reveal add form (modal builds it after load)
        setTimeout(function(){
          try {
            if (mhAddBtn && !mhAddBtn.hidden) mhAddBtn.click();
          } catch(_){ }
        }, 220);
      });
    }

    function setMeta(text){
      var el = container.querySelector('[data-fe-hist-meta]');
      if (el) el.textContent = text || '';
    }

    function setList(html){
      var el = container.querySelector('[data-fe-hist-list]');
      if (el) el.innerHTML = html;
    }

    function load(){
      state.code = String(getCode() || '').replace(/\D/g,'').slice(0,4);
      if (!/^\d{3,4}$/.test(state.code)) {
        setMeta('Укажи код участка — и история появится автоматически.');
        setList('<div class="fe-hist__empty">Нет кода участка для истории.</div>');
        return;
      }

      setMeta('Загрузка…');
      setList('<div class="fe-hist__empty">Загрузка…</div>');

      fetch('api_field_events.php?action=list&type='+encodeURIComponent(state.type)+'&code='+encodeURIComponent(state.code))
        .then(function(r){ return r.json(); })
        .then(function(j){
          if (!j || !j.ok) {
            setMeta('Ошибка загрузки');
            setList('<div class="fe-hist__empty">'+esc(j && j.error ? j.error : 'Не удалось получить историю')+'</div>');
            return;
          }
          state.source = (j.source === 'table') ? 'данные истории' : 'журнал изменений';
          var items = (j.items || []);
          state.total = items.length;
          if (!items.length) {
            setMeta('Источник: '+state.source);
            setList('<div class="fe-hist__empty">Пока нет записей. Добавь первую — и всё будет фиксироваться автоматически.</div>');
            return;
          }
          var slice = items.slice(0, limit);
          setList(slice.map(function(it){ return renderInlineHistoryItem(it, state.type); }).join(''));
          var shown = slice.length;
          var meta = 'Источник: '+state.source+' • Показано '+shown+' из '+state.total;
          setMeta(meta);
        })
        .catch(function(){
          setMeta('Ошибка запроса');
          setList('<div class="fe-hist__empty">Ошибка запроса</div>');
        });
    }

    renderShell();
    load();

    return {
      refresh: load,
      getType: function(){ return state.type; },
      setType: function(t){ if (tabs.indexOf(t) >= 0) { state.type = t; renderShell(); load(); } },
      getCode: function(){ return state.code; }
    };
  }

  function mountInlineHistoryView(){
    if (!fieldView) return;
    var c = fieldView.querySelector('[data-fe-hist="view"]');
    if (!c) return;
    // always rebuild for fresh card
    createInlineHistory(c, {
      getCode: function(){ return lastCode; },
      canAdd: (USER_ROLE==='admin'),
      limit: 10,
      type: 'water'
    });
  }

  // Editor tab: inline history inside modalEdit (lazy on first open)
  var editHistWrap = qs('#feHistEmbedEdit');
  var editHistBtn  = qs('#feHistFullFromEdit');
  var editHistTab  = qs('#modalEdit [data-fe-tab="history"]');
  var editHistInst = null;

  function ensureEditInlineHistory(){
    if (!editHistWrap) return null;
    if (!editHistInst) {
      editHistInst = createInlineHistory(editHistWrap, {
        getCode: function(){
          var v = qs('#edit_field_code');
          return v ? v.value : lastCode;
        },
        canAdd: (USER_ROLE==='admin'),
        limit: 10,
        type: 'water'
      });
    }
    return editHistInst;
  }

  if (editHistTab) {
    editHistTab.addEventListener('click', function(){
      var inst = ensureEditInlineHistory();
      if (inst) inst.refresh();
    });
  }
  if (editHistBtn) {
    editHistBtn.addEventListener('click', function(){
      var inst = ensureEditInlineHistory();
      var t = inst ? inst.getType() : 'water';
      var v = qs('#edit_field_code');
      var code = v ? (v.value||'') : (lastCode||'');
      if (!/^\d{3,4}$/.test(String(code).replace(/\D/g,''))) return;
      openHistory(t, code);
    });
  }

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

// --- Mobile UX fix: prevent unwanted auto keyboard on first paint ---
// Некоторые мобильные браузеры (особенно iOS Safari) могут восстанавливать фокус на input при заходе на страницу,
// из-за чего самопроизвольно открывается клавиатура. Блокируем авто-фокус до первого реального действия пользователя.
(function(){
  try {
    var isCoarse = !!(window.matchMedia && window.matchMedia('(pointer: coarse)').matches);
    if (!isCoarse) return;

    var userInteracted = false;
    ['touchstart','pointerdown','mousedown','keydown'].forEach(function(evt){
      window.addEventListener(evt, function(){ userInteracted = true; }, { once:true, capture:true, passive:true });
    });

    function blurActive(){
      var ae = document.activeElement;
      if (!ae) return;
      var tag = (ae.tagName||'').toUpperCase();
      if (tag === 'INPUT' || tag === 'TEXTAREA' || ae.isContentEditable){
        try { ae.blur(); } catch(_){ }
      }
    }

    // pageshow срабатывает и при восстановлении из BFCache — это как раз тот случай, когда iOS любит вернуть фокус.
    window.addEventListener('pageshow', function(){
      setTimeout(function(){ if (!userInteracted) blurActive(); }, 0);
    });

    // Если фокус прилетел сам — снимаем его (но после первого жеста пользователя уже не мешаем).
    document.addEventListener('focusin', function(e){
      if (userInteracted) return;
      var t = e && e.target;
      if (!t) return;
      var tag = (t.tagName||'').toUpperCase();
      if (tag === 'INPUT' || tag === 'TEXTAREA'){
        setTimeout(function(){ try{ t.blur(); }catch(_){ } }, 0);
      }
    }, true);
  } catch(_){ /* no-op */ }
})();