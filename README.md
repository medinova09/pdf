# Medinova PDF Araçları

Tek sunucuda çalışan, **istemci tarafı gerektirmeyen** (PHP + CLI) PDF yardımcı araçları seti.  
Tüm araçlar **`uploads/`** klasörüne alınan dosyaları işler ve çıktıları **`converted/`**, **`jpgs/`**, **`zips/`** altına kaydeder. İşlem telemetrisi **`stats.json`** dosyasına yazılır ve **`index.php`** üzerinden özetlenir.

---

## Dizin Yapısı
/var/www/html/pdf
├─ index.php # Ana sayfa: formlar + günlük özet + grafikler
├─ compress.php # PDF sıkıştırma
├─ cutter.php # PDF sayfa kesme (aralığa göre ayıklama)
├─ merge.php # Çoklu PDF birleştirme
├─ pdf-to-jpg.php # PDF → JPG(ler) + ZIP
├─ pdf-rotate.php # PDF sayfa döndürme (+90/-90/180)
├─ pdf-to-word.php # PDF → Word/ODT (.docx/.doc/.odt)
├─ stats.php # (Opsiyonel/legacy) İstatistik görüntüleme sayfası
├─ stats_logger.php # (Opsiyonel/legacy) stats.json’a yazan yardımcı
├─ stats.json # İşlem günlükleri (JSON dizi)
├─ cleanup-pdf-tool.sh # Geçici/çıktı dosyalarını temizleme scripti
├─ uploads/ # Gelen dosyalar (geçici)
├─ converted/ # Üretilen PDF/Word çıktı dosyaları
├─ jpgs/ # PDF’ten üretilen JPG sayfaları (klasör)
└─ zips/ # JPG çıktılarının ZIP arşivleri

> **Not:** `stats.php` ve `stats_logger.php` bazı kurulumlarda **kullanılmıyor** olabilir. Yeni araçların tamamı `stats.json`’a **kendi içinde** yazar.

---

## Araç → Dosya Eşlemesi (Ne kullanır? Ne üretir?)

| Araç                                    | Dosya            | Kullandığı CLI/Lib (önerilen)                                                           | Girdi            | Çıktı (yol/isim)                          | `stats.json` alanları (ör.)                                                                 |
|-----------------------------------------|------------------|------------------------------------------------------------------------------------------|------------------|-------------------------------------------|------------------------------------------------------------------------------------------------|
| **PDF Sıkıştırma**                      | `compress.php`   | **Ghostscript** (`gs`) *(mevcut kurulumunda tipik)*                                     | 1× PDF           | `converted/compressed-<id>.pdf`          | `type:"compress"`, `original_size_mb`, `compressed_size_mb`, `saving_percent`, `file`        |
| **PDF Sayfa Kesme (Aralık)**            | `cutter.php`     | **qpdf** (sayfa aralığı seçimi)                                                         | 1× PDF + range   | `converted/cut-<id>.pdf`                 | `type:"cutter"`, `range`, `pages`, `file`                                                    |
| **PDF Birleştirme**                      | `merge.php`      | **qpdf** veya **pdfunite** *(mevcut kurulumuna göre)*                                   | N× PDF           | `converted/merged-<id>.pdf`              | `type:"merge"`, `count` (dosya adedi), `file`                                                |
| **PDF → JPG (+ ZIP)**                   | `pdf-to-jpg.php` | **poppler-utils** (`pdftoppm`, `pdfinfo`) *(yaygın yöntem)*                             | 1× PDF           | `jpgs/<id>/page-*.jpg`, `zips/<id>.zip`  | `type:"pdf2jpg"`, `pages`, `dpi`, `zip_size_mb`, `file` (ZIP yolu)                           |
| **PDF Döndürme (+90/-90/180)**          | `pdf-rotate.php` | **qpdf** (`--rotate`), *yedek:* **pdftk** (yalnız tüm sayfalar)                         | 1× PDF (+ range) | `converted/rotated-<id>.pdf`             | `type:"rotate"`, `angle` (`+90/-90/180`), `pages` (`1-z` vb.), `file`                         |
| **PDF → Word/ODT (.docx/.doc/.odt)**    | `pdf-to-word.php`| **LibreOffice** (`soffice/libreoffice`) *veya* **Pandoc** + `pdftohtml`; **OCR:** `ocrmypdf` | 1× PDF           | `converted/pdf2word-<id>.<ext>`          | `type:"pdf2word"`, `format` (`docx/doc/odt`), `method` (`libreoffice/pandoc`), `ocr` (bool), `pages`, `file` |

> **Rotate** ve **PDF→Word** araçlarında, indirmeyi kesin PDF/Word başlıklarıyla vermek için `?download=` rotası uygulanır:  
> `pdf-rotate.php?download=rotated-*.pdf` • `pdf-to-word.php?download=pdf2word-*.docx`

---

## Ana Sayfa (index.php)

