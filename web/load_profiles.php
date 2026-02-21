<?php
// load_profiles.php v2 - Smart sync with template filtering
// Preserves notes, handles INSERT/UPDATE/DEACTIVATE

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// DB connection
$dsn_host = 'localhost';
$dsn_user = 'root';
$dsn_pass = '';
$dsn_db   = 'securelink';

try {
    $pdo = new PDO("mysql:host=$dsn_host;dbname=$dsn_db;charset=utf8mb4", $dsn_user, $dsn_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n");
    exit(1);
}

// Read swanctl.conf
$confFile = '/etc/swanctl/swanctl.conf';

if (!is_readable($confFile)) {
    fwrite(STDERR, "Cannot read $confFile\n");
    exit(1);
}

$contents = file_get_contents($confFile);

// Parse profiles
$profiles = [];
$pattern = '/^\s*([A-Za-z0-9_\-]+)\s*:\s*(conn-defaults|conn-rad-defaults)\s*\{/m';

if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
    $count = count($matches);
    for ($i = 0; $i < $count; $i++) {
        $name = $matches[$i][1][0];
        $startPos = $matches[$i][0][1];

        $bracePos = strpos($contents, '{', $startPos);
        if ($bracePos === false) {
            continue;
        }

        $level = 0;
        $len = strlen($contents);
        $endPos = null;
        for ($pos = $bracePos; $pos < $len; $pos++) {
            $ch = $contents[$pos];
            if ($ch === '{') {
                $level++;
            } elseif ($ch === '}') {
                $level--;
                if ($level === 0) {
                    $endPos = $pos;
                    break;
                }
            }
        }
        if ($endPos === null) {
            continue;
        }

        $body = substr($contents, $bracePos + 1, $endPos - $bracePos - 1);

        $remote_id = '';
        $local_ts  = '';
        $remote_ts = '';

        // remote { ... id = something ... }
        if (preg_match('/remote\s*\{([^}]*)\}/s', $body, $rm)) {
            if (preg_match('/\bid\s*=\s*([^\s]+)\b/', $rm[1], $idm)) {
                $remote_id = trim($idm[1]);
            }
        }

        // children { ... }
        if (preg_match('/children\s*\{(.+?)\}\s*/s', $body, $cm)) {
            $childrenBody = $cm[1];

            if (preg_match('/local_ts\s*=\s*([^\n\r]+)/', $childrenBody, $lm)) {
                $local_ts = trim($lm[1]);
            }
            if (preg_match('/remote_ts\s*=\s*([^\n\r]+)/', $childrenBody, $rm2)) {
                $remote_ts = trim($rm2[1]);
            }
        }

        $descParts = [];
        if ($remote_id !== '') $descParts[] = "remote_id=$remote_id";
        if ($local_ts  !== '') $descParts[] = "local_ts=$local_ts";
        if ($remote_ts !== '') $descParts[] = "remote_ts=$remote_ts";

        $description = implode(', ', $descParts);

        $profiles[$name] = [
            'name'        => $name,
            'description' => $description,
        ];
    }
}

// Filter out templates
$templates = ['conn-defaults', 'conn-rad-defaults', 'children-defaults', 
              'rad-local-defaults', 'rad-remote-defaults'];

foreach ($templates as $tmpl) {
    unset($profiles[$tmpl]);
}

if (empty($profiles)) {
    echo "No profiles parsed. DB not touched.\n";
    exit(0);
}

// Load existing profiles from DB
$existing = [];
$stmt = $pdo->query('SELECT name, note, status FROM profiles');
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existing[$row['name']] = $row;
}

// Start transaction
$pdo->beginTransaction();

try {
    $inserted = 0;
    $updated = 0;
    $deactivated = 0;

    // Process profiles from config
    foreach ($profiles as $name => $profile) {
        if (isset($existing[$name])) {
            // Profile exists - UPDATE description only (keep note & status)
            $stmt = $pdo->prepare('UPDATE profiles SET description = ? WHERE name = ?');
            $stmt->execute([$profile['description'], $name]);
            $updated++;
            
            // Mark as processed
            unset($existing[$name]);
        } else {
            // New profile - INSERT
            $stmt = $pdo->prepare('INSERT INTO profiles (name, description, status) VALUES (?, ?, ?)');
            $stmt->execute([$name, $profile['description'], 'Active']);
            $inserted++;
        }
    }

    // Profiles remaining in $existing are not in config anymore - DEACTIVATE
    if (!empty($existing)) {
        $stmt = $pdo->prepare('UPDATE profiles SET status = ? WHERE name = ?');
        foreach ($existing as $name => $data) {
            // Only deactivate if currently Active or New
            if ($data['status'] !== 'Inactive') {
                $stmt->execute(['Inactive', $name]);
                $deactivated++;
            }
        }
    }

    $pdo->commit();
    
    echo "Sync completed successfully.\n";
    echo "Inserted: $inserted, Updated: $updated, Deactivated: $deactivated\n";

} catch (Exception $e) {
    $pdo->rollBack();
    fwrite(STDERR, "DB error: " . $e->getMessage() . "\n");
    exit(1);
}
?>