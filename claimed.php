<?php
require_once 'config.php';
$dbInitialized = true;
$pdo = null;
try {
    $pdo = get_db_connection();
} catch (Exception $e) { $dbInitialized = false; }

$search = trim($_GET['search'] ?? '');
$statusFilter = 'Claimed'; // Force claimed
$letterFilter = trim($_GET['letter'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;
$assets = [];
$totalPages = 1;

if ($dbInitialized && $pdo) {
    $whereClauses = ["`status` = 'Claimed'"];
    $params = [];
    if ($search !== '') {
        $whereClauses[] = "(`owner_name` LIKE :search OR `id_passport_no` LIKE :search OR `account_number` LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    if ($letterFilter !== '') {
        $whereClauses[] = "`letter_received` = :letter_received";
        $params[':letter_received'] = $letterFilter;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
    $countQuery = $pdo->prepare("SELECT COUNT(*) FROM `unclaimed_assets` $whereSql");
    $countQuery->execute($params);
    $totalPages = ceil($countQuery->fetchColumn() / $limit);

    $stmt = $pdo->prepare("SELECT * FROM `unclaimed_assets` $whereSql ORDER BY `record_id` DESC LIMIT $limit OFFSET $offset");
    foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
    $stmt->execute();
    $assets = $stmt->fetchAll();
}

$activePage = 'claimed';
require_once 'includes/layout.php';
?>

<div class="data-management-card" style="margin-top: 1rem;">
    <h2 style="margin-bottom: 1.5rem; color: var(--airtel-red);">Claimed Assets</h2>
    
    <form method="GET" action="claimed.php" class="filters-row">
        <div class="search-wrapper">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search..." class="search-input">
        </div>
        <div class="filter-controls">
            <select name="letter" class="select-filter">
                <option value="">-- All Letters --</option>
                <option value="Yes" <?= $letterFilter === 'Yes' ? 'selected' : '' ?>>Letter Received</option>
                <option value="No" <?= $letterFilter === 'No' ? 'selected' : '' ?>>No Letter Received</option>
            </select>
            <button type="submit" class="btn-filter">Filter</button>
            <?php if ($search !== '' || $letterFilter !== ''): ?>
                <a href="claimed.php" class="btn-reset">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Owner Name</th>
                    <th>ID / Passport No</th>
                    <th>Account Number</th>
                    <th>Due Amount</th>
                    <th style="text-align: center;">Status</th>
                    <th style="text-align: center;">Letter Received</th>
                    <th>Letter Date &amp; File</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assets)): ?>
                    <tr><td colspan="8"><div class="empty-state"><p>No claimed records found.</p></div></td></tr>
                <?php else: ?>
                    <?php $itemIndex = $offset + 1; foreach ($assets as $asset): ?>
                        <tr id="row-<?= $asset['record_id'] ?>">
                            <td><?= $itemIndex++ ?></td>
                            <td class="col-owner"><?= htmlspecialchars($asset['owner_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($asset['id_passport_no'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($asset['account_number'] ?? '-') ?></td>
                            <td class="col-amount"><?= htmlspecialchars($asset['due_amount'] ?? '-') ?></td>
                            <td style="text-align: center;">
                                <span class="status-badge claimed"><i class="fa-solid fa-circle-check"></i> <span>Claimed</span></span>
                            </td>
                            <td style="text-align: center;">
                                <span class="status-badge letter-<?= strtolower($asset['letter_received']) ?>" onclick="toggleLetterReceived(<?= $asset['record_id'] ?>, '<?= $asset['letter_received'] ?>')">
                                    <?= $asset['letter_received'] === 'Yes' ? '<i class="fa-solid fa-envelope-open-text"></i> <span>Yes</span>' : '<i class="fa-solid fa-envelope"></i> <span>No</span>' ?>
                                </span>
                            </td>
                            <td>
                                <div class="letter-actions">
                                    <div class="date-input-container">
                                        <input type="text" value="<?= htmlspecialchars($asset['letter_date'] ?? '') ?>" data-original="<?= htmlspecialchars($asset['letter_date'] ?? '') ?>" class="date-edit-input" placeholder="Enter Date..." onblur="handleDateBlur(this, <?= $asset['record_id'] ?>)" onkeydown="handleDateKey(event, this, <?= $asset['record_id'] ?>)">
                                        <i class="date-save-indicator fa-solid fa-pen"></i>
                                    </div>
                                    <button class="btn-upload-letter" onclick="document.getElementById('letter-upload-<?= $asset['record_id'] ?>').click()"><i class="fa-solid fa-paperclip"></i></button>
                                    <input type="file" id="letter-upload-<?= $asset['record_id'] ?>" style="display:none;" onchange="uploadLetter(<?= $asset['record_id'] ?>, this)">
                                    <?php if (!empty($asset['letter_file_path'])): ?>
                                        <a href="<?= htmlspecialchars($asset['letter_file_path']) ?>" download class="letter-file-link"><i class="fa-solid fa-download"></i> Download</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/layout_footer.php'; ?>
