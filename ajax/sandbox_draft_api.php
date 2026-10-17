<?php
/**
 * UFAA – Sandbox Collaborative Drafts, Live Sync & Presence API
 * Optimized for multi-million and large-scale (up to 500k rows) datasets.
 * Features chunked draft saving, file-backed disk caching to prevent MySQL
 * max_allowed_packet errors, lightweight polling, and fast cell patching.
 */

ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');
ini_set('memory_limit', '1024M');
set_time_limit(300);

require_once __DIR__ . '/../config.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$pdo = get_db_connection();
if (!$pdo) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    exit;
}

// ── Self-healing schema bootstrap ─────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `sandbox_drafts` (
            `id`             INT AUTO_INCREMENT PRIMARY KEY,
            `draft_name`     VARCHAR(255) NOT NULL,
            `created_by`     VARCHAR(100) NOT NULL,
            `last_edited_by` VARCHAR(100) NOT NULL,
            `rows_data`      LONGTEXT NOT NULL,
            `presence_data`  TEXT NULL,
            `viewer_data`    TEXT NULL,
            `status`         VARCHAR(20) NOT NULL DEFAULT 'active',
            `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $existingCols = $pdo->query("SHOW COLUMNS FROM `sandbox_drafts`")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('presence_data', $existingCols)) {
        try { $pdo->exec("ALTER TABLE `sandbox_drafts` ADD COLUMN `presence_data` TEXT NULL AFTER `rows_data`"); } catch (Exception $_e) {}
    }
    if (!in_array('viewer_data', $existingCols)) {
        try { $pdo->exec("ALTER TABLE `sandbox_drafts` ADD COLUMN `viewer_data` TEXT NULL AFTER `presence_data`"); } catch (Exception $_e) {}
    }
    if (!in_array('flagged_reason', $existingCols)) {
        try { $pdo->exec("ALTER TABLE `sandbox_drafts` ADD COLUMN `flagged_reason` TEXT NULL AFTER `status`"); } catch (Exception $_e) {}
    }
} catch (Exception $_ex) { /* silent */ }

// Helper for file storage directory
$draftsDir = __DIR__ . '/../uploads/drafts';
if (!is_dir($draftsDir)) {
    @mkdir($draftsDir, 0777, true);
}

function getDraftJsonPath(int $draftId): string {
    global $draftsDir;
    return $draftsDir . '/draft_' . $draftId . '.json';
}

function storeDraftRowsOnDisk(int $draftId, array $rows): bool {
    $path = getDraftJsonPath($draftId);
    $json = json_encode($rows, JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

function fetchDraftRows(int $draftId, string $dbRowsData): array {
    $path = getDraftJsonPath($draftId);
    if (file_exists($path)) {
        $content = @file_get_contents($path);
        if ($content !== false && $content !== '') {
            $arr = json_decode($content, true);
            if (is_array($arr)) return $arr;
        }
    }
    return json_decode($dbRowsData, true) ?: [];
}

$currentUser = $_SESSION['admin_user']['username'] ?? $_SESSION['username'] ?? 'Officer';
$action      = $_GET['action'] ?? $_POST['action'] ?? 'list';

$rawInput = file_get_contents('php://input');
$data     = json_decode($rawInput, true) ?: [];

function getUserColor(string $user): string {
    $colors = ['#1d4ed8', '#0369a1', '#0f766e', '#4338ca', '#6d28d9', '#9333ea', '#c2410c', '#b45309'];
    return $colors[abs(crc32($user)) % count($colors)];
}

function getUserInitials(string $user): string {
    $parts = explode(' ', trim($user));
    if (count($parts) >= 2) {
        return strtoupper($parts[0][0] . $parts[1][0]);
    }
    return strtoupper(substr($user, 0, 2));
}

function parsePresence(array $presenceMap, int $ttl, string $currentUser): array {
    $now    = time();
    $result = [];
    foreach ($presenceMap as $p) {
        if (isset($p['user'], $p['ts']) && ($now - $p['ts'] < $ttl)) {
            $p['is_self'] = ($p['user'] === $currentUser);
            $result[]     = $p;
        }
    }
    return $result;
}

try {

    /* LIST ─────────────────────────────────────────────── */
    if ($action === 'list') {
        $stmt = $pdo->query("
            SELECT `id`, `draft_name`, `created_by`, `last_edited_by`, `updated_at`, `status`, `flagged_reason`,
                   COALESCE(`viewer_data`, '[]') AS viewer_data,
                   COALESCE(`presence_data`, '[]') AS presence_data,
                   `rows_data`
            FROM `sandbox_drafts`
            WHERE `status` IN ('active', 'flagged')
            ORDER BY `updated_at` DESC
            LIMIT 30
        ");
        $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $now = time();
        foreach ($drafts as &$d) {
            $viewers  = json_decode($d['viewer_data'] ?? '[]', true) ?: [];
            $presence = json_decode($d['presence_data'] ?? '[]', true) ?: [];

            $d['viewer_count'] = count(array_filter($viewers, function($v) use ($now) {
                return isset($v['ts']) && ($now - $v['ts'] < 15);
            }));

            $d['editor_count'] = count(array_filter($presence, function($p) use ($now) {
                return isset($p['ts']) && ($now - $p['ts'] < 10);
            }));

            // Fast row count estimation without decompressing huge JSON strings
            $draftId = (int)$d['id'];
            $diskPath = getDraftJsonPath($draftId);
            if (file_exists($diskPath)) {
                $fileContent = @file_get_contents($diskPath);
                $rowsArr = json_decode($fileContent, true) ?: [];
                $d['approx_rows'] = count($rowsArr);
            } elseif (preg_match('/^\[file_stored:(\d+)\]/', (string)$d['rows_data'], $m)) {
                $d['approx_rows'] = (int)$m[1];
            } else {
                $rowsArr = json_decode($d['rows_data'] ?? '[]', true) ?: [];
                $d['approx_rows'] = count($rowsArr);
            }

            unset($d['rows_data']);
            unset($d['viewer_data']);
            unset($d['presence_data']);
        }
        unset($d);

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'drafts' => $drafts]);
        exit;
    }

    /* POLL_SYNC (Lightweight Real-time Status Polling) ─── */
    if ($action === 'poll_sync') {
        $draftId = (int)($_GET['draft_id'] ?? $data['draft_id'] ?? 0);
        if (!$draftId) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Missing draft_id']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT `id`, `status`, `created_by`, `last_edited_by`, `updated_at`, `flagged_reason`, `presence_data`, `viewer_data` FROM `sandbox_drafts` WHERE `id` = ?");
        $stmt->execute([$draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$draft || (isset($draft['status']) && !in_array($draft['status'], ['active', 'flagged']))) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'status'       => 'closed',
                'message'      => 'This working file has been submitted and locked by an officer.',
                'submitted_by' => $draft['last_edited_by'] ?? 'another officer'
            ]);
            exit;
        }

        $presence = parsePresence(json_decode($draft['presence_data'] ?? '[]', true) ?: [], 10, $currentUser);
        $viewers  = parsePresence(json_decode($draft['viewer_data']   ?? '[]', true) ?: [], 15, $currentUser);
        $presence = array_values(array_filter($presence, function($p) { return empty($p['is_self']); }));

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status'         => 'success',
            'draft_id'       => (int)$draft['id'],
            'draft_status'   => $draft['status'],
            'flagged_reason' => $draft['flagged_reason'] ?? '',
            'last_edited_by' => $draft['last_edited_by'],
            'updated_ts'     => strtotime($draft['updated_at']),
            'presence'       => $presence,
            'viewers'        => $viewers,
            'current_user'   => $currentUser
        ]);
        exit;
    }

    /* GET_LATEST ───────────────────────────────────────── */
    if ($action === 'get_latest') {
        $stmt  = $pdo->query("SELECT * FROM `sandbox_drafts` WHERE `status` = 'active' ORDER BY `updated_at` DESC LIMIT 1");
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');

        if (!$draft) { echo json_encode(['status' => 'none']); exit; }

        $rows     = fetchDraftRows((int)$draft['id'], $draft['rows_data'] ?? '');
        $presence = parsePresence(json_decode($draft['presence_data'] ?? '[]', true) ?: [], 10, $currentUser);
        $viewers  = parsePresence(json_decode($draft['viewer_data']   ?? '[]', true) ?: [], 15, $currentUser);

        echo json_encode([
            'status'         => 'success',
            'draft_id'       => (int)$draft['id'],
            'draft_name'     => $draft['draft_name'],
            'created_by'     => $draft['created_by'],
            'last_edited_by' => $draft['last_edited_by'],
            'updated_at'     => date('d M Y, H:i', strtotime($draft['updated_at'])),
            'updated_ts'     => strtotime($draft['updated_at']),
            'rows'           => $rows,
            'presence'       => $presence,
            'viewers'        => $viewers,
            'current_user'   => $currentUser,
        ]);
        exit;
    }

    /* HEARTBEAT ────────────────────────────────────────── */
    if ($action === 'heartbeat') {
        $draftId = (int)($data['draft_id'] ?? 0);
        if (!$draftId) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Missing draft_id']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT `viewer_data` FROM `sandbox_drafts` WHERE `id` = ?");
        $stmt->execute([$draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Draft not found']);
            exit;
        }

        $viewers = json_decode($draft['viewer_data'] ?? '[]', true) ?: [];
        $now     = time();

        $viewers = array_values(array_filter($viewers, function($v) use ($now, $currentUser) {
            return isset($v['user'], $v['ts']) && ($now - $v['ts'] < 15) && $v['user'] !== $currentUser;
        }));

        $viewers[] = [
            'user'     => $currentUser,
            'initials' => getUserInitials($currentUser),
            'color'    => getUserColor($currentUser),
            'ts'       => $now,
        ];

        $pdo->prepare("UPDATE `sandbox_drafts` SET `viewer_data` = ? WHERE `id` = ?")
            ->execute([json_encode($viewers), $draftId]);

        $activeViewers = parsePresence($viewers, 15, $currentUser);

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'viewers' => $activeViewers]);
        exit;
    }

    /* UPDATE_PRESENCE ──────────────────────────────────── */
    if ($action === 'update_presence') {
        $draftId = (int)($data['draft_id'] ?? 0);
        $rowIdx  = isset($data['row_index']) ? (int)$data['row_index'] : -1;
        $field   = trim((string)($data['field'] ?? ''));

        if (!$draftId) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Missing draft_id']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT `presence_data` FROM `sandbox_drafts` WHERE `id` = ?");
        $stmt->execute([$draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Draft not found']);
            exit;
        }

        $presenceMap = json_decode($draft['presence_data'] ?? '[]', true) ?: [];
        $now         = time();

        $clean = array_values(array_filter($presenceMap, function($p) use ($now, $currentUser) {
            return isset($p['user'], $p['ts']) && ($now - $p['ts'] < 10) && $p['user'] !== $currentUser;
        }));

        if ($rowIdx >= 0 && $field !== '') {
            $clean[] = [
                'user'     => $currentUser,
                'initials' => getUserInitials($currentUser),
                'color'    => getUserColor($currentUser),
                'row'      => $rowIdx,
                'field'    => $field,
                'ts'       => $now,
            ];
        }

        $pdo->prepare("UPDATE `sandbox_drafts` SET `presence_data` = ? WHERE `id` = ?")
            ->execute([json_encode($clean), $draftId]);

        $others = array_values(array_filter($clean, function($p) use ($currentUser) {
            return $p['user'] !== $currentUser;
        }));

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'presence' => $others]);
        exit;
    }

    /* PATCH_CELL (Fast Single-Cell Update) ─────────────── */
    if ($action === 'patch_cell') {
        $draftId  = (int)($data['draft_id'] ?? 0);
        $rowIdx   = (int)($data['row_index'] ?? -1);
        $field    = trim((string)($data['field'] ?? ''));
        $newValue = trim((string)($data['val'] ?? ''));

        if (!$draftId || $rowIdx < 0 || $field === '') {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid patch parameters']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT `rows_data` FROM `sandbox_drafts` WHERE `id` = ? AND `status` = 'active'");
        $stmt->execute([$draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Draft not found']);
            exit;
        }

        $rows = fetchDraftRows($draftId, $draft['rows_data'] ?? '');
        if (isset($rows[$rowIdx])) {
            $rows[$rowIdx][$field] = $newValue;
            storeDraftRowsOnDisk($draftId, $rows);

            // Update timestamp & last editor in DB
            $pdo->prepare("UPDATE `sandbox_drafts` SET `last_edited_by` = ?, `updated_at` = NOW() WHERE `id` = ?")
                ->execute([$currentUser, $draftId]);
        }

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'updated_by' => $currentUser]);
        exit;
    }

    /* DELETE_ROW (Remove a single row, real-time) ───────── */
    if ($action === 'delete_row') {
        $draftId = (int)($data['draft_id'] ?? 0);
        $rowIdx  = isset($data['row_index']) ? (int)$data['row_index'] : -1;

        if (!$draftId || $rowIdx < 0) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Invalid delete_row parameters']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT `rows_data` FROM `sandbox_drafts` WHERE `id` = ? AND `status` = 'active'");
        $stmt->execute([$draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Draft not found']);
            exit;
        }

        $rows = fetchDraftRows($draftId, $draft['rows_data'] ?? '');
        if (isset($rows[$rowIdx])) {
            array_splice($rows, $rowIdx, 1);
            storeDraftRowsOnDisk($draftId, $rows);

            $totalRows   = count($rows);
            $rowsDataForDb = ($totalRows > 0)
                ? ('[file_stored:' . $totalRows . ']')
                : '[]';

            $pdo->prepare("UPDATE `sandbox_drafts` SET `rows_data` = ?, `last_edited_by` = ?, `updated_at` = NOW() WHERE `id` = ?")
                ->execute([$rowsDataForDb, $currentUser, $draftId]);
        }

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'total_rows' => count($rows)]);
        exit;
    }


    /* SAVE_CHUNK (Safe Upload for Up to 500k Rows) ──────── */
    if ($action === 'save_chunk') {
        $draftId     = (int)($data['draft_id'] ?? 0);
        $draftName   = trim((string)($data['draft_name'] ?? 'Working Draft'));
        if ($draftName === '') $draftName = 'Working Draft';
        $chunkIndex  = (int)($data['chunk_index'] ?? 0);
        $totalChunks = (int)($data['total_chunks'] ?? 1);
        $chunkRows   = (array)($data['rows'] ?? []);

        if ($chunkIndex === 0 && $draftId === 0) {
            $initialTag = '[file_stored:0]';
            $stmt = $pdo->prepare("INSERT INTO `sandbox_drafts` (`draft_name`, `created_by`, `last_edited_by`, `rows_data`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, 'active', NOW(), NOW())");
            $stmt->execute([$draftName, $currentUser, $currentUser, $initialTag]);
            $draftId = (int)$pdo->lastInsertId();
        }

        if (!$draftId) {
            throw new Exception("Unable to initialize draft session.");
        }

        $tmpFile = $draftsDir . '/tmp_draft_' . $draftId . '.jsonl';
        if ($chunkIndex === 0 && file_exists($tmpFile)) {
            @unlink($tmpFile);
        }

        // Append rows to temporary JSONL file
        $fp = fopen($tmpFile, 'a');
        if ($fp) {
            foreach ($chunkRows as $r) {
                fwrite($fp, json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
            }
            fclose($fp);
        }

        // On final chunk, convert JSONL to standard JSON array
        if ($chunkIndex >= $totalChunks - 1) {
            $allRows = [];
            if (file_exists($tmpFile)) {
                $lines = file($tmpFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $item = json_decode($line, true);
                    if ($item !== null) {
                        $allRows[] = $item;
                    }
                }
                @unlink($tmpFile);
            }

            storeDraftRowsOnDisk($draftId, $allRows);
            $totalCount = count($allRows);
            $dbTag = '[file_stored:' . $totalCount . ']';

            $pdo->prepare("UPDATE `sandbox_drafts` SET `rows_data` = ?, `last_edited_by` = ?, `updated_at` = NOW() WHERE `id` = ?")
                ->execute([$dbTag, $currentUser, $draftId]);

            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'status'     => 'success',
                'draft_id'   => $draftId,
                'draft_name' => $draftName,
                'total_rows' => $totalCount,
                'is_final'   => true
            ]);
            exit;
        }

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status'      => 'chunk_saved',
            'draft_id'    => $draftId,
            'chunk_index' => $chunkIndex,
            'is_final'    => false
        ]);
        exit;
    }

    /* SAVE ─────────────────────────────────────────────── */
    if ($action === 'save') {
        $draftId   = (int)($data['draft_id'] ?? 0);
        $draftName = trim((string)($data['draft_name'] ?? 'Untitled Draft'));
        if ($draftName === '') $draftName = 'Working Draft';
        $rows      = (array)($data['rows'] ?? []);
        $totalRows = count($rows);

        if ($draftId > 0) {
            $stmt = $pdo->prepare("UPDATE `sandbox_drafts` SET `draft_name` = ?, `last_edited_by` = ?, `updated_at` = NOW() WHERE `id` = ? AND `status` = 'active'");
            $stmt->execute([$draftName, $currentUser, $draftId]);
            if ($stmt->rowCount() === 0) {
                // If ID does not exist, insert fresh
                $draftId = 0;
            }
        }

        if ($draftId === 0) {
            $dbTag = '[file_stored:' . $totalRows . ']';
            $pdo->prepare("INSERT INTO `sandbox_drafts` (`draft_name`, `created_by`, `last_edited_by`, `rows_data`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, 'active', NOW(), NOW())")
                ->execute([$draftName, $currentUser, $currentUser, $dbTag]);
            $draftId = (int)$pdo->lastInsertId();
        }

        if (!$draftId) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Failed to save draft session.']);
            exit;
        }

        // Store rows on disk
        storeDraftRowsOnDisk($draftId, $rows);

        // Keep lightweight tag or inline if small (< 2MB)
        $jsonStr = json_encode($rows, JSON_UNESCAPED_UNICODE);
        $rowsDataForDb = (strlen($jsonStr) < 2000000) ? $jsonStr : ('[file_stored:' . $totalRows . ']');
        $pdo->prepare("UPDATE `sandbox_drafts` SET `rows_data` = ?, `last_edited_by` = ?, `updated_at` = NOW() WHERE `id` = ?")
            ->execute([$rowsDataForDb, $currentUser, $draftId]);

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status'     => 'success',
            'draft_id'   => $draftId,
            'draft_name' => $draftName,
            'total_rows' => $totalRows,
            'updated_at' => date('d M Y, H:i')
        ]);
        exit;
    }

    /* GET ──────────────────────────────────────────────── */
    if ($action === 'get') {
        $draftId = (int)($_GET['draft_id'] ?? $data['draft_id'] ?? 0);
        if (!$draftId) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Missing draft_id']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM `sandbox_drafts` WHERE `id` = ?");
        $stmt->execute([$draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$draft || (isset($draft['status']) && !in_array($draft['status'], ['active', 'flagged']))) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'status'       => 'closed',
                'message'      => 'This working file has been submitted and locked by an officer.',
                'submitted_by' => $draft['last_edited_by'] ?? 'another officer'
            ]);
            exit;
        }

        $presence = parsePresence(json_decode($draft['presence_data'] ?? '[]', true) ?: [], 10, $currentUser);
        $viewers  = parsePresence(json_decode($draft['viewer_data']   ?? '[]', true) ?: [], 15, $currentUser);
        $presence = array_values(array_filter($presence, function($p) { return empty($p['is_self']); }));

        $isOwner = ($draft['created_by'] === $currentUser || strtolower($_SESSION['admin_user']['role'] ?? $_SESSION['role'] ?? '') === 'admin');

        // Check if caller only wants summary info
        if (!empty($_GET['summary_only'])) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'status'         => 'success',
                'draft_id'       => (int)$draft['id'],
                'draft_name'     => $draft['draft_name'],
                'created_by'     => $draft['created_by'],
                'is_owner'       => $isOwner,
                'draft_status'   => $draft['status'],
                'flagged_reason' => $draft['flagged_reason'] ?? '',
                'last_edited_by' => $draft['last_edited_by'],
                'updated_at'     => date('d M Y, H:i', strtotime($draft['updated_at'])),
                'updated_ts'     => strtotime($draft['updated_at']),
                'presence'       => $presence,
                'viewers'        => $viewers,
                'current_user'   => $currentUser,
            ]);
            exit;
        }

        $rows = fetchDraftRows((int)$draft['id'], $draft['rows_data'] ?? '');

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'status'         => 'success',
            'draft_id'       => (int)$draft['id'],
            'draft_name'     => $draft['draft_name'],
            'created_by'     => $draft['created_by'],
            'is_owner'       => $isOwner,
            'draft_status'   => $draft['status'],
            'flagged_reason' => $draft['flagged_reason'] ?? '',
            'last_edited_by' => $draft['last_edited_by'],
            'updated_at'     => date('d M Y, H:i', strtotime($draft['updated_at'])),
            'updated_ts'     => strtotime($draft['updated_at']),
            'rows'           => $rows,
            'total_rows'     => count($rows),
            'presence'       => $presence,
            'viewers'        => $viewers,
            'current_user'   => $currentUser,
        ]);
        exit;
    }

    /* DELETE ───────────────────────────────────────────── */
    if ($action === 'delete') {
        $draftId = (int)($_POST['draft_id'] ?? $data['draft_id'] ?? 0);
        if ($draftId) {
            $pdo->prepare("DELETE FROM `sandbox_drafts` WHERE `id` = ?")->execute([$draftId]);
            $diskPath = getDraftJsonPath($draftId);
            if (file_exists($diskPath)) {
                @unlink($diskPath);
            }
        }
        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
        exit;
    }

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
