<?php
session_start();

// Access control
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// DB connection
$dsn_host = 'localhost';
$dsn_user = 'root';
$dsn_pass = '';
$dsn_db   = 'securelink';

try {
    $pdo = new PDO("mysql:host=$dsn_host;dbname=$dsn_db;charset=utf8mb4", $dsn_user, $dsn_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Get parameters
$tunnelName = $_POST['tunnel'] ?? '';
$note = $_POST['note'] ?? '';

if ($tunnelName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing tunnel name']);
    exit;
}

// Update note in profiles table
try {
    $stmt = $pdo->prepare('UPDATE profiles SET note = ? WHERE name = ?');
    $stmt->execute([$note, $tunnelName]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database update failed: ' . $e->getMessage()]);
}