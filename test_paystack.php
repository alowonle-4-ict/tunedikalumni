<?php
/**
 * TUNEDIK — Paystack connectivity test.
 * DELETE THIS FILE after diagnosing.
 * Access: http://localhost:8080/test_paystack.php
 */

// Very simple — no auth, no DB needed
echo '<pre>';
echo 'PHP version: ' . PHP_VERSION . "\n";
echo 'cURL version: ' . curl_version()['version'] . "\n";
echo 'SSL version:  ' . curl_version()['ssl_version'] . "\n\n";

$cainfo = ini_get('curl.cainfo');
echo 'curl.cainfo = ' . ($cainfo ?: '(not set)') . "\n";
echo 'File exists: ' . (file_exists($cainfo) ? 'YES' : 'NO') . "\n\n";

// Test basic TCP connectivity
echo "--- TCP test to api.paystack.co:443 ---\n";
$fp = @fsockopen('ssl://api.paystack.co', 443, $errno, $errstr, 10);
if ($fp) {
    echo "TCP connection: OK\n";
    fclose($fp);
} else {
    echo "TCP connection FAILED: [$errno] $errstr\n";
}
echo "\n";

// Test cURL request
echo "--- cURL test ---\n";
$ch = curl_init('https://api.paystack.co/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_VERBOSE        => true,
    CURLOPT_STDERR         => fopen('php://output', 'w'),
]);
$response = curl_exec($ch);
$errno    = curl_errno($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "\nHTTP Code: $httpCode\n";
echo "cURL errno: $errno\n";
echo "cURL error: " . ($error ?: 'none') . "\n";
echo "Response (first 200 chars): " . substr($response, 0, 200) . "\n";

echo '</pre>';
