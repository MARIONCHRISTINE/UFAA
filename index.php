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
$totalLettersReceived = 0;

// Pagination and Search Params
$ownerNameFilter = trim($_GET['owner_name'] ?? '');
$idNoFilter = trim($_GET['id_no'] ?? '');
$accountNoFilter = trim($_GET['account_no'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$letterFilter = trim($_GET['letter'] ?? '');
$compilationStartFilter = trim($_GET['compilation_start'] ?? '');
$compilationEndFilter = trim($_GET['compilation_end'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 50; // Assets per page
$offset = ($page - 1) * $limit;
$assets = [];
$totalPages = 1;

if ($dbInitialized && $pdo) {
    try {
        // Retrieve global stats
        $totalAssets = $pdo->query("SELECT COUNT(*) FROM `unclaimed_assets`")->fetchColumn();
        $totalUnclaimed = $pdo->query("SELECT COUNT(*) FROM `unclaimed_assets` WHERE `status` = 'Unclaimed'")->fetchColumn();
        $totalClaimed = $pdo->query("SELECT COUNT(*) FROM `unclaimed_assets` WHERE `status` = 'Claimed'")->fetchColumn();
        $totalLettersReceived = $pdo->query("SELECT COUNT(*) FROM `unclaimed_assets` WHERE `letter_received` = 'Yes'")->fetchColumn();

        // Build paginated query
        $whereClauses = [];
        $params = [];

        build_multiple_search_clause('owner_name', $ownerNameFilter, $whereClauses, $params, 'owner_name');
        build_multiple_search_clause('id_passport_no', $idNoFilter, $whereClauses, $params, 'id_no');
        build_multiple_search_clause('account_number', $accountNoFilter, $whereClauses, $params, 'account_no');

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

        // Fetch assets
        $stmt = $pdo->prepare("
            SELECT * FROM `unclaimed_assets` 
            $whereSql 
            ORDER BY `record_id` DESC 
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
                        <h3>Letters Received</h3>
                        <div class="stat-number" id="stat-letters" style="color: #0ea5e9;"><?= number_format($totalLettersReceived) ?></div>
                    </div>
                    <div class="stat-icon letter-yes">
                        <i class="fa-solid fa-envelope-open-text"></i>
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
                            <label>Claim Status</label>
                            <select name="status" class="filter-input">
                                <option value="">-- All Statuses --</option>
                                <option value="Unclaimed" <?= $statusFilter === 'Unclaimed' ? 'selected' : '' ?>>Unclaimed Only</option>
                                <option value="Claimed" <?= $statusFilter === 'Claimed' ? 'selected' : '' ?>>Claimed Only</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Letter Received</label>
                            <select name="letter" class="filter-input">
                                <option value="">-- All Letters --</option>
                                <option value="Yes" <?= $letterFilter === 'Yes' ? 'selected' : '' ?>>Letter Received</option>
                                <option value="No" <?= $letterFilter === 'No' ? 'selected' : '' ?>>No Letter Received</option>
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
                        <a href="ajax/export.php?owner_name=<?= urlencode($ownerNameFilter) ?>&id_no=<?= urlencode($idNoFilter) ?>&account_no=<?= urlencode($accountNoFilter) ?>&status=<?= urlencode($statusFilter) ?>&letter=<?= urlencode($letterFilter) ?>&compilation_start=<?= urlencode($compilationStartFilter) ?>&compilation_end=<?= urlencode($compilationEndFilter) ?>" class="btn-export" title="Download matching assets to Excel">
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
                                <th>Date of Birth</th>
                                <th>Account Number</th>
                                <th>Last Transaction</th>
                                <th>Due Amount</th>
                                <th>Compilation Date</th>
                                <th style="text-align: center; width: 130px;">Status</th>
                                <th style="text-align: center; width: 130px;">Letter Received</th>
                                <th style="width: 250px;">Letter Date &amp; File</th>
                            </tr>
                        </thead>
                        <tbody id="assets-tbody">
                            <?php if (empty($assets)): ?>
                                <tr>
                                    <td colspan="11">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-folder-open"></i>
                                            <p>No asset records found matching search or filter parameters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                // Dynamic index sequential mapping relative to current page count
                                $itemIndex = $offset + 1; 
                                foreach ($assets as $asset): 
                                ?>
                                    <tr id="row-<?= $asset['record_id'] ?>">
                                        <!-- Sequential Item Count -->
                                        <td class="col-item-no"><?= $itemIndex++ ?></td>
                                        
                                        <!-- Owner name -->
                                        <td class="col-owner"><?= $asset['owner_name'] !== null ? htmlspecialchars($asset['owner_name']) : '<span class="empty-field">Not Provided</span>' ?></td>
                                        
                                        <!-- ID / Passport / NSSF -->
                                        <td><?= $asset['id_passport_no'] !== null ? htmlspecialchars($asset['id_passport_no']) : '<span class="empty-field">-</span>' ?></td>
                                        
                                        <!-- DOB -->
                                        <td><?= $asset['date_of_birth'] !== null ? htmlspecialchars($asset['date_of_birth']) : '<span class="empty-field">-</span>' ?></td>
                                        
                                        <!-- Account Number -->
                                        <td><?= $asset['account_number'] !== null ? htmlspecialchars($asset['account_number']) : '<span class="empty-field">-</span>' ?></td>
                                        
                                        <!-- Last Transaction string -->
                                        <td><?= $asset['last_transaction'] !== null ? htmlspecialchars($asset['last_transaction']) : '<span class="empty-field">-</span>' ?></td>
                                        
                                        <!-- Due amount string -->
                                        <td class="col-amount"><?= $asset['due_amount'] !== null ? htmlspecialchars($asset['due_amount']) : '<span class="empty-field">-</span>' ?></td>
                                        
                                        <!-- Compilation Date -->
                                        <td>
                                            <?= htmlspecialchars($asset['compilation_date'] ?? '-') ?>
                                        </td>
                                        
                                        <!-- Claiming status toggle -->
                                        <td style="text-align: center;">
                                            <span 
                                                id="badge-status-<?= $asset['record_id'] ?>" 
                                                class="status-badge <?= strtolower($asset['status']) ?>"
                                                onclick="toggleClaimStatus(<?= $asset['record_id'] ?>, '<?= $asset['status'] ?>')"
                                                title="Click to toggle claim status"
                                            >
                                                <?php if ($asset['status'] === 'Claimed'): ?>
                                                    <i class="fa-solid fa-circle-check"></i> <span>Claimed</span>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-hourglass-half"></i> <span>Unclaimed</span>
                                                <?php endif; ?>
                                            </span>
                                        </td>

                                        <!-- Letter received status toggle -->
                                        <td style="text-align: center;">
                                            <span 
                                                id="badge-letter-<?= $asset['record_id'] ?>" 
                                                class="status-badge letter-<?= strtolower($asset['letter_received']) ?>"
                                                onclick="toggleLetterReceived(<?= $asset['record_id'] ?>, '<?= $asset['letter_received'] ?>')"
                                                title="Click to toggle letter received"
                                            >
                                                <?php if ($asset['letter_received'] === 'Yes'): ?>
                                                    <i class="fa-solid fa-envelope-open-text"></i> <span>Yes</span>
                                                <?php else: ?>
                                                    <i class="fa-solid fa-envelope"></i> <span>No</span>
                                                <?php endif; ?>
                                            </span>
                                        </td>

                                        <!-- Inline Editable Letter Date Field & File Upload -->
                                        <td>
                                            <div class="letter-actions">
                                                <div class="date-input-container">
                                                    <input 
                                                        type="text" 
                                                        value="<?= htmlspecialchars($asset['letter_date'] ?? '') ?>"
                                                        data-original="<?= htmlspecialchars($asset['letter_date'] ?? '') ?>"
                                                        class="date-edit-input" 
                                                        placeholder="Enter Date..."
                                                        onblur="handleDateBlur(this, <?= $asset['record_id'] ?>)"
                                                        onkeydown="handleDateKey(event, this, <?= $asset['record_id'] ?>)"
                                                    >
                                                    <i class="date-save-indicator fa-solid fa-pen"></i>
                                                </div>
                                                
                                                <button class="btn-upload-letter" onclick="document.getElementById('letter-upload-<?= $asset['record_id'] ?>').click()" title="Upload Letter File">
                                                    <i class="fa-solid fa-paperclip"></i>
                                                </button>
                                                <input type="file" id="letter-upload-<?= $asset['record_id'] ?>" style="display:none;" onchange="uploadLetter(<?= $asset['record_id'] ?>, this)">
                                                
                                                <?php if (!empty($asset['letter_file_path'])): ?>
                                                    <a href="<?= htmlspecialchars($asset['letter_file_path']) ?>" download class="letter-file-link" title="Download Uploaded Letter">
                                                        <i class="fa-solid fa-download"></i> Download
                                                    </a>
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
                    <div class="pagination-row">
                        <div class="pagination-info">
                            Showing Page <span><?= $page ?></span> of <span><?= $totalPages ?></span> Pages
                        </div>
                        <div class="pagination-buttons">
                            <!-- Prev button -->
                            <a href="index.php?owner_name=<?= urlencode($ownerNameFilter) ?>&id_no=<?= urlencode($idNoFilter) ?>&account_no=<?= urlencode($accountNoFilter) ?>&status=<?= urlencode($statusFilter) ?>&letter=<?= urlencode($letterFilter) ?>&compilation_start=<?= urlencode($compilationStartFilter) ?>&compilation_end=<?= urlencode($compilationEndFilter) ?>&page=<?= max(1, $page - 1) ?>#records-section" 
                               class="btn-page btn-page-nav <?= $page === 1 ? 'disabled' : '' ?>">
                                <i class="fa-solid fa-chevron-left"></i> Previous
                            </a>

                            <!-- Page Numbers -->
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            
                            for ($i = $startPage; $i <= $endPage; $i++): 
                            ?>
                                <a href="index.php?owner_name=<?= urlencode($ownerNameFilter) ?>&id_no=<?= urlencode($idNoFilter) ?>&account_no=<?= urlencode($accountNoFilter) ?>&status=<?= urlencode($statusFilter) ?>&letter=<?= urlencode($letterFilter) ?>&compilation_start=<?= urlencode($compilationStartFilter) ?>&compilation_end=<?= urlencode($compilationEndFilter) ?>&page=<?= $i ?>#records-section" 
                                   class="btn-page <?= $i === $page ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>

                            <!-- Next button -->
                            <a href="index.php?owner_name=<?= urlencode($ownerNameFilter) ?>&id_no=<?= urlencode($idNoFilter) ?>&account_no=<?= urlencode($accountNoFilter) ?>&status=<?= urlencode($statusFilter) ?>&letter=<?= urlencode($letterFilter) ?>&compilation_start=<?= urlencode($compilationStartFilter) ?>&compilation_end=<?= urlencode($compilationEndFilter) ?>&page=<?= min($totalPages, $page + 1) ?>#records-section" 
                               class="btn-page btn-page-nav <?= $page === $totalPages ? 'disabled' : '' ?>">
                                Next <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

            </div>

        <?php endif; ?>

<?php require_once 'includes/layout_footer.php'; ?>
