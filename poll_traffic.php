<?php
/**
 * LibreQoS Traffic Poller (Delta-based)
 * 
 * Run via cron every 15 minutes on the LAMP server:
 *   Every 15 min: /usr/bin/php /var/www/html/splynx-libreqos/poll_traffic.php >> /var/log/libreqos-poller.log 2>&1
 * 
 * How it works:
 * 1. Fetches current total_bytes per unknown IP from LibreQoS
 * 2. Compares against the previous snapshot to calculate the delta (bytes used since last poll)
 * 3. If total_bytes is LOWER than previous snapshot, lqosd has restarted — treat current total as the delta
 * 4. Stores the delta in SQLite for 31-day aggregation
 * 5. Saves current snapshot for next poll's comparison
 */

require_once __DIR__ . '/config.php';

// --- 1. Initialise SQLite DB ---
$db = new SQLite3($dbPath);
$db->exec('PRAGMA journal_mode=WAL');

$db->exec('CREATE TABLE IF NOT EXISTS traffic_deltas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL,
    bytes_down INTEGER NOT NULL,
    bytes_up INTEGER NOT NULL,
    sampled_at TEXT NOT NULL
)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_deltas_ip ON traffic_deltas(ip, sampled_at)');
$db->exec('CREATE INDEX IF NOT EXISTS idx_deltas_sampled ON traffic_deltas(sampled_at)');

// Snapshot table: stores the last known total_bytes per IP for delta calculation
$db->exec('CREATE TABLE IF NOT EXISTS traffic_snapshot (
    ip TEXT PRIMARY KEY,
    total_bytes_down INTEGER NOT NULL,
    total_bytes_up INTEGER NOT NULL,
    updated_at TEXT NOT NULL
)');

// --- 2. Fetch unknown IPs from LibreQoS agent ---
$ctx = stream_context_create(['http' => ['timeout' => 15]]);
$response = @file_get_contents($libreqosAgentUrl . '/local-api/unknownIps', false, $ctx);

if ($response === false) {
    error_log("splynx-libreqos: Failed to fetch from traffic agent at {$libreqosAgentUrl}");
    exit(1);
}

$unknownIps = json_decode($response, true);
if (!is_array($unknownIps)) {
    error_log("splynx-libreqos: Invalid response from traffic agent");
    exit(1);
}

// --- 3. Load previous snapshot ---
$prevSnapshot = [];
$result = $db->query('SELECT ip, total_bytes_down, total_bytes_up FROM traffic_snapshot');
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $prevSnapshot[$row['ip']] = $row;
}

// --- 4. Calculate deltas and update snapshot ---
$now = gmdate('Y-m-d H:i:s');
$insertDelta = $db->prepare('INSERT INTO traffic_deltas (ip, bytes_down, bytes_up, sampled_at) VALUES (:ip, :down, :up, :sampled_at)');
$upsertSnapshot = $db->prepare('INSERT OR REPLACE INTO traffic_snapshot (ip, total_bytes_down, total_bytes_up, updated_at) VALUES (:ip, :down, :up, :updated_at)');

$recorded = 0;
$resets = 0;

$db->exec('BEGIN TRANSACTION');

foreach ($unknownIps as $entry) {
    $ip = $entry['ip'] ?? null;
    if (!$ip) continue;

    $currentDown = (int)($entry['total_bytes']['down'] ?? 0);
    $currentUp = (int)($entry['total_bytes']['up'] ?? 0);

    // Calculate delta
    $deltaDown = 0;
    $deltaUp = 0;
    $shouldRecord = false;

    if (isset($prevSnapshot[$ip])) {
        $prevDown = (int)$prevSnapshot[$ip]['total_bytes_down'];
        $prevUp = (int)$prevSnapshot[$ip]['total_bytes_up'];

        if ($currentDown >= $prevDown && $currentUp >= $prevUp) {
            // Normal case: totals increased since last poll
            $deltaDown = $currentDown - $prevDown;
            $deltaUp = $currentUp - $prevUp;
        } else {
            // lqosd restarted: totals are lower than before
            // Treat the current total as new traffic since restart
            $deltaDown = $currentDown;
            $deltaUp = $currentUp;
            $resets++;
        }
        $shouldRecord = true;
    } else {
        // First time seeing this IP — just snapshot it, don't record a delta.
        // We don't know how long lqosd has been accumulating this total,
        // so we can't attribute it to a specific time window.
        $shouldRecord = false;
    }

    // Only record if there's meaningful traffic above noise floor
    $deltaTotal = $deltaDown + $deltaUp;
    if ($shouldRecord && $deltaTotal > $noiseThresholdPerPoll) {
        $insertDelta->bindValue(':ip', $ip, SQLITE3_TEXT);
        $insertDelta->bindValue(':down', $deltaDown, SQLITE3_INTEGER);
        $insertDelta->bindValue(':up', $deltaUp, SQLITE3_INTEGER);
        $insertDelta->bindValue(':sampled_at', $now, SQLITE3_TEXT);
        $insertDelta->execute();
        $insertDelta->reset();
        $recorded++;
    }

    // Update snapshot
    $upsertSnapshot->bindValue(':ip', $ip, SQLITE3_TEXT);
    $upsertSnapshot->bindValue(':down', $currentDown, SQLITE3_INTEGER);
    $upsertSnapshot->bindValue(':up', $currentUp, SQLITE3_INTEGER);
    $upsertSnapshot->bindValue(':updated_at', $now, SQLITE3_TEXT);
    $upsertSnapshot->execute();
    $upsertSnapshot->reset();
}

$db->exec('COMMIT');

// --- 5. Cleanup old deltas beyond lookback period ---
$cutoff = gmdate('Y-m-d H:i:s', strtotime("-" . ($lookbackDays + 1) . " days"));
$db->exec("DELETE FROM traffic_deltas WHERE sampled_at < '{$cutoff}'");

// Cleanup snapshot entries not seen in 7 days (IP no longer active)
$snapCutoff = gmdate('Y-m-d H:i:s', strtotime("-7 days"));
$db->exec("DELETE FROM traffic_snapshot WHERE updated_at < '{$snapCutoff}'");

$db->close();

$timestamp = date('Y-m-d H:i:s');
$resetMsg = $resets > 0 ? " ({$resets} lqosd restart detections)" : "";
echo "[{$timestamp}] Poll complete: {$recorded} deltas recorded from " . count($unknownIps) . " unknown IPs{$resetMsg}\n";
