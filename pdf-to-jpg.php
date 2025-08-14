<?php
declare(strict_types=1);

ini_set('memory_limit', '512M');
set_time_limit(60); // İşlemi 60 saniyeyle sınırla (loop kilitlenmesin diye) aksi durumda sorun yasadim ben sizi bilmem

// Klasör tanımları
$uploadDir = __DIR__ . '/uploads/';
$outputDir = __DIR__ . '/jpgs/';
$zipDir    = __DIR__ . '/zips/';
$statsFile = __DIR__ . '/stats.json';
$logFile   = __DIR__ . '/logs/pdf2jpg.log';

foreach ([$uploadDir, $outputDir, $zipDir, dirname($statsFile), dirname($logFile)] as $dir) {
    if (!is_dir($dir)) mkdir($dir, 0777, true);
}

// Güvenli klasör temizleme
function cleanDir($dir) {
    foreach (glob($dir . '*') as $file) {
        if (is_file($file)) unlink($file);
    }
}

// Güvenli dosya adı üret
function sanitizeFilename($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $name);
    return substr($safe, 0, 50); // Çok uzun isimleri kısalt
}

// stats.json hazırla istatistik icin
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

function log_err(string $msg, string $logFile): void {
    @file_put_contents($logFile, '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL, FILE_APPEND);
}

// Başla
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf'])) {
    $file = $_FILES['pdf'];

    if ($file['error'] === UPLOAD_ERR_OK && strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'pdf') {
        // Temizle (tek kullanıcı/tek işlem tasarımına uygun)
        cleanDir($uploadDir);
        cleanDir($outputDir);
        cleanDir($zipDir);

        // PDF'i yükle
        $safeName = sanitizeFilename($file['name']);
        $pdfPath  = $uploadDir . $safeName . '.pdf';
        if (!move_uploaded_file($file['tmp_name'], $pdfPath)) {
            echo "<p style='color:red;'>❌ Dosya yüklenirken hata.</p>";
            exit;
        }

        $dpi = 150;                  // kalite ayarı
        $maxReadPages = 100;         // Imagick read range
        $maxWritePages = 50;         // üretilen JPG üst sınırı

        try {
            // PDF'i oku
            $imagick = new Imagick();
            $imagick->setResolution($dpi, $dpi);
            $imagick->readImage($pdfPath . '[0-'.($maxReadPages-1).']');

            $jpgPaths = [];
            $i = 1;
            foreach ($imagick as $page) {
                if ($i > $maxWritePages) break; // Güvenlik üst sınırı
                $page->setImageFormat('jpg');
                $page->setImageBackgroundColor('white');
                $page->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);

                $jpgName = $safeName . '_page_' . $i . '.jpg';
                $jpgPath = $outputDir . $jpgName;
                $page->writeImage($jpgPath);
                $jpgPaths[] = $jpgPath;
                $i++;
            }

            @unlink($pdfPath); // Orijinal dosyayı sil

            // ZIP oluştur
            $zipName = $safeName . '_converted.zip';
            $zipPath = $zipDir . $zipName;
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
                foreach ($jpgPaths as $jpg) {
                    $zip->addFile($jpg, basename($jpg));
                }
                $zip->close();
            } else {
                throw new Exception('ZIP oluşturulamadı.');
            }

            // 📊 İstatistik yaz (opsiyonel)
            $zipSizeMB = file_exists($zipPath) ? round(filesize($zipPath) / 1024 / 1024, 2) : null;
            append_stats([
                'type'            => 'pdf2jpg',
                'date'            => date('Y-m-d H:i:s'),
                'pages'           => count($jpgPaths),
                'dpi'             => $dpi,
                'zip_size_mb'     => $zipSizeMB,
                'file'            => 'zips/' . $zipName,     // bu biraz göreli yol arkadaslarim(index.php notunu aşağıda verdim)
                'first_image'     => isset($jpgPaths[0]) ? 'jpgs/' . basename($jpgPaths[0]) : null
            ], $statsFile);

            // HTML çıktı
            echo '<div style="background:#111;padding:20px;color:white;font-family:sans-serif;">';
            echo '<h2>✅ PDF JPG\'ye dönüştürüldü</h2>';

            foreach ($jpgPaths as $img) {
                $imgUrl = 'jpgs/' . basename($img);
                echo "<div style='margin-bottom:30px;text-align:center;'>";
                echo "<img src='{$imgUrl}' style='max-width:100%;max-height:800px;border:2px solid #333;'><br>";
                echo "<a href='{$imgUrl}' download style='color:#55f;'>📥 " . basename($img) . "</a>";
                echo "</div>";
            }

            echo '<div style="text-align:center;margin-top:40px;">';
            echo "<a href='zips/{$zipName}' download style='background:#ffdd57;color:black;padding:10px 20px;border-radius:8px;text-decoration:none;'>📦 Tümünü ZIP olarak indir</a><br><br>";
            echo '<a href="/pdf/" style="color:#aaa;text-decoration:underline;">⬅ Ana Sayfa</a>';
            echo '</div></div>';

        } catch (Exception $e) {
            log_err('Hata: '.$e->getMessage(), $logFile);
            echo "<p style='color:red;'>❌ Hata: " . htmlspecialchars($e->getMessage()) . "</p>";
        }

    } else {
        echo "<p style='color:red;'>❌ Geçerli bir PDF dosyası yükleyin.</p>";
    }

} else {
    echo "<p style='color:red;'>❌ Lütfen bir PDF dosyası seçin ve yükleyin.</p>";
}
