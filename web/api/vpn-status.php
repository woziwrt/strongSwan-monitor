<?php
header('Content-Type: application/json');

$file = '/var/run/ipsec-status.json';

if (!is_readable($file)) {
    echo json_encode([
        'tunnels' => [],
        'error'   => 'status file not available',
    ]);
    exit;
}

if (readfile($file) === false) {
    echo json_encode([
        'tunnels' => [],
        'error'   => 'failed to read status file',
    ]);
}
