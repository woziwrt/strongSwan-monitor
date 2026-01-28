<?php
$dsn = 'mysql:host=localhost;dbname=securelink;charset=utf8mb4';
$user = 'user';
$pass = 'password';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$sql = "
  DELETE FROM tunnel_stats
  WHERE FROM_UNIXTIME(ts) < DATE_SUB(NOW(), INTERVAL 90 DAY)
  LIMIT 10000
";
$pdo->exec($sql);
