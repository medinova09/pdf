<?php
require_once __DIR__ . '/stats_logger.php';
$db = stats_db();

// Toplamlar
$tot = $db->query("
  SELECT
    COUNT(*) AS ops,
    SUM(bytes_in) AS inb,
    SUM(bytes_out) AS outb,
    AVG(duration_ms) AS avg_ms
  FROM logs
")->fetch(PDO::FETCH_ASSOC);

// Araç bazında
$byTool = $db->query("
  SELECT tool,
         COUNT(*) AS ops,
         SUM(pages) AS pages,
         SUM(bytes_in) AS inb,
         SUM(bytes_out) AS outb,
         AVG(duration_ms) AS avg_ms
  FROM logs
  GROUP BY tool
  ORDER BY ops DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Günlük trend (son 14 gün)
$byDay = $db->query("
  SELECT substr(ts,1,10) AS day, COUNT(*) AS ops
  FROM logs
  WHERE ts >= date('now','-14 day')
  GROUP BY day
  ORDER BY day DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Son 50 işlem
$last = $db->query("
  SELECT ts, tool, filename, pages, bytes_in, bytes_out, duration_ms, ip
  FROM logs
  ORDER BY id DESC
  LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

function fmtB($b){
  if(!$b) return '0 B';
  $u=['B','KB','MB','GB'];
  $i=floor(log($b,1024));
  return round($b/pow(1024,$i),2).' '.$u[$i];
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8" />
<title>PDF Araçları • İstatistikler</title>
<style>
body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#0f2027;color:#fff;margin:0;padding:30px}
h1{margin:0 0 20px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin-bottom:24px}
.card{background:#17323f;border-radius:12px;padding:16px;box-shadow:0 10px 24px rgba(0,0,0,.25)}
.card h3{margin:0 0 8px;color:#ffdd57}
table{width:100%;border-collapse:collapse}
th,td{padding:8px 10px;border-bottom:1px solid #244552}
th{color:#ffdd57;text-align:left}
.small{opacity:.85;font-size:14px}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#244552}
footer{margin-top:24px;opacity:.7}
a{color:#ffdd57}
</style>
</head>
<body>
<h1>📊 PDF Araçları • İstatistikler</h1>

<div class="grid">
  <div class="card"><h3>Toplam İşlem</h3><div class="big"><?= (int)$tot['ops'] ?></div></div>
  <div class="card"><h3>Giriş Verisi</h3><div class="big"><?= fmtB($tot['inb']) ?></div></div>
  <div class="card"><h3>Çıkış Verisi</h3><div class="big"><?= fmtB($tot['outb']) ?></div></div>
  <div class="card"><h3>Ortalama Süre</h3><div class="big"><?= round($tot['avg_ms']) ?> ms</div></div>
</div>

<div class="card">
  <h3>Araç Bazında Özet</h3>
  <table>
    <tr><th>Araç</th><th>İşlem</th><th>Sayfa</th><th>Giriş</th><th>Çıkış</th><th>Ort. Süre</th></tr>
    <?php foreach($byTool as $r): ?>
    <tr>
      <td><span class="badge"><?= htmlspecialchars($r['tool']) ?></span></td>
      <td><?= (int)$r['ops'] ?></td>
      <td><?= (int)$r['pages'] ?></td>
      <td><?= fmtB($r['inb']) ?></td>
      <td><?= fmtB($r['outb']) ?></td>
      <td><?= round($r['avg_ms']) ?> ms</td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h3>Günlük İşlem (Son 14 Gün)</h3>
  <table>
    <tr><th>Tarih</th><th>İşlem</th></tr>
    <?php foreach($byDay as $r): ?>
    <tr><td><?= $r['day'] ?></td><td><?= (int)$r['ops'] ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="card">
  <h3>Son 50 İşlem</h3>
  <table>
    <tr><th>Zaman</th><th>Araç</th><th>Dosya</th><th>Sayfa</th><th>Giriş</th><th>Çıkış</th><th>Süre</th><th>IP</th></tr>
    <?php foreach($last as $r): ?>
    <tr class="small">
      <td><?= htmlspecialchars($r['ts']) ?></td>
      <td><?= htmlspecialchars($r['tool']) ?></td>
      <td><?= htmlspecialchars($r['filename']) ?></td>
      <td><?= (int)$r['pages'] ?></td>
      <td><?= fmtB($r['bytes_in']) ?></td>
      <td><?= fmtB($r['bytes_out']) ?></td>
      <td><?= (int)$r['duration_ms'] ?> ms</td>
      <td><?= htmlspecialchars($r['ip']) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
</div>

<footer>Bilgi Sistemleri © <span id="y"></span> Medinova</footer>
<script>document.getElementById('y').textContent = new Date().getFullYear();</script>
</body>
</html>
