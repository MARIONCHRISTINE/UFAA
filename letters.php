<?php
require_once 'config.php';
$dbInitialized = true;
$pdo = null;
try {
    $pdo = get_db_connection();
} catch (Exception $e) { $dbInitialized = false; }

$ownerNameFilter = trim($_GET['owner_name'] ?? '');
$idNoFilter = trim($_GET['id_no'] ?? '');
$accountNoFilter = trim($_GET['account_no'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$letterFilter = 'Yes'; // Force letters received
$compilationStartFilter = trim($_GET['compilation_start'] ?? '');
$compilationEndFilter = trim($_GET['compilation_end'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;
$assets = [];
$totalPages = 1;

if ($dbInitialized && $pdo) {
    $whereClauses = ["`letter_received` = 'Yes'"];
    $params = [];
    build_multiple_search_clause('owner_name', $ownerNameFilter, $whereClauses, $params, 'owner_name');
    build_multiple_search_clause('id_passport_no', $idNoFilter, $whereClauses, $params, 'id_no');
    build_multiple_search_clause('account_number', $accountNoFilter, $whereClauses, $params, 'account_no');
    if ($statusFilter !== '') {
        $whereClauses[] = "`status` = :status";
        $params[':status'] = $statusFilter;
    }
    if ($compilationStartFilter !== '') {
        $whereClauses[] = "COALESCE(
            STR_TO_DATE(`compilation_date`, '%Y-%m-%d'),
            STR_TO_DATE(`compilation_date`, '%d/%m/%Y'),
            STR_TO_DATE(`compilation_date`, '%d-%m-%Y'),
            STR_TO_DATE(`compilation_date`, '%d-%b-%Y'),
            STR_TO_DATE(`compilation_date`, '%d-%M-%Y'),
            STR_TO_DATE(`compilation_date`, '%d/%b/%Y'),
            STR_TO_DATE(`compilation_date`, '%d/%M/%Y')
        ) >= :compilation_start";
        $params[':compilation_start'] = $compilationStartFilter;
    }
    if ($compilationEndFilter !== '') {
        $whereClauses[] = "COALESCE(
            STR_TO_DATE(`compilation_date`, '%Y-%m-%d'),
            STR_TO_DATE(`compilation_date`, '%d/%m/%Y'),
            STR_TO_DATE(`compilation_date`, '%d-%m-%Y'),
            STR_TO_DATE(`compilation_date`, '%d-%b-%Y'),
            STR_TO_DATE(`compilation_date`, '%d-%M-%Y'),
            STR_TO_DATE(`compilation_date`, '%d/%b/%Y'),
            STR_TO_DATE(`compilation_date`, '%d/%M/%Y')
        ) <= :compilation_end";
        $params[':compilation_end'] = $compilationEndFilter;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
    $countQuery = $pdo->prepare("SELECT COUNT(*) FROM `unclaimed_assets` $whereSql");
    $countQuery->execute($params);
    $totalFiltered = $countQuery->fetchColumn();
    $totalPages = ceil($totalFiltered / $limit);

    $stmt = $pdo->prepare("SELECT * FROM `unclaimed_assets` $whereSql ORDER BY `record_id` DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $val) { $stmt->bindValue($key, $val); }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $assets = $stmt->fetchAll();
}

$activePage = 'letters';
require_once 'includes/layout.php';
?>

<div class="data-management-card" id="records-section" style="margin-top: 1rem;">
    <h2 style="margin-bottom: 1.5rem; color: var(--airtel-red);">Letters Received</h2>
    
    <!-- Advanced Searching & Filtering controls -->
    <form method="GET" action="letters.php#records-section" class="filters-panel">
        <div class="filters-grid">
            <div class="filter-group">
                <label>Owner Name</label>
                <textarea name="owner_name" rows="1" placeholder="Search name(s)... (comma/newline separated)" class="filter-input"><?= htmlspecialchars($ownerNameFilter) ?></textarea>
            </div>
            <div class="filter-group">
                <label>ID / Passport No</label>
                <textarea name="id_no" rows="1" placeholder="Search ID/Passport(s)... (comma/newline separated)" class="filter-input"><?= htmlspecialchars($idNoFilter) ?></textarea>
            </div>
            <div class="filter-group">
                <label>Account Number</label>
                <textarea name="account_no" rows="1" placeholder="Search account(s)... (comma/newline separated)" class="filter-input"><?= htmlspecialchars($accountNoFilter) ?></textarea>
            </div>
            <div class="filter-group">
                <label>Claim Status</label>
                <select name="status" class="filter-input">
                    <option value="">-- All Statuses --</option>
                    <option value="Unclaimed" <?= $statusFilter === 'Unclaimed' ? 'selected' : '' ?>>Unclaimed Only</option>
                    <option value="Claimed" <?= $statusFilter === 'Claimed' ? 'selected' : '' ?>>Claimed Only</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Compilation Start</label>
                <input type="date" name="compilation_start" value="<?= htmlspecialchars($compilationStartFilter) ?>" class="filter-input">
            </div>
            <div class="filter-group">
                <label>Compilation End</label>
                <input type="date" name="compilation_end" value="<?= htmlspecialchars($compilationEndFilter) ?>" class="filter-input">
            </div>
        </div>

        <div class="filters-actions">
            <div class="filters-buttons">
                <button type="submit" class="btn-filter">
                    <i class="fa-solid fa-filter"></i> Apply Filters
                </button>
                <?php if ($ownerNameFilter !== '' || $idNoFilter !== '' || $accountNoFilter !== '' || $statusFilter !== '' || $compilationStartFilter !== '' || $compilationEndFilter !== ''): ?>
                    <a href="letters.php" class="btn-reset">
                        <i class="fa-solid fa-arrows-rotate"></i> Reset
                    </a>
                <?php endif; ?>
            </div>
            <a href="ajax/download_letters_zip.php?owner_name=<?= urlencode($ownerNameFilter) ?>&id_no=<?= urlencode($idNoFilter) ?>&account_no=<?= urlencode($accountNoFilter) ?>&status=<?= urlencode($statusFilter) ?>&compilation_start=<?= urlencode($compilationStartFilter) ?>&compilation_end=<?= urlencode($compilationEndFilter) ?>" class="btn-zip" title="Download matching letters in a ZIP file">
                <i class="fa-solid fa-file-archive"></i> Download Letters ZIP
            </a>
        </div>
    </form>

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th style="width: 50px;">#</th>
                    <th>Owner Name</th>
                    <th>ID / Passport No</th>
                    <th>Account Number</th>
                    <th>Due Amount</th>
                    <th>Compilation Date</th>
                    <th style="text-align: center; width: 130px;">Status</th>
                    <th style="text-align: center; width: 130px;">Letter Received</th>
                    <th style="width: 250px;">Letter Date &amp; File</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assets)): ?>
                    <tr><td colspan="9"><div class="empty-state"><p>No letters received records found matching search or filter parameters.</p></div></td></tr>
                <?php else: ?>
                    <?php $itemIndex = $offset + 1; foreach ($assets as $asset): ?>
                        <tr id="row-<?= $asset['record_id'] ?>">
                            <td><?= $itemIndex++ ?></td>
                            <td class="col-owner"><?= htmlspecialchars($asset['owner_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($asset['id_passport_no'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($asset['account_number'] ?? '-') ?></td>
                            <td class="col-amount"><?= htmlspecialchars($asset['due_amount'] ?? '-') ?></td>
                            <td>
                                <div class="date-input-container">
                                    <input 
                                        type="text" 
                                        value="<?= htmlspecialchars($asset['compilation_date'] ?? '') ?>" 
                                        data-original="<?= htmlspecialchars($asset['compilation_date'] ?? '') ?>" 
                                        data-field="compilation_date"
                                        class="date-edit-input" 
                                        placeholder="Enter Date..." 
                                        onblur="handleDateBlur(this, <?= $asset['record_id'] ?>)" 
                                        onkeydown="handleDateKey(event, this, <?= $asset['record_id'] ?>)"
                                    >
                                    <i class="date-save-indicator fa-solid fa-pen"></i>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <span 
                                    id="badge-status-<?= $asset['record_id'] ?>" 
                                    class="status-badge <?= strtolower($asset['status']) ?>" 
                                    onclick="toggleClaimStatus(<?= $asset['record_id'] ?>, '<?= $asset['status'] ?>')"
                                    title="Click to toggle claim status"
                                >
                                    <?= $asset['status'] === 'Claimed' ? '<i class="fa-solid fa-circle-check"></i> <span>Claimed</span>' : '<i class="fa-solid fa-hourglass-half"></i> <span>Unclaimed</span>' ?>
                                </span>
                            </td>
                            <td style="text-align: center;">
                                <span 
                                    id="badge-letter-<?= $asset['record_id'] ?>" 
                                    class="status-badge letter-yes" 
                                    onclick="toggleLetterReceived(<?= $asset['record_id'] ?>, '<?= $asset['letter_received'] ?>')"
                                    title="Click to toggle letter received"
                                >
                                    <i class="fa-solid fa-envelope-open-text"></i> <span>Yes</span>
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

    <!-- Pagination Controls -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination-row" style="margin-top: 1.5rem;">
            <div class="pagination-info">
                Showing Page <span><?= $page ?></span> of <span><?= $totalPages ?></span> Pages
            </div>
            <div class="pagination-buttons">
                <!-- Prev button -->
                <a href="letters.php?owner_name=<?= urlencode($ownerNameFilter) ?>&id_no=<?= urlencode($idNoFilter) ?>&account_no=<?= urlencode($accountNoFilter) ?>&status=<?= urlencode($statusFilter) ?>&compilation_start=<?= urlencode($compilationStartFilter) ?>&compilation_end=<?= urlencode($compilationEndFilter) ?>&page=<?= max(1, $page - 1) ?>#records-section" 
                   class="btn-page btn-page-nav <?= $page === 1 ? 'disabled' : '' ?>">
                    <i class="fa-solid fa-chevron-left"></i> Previous
                </a>

                <!-- Page Numbers -->
                <?php
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                for ($i = $startPage; $i <= $endPage; $i++): 
                ?>
                    <a href="letters.php?owner_name=<?= urlencode($ownerNameFilter) ?>&id_no=<?= urlencode($idNoFilter) ?>&account_no=<?= urlencode($accountNoFilter) ?>&status=<?= urlencode($statusFilter) ?>&compilation_start=<?= urlencode($compilationStartFilter) ?>&compilation_end=<?= urlencode($compilationEndFilter) ?>&page=<?= $i ?>#records-section" 
                       class="btn-page <?= $i === $page ? 'active' : '' ?>">
                        <?= $i ?>
                    </a>
                <?php endfor; ?>

                <!-- Next button -->
                <a href="letters.php?owner_name=<?= urlencode($ownerNameFilter) ?>&id_no=<?= urlencode($idNoFilter) ?>&account_no=<?= urlencode($accountNoFilter) ?>&status=<?= urlencode($statusFilter) ?>&compilation_start=<?= urlencode($compilationStartFilter) ?>&compilation_end=<?= urlencode($compilationEndFilter) ?>&page=<?= min($totalPages, $page + 1) ?>#records-section" 
                   class="btn-page btn-page-nav <?= $page === $totalPages ? 'disabled' : '' ?>">
                    Next <i class="fa-solid fa-chevron-right"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/layout_footer.php'; ?>
