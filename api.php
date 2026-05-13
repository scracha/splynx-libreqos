<?php
/**
 * Unknown Service API
 * 
 * Aggregates traffic deltas from the past N days, cross-references
 * against Splynx service data, and returns categorised results.
 *
 * GET ?days=31        (lookback period, default from config)
 * GET ?threshold=50   (MB minimum to show, default from config)
 * GET ?category=all   (unknown|stopped|blocked|unshaped|all)
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$days = max(1, min(90, (int)($_GET['days'] ?? $lookbackDays)));
$thresholdMB = (int)($_GET['threshold'] ?? ($backgroundThresholdBytes / 1024 / 1024));
$thresholdBytes = $thresholdMB * 1024 * 1024;
$categoryFilter = $_GET['category'] ?? 'all';
$validCategories = ['unknown', 'stopped', 'blocked', 'unshaped', 'all'];
if (!in_array($categoryFilter, $validCategories)) $categoryFilter = 'all';

// --- Open DB ---
if (!file_exists($dbPath)) {
    echo json_encode(['error' => 'No traffic data available yet. Poller may not have run.', 'summary' => [], 'results' => []]);
    exit;
}

$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
$db->exec('PRAGMA journal_mode=WAL');

$cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

// --- Load ignored IPs ---
$ignoredIps = [];
$ignoredResult = $db->query("SELECT ip FROM ignored_ips");
if ($ignoredResult) {
    while ($row = $ignoredResult->fetchArray(SQLITE3_ASSOC)) {
        $ignoredIps[$row['ip']] = true;
    }
}
$ignoredCount = count($ignoredIps);

// --- Aggregate deltas per IP ---
$stmt = $db->prepare("SELECT 
    ip,
    SUM(bytes_down) as total_bytes_down,
    SUM(bytes_up) as total_bytes_up,
    MIN(sampled_at) as first_seen,
    MAX(sampled_at) as last_seen,
    COUNT(*) as sample_count
FROM traffic_deltas
WHERE sampled_at >= :cutoff
GROUP BY ip
HAVING (total_bytes_down + total_bytes_up) >= :threshold
ORDER BY (total_bytes_down + total_bytes_up) DESC");

$stmt->bindValue(':cutoff', $cutoff, SQLITE3_TEXT);
$stmt->bindValue(':threshold', $thresholdBytes, SQLITE3_INTEGER);

$result = $stmt->execute();

// --- Load Splynx service data ---
$splynxServices = [];
if (file_exists(DATA_STORE_PATH)) {
    $splynxServices = json_decode(file_get_contents(DATA_STORE_PATH), true) ?? [];
}

// --- Build results with Splynx enrichment ---
$results = [];

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $ip = $row['ip'];
    $totalDown = (int)$row['total_bytes_down'];
    $totalUp = (int)$row['total_bytes_up'];

    // Skip ignored IPs
    if (isset($ignoredIps[$ip])) continue;

    // Categorise against Splynx
    $category = 'unknown';
    $customerName = null;
    $serviceStatus = null;
    $customerStatus = null;
    $customerId = null;
    $serviceId = null;
    $serviceDescription = null;

    if (isset($splynxServices[$ip])) {
        $svc = $splynxServices[$ip];
        $customerName = $svc['customer_name'] ?? null;
        $serviceStatus = $svc['service_status'] ?? null;
        $customerStatus = $svc['customer_status'] ?? null;
        $customerId = $svc['customer_id'] ?? null;
        $serviceId = $svc['service_id'] ?? null;
        $serviceDescription = $svc['service_description'] ?? null;

        $custLower = strtolower($customerStatus ?? '');
        $svcLower = strtolower($serviceStatus ?? '');

        if ($custLower !== '' && $custLower !== 'active') {
            // Any non-active customer status: blocked, inactive, disabled, archived, etc.
            $category = 'blocked';
        } elseif ($svcLower !== '' && $svcLower !== 'active') {
            // Any non-active service status: stopped, disabled, paused, pending, archived, etc.
            $category = 'stopped';
        } else {
            // Active in Splynx but unknown to LibreQoS shaper
            $category = 'unshaped';
        }
    }

    // Apply category filter
    if ($categoryFilter !== 'all' && $category !== $categoryFilter) continue;

    $results[] = [
        'ip' => $ip,
        'total_bytes_down' => $totalDown,
        'total_bytes_up' => $totalUp,
        'total_bytes' => $totalDown + $totalUp,
        'category' => $category,
        'customer_name' => $customerName,
        'customer_id' => $customerId,
        'service_id' => $serviceId,
        'service_description' => $serviceDescription,
        'service_status' => $serviceStatus,
        'customer_status' => $customerStatus,
        'first_seen' => $row['first_seen'],
        'last_seen' => $row['last_seen'],
        'sample_count' => (int)$row['sample_count'],
    ];
}

$db->close();

// --- Summary ---
$summary = [
    'total_ips' => count($results),
    'unknown_count' => count(array_filter($results, fn($r) => $r['category'] === 'unknown')),
    'stopped_count' => count(array_filter($results, fn($r) => $r['category'] === 'stopped')),
    'blocked_count' => count(array_filter($results, fn($r) => $r['category'] === 'blocked')),
    'unshaped_count' => count(array_filter($results, fn($r) => $r['category'] === 'unshaped')),
    'ignored_count' => $ignoredCount,
    'lookback_days' => $days,
    'threshold_mb' => $thresholdMB,
    'data_since' => $cutoff,
];

echo json_encode(['summary' => $summary, 'results' => $results], JSON_PRETTY_PRINT);
