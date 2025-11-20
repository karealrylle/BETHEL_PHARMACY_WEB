// Batch Management Functions
function viewBatches(productId, productName) {
    document.getElementById('batchModalTitle').textContent = productName;
    document.getElementById('batchViewModal').classList.add('show');
    document.getElementById('batchModalOverlay').classList.add('show');
    
    const batchList = document.getElementById('batchList');
    batchList.innerHTML = '<div class="loading-spinner">Loading batches...</div>';
    
    fetch('includes/get_batches.php?product_id=' + productId)
        .then(response => response.json())
        .then(data => {
            if (data.success) displayBatches(data.batches, data.total_stock, data.active_count);
            else batchList.innerHTML = '<div class="no-batches">No batches found</div>';
        })
        .catch(error => batchList.innerHTML = '<div class="error-message">Error loading batches</div>');
}

function closeBatchModal() {
    document.getElementById('batchViewModal').classList.remove('show');
    document.getElementById('batchModalOverlay').classList.remove('show');
}

function openAddBatchModal(productId, productName) {
    // Reset all form fields
    document.getElementById('batch_quantity').value = '';
    document.getElementById('batch_manufactured').value = '';
    document.getElementById('batch_expiry').value = '';
    document.getElementById('supplier_name').value = '';
    document.getElementById('purchase_price').value = '';
    
    document.getElementById('batch_product_id').value = productId;
    document.getElementById('batch_product_name').value = productName;
    document.getElementById('batch_display_product_id').value = 'PRD-' + String(productId).padStart(3, '0');
    
    fetch('update_stock.php?ajax=get_batch_count&product_id=' + productId)
        .then(response => response.json())
        .then(data => {
            const nextBatch = (data.count || 0) + 1;
            const batchNumber = 'BTH-' + productId + String(nextBatch).padStart(3, '0');
            document.getElementById('batch_number').value = batchNumber;
        })
        .catch(() => {
            document.getElementById('batch_number').value = 'BTH-' + productId + '001';
        });
    
    document.getElementById('addBatchModal').classList.add('show');
    document.getElementById('addBatchModalOverlay').classList.add('show');
}

function closeAddBatchModal() {
    document.getElementById('addBatchModal').classList.remove('show');
    document.getElementById('addBatchModalOverlay').classList.remove('show');
    const form = document.querySelector('#addBatchModal form');
    if (form) {
        form.reset();
    }
}

function displayBatches(batches, totalStock, activeCount) {
    let stockStatus = totalStock > 200 ? 'Good Stock' : (totalStock >= 50 ? 'Low in Stock' : 'Critical Stock');
    let stockClass = totalStock > 200 ? 'status-good' : (totalStock >= 50 ? 'status-low' : 'status-critical');
    
    document.getElementById('batchSummaryContainer').innerHTML = `
        <div class="batch-summary-item ${stockClass}">
            <span class="batch-summary-label ${stockClass}">${stockStatus}</span>
            <span class="batch-summary-value">${totalStock}</span>
        </div>
        <div class="batch-summary-item status-available">
            <span class="batch-summary-label status-available">Total Batches</span>
            <span class="batch-summary-value">${activeCount}</span>
        </div>`;
    
    const batchList = document.getElementById('batchList');
    if (batches.length === 0) {
        batchList.innerHTML = '<div class="no-batches">No batches available</div>';
        return;
    }
    
    // Find the closest-to-expiry FRESH batch for "In Use"
    let inUseBatchId = null;
    let closestDays = Infinity;
    
    batches.forEach((batch) => {
        const today = new Date();
        const expiryDate = new Date(batch.expiry_date);
        const diffTime = expiryDate - today;
        const daysLeft = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        const monthsLeft = daysLeft / 30;
        
        // Only consider fresh batches for "In Use"
        if (daysLeft > 0 && monthsLeft >= 12 && daysLeft < closestDays) {
            closestDays = daysLeft;
            inUseBatchId = batch.batch_id;
        }
    });
    
    let html = '';
    batches.forEach((batch) => {
        const today = new Date();
        const expiryDate = new Date(batch.expiry_date);
        const diffTime = expiryDate - today;
        const daysLeft = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
        const monthsLeft = daysLeft / 30;
        
        const cardClass = daysLeft < 0 ? 'expired' : (monthsLeft < 12 ? 'near-expiry' : 'fresh');
        let batchStatus = 'Available';
        let statusClass = 'status-available';

        if (daysLeft < 0) {
            batchStatus = 'Expired';
            statusClass = 'status-expired';
        } else if (monthsLeft < 12) {
            batchStatus = 'Near Expiry';
            statusClass = 'status-near-expiry';
        } else if (batch.batch_id === inUseBatchId) {
            batchStatus = 'In Use';
            statusClass = 'status-in-use';
        }
        
        let disposeBtn = '';
        if (daysLeft < 0 || monthsLeft < 12) {
            disposeBtn = `<button class="dispose-btn" onclick="disposeBatch(${batch.batch_id}, '${batch.batch_number}')">
                <img src="assets/bin.png" alt="Dispose">
            </button>`;
        }
        
        html += `
            <div class="batch-card ${cardClass}">
                <div class="batch-card-header">
                    <span class="batch-number">${batch.batch_number}</span>
                    <div class="batch-status-container">
                        <span class="batch-status ${statusClass}">${batchStatus}</span>
                        ${disposeBtn}
                    </div>
                </div>
                <div class="batch-card-body">
                    <div class="batch-info-item">
                        <span class="batch-label">Quantity:</span>
                        <span class="batch-value">${batch.quantity} units</span>
                    </div>
                    <div class="batch-info-item">
                        <span class="batch-label">Expiry:</span>
                        <span class="batch-value">${new Date(batch.expiry_date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}</span>
                    </div>
                    <div class="batch-info-item">
                        <span class="batch-label">Days Left:</span>
                        <span class="batch-value">${daysLeft} days</span>
                    </div>
                </div>
            </div>`;
    });
    batchList.innerHTML = html;
}

