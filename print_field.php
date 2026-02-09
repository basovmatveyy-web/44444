<?php
require_once __DIR__ . '/config.php';
$code = trim((string)($_GET['code'] ?? ''));
if (!preg_match('/^\d{3,4}$/', $code)) { die('Неверный код'); }
$st = $pdo->prepare("SELECT f.*, c.title AS culture FROM fields f LEFT JOIN cultures c ON c.id=f.culture_id WHERE f.field_code=? LIMIT 1");
$st->execute([$code]);
$f = $st->fetch();
if (!$f) die('Данных по участку нет');
function tx($v){ return htmlspecialchars((string)($v??'—'), ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Карточка участка <?=tx($f['field_code'])?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
@page{
  size: A4;
  margin: 14mm;
}

/* Палитра в стиле дашборда */
:root{
  --bg:#020617;
  --surface:rgba(15,23,42,0.92);
  --border:rgba(148,163,184,0.35);
  --primary:#22d3ee;
  --primary-2:#4ea8ff;
  --text:#e6eefc;
  --muted:#9fb2d1;
  --radius:18px;
  --shadow:0 18px 45px rgba(15,23,42,.9);
}

*{box-sizing:border-box;margin:0;padding:0}

body{
  min-height:100vh;
  font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial;
  color:var(--text);
  background:
    radial-gradient(1200px 800px at 80% 0%, rgba(78,168,255,.22), transparent 60%),
    radial-gradient(900px 700px at 0% 100%, rgba(34,211,238,.18), transparent 55%),
    #020617;
}

/* Верхняя панель действий (на экране) */
.print-actions{
  position:sticky;
  top:0;
  z-index:30;
  padding:10px 18px;
  display:flex;
  gap:10px;
  align-items:center;
  justify-content:flex-start;
  backdrop-filter:blur(20px);
  background:linear-gradient(to bottom, rgba(15,23,42,.92), rgba(15,23,42,.80));
  border-bottom:1px solid rgba(148,163,184,.35);
}

/* Кнопки */
.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:6px;
  padding:8px 16px;
  border-radius:999px;
  border:1px solid rgba(148,163,184,.65);
  background:rgba(15,23,42,.85);
  color:var(--text);
  cursor:pointer;
  text-decoration:none;
  font-size:13px;
  font-weight:500;
  letter-spacing:.02em;
  transition:transform .2s ease, box-shadow .2s ease, background .2s ease;
}
.btn:hover{
  transform:translateY(-1px);
  box-shadow:0 8px 24px rgba(15,23,42,.9);
  background:linear-gradient(135deg, var(--primary), var(--primary-2));
}
.btn.primary{
  background:linear-gradient(135deg, var(--primary), var(--primary-2));
  border-color:transparent;
  box-shadow:0 10px 26px rgba(56,189,248,.8);
}

/* Обёртка карточки */
.shell{
  min-height:calc(100vh - 56px);
  display:flex;
  align-items:center;
  justify-content:center;
  padding:24px 16px 32px;
}
.card{
  width:100%;
  max-width:880px;
  border-radius:var(--radius);
  background:radial-gradient(circle at 0 0, rgba(148,163,184,.18), transparent 55%),
             rgba(15,23,42,0.95);
  border:1px solid var(--border);
  box-shadow:var(--shadow);
  padding:22px 22px 20px;
}

/* Шапка карточки */
.card-head{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:16px;
  margin-bottom:18px;
}
.card-meta{
  display:flex;
  flex-direction:column;
  gap:4px;
}
.card-pill{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:4px 10px;
  border-radius:999px;
  background:rgba(15,23,42,.9);
  border:1px solid rgba(148,163,184,.5);
  font-size:11px;
  letter-spacing:.12em;
  text-transform:uppercase;
  color:var(--muted);
}
.card-title{
  font-size:22px;
  font-weight:600;
}
.card-sub{
  font-size:13px;
  color:var(--muted);
}
.code-tag{
  padding:6px 12px;
  border-radius:999px;
  border:1px solid rgba(148,163,184,.55);
  background:rgba(15,23,42,.7);
  font-size:13px;
}

/* Таблица полей */
.details-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px 18px;
  margin-bottom:18px;
}
.detail-row{
  padding:10px 12px;
  border-radius:14px;
  background:rgba(15,23,42,.95);
  border:1px solid rgba(148,163,184,.26);
}
.detail-label{
  font-size:12px;
  color:var(--muted);
  margin-bottom:2px;
}
.detail-value{
  font-size:13px;
  font-weight:500;
}

/* Заметки */
.notes{
  margin-top:4px;
  padding:12px 12px 10px;
  border-radius:14px;
  background:rgba(15,23,42,.95);
  border:1px solid rgba(148,163,184,.26);
}
.notes-title{
  font-size:12px;
  color:var(--muted);
  margin-bottom:4px;
}
.notes-body{
  font-size:13px;
  white-space:pre-wrap;
}

/* Адаптив */
@media (max-width:720px){
  .card{
    padding:18px 16px 18px;
  }
  .card-head{
    flex-direction:column;
    align-items:flex-start;
  }
  .details-grid{
    grid-template-columns:1fr;
  }
}

/* Версия для печати: белый фон, аккуратные рамки */
@media print{
  body{
    background:#ffffff;
    color:#000000;
  }
  .print-actions{
    display:none;
  }
  .shell{
    padding:0;
    min-height:auto;
  }
  .card{
    box-shadow:none;
    background:#ffffff;
    border:1px solid #e5e7eb;
  }
  .detail-row,
  .notes{
    background:#ffffff;
    border-color:#e5e7eb;
  }
}
</style>
</head>
<body>
<div class="print-actions">
  <a class="btn" href="export_field_csv.php?code=<?=tx($f['field_code'])?>">Экспорт CSV</a>
  <button class="btn primary" onclick="window.print()">Печать</button>
</div>

<div class="shell">
  <div class="card">
    <header class="card-head">
      <div class="card-meta">
        <div class="card-pill">Карточка участка</div>
        <div class="card-title">Участок <?=tx($f['field_code'])?></div>
        <div class="card-sub">СХПК «Береговой» — сводные данные</div>
      </div>
      <div class="code-tag">Кадастровый № <?=tx($f['field_code'])?></div>
    </header>

    <section class="details-grid">
      <div class="detail-row">
        <div class="detail-label">Культура</div>
        <div class="detail-value"><?=tx($f['culture'])?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Площадь</div>
        <div class="detail-value"><?=tx($f['area_ha'])?> га</div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Дата вспашки</div>
        <div class="detail-value"><?=tx($f['plow_date'])?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Дата сева</div>
        <div class="detail-value"><?=tx($f['sow_date'])?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Последний полив</div>
        <div class="detail-value"><?=tx($f['last_water_date'])?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Дата уборки</div>
        <div class="detail-value"><?=tx($f['harvest_date'])?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Валовый сбор</div>
        <div class="detail-value"><?=tx($f['gross_yield'])?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Средняя урожайность</div>
        <div class="detail-value"><?=tx($f['avg_yield'])?></div>
      </div>
    </section>

    <section class="notes">
      <div class="notes-title">Заметки</div>
      <div class="notes-body"><?=tx($f['notes'])?></div>
    </section>
  </div>
</div>
</body>
</html>
