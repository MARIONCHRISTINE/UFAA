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
$amountFilter = trim($_GET['amount'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$letterFilter = trim($_GET['letter_filter'] ?? 'all'); // 'all', 'generated', 'uploaded'
$compilationStartFilter = trim($_GET['compilation_start'] ?? '');
$compilationEndFilter = trim($_GET['compilation_end'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 100;
$offset = ($page - 1) * $limit;
$assets = [];
$totalPages = 1;
$totalFiltered = 0;

if ($dbInitialized && $pdo) {
    $whereClauses = ["(`letter_generated` = 'Yes' OR `letter_received` = 'Yes' OR `letter_file_path` IS NOT NULL)"];
    $params = [];

    if ($ownerNameFilter !== '') {
        $whereClauses[] = "`owner_name` IS NOT NULL AND TRIM(`owner_name`) != ''";
        build_multiple_search_clause('owner_name', $ownerNameFilter, $whereClauses, $params, 'owner_name');
    }
    if ($idNoFilter !== '') {
        $whereClauses[] = "`id_passport_no` IS NOT NULL AND TRIM(`id_passport_no`) != ''";
        build_multiple_search_clause('id_passport_no', $idNoFilter, $whereClauses, $params, 'id_no');
    }
    if ($accountNoFilter !== '') {
        $whereClauses[] = "`account_number` IS NOT NULL AND TRIM(`account_number`) != ''";
        build_multiple_search_clause('account_number', $accountNoFilter, $whereClauses, $params, 'account_no');
    }
    if ($amountFilter !== '') {
        $whereClauses[] = "`due_amount` LIKE :amount_filter";
        $params[':amount_filter'] = '%' . $amountFilter . '%';
    }
    if ($statusFilter !== '') {
        $whereClauses[] = "`status` = :status";
        $params[':status'] = $statusFilter;
    }
    if ($compilationStartFilter !== '') {
        $whereClauses[] = "`compilation_date` >= :compilation_start";
        $params[':compilation_start'] = $compilationStartFilter;
    }
    if ($compilationEndFilter !== '') {
        $whereClauses[] = "`compilation_date` <= :compilation_end";
        $params[':compilation_end'] = $compilationEndFilter;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
    $countQuery = $pdo->prepare("SELECT COUNT(*) FROM `unclaimed_assets` $whereSql");
    $countQuery->execute($params);
    $totalFiltered = $countQuery->fetchColumn();
    $totalPages = ceil($totalFiltered / $limit);

    $stmt = $pdo->prepare("SELECT * FROM `unclaimed_assets` $whereSql ORDER BY `owner_name` IS NULL ASC, `owner_name` ASC LIMIT :limit OFFSET :offset");
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
    <h2 style="margin-bottom: 1.5rem; color: var(--airtel-red); display:flex; align-items:center; gap:0.6rem;">
        <i class="fa-solid fa-file-pdf"></i> Letters Issued Hub
    </h2>
    
    <!-- Advanced Searching & Filtering controls -->
    <form method="GET" action="letters.php#records-section" class="filters-panel">
        <div class="filters-grid">
            <div class="filter-group">
                <label>Owner Name</label>
                <textarea name="owner_name" rows="1" placeholder="Search name(s)..." class="filter-input"><?= htmlspecialchars($ownerNameFilter) ?></textarea>
            </div>
            <div class="filter-group">
                <label>ID / Passport No</label>
                <textarea name="id_no" rows="1" placeholder="Search ID/Passport(s)..." class="filter-input"><?= htmlspecialchars($idNoFilter) ?></textarea>
            </div>
            <div class="filter-group">
                <label>Account Number</label>
                <textarea name="account_no" rows="1" placeholder="Search account(s)..." class="filter-input"><?= htmlspecialchars($accountNoFilter) ?></textarea>
            </div>
            <div class="filter-group">
                <label>Amount</label>
                <input type="text" name="amount" placeholder="e.g. 5000" value="<?= htmlspecialchars($amountFilter) ?>" class="filter-input">
            </div>
            <div class="filter-group">
                <label>Claim Stage</label>
                <select name="status" class="filter-input">
                    <option value="">-- All Stages --</option>
                    <option value="Unclaimed" <?= $statusFilter === 'Unclaimed' ? 'selected' : '' ?>>Unclaimed</option>
                    <option value="Letter Generated" <?= $statusFilter === 'Letter Generated' ? 'selected' : '' ?>>Letter Issued</option>
                    <option value="Submitted" <?= $statusFilter === 'Submitted' ? 'selected' : '' ?>>Submitted to UFAA</option>
                    <option value="Claimed" <?= $statusFilter === 'Claimed' ? 'selected' : '' ?>>Claimed</option>
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
                    <th style="text-align: center; width: 140px;">Claim Stage</th>
                    <th style="text-align: center; width: 160px;">Generated Letter PDF</th>
                    <th style="width: 220px;">Stamped Copy &amp; Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assets)): ?>
                    <tr><td colspan="9"><div class="empty-state"><p>No holder letter records found matching search or filter parameters.</p></div></td></tr>
                <?php else: ?>
                    <?php $itemIndex = $offset + 1; foreach ($assets as $asset): ?>
                        <tr id="row-<?= $asset['record_id'] ?>">
                            <td><?= $itemIndex++ ?></td>
                            <td class="col-owner"><?= htmlspecialchars($asset['owner_name'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($asset['id_passport_no'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($asset['account_number'] ?? '-') ?></td>
                            <td class="col-amount"><?= htmlspecialchars($asset['due_amount'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($asset['compilation_date'] ?? '-') ?></td>
                            
                            <!-- Claim Stage -->
                            <td style="text-align: center;">
                                <span 
                                    id="badge-status-<?= $asset['record_id'] ?>" 
                                    class="status-badge <?= strtolower(str_replace(' ', '-', $asset['status'])) ?>" 
                                    onclick="toggleClaimStatus(<?= $asset['record_id'] ?>, '<?= $asset['status'] ?>')"
                                    title="Click to toggle claim status"
                                >
                                    <?php if ($asset['status'] === 'Claimed'): ?>
                                        <i class="fa-solid fa-circle-check"></i> <span>Claimed</span>
                                    <?php elseif ($asset['status'] === 'Letter Generated'): ?>
                                        <i class="fa-solid fa-file-signature"></i> <span>Letter Issued</span>
                                    <?php elseif ($asset['status'] === 'Submitted'): ?>
                                        <i class="fa-solid fa-paper-plane"></i> <span>Submitted</span>
                                    <?php else: ?>
                                        <i class="fa-solid fa-hourglass-half"></i> <span>Unclaimed</span>
                                    <?php endif; ?>
                                </span>
                            </td>

                            <!-- System-Generated Clean PDF (On-the-fly streaming) -->
                            <td style="text-align: center;">
                                <a href="view_letter.php?id=<?= $asset['record_id'] ?>" target="_blank" class="btn-pdf-gen" title="View & Print Official Holder Confirmation Letter">
                                    <i class="fa-solid fa-print"></i> View / Print Letter
                                </a>
                            </td>

                            <!-- Stamped Copy Attachment & Generated Timestamp -->
                            <td>
                                <div class="letter-actions" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                    <div id="attach-area-<?= $asset['record_id'] ?>" style="display:flex; align-items:center; gap:6px;">
                                        <button class="btn-upload-letter" onclick="document.getElementById('letter-upload-<?= $asset['record_id'] ?>').click()" title="Attach or Replace Stamped/Scanned Copy"><i class="fa-solid fa-paperclip"></i></button>
                                        <?php if (!empty($asset['stamped_file_path'])): ?>
                                            <a href="<?= htmlspecialchars($asset['stamped_file_path']) ?>" target="_blank" class="stamped-view-link" style="font-size:0.82rem; font-weight:700; color:var(--airtel-red); text-decoration:none; display:inline-flex; align-items:center; gap:4px;" title="View Stamped/Attached Copy">
                                                <i class="fa-solid fa-file-circle-check"></i> View Stamped Copy
                                            </a>
                                        <?php else: ?>
                                            <span class="stamped-view-link-placeholder" style="font-size:0.75rem; color:#94a3b8; font-style:italic;">No Stamped Copy</span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($asset['letter_generated_date'])): ?>
                                        <div style="font-size:0.75rem; color:#475569; background:#f1f5f9; padding:2px 7px; border-radius:4px; display:inline-flex; align-items:center; gap:4px; border:1px solid #e2e8f0;" title="Letter Generated Timestamp">
                                            <i class="fa-solid fa-clock" style="color:var(--airtel-red); font-size:0.7rem;"></i>
                                            <?= htmlspecialchars(date('d M Y, H:i', strtotime($asset['letter_generated_date']))) ?>
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" id="letter-upload-<?= $asset['record_id'] ?>" style="display:none;" onchange="uploadLetter(<?= $asset['record_id'] ?>, this)">
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
    <?php
        $paginationBase = 'letters.php?owner_name=' . urlencode($ownerNameFilter)
            . '&id_no=' . urlencode($idNoFilter)
            . '&account_no=' . urlencode($accountNoFilter)
            . '&status=' . urlencode($statusFilter)
            . '&compilation_start=' . urlencode($compilationStartFilter)
            . '&compilation_end=' . urlencode($compilationEndFilter);
    ?>
        <div class="pagination-row" style="margin-top: 1.5rem;">
            <div class="pagination-info">
                Showing Page <span><?= $page ?></span> of <span><?= $totalPages ?></span>
                &nbsp;(<span><?= number_format($totalFiltered) ?></span> records)
            </div>
            <div class="pagination-buttons">
                <a href="<?= $paginationBase ?>&page=<?= max(1, $page - 1) ?>#records-section" 
                   class="btn-page btn-page-nav <?= $page === 1 ? 'disabled' : '' ?>">
                    <i class="fa-solid fa-chevron-left"></i> Prev
                </a>
                <select class="page-jump-select" onchange="window.location.href=this.value+'#records-section'" aria-label="Jump to page">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <option value="<?= $paginationBase ?>&page=<?= $i ?>" <?= $i === $page ? 'selected' : '' ?>>
                            Page <?= $i ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <a href="<?= $paginationBase ?>&page=<?= min($totalPages, $page + 1) ?>#records-section" 
                   class="btn-page btn-page-nav <?= $page === $totalPages ? 'disabled' : '' ?>">
                    Next <i class="fa-solid fa-chevron-right"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once 'includes/layout_footer.php'; ?>