function disposeBatch(batchId, batchNumber) {
    if (confirm(`Are you sure you want to dispose of batch ${batchNumber}? This action cannot be undone.`)) {
        fetch('includes/dispose_batch.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ 
                batch_id: batchId,
                reason: 'expiry'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Batch disposed successfully');
                closeBatchModal();
                location.reload();
            } else {
                alert('Error: ' + (data.message || 'Failed to dispose batch'));
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error disposing batch');
        });
    }
}

// Stock Update Functions
function updateStock(productId, currentStock) {
    const addedStock = prompt(`Current stock: ${currentStock}\n\nEnter quantity to add:`, '0');
    
    if (addedStock === null) return; // User cancelled
    
    const addedStockNum = parseInt(addedStock);
    if (isNaN(addedStockNum) || addedStockNum < 0) {
        alert('Please enter a valid positive number');
        return;
    }

    // Submit via form
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'includes/add_batch.php'; // Reuse add_batch for simplicity
    
    const productIdInput = document.createElement('input');
    productIdInput.type = 'hidden';
    productIdInput.name = 'product_id';
    productIdInput.value = productId;
    
    const quantityInput = document.createElement('input');
    quantityInput.type = 'hidden';
    quantityInput.name = 'quantity';
    quantityInput.value = addedStockNum;
    
    const batchNumberInput = document.createElement('input');
    batchNumberInput.type = 'hidden';
    batchNumberInput.name = 'batch_number';
    batchNumberInput.value = 'STOCK-UPDATE-' + Date.now();
    
    form.appendChild(productIdInput);
    form.appendChild(quantityInput);
    form.appendChild(batchNumberInput);
    
    document.body.appendChild(form);
    form.submit();
}

// Handle form submission
function handleBatchFormSubmit(e) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = form.querySelector('button[type="submit"]');
    
    // Get form values
    const expiryDate = document.getElementById('batch_expiry').value;
    const manufacturedDate = document.getElementById('batch_manufactured').value;
    const quantity = document.getElementById('batch_quantity').value;
    
    // Debug: Log values
    console.log('Expiry Date:', expiryDate);
    console.log('Manufactured Date:', manufacturedDate);
    console.log('Quantity:', quantity);
    
    // Validate required fields
    if (!expiryDate) {
        alert('Please select an expiry date');
        return false;
    }
    
    if (!quantity || quantity <= 0) {
        alert('Please enter a valid quantity');
        return false;
    }
    
    // Show loading state
    const originalBtnText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving...';
    
    // Let the form submit normally
    form.submit();
    
    return true;
}

// Real-time search functionality
function setupRealTimeSearch() {
    const searchInput = document.getElementById('searchInput');
    if (!searchInput) return;

    // Add event listener for input changes
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const table = document.querySelector('.products-table tbody');
        
        if (!table) return;

        const rows = table.getElementsByTagName('tr');
        let hasVisibleRows = false;

        for (let row of rows) {
            let found = false;
            const cells = row.getElementsByTagName('td');
            
            // Skip if no cells or header row
            if (cells.length === 0) continue;

            // Check each cell in the row (except the last cell with action buttons)
            for (let i = 0; i < cells.length - 1; i++) {
                const cell = cells[i];
                if (cell.textContent.toLowerCase().includes(searchTerm)) {
                    found = true;
                    break;
                }
            }

            // Show/hide row based on search
            row.style.display = found ? '' : 'none';
            if (found) hasVisibleRows = true;
        }

        // Show/hide no results message
        const noResults = document.getElementById('noResultsMessage');
        if (!noResults && !hasVisibleRows && rows.length > 0) {
            const noResultsRow = document.createElement('tr');
            noResultsRow.id = 'noResultsMessage';
            noResultsRow.innerHTML = `<td colspan="7" style="text-align: center; padding: 1rem;">No products found matching "${searchTerm}"</td>`;
            table.appendChild(noResultsRow);
        } else if (noResults) {
            if (hasVisibleRows) {
                noResults.remove();
            } else {
                noResults.querySelector('td').textContent = `No products found matching "${searchTerm}"`;
            }
        }
    });
}

// Event Listeners
document.addEventListener('DOMContentLoaded', function() {
    // Add form submit handler
    const addBatchForm = document.getElementById('addBatchForm');
    if (addBatchForm) {
        addBatchForm.addEventListener('submit', handleBatchFormSubmit);
    }
    
    // Modal overlay click events
    const batchModalOverlay = document.getElementById('batchModalOverlay');
    const addBatchModalOverlay = document.getElementById('addBatchModalOverlay');
    
    if (batchModalOverlay) batchModalOverlay.onclick = closeBatchModal;
    if (addBatchModalOverlay) addBatchModalOverlay.onclick = closeAddBatchModal;
    
    // Initialize real-time search
    setupRealTimeSearch();
    
    // Escape key to close modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeBatchModal();
            closeAddBatchModal();
        }
    });
});