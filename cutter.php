<?php
declare(strict_types=1);

$uploadDir  = __DIR__ . '/uploads/';
$statsFile  = __DIR__ . '/stats.json';
$logFile    = __DIR__ . '/logs/cutter.log';

foreach ([$uploadDir, dirname($statsFile), dirname($logFile)] as $d) {
    if (!is_dir($d)) @mkdir($d, 0775, true);
}
if (!file_exists($statsFile)) {
    @file_put_contents($statsFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function append_stats(array $row, string $statsFile): void {
    $fp = fopen($statsFile, 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $content = stream_get_contents($fp);
    $arr = $content ? json_decode($content, true) : [];
    if (!is_array($arr)) $arr = [];
    $arr[] = $row;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($arr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function sanitize_basename(string $name): string {
    $name = preg_replace('/[^A-Za-z0-9_\-\.]/u', '_', $name);
    return preg_replace('/_+/', '_', $name);
}

// --- POST kontrolü ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'], $_POST['range'])) {
    $tmp  = $_FILES['pdf_file']['tmp_name'];
    $err  = $_FILES['pdf_file']['error'];
    $name = $_FILES['pdf_file']['name'];
    $rangeInput = trim((string)$_POST['range']);

    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
        echo "<p style='color: red; text-align:center;'>❌ Dosya yüklenemedi.</p>";
        exit;
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        echo "<p style='color: red; text-align:center;'>❌ Sadece PDF dosyaları kabul edilir.</p>";
        exit;
    }

    // Sayfa aralığı güvenliği: sadece rakam, virgül, tire ve boşluk gibi seylere takilmasin diye yaptim
    if (!preg_match('/^[0-9,\-\s]+$/', $rangeInput)) {
        echo "<p style='color: red; text-align:center;'>❌ Geçersiz sayfa aralığı biçimi.</p>";
        exit;
    }

    $base = sanitize_basename(pathinfo($name, PATHINFO_FILENAME));
    $srcPath = $uploadDir . $base . '_' . time() . '.pdf';

    if (!move_uploaded_file($tmp, $srcPath)) {
        echo "<p style='color: red; text-align:center;'>❌ Dosya taşınırken hata.</p>";
        exit;
    }

    $cutName = $base . '_cut_' . time() . '.pdf';
    $cutPath = $uploadDir . $cutName;

    // pdftk ile sayfa kesme: "A=src.pdf cat <range> output cut.pdf"
    $range = preg_replace('/\s+/', '', $rangeInput); // boşlukları temizle yine
    $cmd = "pdftk A=" . escapeshellarg($srcPath) . " cat A{$range} output " . escapeshellarg($cutPath) . " 2>&1";
    exec($cmd, $output, $status);

    // Kaynağı silebilirsin, isterseniz saklayin
    @unlink($srcPath);

    if ($status === 0 && file_exists($cutPath)) {
        // 📊 İstatistik yaz bu opsiyonel
        append_stats([
            'type'  => 'cutter',
            'date'  => date('Y-m-d H:i:s'),
            'range' => $rangeInput,
            'file'  => $cutName
        ], $statsFile);

        echo <<<HTML
        <div style="margin-top: 40px; padding: 20px; background-color: #1b3c4a; border-radius: 10px; max-width: 500px; margin-left: auto; margin-right: auto; box-shadow: 0 0 15px rgba(0,0,0,0.5); text-align: center; color: #fff;">
          <h2 style="color: #ffdd57;">✅ PDF Sayfaları Başarıyla Kesildi!</h2>
          <p style="margin: 10px 0;">📄 <strong>Aralık:</strong> {$rangeInput}</p>
          <a href="uploads/{$cutName}" download style="display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #ffdd57; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; transition: background-color 0.3s;">
            📥 Kesilmiş PDF'yi İndir
          </a>
          <br>
          <a href="/pdf/" style="display: inline-block; margin-top: 20px; padding: 8px 18px; background-color: #444; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px;">
            ⬅ Ana Sayfaya Dön
          </a>
        </div>
HTML;
    } else {
        @file_put_contents($logFile, '['.date('Y-m-d H:i:s')."] pdftk hata: ".implode(' ', $output).PHP_EOL, FILE_APPEND);
        echo "<p style='color: red; text-align:center;'>❌ PDF kesme işlemi başarısız oldu.</p>";
    }

} else {
    echo "<p style='color: red; text-align:center;'>❌ PDF dosyası ve sayfa aralığı gönderilmelidir.</p>";
}
