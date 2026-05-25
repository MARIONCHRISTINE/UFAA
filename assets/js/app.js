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

// 4. Interactive Badge Status Toggles (Claim Status / Letter Received)
function toggleClaimStatus(recordId, currentStatus) {
    const badge = document.getElementById(`badge-status-${recordId}`);
    if (!badge || badge.getAttribute('data-loading') === 'true') return;

    badge.setAttribute('data-loading', 'true');
    const originalHTML = badge.innerHTML;
    badge.innerHTML = '<i class="badge-spinner"></i> <span>Saving...</span>';

    const nextStatus = currentStatus === 'Claimed' ? 'Unclaimed' : 'Claimed';

    updateRecord(recordId, 'status', nextStatus, (success, data) => {
        badge.removeAttribute('data-loading');
        if (success) {
            // Update UI badge classes & click handler
            badge.className = `status-badge ${nextStatus.toLowerCase()}`;
            badge.setAttribute('onclick', `toggleClaimStatus(${recordId}, '${nextStatus}')`);
            
            if (nextStatus === 'Claimed') {
                badge.innerHTML = '<i class="fa-solid fa-circle-check"></i> <span>Claimed</span>';
            } else {
                badge.innerHTML = '<i class="fa-solid fa-hourglass-half"></i> <span>Unclaimed</span>';
            }

            // Realtime stats counters adjustments
            adjustCounter('stat-claimed', nextStatus === 'Claimed' ? 1 : -1);
            adjustCounter('stat-unclaimed', nextStatus === 'Unclaimed' ? 1 : -1);
        } else {
            badge.innerHTML = originalHTML;
        }
    });
}

function toggleLetterReceived(recordId, currentVal) {
    const badge = document.getElementById(`badge-letter-${recordId}`);
    if (!badge || badge.getAttribute('data-loading') === 'true') return;

    badge.setAttribute('data-loading', 'true');
    const originalHTML = badge.innerHTML;
    badge.innerHTML = '<i class="badge-spinner"></i> <span>Saving...</span>';

    const nextVal = currentVal === 'Yes' ? 'No' : 'Yes';

    updateRecord(recordId, 'letter_received', nextVal, (success, data) => {
        badge.removeAttribute('data-loading');
        if (success) {
            // Update UI badge classes & click handler
            badge.className = `status-badge letter-${nextVal.toLowerCase()}`;
            badge.setAttribute('onclick', `toggleLetterReceived(${recordId}, '${nextVal}')`);
            
            if (nextVal === 'Yes') {
                badge.innerHTML = '<i class="fa-solid fa-envelope-open-text"></i> <span>Yes</span>';
            } else {
                badge.innerHTML = '<i class="fa-solid fa-envelope"></i> <span>No</span>';
            }

            // Realtime stats counters adjustments
            adjustCounter('stat-letters', nextVal === 'Yes' ? 1 : -1);
        } else {
            badge.innerHTML = originalHTML;
        }
    });
}

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

        // Receive dropped file
        dropzone.addEventListener('drop', e => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files.length > 0) {
                uploadExcelFile(files[0]);
            }
        }, false);
    }
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

function handleFileSelect(e) {
    const files = e.target.files;
    if (files.length > 0) {
        uploadExcelFile(files[0]);
    }
}

function uploadExcelFile(file) {
    const progressContainer = document.getElementById('upload-progress-container');
    const progressBarFill = document.getElementById('progress-bar-fill');
    const progressPercent = document.getElementById('progress-percent');
    const progressStatus = document.getElementById('progress-status');

    // File type validation — supports .xlsx, .xls, and .csv
    const ext = file.name.split('.').pop().toLowerCase();
    const allowedFormats = ['xlsx', 'xls', 'csv'];
    if (!allowedFormats.includes(ext)) {
        showNotification('error', 'Unsupported format! Please upload an Excel file (.xlsx, .xls) or a CSV file (.csv).');
        return;
    }

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
            if (progressStatus) progressStatus.innerText = 'Import complete!';
            
            // Reload page to show fresh rows
            setTimeout(() => {
                window.location.href = 'index.php';
            }, 1200);
        } else {
            if (progressContainer) progressContainer.style.display = 'none';
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
            setTimeout(() => window.location.reload(), 1000);
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
