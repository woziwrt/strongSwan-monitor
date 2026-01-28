<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
session_start();

// Only logged-in admin can access this page
if (empty($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

// DB connection
$dsn  = 'mysql:host=localhost;dbname=securelink;charset=utf8mb4';
$user = 'user';
$pass = 'password';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

// Flash message support
$message = '';
if (!empty($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ===== NOVÉ: Handle "load_profiles" action =====
    if (isset($_POST['action']) && $_POST['action'] === 'load_profiles') {
        // Spus load_profiles.php
        ob_start();
        include 'load_profiles.php';
        $output = ob_get_clean();
        
        // Parse output pro message
        if (preg_match('/Inserted (\d+) profiles/', $output, $m)) {
            $_SESSION['flash_message'] = "Profiles loaded: {$m[1]} profiles inserted.";
        } else {
            $_SESSION['flash_message'] = strip_tags($output);
        }
        
        header('Location: admin_user_tunnels.php');
        exit;
    }
    // ===== KONEC NOVÉ =====

    if (isset($_POST['action']) && $_POST['action'] === 'add_mapping') {
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $profile_id = (int)($_POST['profile_id'] ?? 0);

        if ($user_id > 0 && $profile_id > 0) {
            try {
		$stmt = $pdo->prepare(
		    'INSERT INTO user_tunnels (user_id, profile_id, tunnel_name) VALUES (:user_id, :profile_id, :tunnel_name)'
		);
		$stmt->execute([
		    ':user_id'     => $user_id,
		    ':profile_id'  => $profile_id,
		    ':tunnel_name' => '',
		]);

                // úspìch – flash + redirect
                $_SESSION['flash_message'] = 'Tunnel assigned to user.';
                header('Location: admin_user_tunnels.php');
                exit;
            } catch (PDOException $e) {
                // duplicate key (UNIQUE porušení)
                if ($e->getCode() === '23000' && str_contains($e->getMessage(), '1062')) {
                    $message = 'This profile is already assigned to this user.';
                } else {
                    $message = 'Database error: ' . $e->getMessage();
                }
            }
        } else {
            $message = 'Please select both user and profile.';
        }
    }

    // Handle "remove mapping" action
    if (isset($_POST['action']) && $_POST['action'] === 'remove_mapping') {
        $mapping_id = (int)($_POST['mapping_id'] ?? 0);
        if ($mapping_id > 0) {
            $stmt = $pdo->prepare('DELETE FROM user_tunnels WHERE id = :id');
            $stmt->execute([':id' => $mapping_id]);

            $_SESSION['flash_message'] = 'Assignment removed.';
            header('Location: admin_user_tunnels.php');
            exit;
        } else {
            $message = 'Invalid mapping ID.';
        }
    }

    // TODO: load_profiles...
}

// Load users
$stmt  = $pdo->query('SELECT id, username FROM users ORDER BY username ASC');
//$stmt = $pdo->query('SELECT id, name, description, status FROM profiles WHERE status != "Inactive" ORDER BY name ASC');
$users = $stmt->fetchAll();

// Load profiles
//$stmt = $pdo->query('SELECT id, name, description, status FROM profiles ORDER BY name ASC');
$stmt = $pdo->query('SELECT id, name, description, status FROM profiles WHERE status != "Inactive" ORDER BY name ASC');
$profiles = $stmt->fetchAll();

// Load existing mappings user <-> profiles
$sql = '
    SELECT ut.id AS mapping_id,
           u.username,
           p.name AS profile_name
    FROM user_tunnels ut
    JOIN users u    ON ut.user_id = u.id
    JOIN profiles p ON ut.profile_id = p.id
    ORDER BY u.username, p.name
';
$assignments = $pdo->query($sql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Admin - User tunnel management</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        h1 { font-size: 22px; margin-bottom: 10px; }
        .message { margin: 10px 0; color: green; }
        .error { margin: 10px 0; color: red; }
        form { margin-bottom: 20px; }
        label { display: inline-block; width: 120px; }
        select { min-width: 200px; max-width: 300px; }
        button { padding: 4px 10px; }
        table { border-collapse: collapse; width: 100%; margin-top: 10px; }
        th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
        th { background-color: #f0f0f0; }
        .btn-small { font-size: 11px; padding: 2px 6px; }
        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
    </style>
</head>
<body>
<div class="container">
    <div class="top-bar">
        <h1>Admin - User tunnel management</h1>
        <div>
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="load_profiles">
                <button type="submit">Load Profiles</button>
            </form>
            <a href="admin_add_user.php">User management</a>
            |
            <a href="logout.php">Logout</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="<?php echo str_starts_with($message, 'This profile is already') || str_starts_with($message, 'Database error') ? 'error' : 'message'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <h2>Assign tunnel to user</h2>
    <form method="post">
        <input type="hidden" name="action" value="add_mapping">

        <p>
            <label for="user_id">User:</label>
            <select name="user_id" id="user_id" required>
                <option value="">-- select user --</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?php echo (int)$u['id']; ?>">
                        <?php echo htmlspecialchars($u['username']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <label for="profile_id">Profile:</label>
            <select name="profile_id" id="profile_id" required>
                <option value="">-- select profile --</option>
                <?php foreach ($profiles as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>"
                            title="<?php
                                $tt = $p['name'];
                                if (!empty($p['status'])) {
                                    $tt .= ' [' . $p['status'] . ']';
                                }
                                if (!empty($p['description'])) {
                                    $tt .= ' – ' . $p['description'];
                                }
                                echo htmlspecialchars($tt, ENT_QUOTES, 'UTF-8');
                            ?>">
                        <?php echo htmlspecialchars($p['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>

        <p>
            <button type="submit">Save</button>
        </p>
    </form>

    <h2>Profile details</h2>
    <table>
        <thead>
        <tr>
            <th>Name</th>
            <th>Status</th>
            <th>Description</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($profiles as $p): ?>
            <tr>
                <td><?php echo htmlspecialchars($p['name']); ?></td>
                <td><?php echo htmlspecialchars($p['status']); ?></td>
                <td><?php echo htmlspecialchars($p['description']); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Existing assignments</h2>
    <?php if (empty($assignments)): ?>
        <p>No user–tunnel assignments yet.</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>User</th>
                <th>Profile</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($assignments as $row): ?>
                <tr>
                    <td><?php echo (int)$row['mapping_id']; ?></td>
                    <td><?php echo htmlspecialchars($row['username']); ?></td>
                    <td><?php echo htmlspecialchars($row['profile_name']); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="remove_mapping">
                            <input type="hidden" name="mapping_id" value="<?php echo (int)$row['mapping_id']; ?>">
                            <button type="submit" class="btn-small">Remove</button>
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
