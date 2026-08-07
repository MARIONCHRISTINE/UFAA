<?php
/**
 * UFAA - Main Compliance Dashboard (Modular Hub)
 * Features a gorgeous dark theme, interactive stats, drag-and-drop upload,
 * live searching/filtering, and seamless AJAX status, letter received, and letter date toggles.
 */

require_once 'config.php';

// Check if database is initialized by attempting connection
$dbInitialized = true;
$dbErrorMessage = '';
$pdo = null;

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        $dbInitialized = false;
    } else {
        // Double check if table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'unclaimed_assets'");
        if ($tableCheck->rowCount() == 0) {
            $dbInitialized = false;
        }
    }
} catch (Exception $e) {
    $dbInitialized = false;
    $dbErrorMessage = $e->getMessage();
}

// Stats initialization
$totalAssets = 0;
$totalUnclaimed = 0;
$totalClaimed = 0;
$totalLettersGenerated = 0;

// Pagination and Search Params
$ownerNameFilter = trim($_GET['owner_name'] ?? '');
$idNoFilter = trim($_GET['id_no'] ?? '');
$accountNoFilter = trim($_GET['account_no'] ?? '');
$amountFilter = trim($_GET['amount'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$letterFilter = trim($_GET['letter'] ?? '');
$compilationStartFilter = trim($_GET['compilation_start'] ?? '');
$compilationEndFilter = trim($_GET['compilation_end'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 100; // Assets per page
$offset = ($page - 1) * $limit;
$assets = [];
$totalPages = 1;

if ($dbInitialized && $pdo) {
    try {
        // Retrieve global stats
        $totalAssets = $pdo->query("SELECT COUNT(*) FROM `unclaimed_assets`")->fetchColumn();
        $totalUnclaimed = $pdo->query("SELECT COUNT(*) FROM `unclaimed_assets` WHERE `status` = 'Unclaimed'")->fetchColumn();
        $totalClaimed = $pdo->query("SELECT COUNT(*) FROM `unclaimed_assets` WHERE `status` = 'Claimed'")->fetchColumn();
        $totalLettersGenerated = $pdo->query("SELECT COUNT(*) FROM `unclaimed_assets` WHERE `letter_generated` = 'Yes' OR `letter_received` = 'Yes'")->fetchColumn();

        // Build paginated query
        $whereClauses = [];
        $params = [];

        // When filtering by a text field, also exclude records where that field is NULL or empty
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

        if ($letterFilter !== '') {
            $whereClauses[] = "`letter_received` = :letter_received";
            $params[':letter_received'] = $letterFilter;
        }

        if ($compilationStartFilter !== '') {
            $whereClauses[] = "`compilation_date` >= :compilation_start";
            $params[':compilation_start'] = $compilationStartFilter;
        }

        if ($compilationEndFilter !== '') {
            $whereClauses[] = "`compilation_date` <= :compilation_end";
            $params[':compilation_end'] = $compilationEndFilter;
        }

        $whereSql = '';
        if (!empty($whereClauses)) {
            $whereSql = 'WHERE ' . implode(' AND ', $whereClauses);
        }

        // Count total matching records for pagination
        $countQuery = $pdo->prepare("SELECT COUNT(*) FROM `unclaimed_assets` $whereSql");
        $countQuery->execute($params);
        $totalFiltered = $countQuery->fetchColumn();
        $totalPages = ceil($totalFiltered / $limit);

        // Fetch assets (alphabetical by owner_name; NULLs sorted last)
        $stmt = $pdo->prepare("
            SELECT * FROM `unclaimed_assets` 
            $whereSql 
            ORDER BY `owner_name` IS NULL ASC, `owner_name` ASC
            LIMIT :limit OFFSET :offset
        ");
        
        // Bind pagination params manually
        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $assets = $stmt->fetchAll();

    } catch (PDOException $e) {
        $dbErrorMessage = "Query Error: " . $e->getMessage();
    }
}
$activePage = 'home';
require_once 'includes/layout.php';
?>
        <!-- System Maintenance Banner (if configured in Admin Settings) -->
        <?php
        if ($pdo) {
            try {
                $mSettings = [];
                $mRows = $pdo->query("SELECT setting_key, setting_value FROM admin_settings WHERE setting_key LIKE 'maintenance_%'")->fetchAll();
                foreach ($mRows as $mr) $mSettings[$mr['setting_key']] = $mr['setting_value'];

                $mMode   = $mSettings['maintenance_mode'] ?? '0';
                $mBanner = $mSettings['maintenance_show_banner'] ?? '1';
                $mMsg    = $mSettings['maintenance_banner_msg'] ?? '';
                $mSched  = $mSettings['maintenance_scheduled_at'] ?? '';

                if ($mBanner === '1' && ($mMode === '1' || $mMode === 'scheduled')):
                    $isNow = ($mMode === '1');
                    $bg    = $isNow ? 'rgba(239, 68, 68, 0.15)' : 'rgba(245, 158, 11, 0.15)';
                    $border= $isNow ? 'rgba(239, 68, 68, 0.4)'  : 'rgba(245, 158, 11, 0.4)';
                    $color = $isNow ? '#f87171' : '#fbbf24';
        ?>
        <div style="background:<?= $bg ?>;border:1px solid <?= $border ?>;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1rem;backdrop-filter:blur(8px);">
            <i class="fa-solid fa-triangle-exclamation" style="font-size:1.5rem;color:<?= $color ?>;"></i>
            <div style="flex:1;font-size:0.9rem;line-height:1.5;color:var(--text-main);">
                <strong style="color:<?= $color ?>;">
                    <?= $isNow ? 'System Under Maintenance Notice:' : 'Scheduled Maintenance Notice:' ?>
                </strong>
                <span><?= htmlspecialchars($mMsg ?: 'System maintenance is scheduled.') ?></span>
                <?php if ($mSched && !$isNow): ?>
                <span style="font-weight:600;margin-left:0.35rem;color:var(--color-gold);">(Scheduled for <?= date('d M Y, H:i', strtotime($mSched)) ?>)</span>
                <?php endif; ?>
            </div>
        </div>
        <?php
                endif;
            } catch (Exception $mEx) {}
        }
        ?>

        <!-- Setup Database Modal Card if DB doesn't exist -->
        <?php if (!$dbInitialized): ?>
            <div class="setup-warning-card">
                <h2><i class="fa-solid fa-triangle-exclamation"></i> Database Initialization Required</h2>
                <p>
                    The UFAA database (`ufaa_db`) or the `unclaimed_assets` table has not been initialized.
                    Please run our self-healing setup script to configure your environment automatically.
                </p>
                <button onclick="runSetup()" class="btn-setup" id="setup-btn">
                    <i class="fa-solid fa-gears"></i> Initialize Database System
                </button>
                <?php if ($dbErrorMessage !== ''): ?>
                    <div style="margin-top: 15px; font-size: 0.8rem; color: var(--color-rose);">
                        Error detail: <?= htmlspecialchars($dbErrorMessage) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>

            <!-- Dynamic Stat Counters -->
            <div class="stats-grid">
                
                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Total Financial Assets</h3>
                        <div class="stat-number" id="stat-total"><?= number_format($totalAssets) ?></div>
                    </div>
                    <div class="stat-icon total">
                        <i class="fa-solid fa-vault"></i>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Unclaimed Assets</h3>
                        <div class="stat-number" id="stat-unclaimed" style="color: var(--color-gold);"><?= number_format($totalUnclaimed) ?></div>
                    </div>
                    <div class="stat-icon unclaimed">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Claimed Assets</h3>
                        <div class="stat-number" id="stat-claimed" style="color: var(--color-emerald);"><?= number_format($totalClaimed) ?></div>
                    </div>
                    <div class="stat-icon claimed">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-info">
                        <h3>Holder Letters Issued</h3>
                        <div class="stat-number" id="stat-letters" style="color: #0ea5e9;"><?= number_format($totalLettersGenerated) ?></div>
                    </div>
                    <div class="stat-icon letter-yes">
                        <i class="fa-solid fa-file-pdf"></i>
                    </div>
                </div>

            </div>

            <!-- Premium Animated Excel Drag-and-Drop Uploader -->
            <div class="uploader-card" id="dropzone">
                <!-- Default State -->
                <div id="upload-default-view" onclick="document.getElementById('file-input').click()" style="cursor:pointer;">
                    <div class="upload-icon-wrapper">
                        <i class="fa-solid fa-file-excel"></i>
                    </div>
                    <h3>Drag & Drop Compliance Sheet</h3>
                    <p>or <span style="color:var(--airtel-red); font-weight:600;">browse files</span> from your computer</p>
                    <div class="supported-types" style="margin-top:10px;">Supported Formats: Excel (.xlsx · .xls) &amp; CSV (.csv)</div>
                    <div class="template-download" style="margin-top:18px;" onclick="event.stopPropagation()">
                        <a href="download_template.php" class="btn-reset" style="padding: 0.45rem 1rem; font-size:0.8rem; border-radius: 8px; display:inline-flex; align-items:center; gap:0.5rem; text-decoration:none;">
                            <i class="fa-solid fa-download" style="color:var(--airtel-red)"></i> Download Excel/CSV Template
                        </a>
                    </div>
                </div>
                <input type="file" id="file-input" accept=".xlsx,.xls,.csv" onchange="handleFileSelect(event)" style="display:none;">
                
                <!-- Preview State -->
                <div id="upload-preview-view" style="display: none; padding: 1rem;">
                    <div style="font-size: 3rem; color: #2563eb; margin-bottom: 10px;">
                        <i class="fa-solid fa-file-lines"></i>
                    </div>
                    <h4 id="preview-filename" style="margin-bottom: 5px; word-break: break-all;">filename.xlsx</h4>
                    <p id="preview-filesize" style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 15px;">Size: 0KB</p>
                    
                    <div style="display: flex; gap: 10px; justify-content: center;">
                        <button class="btn-reset" onclick="cancelUpload()">
                            <i class="fa-solid fa-trash-can"></i> Remove File
                        </button>
                        <button class="btn-filter" id="btn-upload-action" onclick="confirmUpload()">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Upload Now
                        </button>
                    </div>
                </div>
                
                <!-- Approved / Success State -->
                <div id="upload-approved-view" style="display: none; padding: 1rem;">
                    <div style="font-size: 3rem; color: #16a34a; margin-bottom: 10px;">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <h4 style="color: #16a34a; margin-bottom: 5px;">File Approved!</h4>
                    <p id="approved-message" style="font-size: 0.9rem; color: var(--text-secondary); margin-bottom: 15px;">Data successfully imported into the system.</p>
                    <button class="btn-reset" onclick="resetUploader()">Upload Another File</button>
                </div>

                <!-- Progress Bar during AJAX upload -->
                <div class="progress-container" id="upload-progress-container" style="display:none; margin-top: 20px;" onclick="event.stopPropagation()">
                    <div class="progress-label">
                        <span id="progress-status">Uploading compliance rows...</span>
                        <span id="progress-percent">0%</span>
                    </div>
                    <div class="progress-bar-bg">
                        <div class="progress-bar-fill" id="progress-bar-fill"></div>
                    </div>
                </div>
            </div>

            <!-- Data Management & Table Card -->
            <div class="data-management-card" id="records-section">
                
                <!-- Advanced Searching & Filtering controls -->
                <form method="GET" action="index.php#records-section" class="filters-panel">
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
                            <label>Amount</label>
                            <input type="text" name="amount" placeholder="e.g. 5000" value="<?= htmlspecialchars($amountFilter) ?>" class="filter-input">
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
                            <label>Letter Issued</label>
                            <select name="letter" class="filter-input">
                                <option value="">-- All Letters --</option>
                                <option value="Yes" <?= $letterFilter === 'Yes' ? 'selected' : '' ?>>Letter Issued</option>
                                <option value="No" <?= $letterFilter === 'No' ? 'selected' : '' ?>>No Letter Issued</option>
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
                            <?php if ($ownerNameFilter !== '' || $idNoFilter !== '' || $accountNoFilter !== '' || $statusFilter !== '' || $letterFilter !== '' || $compilationStartFilter !== '' || $compilationEndFilter !== ''): ?>
                                <a href="index.php" class="btn-reset">
                                    <i class="fa-solid fa-arrows-rotate"></i> Reset
                                </a>
                            <?php endif; ?>
                        </div>
                        <a href="#" onclick="triggerChunkedExport('owner_name=<?= urlencode($ownerNameFilter) ?>&id_no=<?= urlencode($idNoFilter) ?>&account_no=<?= urlencode($accountNoFilter) ?>&status=<?= urlencode($statusFilter) ?>&letter=<?= urlencode($letterFilter) ?>&compilation_start=<?= urlencode($compilationStartFilter) ?>&compilation_end=<?= urlencode($compilationEndFilter) ?>'); return false;" class="btn-export" title="Download matching assets to Excel">
                            <i class="fa-solid fa-file-excel"></i> Download Excel
                        </a>
                    </div>
                </form>

                <!-- Data Table -->
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Owner Name</th>
                                <th>ID / Passport No</th>
                                <th>Account Number</th>
                                <th>Last Transaction</th>
                                <th>Due Amount</th>
                                <th>Compilation Date</th>
                                <th style="text-align: center; width: 130px;">Status</th>
                                <th style="text-align: center; width: 160px;">Generated Letter PDF</th>
                                <th style="width: 220px;">Stamped Copy &amp; Timestamp</th>
                            </tr>
                        </thead>
                        <tbody id="assets-tbody">
                            <?php if (empty($assets)): ?>
                                <tr>
                                    <td colspan="10">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-folder-open"></i>
                                            <p>No asset records found matching search or filter parameters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                $itemIndex = $offset + 1; 
                                foreach ($assets as $asset): 
                                    $hasLetter = (!empty($asset['letter_generated']) && $asset['letter_generated'] === 'Yes') || (!empty($asset['letter_file_path']));
                                ?>
                                    <tr id="row-<?= $asset['record_id'] ?>">
                                        <td class="col-item-no"><?= $itemIndex++ ?></td>
                                        <td class="col-owner"><?= $asset['owner_name'] !== null ? htmlspecialchars($asset['owner_name']) : '<span class="empty-field">Not Provided</span>' ?></td>
                                        <td><?= $asset['id_passport_no'] !== null ? htmlspecialchars($asset['id_passport_no']) : '<span class="empty-field">-</span>' ?></td>
                                        <td><?= $asset['account_number'] !== null ? htmlspecialchars($asset['account_number']) : '<span class="empty-field">-</span>' ?></td>
                                        <td><?= $asset['last_transaction'] !== null ? htmlspecialchars($asset['last_transaction']) : '<span class="empty-field">-</span>' ?></td>
                                        <td class="col-amount"><?= $asset['due_amount'] !== null ? htmlspecialchars($asset['due_amount']) : '<span class="empty-field">-</span>' ?></td>
                                        <td><?= htmlspecialchars($asset['compilation_date'] ?? '-') ?></td>
                                        
                                        <!-- Claim Status Badge -->
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

                                        <!-- Generated Timestamp & Legacy Attachment Upload -->
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
                    // Build base query string for pagination links (excludes page param)
                    $paginationBase = 'index.php?owner_name=' . urlencode($ownerNameFilter)
                        . '&id_no=' . urlencode($idNoFilter)
                        . '&account_no=' . urlencode($accountNoFilter)
                        . '&status=' . urlencode($statusFilter)
                        . '&letter=' . urlencode($letterFilter)
                        . '&compilation_start=' . urlencode($compilationStartFilter)
                        . '&compilation_end=' . urlencode($compilationEndFilter);
                ?>
                    <div class="pagination-row">
                        <div class="pagination-info">
                            Showing Page <span><?= $page ?></span> of <span><?= $totalPages ?></span>
                            &nbsp;(<span><?= number_format($totalFiltered) ?></span> records)
                        </div>
                        <div class="pagination-buttons">
                            <!-- Prev button -->
                            <a href="<?= $paginationBase ?>&page=<?= max(1, $page - 1) ?>#records-section"
                               class="btn-page btn-page-nav <?= $page === 1 ? 'disabled' : '' ?>">
                                <i class="fa-solid fa-chevron-left"></i> Prev
                            </a>

                            <!-- Jump to Page dropdown -->
                            <select class="page-jump-select" onchange="window.location.href=this.value+'#records-section'" aria-label="Jump to page">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <option value="<?= $paginationBase ?>&page=<?= $i ?>" <?= $i === $page ? 'selected' : '' ?>>
                                        Page <?= $i ?>
                                    </option>
                                <?php endfor; ?>
                            </select>

                            <!-- Next button -->
                            <a href="<?= $paginationBase ?>&page=<?= min($totalPages, $page + 1) ?>#records-section"
                               class="btn-page btn-page-nav <?= $page === $totalPages ? 'disabled' : '' ?>">
                                Next <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        <?php endif; ?>

<?php require_once 'includes/layout_footer.php'; ?>
