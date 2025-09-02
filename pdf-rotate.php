<?php
// pdf-rotate.php — Medinova PDF Araçları: PDF Döndürme (Rotate)
// Bağımlılıklar: qpdf (önerilir), pdftk-java (yedek: tüm sayfalar)
// Dizin yapısı: /var/www/html/pdf/{uploads,converted,jpgs,zips, ...}

declare(strict_types=1);
session_start();

// ---- Dizinler / yollar ----
$BASE_DIR   = realpath(__DIR__);
$UPLOAD_DIR = $BASE_DIR . '/uploads';
$OUTPUT_DIR = $BASE_DIR . '/converted';
$STATS_FILE = $BASE_DIR . '/stats.json';

if (!is_dir($UPLOAD_DIR)) @mkdir($UPLOAD_DIR, 0775, true);
if (!is_dir($OUTPUT_DIR)) @mkdir($OUTPUT_DIR, 0775, true);

// ---- CSRF ----
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// ---- Doğrudan indirme (converted/ altından) ----
// Örn: pdf-rotate.php?download=rotated-abc123.pdf
if (isset($_GET['download'])) {
  $fname = basename((string)$_GET['download']); // güvenlik: path kırp
  $full  = $OUTPUT_DIR . '/' . $fname;

  if (is_file($full)) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Length: ' . filesize($full));
    header('Cache-Control: private, max-age=0, no-cache');
    readfile($full);
    exit;
  } else {
    http_response_code(404);
    echo 'Dosya bulunamadı.';
    exit;
  }
}

// ---- Yardımcılar ----
function has_cmd(string $cmd): bool {
  $which = trim((string)@shell_exec('command -v ' . escapeshellarg($cmd)));
  return $which !== '';
}

function normalize_range(string $raw): string {
  // Kullanıcı 1-3,5,8-9 veya 1-z yazabilir (qpdf 'z' = son sayfa)
  $s = trim(str_replace([' ', ';'], ['', ','], $raw));
  if ($s === '') return '1-z';
  if (!preg_match('/^[0-9zZ,\-]+$/', $s)) return '1-z';
  return str_replace('Z', 'z', $s);
}

function rotate_with_qpdf(string $in, string $out, string $angle, string $range): array {
  $angle = in_array($angle, ['+90','-90','180'], true) ? $angle : '+90';
  $range = normalize_range($range);
  $cmd = sprintf('qpdf %s --rotate=%s:%s -- %s 2>&1',
                 escapeshellarg($in), $angle, $range, escapeshellarg($out));
  $lines = [];
  exec($cmd, $lines, $ret);
  return [$ret, implode("\n", $lines), $cmd];
}

function rotate_with_pdftk_all(string $in, string $out, string $angle): array {
  // pdftk yalnızca tüm sayfalar için yedek motor olarak kullanılır
  $map = ['+90'=>'E', '-90'=>'W', '180'=>'S'];
  $suffix = $map[$angle] ?? 'E';
  $cmd = sprintf('pdftk %s cat 1-end%s output %s 2>&1',
                 escapeshellarg($in), $suffix, escapeshellarg($out));
  $lines = [];
  exec($cmd, $lines, $ret);
  return [$ret, implode("\n", $lines), $cmd];
}

function append_stats(string $statsFile, array $entry): void {
  $list = [];
  if (is_file($statsFile)) {
    $json = @file_get_contents($statsFile);
    $tmp  = json_decode($json ?: '[]', true);
    if (is_array($tmp)) $list = $tmp;
  }
  $list[] = $entry;
  @file_put_contents(
    $statsFile,
    json_encode($list, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)
  );
}

// ---- İşlem durum değişkenleri ----
$errors = [];
$result = null; // ['url'=>'converted/rotated-*.pdf','engine'=>'qpdf','angle'=>'+90','pages'=>'1-z']

