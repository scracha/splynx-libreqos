<?php
/**
 * Ignore list management for unknown service tracker.
 * 
 * GET  ?action=list              — returns all ignored IPs with notes
 * POST {action:"ignore", ip, note}  — add IP to ignore list
 * POST {action:"unignore", ip}      — remove IP from ignore list
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db = new SQLite3($dbPath);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('CREATE TABLE IF NOT EXISTS ignored_ips (
    ip TEXT PRIMARY KEY,
    note TEXT,
    ignored_at TEXT NOT NULL
)');

$db->exec('CREATE TABLE IF NOT EXISTS ip_notes (
    ip TEXT PRIMARY KEY,
    note TEXT NOT NULL,
    updated_at TEXT NOT NULL
)');

// --- GET: list ignored IPs ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    if ($action === 'list') {
        // Also fetch total traffic and last seen for each ignored IP from deltas
        $results = [];
        $stmt = $db->query("SELECT ip, note, ignored_at FROM ignored_ips ORDER BY ignored_at DESC");
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            // Get traffic total and last seen for this IP
            $trafficStmt = $db->prepare("SELECT SUM(bytes_down + bytes_up) as total_bytes, MAX(sampled_at) as last_seen FROM traffic_deltas WHERE ip = :ip");
            $trafficStmt->bindValue(':ip', $row['ip'], SQLITE3_TEXT);
            $trafficResult = $trafficStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $row['total_bytes'] = (int)($trafficResult['total_bytes'] ?? 0);
            $row['last_seen'] = $trafficResult['last_seen'] ?? null;
            $results[] = $row;
        }
        echo json_encode($results);
        exit;
    }

    if ($action === 'get_note') {
        $ip = $_GET['ip'] ?? '';
        $stmt = $db->prepare('SELECT note FROM ip_notes WHERE ip = :ip');
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        echo json_encode(['ip' => $ip, 'note' => $result['note'] ?? '']);
        exit;
    }

    if ($action === 'all_notes') {
        $notes = [];
        $stmt = $db->query("SELECT ip, note FROM ip_notes");
        while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
            $notes[$row['ip']] = $row['note'];
        }
        echo json_encode($notes);
        exit;
    }

    // Return count only
    $count = $db->querySingle("SELECT COUNT(*) FROM ignored_ips");
    echo json_encode(['count' => (int)$count]);
    exit;
}

// --- POST: ignore/unignore ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $ip = $input['ip'] ?? '';

    if (empty($ip)) {
        http_response_code(400);
        echo json_encode(['error' => 'IP required']);
        exit;
    }

    if ($action === 'ignore') {
        $note = $input['note'] ?? '';
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare('INSERT OR REPLACE INTO ignored_ips (ip, note, ignored_at) VALUES (:ip, :note, :ignored_at)');
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $stmt->bindValue(':note', $note, SQLITE3_TEXT);
        $stmt->bindValue(':ignored_at', $now, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['success' => true, 'ip' => $ip]);
    } elseif ($action === 'unignore') {
        $stmt = $db->prepare('DELETE FROM ignored_ips WHERE ip = :ip');
        $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
        $stmt->execute();
        echo json_encode(['success' => true, 'ip' => $ip]);
    } elseif ($action === 'save_note') {
        $note = trim($input['note'] ?? '');
        $now = gmdate('Y-m-d H:i:s');
        if ($note === '') {
            // Empty note = delete
            $stmt = $db->prepare('DELETE FROM ip_notes WHERE ip = :ip');
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->execute();
        } else {
            $stmt = $db->prepare('INSERT OR REPLACE INTO ip_notes (ip, note, updated_at) VALUES (:ip, :note, :updated_at)');
            $stmt->bindValue(':ip', $ip, SQLITE3_TEXT);
            $stmt->bindValue(':note', $note, SQLITE3_TEXT);
            $stmt->bindValue(':updated_at', $now, SQLITE3_TEXT);
            $stmt->execute();
        }
        echo json_encode(['success' => true, 'ip' => $ip]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
    }
    exit;
}
