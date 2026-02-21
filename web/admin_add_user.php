<?php
session_start();

if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

$dsn_host = 'localhost';
$dsn_user = 'root';
$dsn_pass = '';
$dsn_db   = 'securelink';

$pdo = new PDO("mysql:host=$dsn_host;dbname=$dsn_db;charset=utf8mb4", $dsn_user, $dsn_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$msg = '';

// --- FORM ACTIONS (POST) ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add user
    if (isset($_POST['action']) && $_POST['action'] === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

        if ($username === '' || $password === '') {
            $msg = 'Please enter both username and password.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare('INSERT INTO users (username, password_hash, role, active) VALUES (?, ?, ?, 1)');
            try {
                $stmt->execute([$username, $hash, $role]);
                $msg = 'User has been added.';
            } catch (PDOException $e) {
                $msg = 'Error while inserting user (possibly duplicate username).';
            }
        }
    }

    // Reset password
    if (isset($_POST['action']) && $_POST['action'] === 'reset_password') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $password = $_POST['new_password'] ?? '';

        if ($userId <= 0 || $password === '') {
            $msg = 'You must select a user and enter a new password.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([$hash, $userId]);
            $msg = 'Password has been reset.';
        }
    }

    // Activate / deactivate
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_active') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newVal = (int)($_POST['new_active'] ?? 0); // 0 or 1
        if ($userId > 0) {
            $stmt = $pdo->prepare('UPDATE users SET active = ? WHERE id = ?');
            $stmt->execute([$newVal, $userId]);
            $msg = $newVal ? 'User has been activated.' : 'User has been deactivated.';
        }
    }

    // Delete user + his tunnels
    if (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            // First delete tunnels
            $stmt = $pdo->prepare('DELETE FROM user_tunnels WHERE user_id = ?');
            $stmt->execute([$userId]);

            // Then delete user
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$userId]);

            $msg = 'User and all of his tunnels have been deleted.';
        }
    }
}

// --- LOAD USERS FOR TABLE ---

$stmt = $pdo->query('SELECT id, username, role, active FROM users ORDER BY id ASC');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin - User management</title>
    <style>
        body {
            font-family: sans-serif;
            background: #f5f5f5;
            margin: 0;
            padding: 20px;
        }
        h1 {
            margin-top: 0;
        }
        .msg {
            padding: 8px 12px;
            background: #fff9c4;
            border: 1px solid #fbc02d;
            margin-bottom: 15px;
        }
        .panel {
            background: #ffffff;
            border: 1px solid #ddd;
            padding: 15px;
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 6px;
        }
        input[type="text"],
        input[type="password"],
        select {
            padding: 4px 6px;
            font-size: 13px;
            width: 220px;
            box-sizing: border-box;
        }
        button {
            padding: 4px 10px;
            font-size: 13px;
            margin-top: 6px;
            cursor: pointer;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            font-size: 13px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 6px 8px;
            text-align: left;
        }
        th {
            background: #eceff1;
        }
        .inactive {
            color: #999;
        }
        .btn-small {
            padding: 2px 6px;
            font-size: 12px;
            margin-right: 4px;
        }
        .back-link {
            margin-bottom: 15px;
            display: inline-block;
        }
    </style>
</head>
<body>

<a href="securelink-status.php" class="back-link">&laquo; Back to monitoring</a>
&nbsp; | &nbsp;
<a href="admin_add_user.php" class="back-link">User profile assignments</a>
&nbsp; | &nbsp;
<a href="admin_user_tunnels.php" class="back-link">User tunnel management</a>

<h1>Admin - User management</h1>

<?php if ($msg !== ''): ?>
    <div class="msg"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<div class="panel">
    <h2>Add user</h2>
    <form method="post">
        <input type="hidden" name="action" value="add_user">

        <label>
            Username:
            <input type="text" name="username" required>
        </label>

        <label>
            Password:
            <input type="password" id="password" name="password" required>
            <input type="checkbox" id="show_password" onclick="
                var p = document.getElementById('password');
                p.type = (p.type === 'password') ? 'text' : 'password';
            ">
            <label for="show_password">Show</label>
        </label>

        <label>
            Role:
            <select name="role">
                <option value="user">user</option>
                <option value="admin">admin</option>
            </select>
        </label>

        <button type="submit">Add user</button>
    </form>
</div>

<div class="panel">
    <h2>Existing users</h2>
    <?php if (!$users): ?>
        <p>No users in the database.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Role</th>
                <th>Active</th>
                <th>Actions</th>
                <th>Password reset</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr class="<?php echo $u['active'] ? '' : 'inactive'; ?>">
                <td><?php echo (int)$u['id']; ?></td>
                <td><?php echo htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo htmlspecialchars($u['role'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?php echo $u['active'] ? '1' : '0'; ?></td>
                <td>
                    <!-- toggle active -->
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                        <input type="hidden" name="new_active" value="<?php echo $u['active'] ? 0 : 1; ?>">
                        <button type="submit" class="btn-small">
                            <?php echo $u['active'] ? 'Deactivate' : 'Activate'; ?>
                        </button>
                    </form>

                    <!-- delete user + tunnels -->
                    <form method="post" style="display:inline;" onsubmit="return confirm('Delete this user and all of his tunnels?');">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                        <button type="submit" class="btn-small">Delete</button>
                    </form>
                </td>
                <td>
                    <!-- reset password -->
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="reset_password">
                        <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                        <input type="password" name="new_password" placeholder="New password" style="width: 130px;" required>
                        <button type="submit" class="btn-small">Reset</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</body>
</html>