// ---- POST işlemi ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    $errors[] = 'Güvenlik doğrulaması (CSRF) başarısız.';
  }

  // Parametreler
  $angle = $_POST['angle'] ?? '+90';
  $scope = $_POST['scope'] ?? 'all'; // 'all' | 'range' (formda radio varsa)
  // Eğer index.php'den geliyorsa 'scope' olmayabilir; bu durumda range boşsa 1-z olsun
  $rangeRaw = (string)($_POST['range'] ?? '');
  $range = ($scope === 'range' || $rangeRaw !== '') ? normalize_range($rangeRaw) : '1-z';

  // Dosya
  if (!isset($_FILES['pdf']) || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $errors[] = 'PDF dosyası yüklenemedi.';
  } else {
    $file = $_FILES['pdf'];
    if (($file['size'] ?? 0) <= 0) {
      $errors[] = 'Boş dosya.';
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== 'application/pdf') {
      $errors[] = 'Yalnızca PDF kabul edilir.';
    }
  }

  if (!$errors) {
    $uid = bin2hex(random_bytes(6));
    $infile  = $UPLOAD_DIR . "/in-{$uid}.pdf";
    $outfile = $OUTPUT_DIR . "/rotated-{$uid}.pdf";

    if (!move_uploaded_file($file['tmp_name'], $infile)) {
      $errors[] = 'Geçici dosya taşınamadı.';
    } else {
      // Öncelik: qpdf, yedek: pdftk (sadece tüm sayfalar)
      $used = '';
      if (has_cmd('qpdf')) {
        [$ret, $log, $cmd] = rotate_with_qpdf($infile, $outfile, $angle, $range);
        $used = 'qpdf';
      } elseif ($range === '1-z' && has_cmd('pdftk')) {
        [$ret, $log, $cmd] = rotate_with_pdftk_all($infile, $outfile, $angle);
        $used = 'pdftk';
      } else {
        $ret = 1;
        $log = 'Sunucuda qpdf yok. pdftk yalnızca Tüm sayfalar için çalışır. Lütfen qpdf kurun: sudo apt-get install qpdf';
        $cmd = '';
      }

      if ($ret !== 0 || !is_file($outfile) || filesize($outfile) === 0) {
        $errors[] = 'Döndürme başarısız: ' . htmlspecialchars($log);
        @unlink($outfile);
      } else {
        // İstatistik kaydı
        $entry = [
          'type'  => 'rotate',
          'date'  => date('Y-m-d H:i:s'),
          'angle' => $angle,
          'pages' => $range,
          'file'  => 'converted/' . basename($outfile),
        ];
        append_stats($STATS_FILE, $entry);

        // Sonuç
        $result = [
          'url'    => 'converted/' . basename($outfile), // görüntüleme linki
          'dl'     => '?download=' . rawurlencode(basename($outfile)), // PDF olarak indir
          'engine' => $used,
          'angle'  => $angle,
          'pages'  => $range,
        ];
      }

      // Giriş dosyasını sil
      @unlink($infile);
    }
  }
}

