<?php
// stats_logger.php
function stats_db() {
  $dbFile = __DIR__ . '/stats.sqlite';
  $init = !file_exists($dbFile);

  // DB'yi aç
  $db = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);

  // Eşzamanlı yazımlar için WAL + timeout
  $db->exec("PRAGMA journal_mode=WAL;");
  $db->exec("PRAGMA synchronous=NORMAL;");
  $db->exec("PRAGMA busy_timeout=3000;"); // 3 sn bekle

  if ($init) {
    $db->exec("
      CREATE TABLE logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ts TEXT,
        tool TEXT,
        filename TEXT,
        pages INTEGER,
        bytes_in INTEGER,
        bytes_out INTEGER,
        duration_ms INTEGER,
        ip TEXT,
        extra TEXT
      );
      CREATE INDEX idx_logs_ts ON logs(ts);
      CREATE INDEX idx_logs_tool ON logs(tool);
    ");
    // İzinleri kısıtla (web kullanıcısı + aynı grup)
    @chmod($dbFile, 0660);
  }

  return $db;
}

function client_ip(): string {
  // Reverse proxy arkasındaysanız en soldaki gerçek IP'yi alın
  if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
    return trim($parts[0]);
  }
  return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function stats_log($tool, $filename, $pages, $bytes_in, $bytes_out, $duration_ms, $extraArr = []) {
  try {
    $db = stats_db();
    $stmt = $db->prepare("
      INSERT INTO logs (ts, tool, filename, pages, bytes_in, bytes_out, duration_ms, ip, extra)
      VALUES (:ts, :tool, :filename, :pages, :bytes_in, :bytes_out, :duration_ms, :ip, :extra)
    ");
    $stmt->execute([
      ':ts' => date('c'),
      ':tool' => (string)$tool,
      ':filename' => (string)$filename,
      ':pages' => $pages !== null ? (int)$pages : null,
      ':bytes_in' => $bytes_in !== null ? (int)$bytes_in : null,
      ':bytes_out' => $bytes_out !== null ? (int)$bytes_out : null,
      ':duration_ms' => $duration_ms !== null ? (int)$duration_ms : null,
      ':ip' => client_ip(),
      ':extra' => json_encode($extraArr, JSON_UNESCAPED_UNICODE),
    ]);
  } catch (Throwable $e) {
    // sessizce geç
  }
}

// (opsiyonel) sayfa sayısı—poppler-utils (pdfinfo) varsa kullanılır
function pdf_pages_or_null($path) {
  $cmd = 'pdfinfo ' . escapeshellarg($path) . ' 2>/dev/null';
  @exec($cmd, $out, $st);
  if ($st === 0) {
    foreach ($out as $line) {
      if (stripos($line, 'Pages:') === 0) {
        return (int)trim(substr($line, 6));
      }
    }
  }
  return null;
}
