<?php
session_start();
if (empty($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

date_default_timezone_set('Europe/Prague');

// ---- helper funkce ----

function format_bytes($bytes, $precision = 1) {
    $bytes = (float)$bytes;
    if ($bytes < 1024) {
        return sprintf('%.0f B', $bytes);
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, $precision) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, $precision) . ' MB';
    } else {
        return round($bytes / 1073741824, $precision) . ' GB';
    }
}

function format_bytes_pair($in, $out) {
    return format_bytes($in) . ' / ' . format_bytes($out);
}

function format_int($v) {
    $v = (int)$v;
    return $v ? number_format($v, 0, ',', ' ') : '0';
}

function format_subnets(array $subs) {
    if (!$subs) return '';
    return nl2br(htmlspecialchars(implode("\n", $subs), ENT_QUOTES, 'UTF-8'));
}

function map_status(array $t) {
    $raw  = strtoupper($t['ike_state'] ?? '');
    $text = $raw ?: 'DOWN';
    $cls  = 'status-down';

    if (in_array($raw, ['ESTABLISHED', 'UP'], true)) {
        $text = 'UP';
        $cls  = 'status-up';
    } elseif (in_array($raw, ['CONNECTING', 'REKEYING'], true)) {
        $text = 'TRANSIT';
        $cls  = 'status-warn';
    }
    return [$text, $cls];
}

function format_speed($bytes_per_sec) {
    $bps = (float)$bytes_per_sec;
    if ($bps <= 0) {
        return '-';
    }

    $bits = $bps * 8;

    if ($bits < 1000) {
        return round($bits, 1) . ' b/s';
    }

    $kb = $bits / 1000;
    if ($kb < 1000) {
        return round($kb, 1) . ' kb/s';
    }

    $mb = $kb / 1000;
    if ($mb < 1000) {
        return round($mb, 2) . ' Mb/s';
    }

    $gb = $mb / 1000;
    return round($gb, 2) . ' Gb/s';
}

// ---- nacteni dat z API ----

$ip = $_SERVER['SERVER_ADDR'] ?? gethostbyname(gethostname());
$serverLabel = $ip;

$servers = [
    '87.236.194.191' => 'ikev2-ch.securelink.cc',
    'X.X.X.X'        => 'ikev2-de.securelink.cc',
    'Y.Y.Y.Y'        => 'ikev2-cz.securelink.cc',
];

if (isset($servers[$ip])) {
    $serverLabel = $servers[$ip] . ' (' . $ip . ')';
}

$json    = @file_get_contents('http://127.0.0.1:80/api/vpn-status.php');
$data    = $json ? json_decode($json, true) : null;
$tunnels = is_array($data) && isset($data['tunnels']) ? $data['tunnels'] : [];

// ---- DB pripojeni ----

$dsn_host = 'localhost';
$dsn_user = 'root';
$dsn_pass = '';
$dsn_db   = 'securelink';

$pdo = null;
try {
    $pdo = new PDO("mysql:host=$dsn_host;dbname=$dsn_db;charset=utf8mb4", $dsn_user, $dsn_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // kdyz DB padne, pojedeme bez omezeni tunelu a bez rychlosti
}

// ---- Nacteni notes z profiles ----
$profileNotes = [];
if ($pdo) {
    try {
        $stmt = $pdo->query('SELECT name, note FROM profiles WHERE note IS NOT NULL AND note != ""');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $profileNotes[$row['name']] = $row['note'];
        }
    } catch (PDOException $e) {
        // ignore
    }
}

// ---- omezeni tunelu podle uzivatele/role ----

$allowedTunnelNames = null;

