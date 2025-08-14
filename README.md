PDF İşleme Araçları (Sunucu tarafı)

Ghostscript (gs) → PDF sıkıştırma (compress.php)

pdftk → PDF sayfa kesme/birleştirme (cutter.php, merge.php)

Imagick (ImageMagick + PHP Imagick extension) → PDF’ten JPG dönüştürme (pdf-to-jpg.php)

ZipArchive (PHP built-in) → Çoklu dosyaları ZIP olarak indirme

pdfinfo (poppler-utils paketi) → PDF sayfa sayısını öğrenme (istatistiklerde kullanılıyor)

💾 Veri ve İstatistik Yönetimi

SQLite (PHP PDO SQLite) → Kullanım istatistiklerini saklama (stats_logger.php)

stats_logger.php → Ortak loglama fonksiyonu

stats_summary.php → Ana sayfaya JSON formatında özet istatistik sağlama

stats.php → Ayrıntılı istatistik paneli (isteğe bağlı)

🌐 Web Arayüz ve Stil

HTML + CSS (modern responsive tasarım) → index.html, araç formları

JavaScript (fetch API) → Ana sayfada istatistikleri dinamik olarak getirme

Chart.js (opsiyonel, istenirse eklenebilir) → Grafikler için

🛠 Sistem / Destekleyici

Cron job → Gece temizlik işlemleri (uploads/converted/jpgs/zips klasörleri)

Linux PARDUS
Sunucu özelliği Apache+phpMySQL+PHP
