<?php
declare(strict_types=1);

$uploadDir  = __DIR__ . '/uploads/';
$statsFile  = __DIR__ . '/stats.json';
$logFile    = __DIR__ . '/logs/merge.log';

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_files'])) {
    $files = $_FILES['pdf_files'];
    $uploadedFiles = [];

    foreach ($files['tmp_name'] as $i => $tmpName) {
        if ($files['error'][$i] === UPLOAD_ERR_OK && is_uploaded_file($tmpName)) {
            $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') continue;

            $originalName = pathinfo($files['name'][$i], PATHINFO_FILENAME);
            $safeName = sanitize_basename($originalName);
            $destination = $uploadDir . $safeName . '_' . time() . "_$i.pdf";

            if (move_uploaded_file($tmpName, $destination)) {
                $uploadedFiles[] = $destination;
            }
        }
    }

    if (count($uploadedFiles) < 2) {
        echo "<p style='color: red; text-align:center;'>❌ En az iki PDF dosyası seçmelisiniz.</p>";
        exit;
    }

    $mergedName = 'merged_' . time() . '.pdf';
    $mergedPath = $uploadDir . $mergedName;

    $fileList = implode(' ', array_map('escapeshellarg', $uploadedFiles));
    $cmd = "pdftk $fileList cat output " . escapeshellarg($mergedPath) . " 2>&1";
    exec($cmd, $output, $status);

    // Geçicileri temizle bunu atlama yoksa linux siser
    foreach ($uploadedFiles as $f) { @unlink($f); }

    if ($status === 0 && file_exists($mergedPath)) {
        // 📊 İstatistik yaz opsiyonel
        append_stats([
            'type'  => 'merge',
            'date'  => date('Y-m-d H:i:s'),
            'count' => count($files['name']),
            'file'  => $mergedName
        ], $statsFile);

        echo <<<HTML
        <div style="margin-top: 40px; padding: 20px; background-color: #1b3c4a; border-radius: 10px; max-width: 500px; margin-left: auto; margin-right: auto; box-shadow: 0 0 15px rgba(0,0,0,0.5); text-align: center; color: #fff;">
          <h2 style="color: #ffdd57;">✅ PDF'ler Başarıyla Birleştirildi!</h2>
          <a href="uploads/{$mergedName}" download style="display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #ffdd57; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; transition: background-color 0.3s;">
            📥 Birleştirilmiş PDF'yi İndir
          </a>
          <br>
          <a href="/pdf/" style="display: inline-block; margin-top: 20px; padding: 8px 18px; background-color: #444; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px;">
            ⬅ Ana Sayfaya Dön
          </a>
        </div>
HTML;
    } else {
        @file_put_contents($logFile, '['.date('Y-m-d H:i:s')."] pdftk hata: ".implode(' ', $output).PHP_EOL, FILE_APPEND);
        echo "<p style='color: red; text-align:center;'>❌ PDF birleştirme işlemi başarısız oldu.</p>";
    }

} else {
    echo "<p style='color: red; text-align:center;'>❌ PDF dosyaları eksik gönderildi.</p>";
}