- **Formlar:** Tüm araçlara tek sayfadan erişim.
- **CSRF koruması:** `session_start()` + her forma `<input type="hidden" name="csrf" ...>`.
- **Günlük/İstatistik:** `stats.json` okunur, **bugünün işlemleri** tabloya dökülür.
- **Grafikler:** Chart.js ile **Bu ay** için:
  - **Pasta (pie):** Tür dağılımı → `compress / merge / cutter / pdf2jpg / rotate / pdf2word`
  - **Çizgi (line):** Günlük toplam işlem & **compress** MB tasarrufu
- **Bağlantılar:** Çıktı dosyaları için **indir** linkleri.

---

## `stats.json` Şeması (örnekler)

```json
[
  {
    "type": "compress",
    "date": "2025-09-02 12:34:56",
    "original_size_mb": 12.45,
    "compressed_size_mb": 4.92,
    "saving_percent": 60.5,
    "file": "converted/compressed-ab12cd.pdf"
  },
  {
    "type": "pdf2jpg",
    "date": "2025-09-02 13:02:11",
    "pages": 8,
    "dpi": 200,
    "zip_size_mb": 3.41,
    "file": "zips/ab12cd.zip"
  },
  {
    "type": "rotate",
    "date": "2025-09-02 14:00:01",
    "angle": "+90",
    "pages": "1-z",
    "file": "converted/rotated-ab12cd.pdf"
  },
  {
    "type": "pdf2word",
    "date": "2025-09-02 15:20:05",
    "format": "docx",
    "method": "libreoffice",
    "ocr": false,
    "pages": 12,
    "file": "converted/pdf2word-ab12cd.docx"
  }
]



Bağımlılıklar

PHP 7 uyumlu (sunucunda PHP 7 çalışıyor).
CLI paketleri:

Genel: php-xml php-mbstring

qpdf (rotate/cutter/merge için önerilir)
sudo apt-get install -y qpdf

pdftk-java (rotate için yedek, tüm sayfalar modunda)
sudo apt-get install -y pdftk-java

poppler-utils (pdfinfo, pdftoppm) – pdf-to-jpg ve sayfa sayımı
sudo apt-get install -y poppler-utils

LibreOffice (PDF→Word görünüm odaklı)
sudo apt-get install -y libreoffice-writer

Pandoc + pdftohtml (PDF→Word metin odaklı boru hattı)
sudo apt-get install -y pandoc

OCR (isteğe bağlı): ocrmypdf + tesseract-ocr (+ tesseract-ocr-tur)
sudo apt-get install -y ocrmypdf tesseract-ocr tesseract-ocr-tur

Ghostscript (compress.php kullanan tipik motor)
sudo apt-get install -y ghostscript

Not: Bazı scriptler alternatif araçlarla da çalışacak şekilde yazılmıştır (örn. merge).
Kurulum / İzinler
sudo mkdir -p /var/www/html/pdf/{uploads,converted,jpgs,zips}
sudo chown -R www-data:www-data /var/www/html/pdf
sudo chmod -R 775 /var/www/html/pdf

Apache/PHP oturumları için (Debian/Pardus):
php -i | grep session.save_path
sudo ls -ld /var/lib/php/sessions  # www-data yazabiliyor olmalı

Güvenlik

Tüm POST formlarında CSRF token (session tabanlı).

Yükleme sırasında MIME doğrulama (application/pdf).

İndirme rotalarında sadece basename() kullanımı ile path traversal engeli.

Geçici dosyalar işlem sonrasında silinir.

Bakım & Temizlik

Temizlik scripti: cleanup-pdf-tool.sh
(örnek) 3 günden eski dosyaları silmek için cron:

# Her gece 02:30'da çıktı/ara klasörlerini temizle
30 2 * * * /var/www/html/pdf/cleanup-pdf-tool.sh >> /var/log/pdf-tools-cleanup.log 2>&1

Sorun Giderme

İndirme 404 / boş yanıt: İzinler ve dosya yolunu kontrol edin (converted/ altında var mı?).

“Call to undefined function …”: PHP 7 ortamında PHP 8 fonksiyonları yoktur. Kodlar PHP 7’ye uyarlanmıştır (örn. pathinfo() ile uzantı tespiti).

Dönüşüm başarısız: İlgili CLI’nin kurulu olup olmadığını kontrol edin (which qpdf, which soffice, which pandoc, which pdftoppm, which ocrmypdf).

Hızlı Bağlantılar

Ana sayfa: index.php

Döndürme: pdf-rotate.php (indirme: ?download=rotated-<id>.pdf)

PDF→Word: pdf-to-word.php (indirme: ?download=pdf2word-<id>.<ext>)

Diğer araçlar: compress.php, cutter.php, merge.php, pdf-to-jpg.php

Lisans / Notlar

Bu depo, kurum içi kullanım amaçlı Medinova Bilgi Sistemleri için hazırlanmıştır.
Üçüncü taraf CLI araçların lisansları kendi projelerine aittir.

