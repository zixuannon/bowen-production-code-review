<?php

// Nonpublic, read-only FastCGI probe. Never boot Laravel or disclose configuration.
if (PHP_SAPI !== 'fpm-fcgi' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
    || !preg_match('/^[a-f0-9]{64}$/D', $_SERVER['P0_PROBE_NONCE'] ?? '')) {
    http_response_code(404);
    exit;
}
$release = dirname(__DIR__, 2);
header('Content-Type: application/json');
echo json_encode([
    'pid' => getmypid(),
    'php_version' => PHP_VERSION,
    'release_path' => $release,
    'release_sha' => trim(file_get_contents($release.'/.release-commit')),
    'release' => $release,
    'sha' => trim(file_get_contents($release.'/.release-commit')),
    'sapi' => PHP_SAPI,
    'nonce' => $_SERVER['P0_PROBE_NONCE'],
], JSON_THROW_ON_ERROR);
