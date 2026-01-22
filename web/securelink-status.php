<?php

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

// lokální / vzdálené subnety – prosté zalomení na øádky
function format_subnets(array $subs) {
    if (!$subs) return '';
    return nl2br(htmlspecialchars(implode("\n", $subs), ENT_QUOTES, 'UTF-8'));
}

// mapování stavu na text + CSS tøídu
function map_status(array $t) {
    $raw = strtoupper($t['ike_state'] ?? '');
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

// ---- naètení dat z API ----

$host = $_SERVER['SERVER_NAME'] ?? gethostname();

$json = @file_get_contents('http://127.0.0.1:80/api/vpn-status.php');
$data = $json ? json_decode($json, true) : null;
$tunnels = is_array($data) && isset($data['tunnels']) ? $data['tunnels'] : [];

// HTTP hlavièky proti cache
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// èas poslední aktualizace (aktuální generování stránky)
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
body {
    font-family: sans-serif;
    background: #f5f5f5;
    margin: 0;
    padding: 0;
}
header {
    background: #263238;
    color: #fff;
    padding: 8px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
header h1 {
    margin: 0;
    font-size: 20px;
}
header .server {
    font-size: 12px;
    opacity: 0.8;
}
header .meta {
    font-size: 12px;
    opacity: 0.9;
    text-align: right;
}
header .meta .label {
    opacity: 0.8;
}
main {
    padding: 8px 20px 20px 20px;
}
.table-wrap {
    max-height: 80vh;        /* vyšší okno */
    overflow-y: auto;
    border: 1px solid #ddd;
    background: #fff;
}
table {
    border-collapse: collapse;
    width: 100%;
}
th, td {
    padding: 6px 8px;
    border-bottom: 1px solid #ddd;
    font-size: 13px;
    vertical-align: top;
}
th {
    background: #eceff1;
    text-align: left;
    position: sticky;
    top: 0;
    z-index: 1;
}
tr:nth-child(even) {
    background: #fafafa;
}
.status-up {
    background: #4caf50;
    color: #fff;
    font-weight: bold;
    text-align: center;
}
.status-down {
    background: #f44336;
    color: #fff;
    font-weight: bold;
    text-align: center;
}
.status-warn {
    background: #ff9800;
    color: #fff;
    font-weight: bold;
    text-align: center;
}
.subnets {
    font-size: 11px;
    color: #555;
    white-space: normal;
}
.actions .btn-print {
    padding: 4px 10px;
    font-size: 12px;
    border: none;
    border-radius: 3px;
    background: #546e7a;
    color: #fff;
    cursor: pointer;
}
.actions .btn-print:hover {
    background: #78909c;
}
@media print {
    body {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
        background: #fff;
    }
    header .actions {
        display: none;
    }
    .table-wrap {
        max-height: none;
        overflow: visible;
    }
}
</style>
</head>
<body>
<header>
    <div>
        <h1>SecureLink Status</h1>
        <div class="server">
            Server: <?php echo htmlspecialchars($host, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>
    <div class="meta">
        <div class="label">Last update:</div>
        <div><?php echo htmlspecialchars($lastUpdate, ENT_QUOTES, 'UTF-8'); ?></div>
    </div>
    <div class="actions">
        <button type="button" class="btn-print" onclick="window.print()">Print / PDF</button>
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
            <th>Local subnets</th>
            <th>Remote subnets</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($tunnels as $t):
        [$statusText, $cls] = map_status($t);
        $peer = $t['peer_fqdn'] ?: $t['peer_ip'];
    ?>
        <tr>
            <td><?php echo htmlspecialchars($t['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo htmlspecialchars($peer ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="<?php echo $cls; ?>"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?php echo format_bytes_pair($t['bytes_in'] ?? 0, $t['bytes_out'] ?? 0); ?></td>
            <td><?php
                $pi = $t['packets_in'] ?? 0;
                $po = $t['packets_out'] ?? 0;
                echo format_int($pi) . ' / ' . format_int($po);
            ?></td>
            <td class="subnets">
                <?php echo format_subnets($t['local_subnets'] ?? []); ?>
            </td>
            <td class="subnets">
                <?php echo format_subnets($t['remote_subnets'] ?? []); ?>
            </td>
        </tr>
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

    // 1) nastav explicitnì scrollRestoration
    if ('scrollRestoration' in history) {
        history.scrollRestoration = 'manual';
    }

    // 2) po malém delay obnov pozici
    var pos = sessionStorage.getItem('tableScrollTop');
    if (pos !== null) {
        setTimeout(function () {
            wrap.scrollTop = parseInt(pos, 10) || 0;
        }, 50);
    }

    // 3) prùbìžnì ukládej pozici
    setInterval(function () {
        try {
            sessionStorage.setItem('tableScrollTop', wrap.scrollTop || 0);
        } catch (e) {
            // Safari private mode mùže hodit chybu, ignorujeme
        }
    }, 1000);
});
</script>

</body>
</html>