if (($_SESSION['role'] ?? '') !== 'admin') {
    if ($pdo) {
        try {
            $stmt = $pdo->prepare('
                SELECT p.name
                FROM user_tunnels ut
                JOIN profiles p ON ut.profile_id = p.id
                WHERE ut.user_id = ?
            ');
            $stmt->execute([ (int)($_SESSION['user_id'] ?? 0) ]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $allowedTunnelNames = $rows ?: [];
        } catch (PDOException $e) {
            $allowedTunnelNames = [];
        }
    } else {
        $allowedTunnelNames = [];
    }
}

if (($_SESSION['role'] ?? '') !== 'admin' && is_array($allowedTunnelNames)) {
    $tunnels = array_filter($tunnels, function ($t) use ($allowedTunnelNames) {
        $name = $t['name'] ?? '';
        return $name !== '' && in_array($name, $allowedTunnelNames, true);
    });
}

// ---- prepared statement pro rychlost z tunnel_stats ----

$stmtSpeed = null;
if ($pdo) {
    try {
        $stmtSpeed = $pdo->prepare('
            SELECT bytes_in, bytes_out, ts, client_id
            FROM tunnel_stats
            WHERE tunnel_name = ? AND child_name = ? AND client_id = ?
            ORDER BY ts DESC
            LIMIT 2
        ');
    } catch (PDOException $e) {
        $stmtSpeed = null;
    }
}

// HTTP hlavicky proti cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$lastUpdate = date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SecureLink Status</title>
<meta http-equiv="refresh" content="20">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<style>
body { font-family: sans-serif; background: #f5f5f5; margin: 0; padding: 0; }
header { background: #263238; color: #fff; padding: 8px 20px; display: flex; justify-content: space-between; align-items: center; }
header h1 { margin: 0; font-size: 20px; }
header .server { font-size: 12px; opacity: 0.8; }
header .meta { font-size: 12px; opacity: 0.9; text-align: right; }
header .meta .label { opacity: 0.8; }
main { padding: 8px 20px 20px 20px; }
.table-wrap { max-height: 80vh; overflow-y: auto; border: 1px solid #ddd; background: #fff; }
table { border-collapse: collapse; width: 100%; }
th, td { padding: 6px 8px; border-bottom: 1px solid #ddd; font-size: 13px; vertical-align: top; }
th { background: #eceff1; text-align: left; position: sticky; top: 0; z-index: 1; }
tr:nth-child(even) { background: #fafafa; }
.status-up { background: #4caf50; color: #fff; font-weight: bold; text-align: center; }
.status-down { background: #f44336; color: #fff; font-weight: bold; text-align: center; }
.status-warn { background: #ff9800; color: #fff; font-weight: bold; text-align: center; }
.subnets { font-size: 11px; color: #555; white-space: normal; }
.actions .btn { padding: 4px 10px; font-size: 12px; border: none; border-radius: 3px; background: #546e7a; color: #fff; cursor: pointer; margin-left: 6px; }
.actions .btn:hover { background: #78909c; }

.tunnel-name { display: inline-block; position: relative; }
.note-icon { cursor: help; margin-left: 4px; font-size: 14px; }
.edit-note-btn { 
    background: none; 
    border: none; 
    cursor: pointer; 
    font-size: 14px; 
    padding: 0 4px;
    opacity: 0.6;
    transition: opacity 0.2s;
}
.edit-note-btn:hover { opacity: 1; }

.tooltip {
    position: relative;
    display: inline-block;
}
.tooltip .tooltiptext {
    visibility: hidden;
    min-width: 200px;
    max-width: 400px;
    background-color: #555 !important;
    color: #fff !important;
    text-align: left;
    border-radius: 6px;
    padding: 8px;
    position: absolute;
    z-index: 1000;
    bottom: 125%;
    left: 0;
    margin-left: 0;
    opacity: 0;
    transition: opacity 0.3s;
    font-size: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
    white-space: normal;
    word-wrap: break-word;
}
.tooltip .tooltiptext::after {
    content: "";
    position: absolute;
    top: 100%;
    left: 20px;
    margin-left: 0;
    border-width: 5px;
    border-style: solid;
    border-color: #555 transparent transparent transparent;
}
.tooltip:hover .tooltiptext {
    visibility: visible;
    opacity: 1;
}

@media print {
    body { -webkit-print-color-adjust: exact; print-color-adjust: exact; background: #fff; }
    header .actions { display: none; }
    .table-wrap { max-height: none; overflow: visible; }
    .edit-note-btn { display: none; }
}
</style>
</head>
<body>
<header>
    <div>
        <h1>SecureLink Status</h1>
        <div class="server">
            Server: <?php echo htmlspecialchars($serverLabel, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
    <div class="meta">
        <div class="label">Last update:</div>
        <div><?php echo htmlspecialchars($lastUpdate, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <div class="actions">
        <button type="button" class="btn" onclick="window.print()">Print / PDF</button>
        <button type="button" class="btn" onclick="window.location.href='logout.php'">Logout</button>
        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <button type="button" class="btn" onclick="window.location.href='admin_add_user.php'">
                User administration
            </button>
        <?php endif; ?>
    </div>
</header>

<main>
<?php if (!$tunnels): ?>
    <p>No tunnel data available.</p>
<?php else: ?>
<div class="table-wrap">
<table>
    <thead>
        <tr>
            <th>Name</th>
            <th>Peer</th>
            <th>Status</th>
            <th>Bytes (in/out)</th>
            <th>Packets (in/out)</th>
            <th>Speed (in/out)</th>
            <th>Local subnets</th>
            <th>Remote subnets</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($tunnels as $t): ?>
        <?php
            $peer = $t['peer_fqdn'] ?: $t['peer_ip'];
            $tunnelNameForSpeed = $t['name'] ?? '';
            $tunnelNameRaw = $t['name'] ?? '';
            
            $hasNote = isset($profileNotes[$tunnelNameRaw]) && $profileNotes[$tunnelNameRaw] !== '';
            $noteText = $hasNote ? $profileNotes[$tunnelNameRaw] : 'Click to add note...';

            if (!empty($t['children']) && is_array($t['children'])):
                $firstChild = true;
                foreach ($t['children'] as $child):
                    $childState = strtoupper($child['state'] ?? '');
                    if ($childState === 'INSTALLED') {
                        $statusText = 'UP';
                        $cls        = 'status-up';
                    } else {
                        $statusText = 'DOWN';
                        $cls        = 'status-down';
                    }

                    $localTs  = isset($child['local_ts'])  && is_array($child['local_ts'])  ? $child['local_ts']  : [];
                    $remoteTs = isset($child['remote_ts']) && is_array($child['remote_ts']) ? $child['remote_ts'] : [];

                    $bytesIn  = $child['bytes_in']  ?? 0;
                    $bytesOut = $child['bytes_out'] ?? 0;
                    $packIn   = $child['packets_in']  ?? 0;
                    $packOut  = $child['packets_out'] ?? 0;

                    $childNameForSpeed = $child['name'] ?? '';

                    $clientId = 'default';
                    if (!empty($remoteTs) && is_array($remoteTs)) {
                        $subnet = $remoteTs[0] ?? '';
                        $clientId = preg_replace('/\/\d+$/', '', $subnet);
                        if ($clientId === '') {
                            $clientId = 'default';
                        }
                    }

                    $speedIn  = '-';
                    $speedOut = '-';

                    if ($stmtSpeed && $tunnelNameForSpeed !== '' && $childNameForSpeed !== '' && $clientId !== 'default') {
                        try {
                            $stmtSpeed->execute([$tunnelNameForSpeed, $childNameForSpeed, $clientId]);
                            $rowsSpeed = $stmtSpeed->fetchAll(PDO::FETCH_ASSOC);

                            if (count($rowsSpeed) === 2) {
                                $new = $rowsSpeed[0];
                                $old = $rowsSpeed[1];

                                $dt   = $new['ts']        - $old['ts'];
                                $din  = $new['bytes_in']  - $old['bytes_in'];
                                $dout = $new['bytes_out'] - $old['bytes_out'];

                                if ($din < 0 || $dout < 0) {
                                    $speedIn  = '-';
                                    $speedOut = '-';
                                } elseif ($dt > 0 && $dt < 120) {
                                    $speedIn  = format_speed($din  / $dt);
                                    $speedOut = format_speed($dout / $dt);
                                } else {
                                    $speedIn  = '-';
                                    $speedOut = '-';
                                }
                            }
                        } catch (PDOException $e) {
                            $speedIn  = '-';
                            $speedOut = '-';
                        }
                    }

                    if (($packIn == 0 && $packOut == 0) || ($bytesIn == 0 && $bytesOut == 0)) {
                        $speedIn  = '-';
                        $speedOut = '-';
                    }
        ?>
        <tr>
            <td>
                <span class="tunnel-name">
                    <?php echo htmlspecialchars($tunnelNameRaw, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($firstChild): ?>
                        <?php if ($hasNote): ?>
                            <span class="tooltip">
                                <span class="note-icon">&#128221;</span>
                                <span class="tooltiptext"><?php echo htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8'); ?></span>
                            </span>
                        <?php endif; ?>
                        <button class="edit-note-btn" 
                                onclick="editNote('<?php echo htmlspecialchars($tunnelNameRaw, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8'); ?>')" 
                                title="Edit note">&#9998;</button>
                    <?php endif; ?>
                </span>
            </td>
            <td><?php echo htmlspecialchars($peer ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="<?php echo $cls; ?>"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo format_bytes_pair($bytesIn, $bytesOut); ?></td>
            <td><?php echo format_int($packIn) . ' / ' . format_int($packOut); ?></td>
            <td><?php echo htmlspecialchars($speedIn . ' / ' . $speedOut, ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="subnets"><?php echo format_subnets($localTs); ?></td>
            <td class="subnets"><?php echo format_subnets($remoteTs); ?></td>
        </tr>
        <?php
                    $firstChild = false;
                endforeach;
            else:
                [$statusText, $cls] = map_status($t);
                $pi = $t['packets_in']  ?? 0;
                $po = $t['packets_out'] ?? 0;
                $bytesInTunnel  = $t['bytes_in']  ?? 0;
                $bytesOutTunnel = $t['bytes_out'] ?? 0;

                $speedIn  = '-';
                $speedOut = '-';
        ?>
        <tr>
            <td>
                <span class="tunnel-name">
                    <?php echo htmlspecialchars($tunnelNameRaw, ENT_QUOTES, 'UTF-8'); ?>
                    <?php if ($hasNote): ?>
                        <span class="tooltip">
                            <span class="note-icon">&#128221;</span>
                            <span class="tooltiptext"><?php echo htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8'); ?></span>
                        </span>
                    <?php endif; ?>
                    <button class="edit-note-btn" 
                            onclick="editNote('<?php echo htmlspecialchars($tunnelNameRaw, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($noteText, ENT_QUOTES, 'UTF-8'); ?>')" 
                            title="Edit note">&#9998;</button>
                </span>
            </td>
            <td><?php echo htmlspecialchars($peer ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="<?php echo $cls; ?>"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo format_bytes_pair($bytesInTunnel, $bytesOutTunnel); ?></td>
            <td><?php echo format_int($pi) . ' / ' . format_int($po); ?></td>
            <td><?php echo htmlspecialchars($speedIn . ' / ' . $speedOut, ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="subnets"><?php echo format_subnets($t['local_subnets'] ?? []); ?></td>
            <td class="subnets"><?php echo format_subnets($t['remote_subnets'] ?? []); ?></td>
        </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</main>

<script>
document.addEventListener("DOMContentLoaded", function () {
    var wrap = document.querySelector('.table-wrap');
    if (!wrap) return;

    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }

    var pos = sessionStorage.getItem('tableScrollTop');
    if (pos !== null) {
        setTimeout(function () {
            wrap.scrollTop = parseInt(pos, 10) || 0;
        }, 50);
    }

    setInterval(function () {
        try {
            sessionStorage.setItem('tableScrollTop', wrap.scrollTop || 0);
        } catch (e) {}
    }, 1000);
});

function editNote(tunnelName, currentNote) {
    if (currentNote === 'Click to add note...') {
        currentNote = '';
    }
    
    var newNote = prompt('Note for "' + tunnelName + '":', currentNote);
    
    if (newNote !== null) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'update_note.php', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + (response.error || 'Unknown error'));
                    }
                } catch (e) {
                    alert('Error saving note');
                }
            } else {
                alert('Error: HTTP ' + xhr.status);
            }
        };
        
        xhr.send('tunnel=' + encodeURIComponent(tunnelName) + '&note=' + encodeURIComponent(newNote));
    }
}
</script>

</body>
</html>