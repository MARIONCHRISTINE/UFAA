/**
 * UFAA - Dashboard Client Application Logic
 * Manages real-time AJAX uploads, status badge toggles, and inline saves.
 */

// 1. Toast Notification System
function showNotification(type, message) {
    const container = document.getElementById('toast-holder');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast-notification ${type}`;
    
    const icon = type === 'success' 
        ? '<i class="fa-solid fa-circle-check"></i>' 
        : '<i class="fa-solid fa-circle-xmark"></i>';
        
    toast.innerHTML = `${icon} <span>${message}</span>`;
    container.appendChild(toast);
    
    // Auto-remove toast after 4 seconds
    setTimeout(() => {
        toast.style.animation = 'fadeOut 0.4s forwards';
        setTimeout(() => {
            toast.remove();
        }, 400);
    }, 4000);
}

// 2. Database Initialization AJAX Handler
function runSetup() {
    const btn = document.getElementById('setup-btn');
    if (!btn) return;
    
    btn.disabled = true;
    btn.innerHTML = '<i class="badge-spinner"></i> Initializing DB...';
    
    fetch('init_db.php')
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                showNotification('success', data.message);
                setTimeout(() => {
                    window.location.reload();
                }, 1200);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-gears"></i> Initialize Database System';
                showNotification('error', data.message);
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-gears"></i> Initialize Database System';
            showNotification('error', 'Database initialization request failed.');
        });
}

// 3. Consolidated AJAX Record Editor (Claim Status, Letter Status, Letter Date)
function updateRecord(recordId, fieldName, newValue, callback) {
    const formData = new FormData();
    formData.append('record_id', recordId);
    formData.append('field', fieldName);
    formData.append('value', newValue);

    fetch('ajax/update_record.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            if (callback) callback(true, data);
            showNotification('success', data.message);
        } else {
            if (callback) callback(false, data);
            showNotification('error', data.message || 'Update failed.');
        }
    })
    .catch(err => {
        if (callback) callback(false, err);
        showNotification('error', 'Update failed due to network issue.');
    });
}

// 4. Interactive Badge Status Toggles via Popups
function toggleClaimStatus(recordId, currentStatus) {
    const overlay = document.getElementById('status-popup-overlay');
    if (!overlay) return;
    
    document.getElementById('status-popup-record-id').value = recordId;
    
    const unclaimedRadio = document.querySelector('input[name="status-radio"][value="Unclaimed"]');
    const claimedRadio = document.querySelector('input[name="status-radio"][value="Claimed"]');
    
    if (currentStatus === 'Claimed') {
        if (claimedRadio) claimedRadio.checked = true;
    } else {
        if (unclaimedRadio) unclaimedRadio.checked = true;
    }
    
    updateRadioLabels('status');
    overlay.classList.add('show');
}

function closeStatusPopup() {
    const overlay = document.getElementById('status-popup-overlay');
    if (overlay) overlay.classList.remove('show');
}

function saveStatusPopup() {
    const recordId = document.getElementById('status-popup-record-id').value;
    const selectedRadio = document.querySelector('input[name="status-radio"]:checked');
    if (!selectedRadio) return;
    
    const nextStatus = selectedRadio.value;
    const badge = document.getElementById(`badge-status-${recordId}`);
    if (badge) {
        badge.setAttribute('data-loading', 'true');
        badge.innerHTML = '<i class="badge-spinner"></i> <span>Saving...</span>';
    }
    
    updateRecord(recordId, 'status', nextStatus, (success, data) => {
        closeStatusPopup();
        if (!badge) return;
        badge.removeAttribute('data-loading');
        if (success) {
            const currentBadgeVal = badge.classList.contains('claimed') ? 'Claimed' : 'Unclaimed';
            badge.className = `status-badge ${nextStatus.toLowerCase()}`;
            badge.setAttribute('onclick', `toggleClaimStatus(${recordId}, '${nextStatus}')`);
            
            if (nextStatus === 'Claimed') {
                badge.innerHTML = '<i class="fa-solid fa-circle-check"></i> <span>Claimed</span>';
            } else {
                badge.innerHTML = '<i class="fa-solid fa-hourglass-half"></i> <span>Unclaimed</span>';
            }

            if (currentBadgeVal !== nextStatus) {
                adjustCounter('stat-claimed', nextStatus === 'Claimed' ? 1 : -1);
                adjustCounter('stat-unclaimed', nextStatus === 'Unclaimed' ? 1 : -1);
            }
        } else {
            const isClaimed = badge.classList.contains('claimed');
            badge.innerHTML = isClaimed ? '<i class="fa-solid fa-circle-check"></i> <span>Claimed</span>' : '<i class="fa-solid fa-hourglass-half"></i> <span>Unclaimed</span>';
        }
    });
}

function toggleLetterReceived(recordId, currentVal) {
    const overlay = document.getElementById('letter-popup-overlay');
    if (!overlay) return;
    
    document.getElementById('letter-popup-record-id').value = recordId;
    
    const noRadio = document.querySelector('input[name="letter-radio"][value="No"]');
    const yesRadio = document.querySelector('input[name="letter-radio"][value="Yes"]');
    
    if (currentVal === 'Yes') {
        if (yesRadio) yesRadio.checked = true;
    } else {
        if (noRadio) noRadio.checked = true;
    }
    
    updateRadioLabels('letter');
    overlay.classList.add('show');
}

function closeLetterPopup() {
    const overlay = document.getElementById('letter-popup-overlay');
    if (overlay) overlay.classList.remove('show');
}

function saveLetterPopup() {
    const recordId = document.getElementById('letter-popup-record-id').value;
    const selectedRadio = document.querySelector('input[name="letter-radio"]:checked');
    if (!selectedRadio) return;
    
    const nextVal = selectedRadio.value;
    const badge = document.getElementById(`badge-letter-${recordId}`);
    if (badge) {
        badge.setAttribute('data-loading', 'true');
        badge.innerHTML = '<i class="badge-spinner"></i> <span>Saving...</span>';
    }
    
    updateRecord(recordId, 'letter_received', nextVal, (success, data) => {
        closeLetterPopup();
        if (!badge) return;
        badge.removeAttribute('data-loading');
        if (success) {
            const currentBadgeVal = badge.classList.contains('letter-yes') ? 'Yes' : 'No';
            badge.className = `status-badge letter-${nextVal.toLowerCase()}`;
            badge.setAttribute('onclick', `toggleLetterReceived(${recordId}, '${nextVal}')`);
            
            if (nextVal === 'Yes') {
                badge.innerHTML = '<i class="fa-solid fa-envelope-open-text"></i> <span>Yes</span>';
            } else {
                badge.innerHTML = '<i class="fa-solid fa-envelope"></i> <span>No</span>';
            }

            if (currentBadgeVal !== nextVal) {
                adjustCounter('stat-letters', nextVal === 'Yes' ? 1 : -1);
            }
        } else {
            const isYes = badge.classList.contains('letter-yes');
            badge.innerHTML = isYes ? '<i class="fa-solid fa-envelope-open-text"></i> <span>Yes</span>' : '<i class="fa-solid fa-envelope"></i> <span>No</span>';
        }
    });
}

function updateRadioLabels(type) {
    if (type === 'status') {
        const labels = ['unclaimed', 'claimed'];
        labels.forEach(lbl => {
            const labelEl = document.getElementById(`label-status-${lbl}`);
            if (!labelEl) return;
            const radio = labelEl.querySelector('input');
            if (radio && radio.checked) {
                labelEl.classList.add('active');
            } else {
                labelEl.classList.remove('active');
            }
        });
    } else if (type === 'letter') {
        const labels = ['no', 'yes'];
        labels.forEach(lbl => {
            const labelEl = document.getElementById(`label-letter-${lbl}`);
            if (!labelEl) return;
            const radio = labelEl.querySelector('input');
            if (radio && radio.checked) {
                labelEl.classList.add('active');
            } else {
                labelEl.classList.remove('active');
            }
        });
    }
}

// Close overlays when clicking on the overlay background
document.addEventListener('DOMContentLoaded', () => {
    const statusOverlay = document.getElementById('status-popup-overlay');
    const letterOverlay = document.getElementById('letter-popup-overlay');
    
    if (statusOverlay) {
        statusOverlay.addEventListener('click', (e) => {
            if (e.target === statusOverlay) closeStatusPopup();
        });
    }
    if (letterOverlay) {
        letterOverlay.addEventListener('click', (e) => {
            if (e.target === letterOverlay) closeLetterPopup();
        });
    }
});

// Helper to increase or decrease global statistics values beautifully
function adjustCounter(elementId, changeAmount) {
    const el = document.getElementById(elementId);
    if (!el) return;
    
    let value = parseInt(el.innerText.replace(/,/g, ''));
    if (isNaN(value)) value = 0;
    
    value = Math.max(0, value + changeAmount);
    el.innerText = value.toLocaleString();
}

// 5. Inline Date Auto-Saves (on Enter or Blur)
function handleDateBlur(input, recordId) {
    const originalVal = input.getAttribute('data-original');
    const currentVal = input.value.trim();

    if (originalVal === currentVal) return; // Value didn't change

    saveDateInline(input, recordId, currentVal);
}

function handleDateKey(event, input, recordId) {
    if (event.key === 'Enter') {
        input.blur(); // Triggers blur which does the saving
    }
}

function saveDateInline(input, recordId, newVal) {
    const container = input.closest('.date-input-container');
    if (container) container.classList.remove('saved');

    // Show inline saving status
    const indicator = container ? container.querySelector('.date-save-indicator') : null;
    if (indicator) {
        indicator.className = 'date-save-indicator fa-solid fa-spinner fa-spin';
    }

    updateRecord(recordId, 'letter_date', newVal, (success, data) => {
        if (success) {
            input.setAttribute('data-original', newVal);
            if (indicator) {
                indicator.className = 'date-save-indicator fa-solid fa-circle-check';
                container.classList.add('saved');
            }
        } else {
            input.value = input.getAttribute('data-original'); // Reset back to original
            if (indicator) {
                indicator.className = 'date-save-indicator fa-solid fa-pen';
            }
        }
    });
}

// 6. Excel Drag & Drop File Upload Setup
document.addEventListener('DOMContentLoaded', () => {
    const dropzone = document.getElementById('dropzone');
    const fileInput = document.getElementById('file-input');
    const progressContainer = document.getElementById('upload-progress-container');
    const progressBarFill = document.getElementById('progress-bar-fill');
    const progressPercent = document.getElementById('progress-percent');
    const progressStatus = document.getElementById('progress-status');

    if (dropzone && fileInput) {
        // Prevent default browser dragging behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });

        // Set drag over styles
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.add('dragover'), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, () => dropzone.classList.remove('dragover'), false);
        });

        // Receive dropped file — show preview first, same as browse
        dropzone.addEventListener('drop', e => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files.length > 0) {
                showPreview(files[0]);
            }
        }, false);
    }
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

let selectedExcelFile = null;

function handleFileSelect(e) {
    const files = e.target.files;
    if (files.length > 0) {
        showPreview(files[0]);
    }
}

function showPreview(file) {
    // Validate file type first
    const ext = file.name.split('.').pop().toLowerCase();
    const allowedFormats = ['xlsx', 'xls', 'csv'];
    if (!allowedFormats.includes(ext)) {
        showNotification('error', 'Unsupported format! Please upload an Excel file (.xlsx, .xls) or a CSV file (.csv).');
        document.getElementById('file-input').value = '';
        return;
    }

    selectedExcelFile = file;
    
    // Switch UI views
    document.getElementById('upload-default-view').style.display = 'none';
    document.getElementById('upload-approved-view').style.display = 'none';
    document.getElementById('upload-progress-container').style.display = 'none';
    
    const previewView = document.getElementById('upload-preview-view');
    previewView.style.display = 'block';
    
    // Populate preview details
    document.getElementById('preview-filename').innerText = file.name;
    const sizeKB = (file.size / 1024).toFixed(1);
    document.getElementById('preview-filesize').innerText = `Size: ${sizeKB} KB`;
}

function cancelUpload() {
    selectedExcelFile = null;
    document.getElementById('file-input').value = '';
    
    document.getElementById('upload-preview-view').style.display = 'none';
    document.getElementById('upload-approved-view').style.display = 'none';
    document.getElementById('upload-progress-container').style.display = 'none';
    document.getElementById('upload-default-view').style.display = 'block';
}

function confirmUpload() {
    if (selectedExcelFile) {
        uploadExcelFile(selectedExcelFile);
    }
}

function resetUploader() {
    cancelUpload();
    // Optional: reload the page to show newly imported data
    window.location.reload();
}

function uploadExcelFile(file) {
    const progressContainer = document.getElementById('upload-progress-container');
    const progressBarFill = document.getElementById('progress-bar-fill');
    const progressPercent = document.getElementById('progress-percent');
    const progressStatus = document.getElementById('progress-status');

    // Hide preview buttons
    document.getElementById('upload-preview-view').style.display = 'none';

    if (progressContainer) {
        progressContainer.style.display = 'block';
        progressBarFill.style.width = '0%';
        progressPercent.innerText = '0%';
        progressStatus.innerText = 'Uploading: ' + file.name;
    }

    const formData = new FormData();
    formData.append('excel', file);

    const xhr = new XMLHttpRequest();
    
    // Track file upload progress
    xhr.upload.addEventListener('progress', e => {
        if (e.lengthComputable && progressContainer) {
            const percentComplete = Math.round((e.loaded / e.total) * 100);
            progressBarFill.style.width = percentComplete + '%';
            progressPercent.innerText = percentComplete + '%';
            if (percentComplete === 100) {
                const formatLabel = file.name.split('.').pop().toUpperCase();
                progressStatus.innerText = `Parsing ${formatLabel} data and inserting into database...`;
            }
        }
    }, false);

    // Process server response
    xhr.addEventListener('load', () => {
        let response = {};
        try {
            response = JSON.parse(xhr.responseText);
        } catch(e) {
            response = { status: 'error', message: 'Parser produced unexpected server response.' };
        }

        if (xhr.status === 200 && response.status === 'success') {
            showNotification('success', response.message);
            if (progressContainer) progressContainer.style.display = 'none';
            
            // Show Approved View
            document.getElementById('upload-approved-view').style.display = 'block';
            document.getElementById('approved-message').innerText = response.message;

        } else if (response.status === 'duplicate') {
            // File already uploaded — show warning and reset to default view
            if (progressContainer) progressContainer.style.display = 'none';
            selectedExcelFile = null;
            document.getElementById('file-input').value = '';
            document.getElementById('upload-preview-view').style.display = 'none';
            document.getElementById('upload-default-view').style.display = 'block';
            showNotification('error', '⚠️ ' + response.message);

        } else {
            if (progressContainer) progressContainer.style.display = 'none';
            // Fallback to preview view so they can try again
            document.getElementById('upload-preview-view').style.display = 'block';
            showNotification('error', response.message || 'Excel processing failed.');
        }
    });

    xhr.addEventListener('error', () => {
        if (progressContainer) progressContainer.style.display = 'none';
        showNotification('error', 'Network upload connection error.');
    });

    xhr.open('POST', 'ajax/upload.php', true);
    xhr.send(formData);
}

// 7. Letter File Upload
function uploadLetter(recordId, inputElement) {
    if (!inputElement.files || inputElement.files.length === 0) return;
    
    const file = inputElement.files[0];
    const formData = new FormData();
    formData.append('record_id', recordId);
    formData.append('letter_file', file);
    
    showNotification('success', 'Uploading letter...');
    
    fetch('ajax/upload_letter.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            showNotification('success', data.message);
            // Optionally reload to show the view link immediately
            setTimeout(() => {
                window.location.hash = 'row-' + recordId;
                window.location.reload();
            }, 1000);
        } else {
            showNotification('error', data.message);
        }
    })
    .catch(err => {
        showNotification('error', 'Network error during upload.');
    });
    
    // Clear input
    inputElement.value = '';
}
