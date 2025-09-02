<?php
// pdf-to-word.php — Medinova PDF Araçları: PDF → Word/ODT Dönüştürme
// Dizin: /var/www/html/pdf/{uploads,converted,stats.json,...}
// Çıktı: converted/pdf2word-<id>.(docx|doc|odt)

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
// Örn: pdf-to-word.php?download=pdf2word-abc123.docx
if (isset($_GET['download'])) {
  $fname = basename((string)$_GET['download']);
  $full  = $OUTPUT_DIR . '/' . $fname;
  if (is_file($full)) {
    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION)); // PHP7 uyumlu
    if ($ext === 'docx') {
      header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    } elseif ($ext === 'doc') {
      header('Content-Type: application/msword');
    } elseif ($ext === 'odt') {
      header('Content-Type: application/vnd.oasis.opendocument.text');
    } else {
      header('Content-Type: application/octet-stream');
    }
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
function pdf_pages(string $file): ?int {
  $out = [];
  @exec('pdfinfo ' . escapeshellarg($file) . ' 2>/dev/null', $out);
  foreach ($out as $line) {
    if (stripos($line, 'Pages:') === 0) {
      $n = (int)trim(substr($line, 6));
      return $n > 0 ? $n : null;
    }
  }
  return null;
}
function append_stats(string $statsFile, array $entry): void {
  $list = [];
  if (is_file($statsFile)) {
    $json = @file_get_contents($statsFile);
    $tmp  = json_decode($json ?: '[]', true);
    if (is_array($tmp)) $list = $tmp;
  }
  $list[] = $entry;
  @file_put_contents($statsFile, json_encode($list, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
}

// ---- Dönüştürücüler ----
function convert_with_libreoffice(string $inPdf, string $outFormat, string $outFinalPath): array {
  // outFormat: docx|doc|odt
  $bin = has_cmd('soffice') ? 'soffice' : (has_cmd('libreoffice') ? 'libreoffice' : null);
  if (!$bin) return [1, 'LibreOffice (soffice/libreoffice) bulunamadı.'];

  $tmpOutDir = dirname($outFinalPath);
  $cmd = sprintf('%s --headless --nologo --convert-to %s --outdir %s %s 2>&1',
                  $bin, escapeshellarg($outFormat), escapeshellarg($tmpOutDir), escapeshellarg($inPdf));
  $log = [];
  exec($cmd, $log, $ret);

  // LibreOffice çıktı adı: in-<uid>.<ext>
  $base = pathinfo($inPdf, PATHINFO_FILENAME);
  $produced = $tmpOutDir . '/' . $base . '.' . $outFormat;

  if ($ret === 0 && is_file($produced)) {
    if (!@rename($produced, $outFinalPath)) {
      @copy($produced, $outFinalPath);
      @unlink($produced);
    }
    return [0, implode("\n", $log)];
  }
  return [1, implode("\n", $log)];
}

function convert_with_pandoc_pipeline(string $inPdf, string $outFormat, string $outFinalPath): array {
  // pdftohtml -> pandoc (metin odaklı)
  if (!has_cmd('pdftohtml') || !has_cmd('pandoc')) {
    return [1, 'pdftohtml veya pandoc bulunamadı.'];
  }
  $tmpDir = sys_get_temp_dir() . '/pdf2word_' . bin2hex(random_bytes(4));
  @mkdir($tmpDir, 0700, true);
  $htmlPath = $tmpDir . '/out.html';

  $cmd1 = sprintf('pdftohtml -s -c -noframes -enc UTF-8 %s %s 2>&1',
                  escapeshellarg($inPdf), escapeshellarg($htmlPath));
  $log1 = [];
  exec($cmd1, $log1, $r1);
  if ($r1 !== 0 || !is_file($htmlPath)) {
    return [1, "pdftohtml başarısız:\n" . implode("\n", $log1)];
  }

  $cmd2 = sprintf('pandoc %s -o %s 2>&1', escapeshellarg($htmlPath), escapeshellarg($outFinalPath));
  $log2 = [];
  exec($cmd2, $log2, $r2);

  foreach (glob($tmpDir . '/*') as $f) @unlink($f);
  @rmdir($tmpDir);

  if ($r2 === 0 && is_file($outFinalPath)) {
    return [0, implode("\n", array_merge($log1, $log2))];
  }
  return [1, "pandoc başarısız:\n" . implode("\n", $log2)];
}

function run_ocr_if_requested(string $inPdf, bool $doOcr): array {
  if (!$doOcr) return [0, $inPdf, ''];
  if (!has_cmd('ocrmypdf')) return [1, $inPdf, 'ocrmypdf bulunamadı, OCR atlandı.'];

  $tmp = dirname($inPdf) . '/ocr-' . basename($inPdf);
  $cmd = sprintf('ocrmypdf --force-ocr --skip-text %s %s 2>&1',
                  escapeshellarg($inPdf), escapeshellarg($tmp));
  $log = [];
  exec($cmd, $log, $ret);
  if ($ret === 0 && is_file($tmp)) {
    return [0, $tmp, implode("\n", $log)];
  }
  return [1, $inPdf, "OCR başarısız/atlandı:\n" . implode("\n", $log)];
}

// ---- İşlem durum değişkenleri ----
$errors = [];
$result = null; // ['url'=>'converted/...','dl'=>'?download=...','format'=>'docx','method'=>'libreoffice|pandoc','pages'=>N,'ocr'=>bool]

// ---- POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
    $errors[] = 'Güvenlik doğrulaması (CSRF) başarısız.';
  }

  // Parametreler
  $format = strtolower(trim((string)($_POST['format'] ?? 'docx')));
  if (!in_array($format, ['docx','doc','odt'], true)) $format = 'docx';

  $engine = (string)($_POST['engine'] ?? 'auto'); // auto|libreoffice|pandoc
  $ocr    = !empty($_POST['ocr']);               // true/false

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
    $outfile = $OUTPUT_DIR . "/pdf2word-{$uid}.{$format}";

    if (!move_uploaded_file($file['tmp_name'], $infile)) {
      $errors[] = 'Geçici dosya taşınamadı.';
    } else {
      // OCR (isteğe bağlı)
      list($ocrRet, $pdfForConv, $ocrLog) = run_ocr_if_requested($infile, $ocr);

      // Sayfa sayısı
      $pages = pdf_pages($pdfForConv);

      // Motor seçimi
      $method = null;
      $ret = 1; $log = '';

      if ($engine === 'libreoffice' || ($engine === 'auto' && (has_cmd('soffice') || has_cmd('libreoffice')))) {
        $method = 'libreoffice';
        list($ret, $log) = convert_with_libreoffice($pdfForConv, $format, $outfile);
        if ($ret !== 0 && $engine === 'auto') {
          // Otomatikteysek, pandoc boru hattına düş
          $method = 'pandoc';
          list($ret, $log2) = convert_with_pandoc_pipeline($pdfForConv, $format, $outfile);
          $log .= "\n---- fallback pandoc ----\n" . $log2;
        }
      } else {
        $method = 'pandoc';
        list($ret, $log) = convert_with_pandoc_pipeline($pdfForConv, $format, $outfile);
      }

      if ($ret !== 0 || !is_file($outfile) || filesize($outfile) === 0) {
        $errors[] = 'Dönüştürme başarısız: ' . htmlspecialchars($log);
        @unlink($outfile);
      } else {
        // İstatistik
        $entry = [
          'type'   => 'pdf2word',
          'date'   => date('Y-m-d H:i:s'),
          'format' => $format,
          'method' => $method,
          'ocr'    => $ocr ? true : false,
          'pages'  => $pages,
          'file'   => 'converted/' . basename($outfile),
        ];
        append_stats($STATS_FILE, $entry);

        // Sonuç
        $result = [
          'url'    => 'converted/' . basename($outfile),
          'dl'     => '?download=' . rawurlencode(basename($outfile)),
          'format' => $format,
          'method' => $method,
          'ocr'    => $ocr,
          'pages'  => $pages,
        ];
      }

      if (isset($pdfForConv) && $pdfForConv !== $infile) @unlink($pdfForConv);
      @unlink($infile);
    }
  }
}
?>
<!doctype html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PDF → Word (.docx/.doc/.odt) — Medinova</title>
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
    input[type="file"],select{width:100%;padding:.75rem;border-radius:12px;border:1px solid #334155;background:#0b1220;color:#e2e8f0}
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
        <h1>PDF → Word (.docx / .doc / .odt)</h1>
        <div class="muted">Metin odaklı (pandoc) veya görünüm odaklı (LibreOffice) dönüşüm. Çıktılar <code>converted/</code> klasörüne kaydedilir.</div>
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
            <strong>Başarılı!</strong>
            <?php if (!empty($result['pages'])): ?>
              <span class="muted">(<?php echo (int)$result['pages']; ?> sayfa)</span>
            <?php endif; ?>
            <div class="muted">Yöntem: <?php echo htmlspecialchars($result['method']); ?> • Format: <?php echo htmlspecialchars(strtoupper($result['format'])); ?> <?php echo $result['ocr'] ? '• OCR: açık' : '• OCR: kapalı'; ?></div>
            <a class="dl" href="<?php echo $result['dl']; ?>">⤓ İndir</a>
            <a class="dl" href="<?php echo htmlspecialchars($result['url']); ?>" target="_blank" rel="noopener">Görüntüle</a>
          </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['csrf']); ?>">

          <label>PDF dosyası</label>
          <input type="file" name="pdf" accept="application/pdf" required>

          <div class="row" style="margin-top:12px">
            <div>
              <label>Çıktı formatı</label>
              <select name="format" required>
                <option value="docx" selected>.docx (Önerilen)</option>
                <option value="odt">.odt</option>
                <option value="doc">.doc (eski)</option>
              </select>
            </div>
            <div>
              <label>Dönüşüm motoru</label>
              <select name="engine" required>
                <option value="auto" selected>Otomatik (Önce LibreOffice, olmazsa pandoc)</option>
                <option value="libreoffice">LibreOffice (görünüm odaklı)</option>
                <option value="pandoc">Pandoc (metin odaklı)</option>
              </select>
            </div>
          </div>

          <div class="radio-line" style="margin-top:10px">
            <label><input type="checkbox" name="ocr" value="1"> OCR uygula (taranmış PDF’ler için metin tanıma)</label>
          </div>

          <div class="tip">
            <b>Kurulum ipuçları:</b>
            <div><code>sudo apt-get install libreoffice-writer poppler-utils pandoc</code></div>
            <div>OCR için: <code>sudo apt-get install ocrmypdf tesseract-ocr tesseract-ocr-tur</code></div>
          </div>

          <div style="margin-top:16px">
            <button class="btn" type="submit">Dönüştür</button>
          </div>
        </form>
      </div>
    </div>

    <div style="max-width:880px;margin:14px auto 0" class="muted">
      <small>Not: LibreOffice yöntemi genellikle sayfa görünümünü daha iyi korur; Pandoc ise metin düzenlenebilirliğini artırır. OCR, taranmış (resim) PDF’lerde metin çıkarır.</small>
    </div>
  </div>
</body>
</html>
