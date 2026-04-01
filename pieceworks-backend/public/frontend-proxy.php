<?php
$logFile = '/home/hrpsp/pieceworks.myflexihr.com/pieceworks-backend/public/proxy_debug.log';
$nextPort = 3001;
$domain   = 'https://pieceworks.myflexihr.com';
$uri      = $_SERVER['REQUEST_URI'];
$method   = $_SERVER['REQUEST_METHOD'];

// Log every request
$logEntry = date('H:i:s') . " URI=$uri\n";
file_put_contents($logFile, $logEntry, FILE_APPEND);

// Intercept stale cached chunks - serve correct file directly from disk
$chunkIntercepts = [
  '/_next/static/chunks/app/(auth)/login/page-04c6fdfc4aaa3071.js'
    => '/home/hrpsp/pieceworks.myflexihr.com/pieceworks-frontend/.next/standalone/.next/static/chunks/app/(auth)/login/page-688f68d6ea5e0f33.js',
];
$cleanUri = strtok($uri, '?');
if (isset($chunkIntercepts[$cleanUri])) {
    $filepath = $chunkIntercepts[$cleanUri];
    file_put_contents($logFile, "  INTERCEPT HIT: $filepath exists=" . (file_exists($filepath) ? 'yes' : 'no') . "\n", FILE_APPEND);
    if (file_exists($filepath)) {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        readfile($filepath);
        exit;
    }
}

$url = "http://127.0.0.1:{$nextPort}{$uri}";
$headers = [
    'Host: pieceworks.myflexihr.com',
    'X-Forwarded-Host: pieceworks.myflexihr.com',
    'X-Forwarded-Proto: https',
    'X-Forwarded-For: ' . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
    'X-Real-IP: '       . ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_') && !in_array($k, ['HTTP_HOST','HTTP_X_FORWARDED_HOST'])) {
        $headers[] = str_replace('_', '-', substr($k, 5)) . ': ' . $v;
    }
}
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => file_get_contents('php://input'),
    CURLOPT_TIMEOUT => 30,
]);
$resp  = curl_exec($ch);
$hSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($resp === false) { http_response_code(502); echo '<h1>502</h1>'; exit; }
http_response_code($code);
$rawHeaders = substr($resp, 0, $hSize);
foreach (explode("\r\n", $rawHeaders) as $h) {
    if (preg_match('/^Location:\s*(https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?)(.*)/i', $h, $m)) {
        header('Location: ' . $domain . $m[4]);
    } elseif (preg_match('/^(Content-Type|Cache-Control|Set-Cookie|X-Powered-By):/i', $h)) {
        header($h);
    }
}
echo substr($resp, $hSize);
?>