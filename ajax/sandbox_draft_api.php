<?php
/**
 * UFAA – Sandbox Collaborative Drafts, Live Sync & Presence API
 * Compatible with PHP 7.0+ (standard function syntax).
 */

ob_start();
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ini_set('display_errors', '0');

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

            $rowsArr = json_decode($d['rows_data'] ?? '[]', true) ?: [];
            $d['approx_rows'] = count($rowsArr);

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

    /* GET_LATEST ───────────────────────────────────────── */
    if ($action === 'get_latest') {
        $stmt  = $pdo->query("SELECT * FROM `sandbox_drafts` WHERE `status` = 'active' ORDER BY `updated_at` DESC LIMIT 1");
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');

        if (!$draft) { echo json_encode(['status' => 'none']); exit; }

        $rows     = json_decode($draft['rows_data'], true)    ?: [];
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

    /* PATCH_CELL ───────────────────────────────────────── */
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

        $rows = json_decode($draft['rows_data'], true) ?: [];
        if (isset($rows[$rowIdx])) {
            $rows[$rowIdx][$field] = $newValue;
            $pdo->prepare("UPDATE `sandbox_drafts` SET `rows_data` = ?, `last_edited_by` = ?, `updated_at` = NOW() WHERE `id` = ?")
                ->execute([json_encode($rows), $currentUser, $draftId]);
        }

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'updated_by' => $currentUser]);
        exit;
    }

    /* SAVE ─────────────────────────────────────────────── */
    if ($action === 'save') {
        $draftId   = (int)($data['draft_id'] ?? 0);
        $draftName = trim((string)($data['draft_name'] ?? 'Untitled Draft'));
        if ($draftName === '') $draftName = 'Working Draft';
        $rowsData  = json_encode($data['rows'] ?? []);

        if ($rowsData === false) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Failed to encode rows data.']);
            exit;
        }

        if ($draftId > 0) {
            $stmt = $pdo->prepare("UPDATE `sandbox_drafts` SET `rows_data` = ?, `last_edited_by` = ?, `updated_at` = NOW() WHERE `id` = ? AND `status` = 'active'");
            $stmt->execute([$rowsData, $currentUser, $draftId]);
            if ($stmt->rowCount() === 0) {
                $draftId = 0;
            }
        }

        if ($draftId === 0) {
            $pdo->prepare("INSERT INTO `sandbox_drafts` (`draft_name`, `created_by`, `last_edited_by`, `rows_data`, `status`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, 'active', NOW(), NOW())")
                ->execute([$draftName, $currentUser, $currentUser, $rowsData]);
            $draftId = (int)$pdo->lastInsertId();
        }

        if (!$draftId) {
            if (ob_get_length()) ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Failed to save draft to database.']);
            exit;
        }

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'success', 'draft_id' => $draftId, 'draft_name' => $draftName, 'updated_at' => date('d M Y, H:i')]);
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

        $rows     = json_decode($draft['rows_data'],    true) ?: [];
        $presence = parsePresence(json_decode($draft['presence_data'] ?? '[]', true) ?: [], 10, $currentUser);
        $viewers  = parsePresence(json_decode($draft['viewer_data']   ?? '[]', true) ?: [], 15, $currentUser);

        $presence = array_values(array_filter($presence, function($p) {
            return empty($p['is_self']);
        }));

        if (ob_get_length()) ob_clean();
        header('Content-Type: application/json');
        $isOwner = ($draft['created_by'] === $currentUser || strtolower($_SESSION['admin_user']['role'] ?? $_SESSION['role'] ?? '') === 'admin');
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