// ---- HTML ----
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PDF Döndürme (Rotate) — Medinova</title>
  <style>
    body{font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial,sans-serif; background:#0f172a; color:#e2e8f0; padding:24px}
    .container{max-width:920px;margin:0 auto}
    .topnav{margin-bottom:12px}
    .topnav a{color:#22d3ee;text-decoration:none}
    .card{background:#111827;border:1px solid #1f2937;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.35)}
    .hdr{padding:20px 24px;border-bottom:1px solid #1f2937}
    .hdr h1{margin:0;font-size:22px}
    .body{padding:24px}
    label{display:block;margin:.25rem 0 .35rem;font-weight:600}
    input[type="file"],select, input[type="text"]{width:100%;padding:.75rem;border-radius:12px;border:1px solid #334155;background:#0b1220;color:#e2e8f0}
    .row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .muted{color:#93a3b8;font-size:.9rem}
    .radio-line{display:flex;gap:14px;flex-wrap:wrap}
    .radio-line label{font-weight:500}
    .btn{background:#2563eb;color:#fff;border:0;border-radius:12px;padding:.8rem 1.1rem;font-weight:700;cursor:pointer}
    .btn:disabled{opacity:.5;cursor:not-allowed}
    .alert{padding:12px 14px;border-radius:12px;margin-bottom:12px}
    .alert.err{background:#3b0d0d;border:1px solid #7f1d1d}
    .alert.ok{background:#0b2f20;border:1px solid #14532d}
    a.dl{display:inline-block;margin-top:10px;color:#22d3ee;text-decoration:none;margin-right:12px}
    .tip{background:#0b1220;border:1px dashed #334155;padding:10px;border-radius:12px;margin-top:10px}
  </style>
</head>
<body>
  <div class="container">
    <div class="topnav">
      ⟵ <a href="index.php">Anasayfaya dön</a>
    </div>
    <div class="card">
      <div class="hdr">
        <h1>PDF Döndürme (Rotate)</h1>
        <div class="muted">Mevcut PDF sayfalarını +90 / -90 / 180 derece döndür. Klasörler: <code>uploads/</code> → <code>converted/</code>.</div>
      </div>
      <div class="body">
        <?php if ($errors): ?>
          <div class="alert err">
            <strong>Hata:</strong><br>
            <?php foreach ($errors as $e) echo '<div>'.htmlspecialchars($e).'</div>'; ?>
          </div>
        <?php endif; ?>

        <?php if ($result): ?>
          <div class="alert ok">
            <strong>Başarılı!</strong> "<?php echo htmlspecialchars($result['pages']); ?>" aralığı
            <strong><?php echo htmlspecialchars($result['angle']); ?>°</strong> döndürüldü.
            <div class="muted">Kullanılan motor: <?php echo htmlspecialchars($result['engine']); ?></div>
            <a class="dl" href="<?php echo $result['dl']; ?>">⤓ PDF olarak indir</a>
            <a class="dl" href="<?php echo htmlspecialchars($result['url']); ?>" target="_blank" rel="noopener">Görüntüle</a>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">

          <label>PDF dosyası</label>
          <input type="file" name="pdf" accept="application/pdf" required>

          <div class="row" style="margin-top:12px">
            <div>
              <label>Döndürme açısı</label>
              <div class="radio-line">
                <label><input type="radio" name="angle" value="+90" checked> +90° (sağa)</label>
                <label><input type="radio" name="angle" value="-90"> -90° (sola)</label>
                <label><input type="radio" name="angle" value="180"> 180° (ters)</label>
              </div>
            </div>
            <div>
              <label>Hedef sayfalar</label>
              <div class="radio-line">
                <label><input type="radio" name="scope" value="all" checked> Tüm sayfalar</label>
                <label><input type="radio" name="scope" value="range"> Belirli sayfalar</label>
              </div>
              <input type="text" name="range" placeholder="Örn: 1-3,5,8-9 veya 1-z" title="1-3,5,8-9 veya 1-z">
              <div class="muted">İpucu: <code>1-z</code> son sayfaya kadar demektir. Örn <code>2-4,7,10-z</code></div>
            </div>
          </div>

          <div class="tip">
            <strong>QPDF önerilir.</strong> Kurulum: <code>sudo apt-get install qpdf</code><br>
            Yedek motor <code>pdftk</code> yalnızca <em>Tüm sayfalar</em> seçeneğinde çalışır.
          </div>

          <div style="margin-top:16px">
            <button class="btn" type="submit">PDF'yi Döndür</button>
          </div>
        </form>
      </div>
    </div>

    <div style="max-width:880px;margin:14px auto 0" class="muted">
      <small>İpucu: Zamanla <code>uploads/</code>, <code>converted/</code>, <code>jpgs/</code>, <code>zips/</code> klasörlerini temizlemek için mevcut temizleme scriptinizi kullanabilirsiniz.</small>
    </div>
  </div>
</body>
</html>
