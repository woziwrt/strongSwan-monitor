<?php
session_start();

// pokud uz je uzivatel prihlaseny, rovnou na status
if (!empty($_SESSION['logged_in'])) {
    header('Location: securelink-status.php');
    exit;
}

// *** UPRAV PODLE SVE DB ***
$dsn_host = 'localhost';
$dsn_user = 'root';          
$dsn_pass = ''; 
$dsn_db   = 'securelink';

try {
    $pdo = new PDO("mysql:host=$dsn_host;dbname=$dsn_db;charset=utf8mb4", $dsn_user, $dsn_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('DB error');
}

$login_error = '';

// zpracovani formulare – REALNY login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $login_error = 'Zadej uživatelské jméno i heslo.';
    } else {
        $stmt = $pdo->prepare('SELECT id, username, password_hash, role FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['logged_in']  = true;
            $_SESSION['user_id']    = (int)$user['id'];
            $_SESSION['login_user'] = $user['username'];
            $_SESSION['role']       = $user['role'];
            header('Location: securelink-status.php');
            exit;
        } else {
            $login_error = 'Neplatné pøihlašovací údaje.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SecureLink Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>
* {
    box-sizing: border-box;
}
body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen,
                 Ubuntu, Cantarell, "Open Sans", "Helvetica Neue", sans-serif;
    background: #050f1b;
    color: #e5e5e5;
}

/* Center wrapper */
.login-wrapper {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Login box */
.login-box {
    background: #111827;
    border-radius: 12px;
    padding: 32px 32px 24px;
    width: 100%;
    max-width: 420px;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
}

/* Header with logo and title */
.login-header {
    display: flex;
    align-items: center;
    margin-bottom: 24px;
}

.logo-placeholder {
    margin-right: 10px;
}
.logo-placeholder img {
    display: block;
    height: 48px;
}

.app-title {
    font-size: 1.6rem;
    font-weight: 600;
}

/* Form */
.login-form {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-top: 8px;
}

.login-form label {
    font-size: 0.9rem;
    margin-bottom: 4px;
    display: block;
}

.login-form input[type="text"],
.login-form input[type="password"] {
    width: 100%;
    padding: 10px 12px;
    border-radius: 6px;
    border: 1px solid #1f2937;
    background: #020617;
    color: #e5e5e5;
    font-size: 0.95rem;
    outline: none;
}

.login-form input[type="text"]:focus,
.login-form input[type="password"]:focus {
    border-color: #0ea5e9;
    box-shadow: 0 0 0 1px rgba(14, 165, 233, 0.5);
}

/* Button */
.login-form button[type="submit"] {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 6px;
    border: none;
    background: #0ea5e9;
    color: #0b1120;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.05s ease;
}

.login-form button[type="submit"]:hover {
    background: #22c1f1;
}

.login-form button[type="submit"]:active {
    transform: translateY(1px);
}

/* Footer text */
.login-footer {
    margin-top: 18px;
    font-size: 0.8rem;
    text-align: center;
    color: #9ca3af;
}
</style>
</head>
<body>
<div class="login-wrapper">
    <div class="login-box">
        <div class="login-header">
            <div class="logo-placeholder">
                <img src="img/securelink_logo.svg" alt="SecureLink">
            </div>
            <div class="app-title">SecureLink</div>
        </div>

        <?php if ($login_error !== ''): ?>
            <p style="color:#ff6666; text-align:center; margin:0 0 10px;">
                <?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?>
            </p>
        <?php endif; ?>

        <form class="login-form" method="post" action="">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" autocomplete="username" required>

            <label for="password">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" required>

            <button type="submit">Login</button>
        </form>

        <div class="login-footer">
            SecureLink VPN Monitoring
        </div>
    </div>
</div>
</body>
</html>
