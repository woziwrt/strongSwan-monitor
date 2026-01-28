<?php
// Collector pro tunnel_stats – per-tunnel a per-child
// UPRAVENO: Pøidána podpora pro client_id + skip default (unreliable data)

date_default_timezone_set('Europe/Prague');

// ---- config ----
$apiUrl  = 'http://127.0.0.1:80/api/vpn-status.php';

$dsn_host = 'localhost';
$dsn_user = 'user';
$dsn_pass = 'password';
$dsn_db   = 'securelink';

// ---- load JSON z API ----
$json = @file_get_contents($apiUrl);
if ($json === false) {
    exit;
}

$data = json_decode($json, true);
if (!is_array($data) || !isset($data['tunnels']) || !is_array($data['tunnels'])) {
    exit;
}

$tunnels = $data['tunnels'];
if (!$tunnels) {
    exit;
}

// ---- DB connect ----
try {
    $pdo = new PDO("mysql:host=$dsn_host;dbname=$dsn_db;charset=utf8mb4", $dsn_user, $dsn_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    exit;
}

$ts = time();

/*
* INSERT:
*  - tunnel_name = jméno tunelu (napø. "bejdove")
*  - child_name  = jméno child SA (napø. "net-1"), nebo '' pro IKE-only
*  - client_id   = identifikátor klienta (virtual IP pro EAP, subnet IP pro S2S)
*  - bytes_in / bytes_out = èítaèe pro daný child nebo tunel
*  - ts          = timestamp
*/
$stmt = $pdo->prepare('
    INSERT INTO tunnel_stats (tunnel_name, child_name, client_id, bytes_in, bytes_out, ts)
    VALUES (?, ?, ?, ?, ?, ?)
');

// ---- iterate tunnels ----
foreach ($tunnels as $t) {
    $tunnelName = $t['name'] ?? '';
    if ($tunnelName === '') {
        continue;
    }

    // Má tunel children?
    if (!empty($t['children']) && is_array($t['children'])) {
        foreach ($t['children'] as $child) {
            // jméno childa – podle JSONu to mùže být napø. "name" nebo "id"
            $childName = $child['name'] ?? ($child['id'] ?? '');

            if ($childName === '') {
                $childName = '';
            }

            // Identifikace klienta podle remote_ts
            $clientId = 'default';
            $remoteTs = $child['remote_ts'] ?? [];
            
            if (!empty($remoteTs) && is_array($remoteTs)) {
                // Vezmi první subnet (napø. "192.168.80.1/32" nebo "10.10.10.0/24")
                $subnet = $remoteTs[0] ?? '';
                // Odstranit /XX suffix (napø. /32 nebo /24)
                $clientId = preg_replace('/\/\d+$/', '', $subnet);
                
                // Pokud je prázdný, zùstane default
                if ($clientId === '') {
                    $clientId = 'default';
                }
            }

            // ===== NOVÉ: Skip default (unreliable data) =====
            if ($clientId === 'default') {
                continue; // pøeskoè tento child, neuložíme do DB
            }
            // ===== KONEC NOVÉ =====

            $bytesIn  = (int)($child['bytes_in']  ?? 0);
            $bytesOut = (int)($child['bytes_out'] ?? 0);

            try {
                $stmt->execute([$tunnelName, $childName, $clientId, $bytesIn, $bytesOut, $ts]);
            } catch (PDOException $e) {
                continue;
            }
        }
    } else {
        // tunel bez children – uložíme jen agregované bytes na úrovni tunelu
        $bytesIn  = (int)($t['bytes_in']  ?? 0);
        $bytesOut = (int)($t['bytes_out'] ?? 0);

        try {
            // Pro tunely bez children použijeme 'default' jako legitimní hodnotu
            $stmt->execute([$tunnelName, '', 'default', $bytesIn, $bytesOut, $ts]);
        } catch (PDOException $e) {
            continue;
        }
    }
}