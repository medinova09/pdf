<?php
// ---- İSTATİSTİK OKUMA & ÖZET ----
$STATS_FILE = __DIR__ . '/stats.json';
$stats = [];
if (file_exists($STATS_FILE)) {
    $raw = @file_get_contents($STATS_FILE);
    $arr = json_decode($raw ?: '[]', true);
    if (is_array($arr)) $stats = $arr;
}

// Son 15 kayıt (yeniler üstte)
$last = array_slice(array_reverse($stats), 0, 15);

// Özetler
$total_ops = count($stats);
$by_type = [];
$total_saved_mb = 0.0;      // sadece compress türü için hesaplanabilir
$avg_saving_percent = null; // compress türünde ortalama
$compress_count = 0;

foreach ($stats as $r) {
    $t = $r['type'] ?? 'unknown';
    $by_type[$t] = ($by_type[$t] ?? 0) + 1;

    if ($t === 'compress') {
        $orig = isset($r['original_size_mb']) ? floatval($r['original_size_mb']) : null;
        $comp = isset($r['compressed_size_mb']) ? floatval($r['compressed_size_mb']) : null;
        if ($orig !== null && $comp !== null && $orig >= $comp) {
            $total_saved_mb += ($orig - $comp);
        }
        if (isset($r['saving_percent'])) {
            $avg_saving_percent = ($avg_saving_percent ?? 0) + floatval($r['saving_percent']);
            $compress_count++;
        }
    }
}
if ($compress_count > 0) {
    $avg_saving_percent = round($avg_saving_percent / $compress_count, 1);
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <title>PDF İşlem Aracı</title>
  <style>
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background: linear-gradient(to right, #0f2027, #203a43, #2c5364);
      color: #fff;
      margin: 0;
      padding: 50px 20px;
    }
    h2 { color: #ffdd57; text-align: center; margin-bottom: 40px; }
    .wrapper { display: flex; flex-wrap: wrap; justify-content: center; gap: 30px; }
    .form-container {
      background-color: rgba(255, 255, 255, 0.05);
      padding: 30px; border-radius: 12px; width: 100%; max-width: 400px;
      box-shadow: 0 0 15px rgba(0, 0, 0, 0.4);
    }
    .section-title { font-size: 20px; margin-bottom: 20px; border-bottom: 1px solid #888; padding-bottom: 5px; }
    label { display: block; margin-top: 15px; margin-bottom: 5px; font-weight: bold; }
    input[type="file"], input[type="text"], select {
      width: 100%; padding: 8px; margin-bottom: 15px; border-radius: 6px; border: none;
    }
    button {
      background-color: #ffdd57; border: none; padding: 10px 20px; font-size: 16px; color: #000;
      border-radius: 8px; cursor: pointer; transition: background-color 0.3s ease;
    }
    button:hover { background-color: #e6c44c; }

    /* İstatistik kartı */
    .stats-container {
      background-color: rgba(255,255,255,0.06);
      padding: 30px; border-radius: 12px; margin: 40px auto 0; max-width: 1100px;
      box-shadow: 0 0 15px rgba(0,0,0,0.35);
    }
    .stats-grid {
      display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 16px; margin-bottom: 18px;
    }
    .stat-chip {
      background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.12);
      border-radius: 10px; padding: 14px; text-align: center;
    }
    .stat-chip b { color: #ffdd57; font-size: 18px; display: block; }
    .table-wrap { overflow-x: auto; }
    table {
      width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 14px;
      background: rgba(0,0,0,0.2); border-radius: 10px; overflow: hidden;
    }
    thead th {
      text-align: left; padding: 12px; background: rgba(255,255,255,0.06); border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    tbody td { padding: 10px 12px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    tbody tr:hover { background: rgba(255,255,255,0.04); }
    .badge {
      display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; background:#2c3f49; color:#ffdd57; font-weight:600;
    }

    @media screen and (max-width: 850px) {
      .wrapper { flex-direction: column; align-items: center; }
      .stats-grid { grid-template-columns: repeat(2, minmax(0,1fr)); }
    }
    footer { margin-top: 60px; text-align: center; font-size: 14px; color: #ccc; opacity: 0.8; }
    footer p { margin: 20px 0 0; }
  </style>
</head>
<body>

  <h2>Medinova 📄 PDF İşlem Aracı</h2>

  <div class="wrapper">
    <!-- PDF Küçültme -->
    <div class="form-container">
      <div class="section-title">📉 PDF Küçültme</div>
      <form action="compress.php" method="post" enctype="multipart/form-data">
        <label for="pdf_compress">PDF Dosyası:</label>
        <input type="file" name="pdf_file" id="pdf_compress" accept="application/pdf" required>

        <label for="quality">Kalite Seçimi:</label>
        <select name="quality" id="quality" required>
          <option value="screen">🟢 Düşük Kalite (En Küçük Boyut)</option>
          <option value="ebook" selected>🟡 Orta Kalite (Tavsiye Edilen)</option>
          <option value="printer">🔵 Yüksek Kalite (Daha Net Görüntü)</option>
        </select>

        <button type="submit">PDF'yi Küçült</button>
      </form>
    </div>

    <!-- PDF Kesme -->
    <div class="form-container">
      <div class="section-title">✂️ PDF Kesme (Cutter)</div>
      <form action="cutter.php" method="post" enctype="multipart/form-data">
        <label for="pdf_cut">PDF Dosyası:</label>
        <input type="file" name="pdf_file" id="pdf_cut" accept="application/pdf" required>

        <label for="range">Sayfa Aralığı (örn: 1-3, 5):</label>
        <input type="text" name="range" id="range" placeholder="1-2 veya 4" required>

        <button type="submit">Sayfaları Kes</button>
      </form>
    </div>

    <!-- PDF Birleştirme -->
    <div class="form-container">
      <div class="section-title">🧩 PDF Birleştirme</div>
      <form action="merge.php" method="post" enctype="multipart/form-data">
        <label for="pdf_merge">PDF Dosyaları (çoklu seçim):</label>
        <input type="file" name="pdf_files[]" id="pdf_merge" accept="application/pdf" multiple required>
        <button type="submit">PDF'leri Birleştir</button>
      </form>
    </div>

    <!-- PDF to JPG -->
    <div class="form-container">
      <div class="section-title">🖼 PDF to JPG</div>
      <form action="pdf-to-jpg.php" method="post" enctype="multipart/form-data">
        <label for="pdf_jpg">PDF Dosyası:</label>
        <input type="file" name="pdf" id="pdf_jpg" accept="application/pdf" required>
        <button type="submit">JPG'ye Dönüştür</button>
      </form>
    </div>
  </div>

  <!-- İSTATİSTİKLER -->
  <div class="stats-container">
    <div class="section-title">📊 İstatistikler</div>

    <div class="stats-grid">
      <div class="stat-chip">
        <b>Toplam İşlem</b>
        <div><?php echo number_format($total_ops); ?></div>
      </div>
      <div class="stat-chip">
        <b>Compress</b>
        <div><?php echo number_format($by_type['compress'] ?? 0); ?></div>
      </div>
      <div class="stat-chip">
        <b>Toplam Kazanç</b>
        <div><?php echo ($total_saved_mb > 0) ? number_format($total_saved_mb, 2) . ' MB' : '—'; ?></div>
      </div>
      <div class="stat-chip">
        <b>Avg. Compress Tasarruf</b>
        <div><?php echo ($avg_saving_percent !== null) ? '%' . $avg_saving_percent : '—'; ?></div>
      </div>
    </div>

    <?php if (!empty($by_type)): ?>
      <div style="margin-bottom: 12px;">
        <?php foreach ($by_type as $t => $cnt): ?>
          <span class="badge" title="Bu türde işlem sayısı"><?php echo htmlspecialchars($t) . ': ' . $cnt; ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Tarih</th>
            <th>İşlem</th>
            <th>Detay</th>
            <th>Dosya</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($last)): ?>
          <tr><td colspan="4" style="text-align:center;color:#ddd;">Henüz kayıt yok.</td></tr>
        <?php else: ?>
          <?php foreach ($last as $r): ?>
            <?php
              $date = htmlspecialchars($r['date'] ?? '-');
              $type = htmlspecialchars($r['type'] ?? 'unknown');

              // ✅ Dosya linkini doğru kur: eğer 'file' alanında klasör içeriyorsa aynen kullan, yoksa uploads/ ekle
              if (isset($r['file'])) {
                  $f = (string)$r['file'];
                  $file = (strpos($f, '/') !== false) ? $f : ('uploads/' . rawurlencode($f));
              } else {
                  $file = null;
              }

              // Detay alanını akıllıca oluştur
              $detail = '';
              $rtype = $r['type'] ?? '';

              if ($rtype === 'compress') {
                  $orig = isset($r['original_size_mb']) ? number_format((float)$r['original_size_mb'], 2) . ' MB' : '?';
                  $comp = isset($r['compressed_size_mb']) ? number_format((float)$r['compressed_size_mb'], 2) . ' MB' : '?';
                  $savp = isset($r['saving_percent']) ? '%' . (0 + $r['saving_percent']) : '—';
                  $detail = "{$orig} → {$comp} (Tasarruf: {$savp})";
              } elseif ($rtype === 'pdf2jpg') {
                  $pages = isset($r['pages']) ? (int)$r['pages'] : null;
                  $dpi   = isset($r['dpi']) ? (int)$r['dpi'] : null;
                  $zipmb = isset($r['zip_size_mb']) ? number_format((float)$r['zip_size_mb'], 2) . ' MB' : null;
                  $bits = [];
                  if ($pages !== null) $bits[] = "pages: {$pages}";
                  if ($dpi !== null)   $bits[] = "dpi: {$dpi}";
                  if ($zipmb !== null) $bits[] = "zip: {$zipmb}";
                  $detail = $bits ? implode(' • ', $bits) : '—';
              } else {
                  // Diğer araçlar (merge/cutter vs.) için genel gösterim
                  $knownKeys = ['pages','count','range','result','note','dpi','zip_size_mb'];
                  $parts = [];
                  foreach ($knownKeys as $k) {
                      if (isset($r[$k])) {
                          $val = $k === 'zip_size_mb' ? (number_format((float)$r[$k], 2).' MB') : (string)$r[$k];
                          $parts[] = $k . ': ' . htmlspecialchars($val);
                      }
                  }
                  $detail = $parts ? implode(' • ', $parts) : '—';
              }
            ?>
            <tr>
              <td><?php echo $date; ?></td>
              <td><span class="badge"><?php echo $type; ?></span></td>
              <td><?php echo $detail; ?></td>
              <td>
                <?php if ($file): ?>
                  <a href="<?php echo htmlspecialchars($file); ?>" style="color:#ffdd57;">İndir</a>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <footer>
    <p>Bilgi Sistemleri © <span id="year"></span> Medinova</p>
  </footer>

  <script>document.getElementById('year').textContent = new Date().getFullYear();</script>
</body>
</html>
