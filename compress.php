<?php
// --- Basit, stabil PDF sıkıştırma + stats.json (v1.0)

// Ghostscript yolu
$gs = '/usr/bin/gs';

// Klasör
$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// stats.json bunu istatistik icin yaptım
$statsFile = __DIR__ . '/stats.json';
if (!file_exists($statsFile)) {
    @file_put_contents($statsFile, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function append_stats_row(array $row, string $statsFile): void {
    $fp = @fopen($statsFile, 'c+');
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

function compressPDF($source, $destination, $quality = 'ebook', $gs = '/usr/bin/gs'): bool {
    // gs var mı?
    if (!is_executable($gs)) {
        return false;
    }
    $command = "$gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/$quality " .
               "-dNOPAUSE -dQUIET -dBATCH -sOutputFile=" . escapeshellarg($destination) . " " .
               escapeshellarg($source);
    exec($command . " 2>&1", $output, $status);
    return $status === 0;
}

// İstek kontrolü
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pdf_file'])) {
    $file = $_FILES['pdf_file'];
    $quality = $_POST['quality'] ?? 'ebook'; // varsayılan kalite

    // Basit doğrulama
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file['error'] !== UPLOAD_ERR_OK || $ext !== 'pdf') {
        echo "<p style='color: red; text-align:center;'>❌ Geçerli bir PDF dosyası yükleyiniz.</p>";
        exit;
    }

    // Güvenli ad + tekrar sıkıştırma engeli
    $originalName = pathinfo($file['name'], PATHINFO_FILENAME);
    $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', $originalName);
    $safeBase = preg_replace('/_+$/', '_', $safeBase); // gorev sonunda temizletiyorum
    // Eğer zaten _compressed ile bitiyorsa aynı adıyla tekrar sıkıştırmayı engelle
    if (preg_match('/_compressed$/i', $safeBase)) {
        echo "<p style='color:#ffdd57; text-align:center;'>ℹ️ Bu dosya zaten sıkıştırılmış görünüyor (<code>_compressed</code>). Tekrar sıkıştırmaya gerek yok.</p>";
        exit;
    }

    $uploadedPath    = $uploadDir . $safeBase . '.pdf';
    $compressedName  = $safeBase . '_compressed.pdf';
    $compressedPath  = $uploadDir . $compressedName;

    // Yükle
    if (!move_uploaded_file($file['tmp_name'], $uploadedPath)) {
        echo "<p style='color: red; text-align:center;'>❌ Dosya yüklenirken hata oluştu.</p>";
        exit;
    }

    // 0 bayt/eksik dosya kontrolü
    $originalSizeBytes = @filesize($uploadedPath);
    if (!$originalSizeBytes || $originalSizeBytes === 0) {
        @unlink($uploadedPath);
        echo "<p style='color: red; text-align:center;'>❌ Yüklenen dosya boş görünüyor. PHP limitlerini (upload_max_filesize, post_max_size) büyütün.</p>";
        exit;
    }

    // Sıkıştır
    if (compressPDF($uploadedPath, $compressedPath, $quality, $gs)) {
        
        if (file_exists($uploadedPath)) {
            @unlink($uploadedPath);
        }

        $compressedSizeBytes = @filesize($compressedPath) ?: 0;
        $originalSize  = round($originalSizeBytes / 1024 / 1024, 2);
        $compressedSize = round($compressedSizeBytes / 1024 / 1024, 2);
        $savingPercent = $originalSize > 0 ? round((1 - ($compressedSize / $originalSize)) * 100, 1) : 0;

        // stats.json'a yaz
        append_stats_row([
            'type' => 'compress',
            'date' => date('Y-m-d H:i:s'),
            'original_size_mb' => $originalSize,
            'compressed_size_mb' => $compressedSize,
            'saving_percent' => $savingPercent,
            'file' => $compressedName
        ], $statsFile);

        // Çıktı (seninkiyle aynı stil)
        echo <<<HTML
        <div style="margin-top: 40px; padding: 20px; background-color: #1b3c4a; border-radius: 10px; max-width: 500px; margin-left: auto; margin-right: auto; box-shadow: 0 0 15px rgba(0,0,0,0.5); text-align: center; color: #fff;">
          <h2 style="color: #ffdd57;">✅ PDF Başarıyla Küçültüldü!</h2>
          <p style="margin: 10px 0;">📄 <strong>Orijinal boyut:</strong> {$originalSize} MB</p>
          <p style="margin: 10px 0;">📉 <strong>Küçültülmüş boyut:</strong> {$compressedSize} MB</p>
          <p style="margin: 10px 0;">💡 <strong>Kazanç:</strong> %{$savingPercent} oranında azalma</p>
          <a href="uploads/{$compressedName}" download style="display: inline-block; margin-top: 20px; padding: 10px 20px; background-color: #ffdd57; color: #000; text-decoration: none; border-radius: 8px; font-weight: bold; transition: background-color 0.3s;">
            📥 Sıkıştırılmış PDF'yi İndir
          </a>
          <br>
          <a href="/pdf/" style="display: inline-block; margin-top: 20px; padding: 8px 18px; background-color: #444; color: #fff; text-decoration: none; border-radius: 6px; font-size: 14px;">
            ⬅ Ana Sayfaya Dön
          </a>
        </div>
HTML;

        // Bilgi: Tasarruf yoksa küçük uyarı (opsiyonel)
        if ($compressedSizeBytes >= $originalSizeBytes) {
            echo "<p style='text-align:center;color:#ffdd57;margin-top:10px;'>ℹ️ Bu dosyada sıkıştırma tasarruf sağlamadı (çıktı ≥ girdi). Yine de indirebilirsiniz.</p>";
        }

    } else {
        // gs yoksa veya komut başarısızsa
        if (!is_executable($gs)) {
            echo "<p style='color: red; text-align:center;'>❌ Ghostscript bulunamadı: {$gs}. Lütfen sunucuda 'apt install ghostscript' yapın.</p>";
        } else {
            echo "<p style='color: red; text-align:center;'>❌ PDF sıkıştırma işlemi başarısız oldu.</p>";
        }
        // orijinali silmeden bırakmak istersen: yorum satırı yap
        @unlink($uploadedPath);
    }

} else {
    echo "<p style='color: red; text-align:center;'>❌ Lütfen bir PDF dosyası seçin ve yükleyin.</p>";
}
