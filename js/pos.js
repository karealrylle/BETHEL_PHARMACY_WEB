let cart = [];
let products = [];

// Load products on page load
document.addEventListener('DOMContentLoaded', function() {
    loadProducts();
    
    // Search functionality
    document.getElementById('searchProduct').addEventListener('input', function(e) {
        filterProducts(e.target.value);
    });
    
    // Amount paid change event
    document.getElementById('amountPaid').addEventListener('input', calculateChange);
    
    // Discount change event
    document.getElementById('discountPercent').addEventListener('input', updateTotals);
    
    // Payment method change event for GCash auto-fill
    document.getElementById('paymentMethod').addEventListener('change', function() {
        handlePaymentMethodChange(this.value);
    });
});

// Handle payment method change
function handlePaymentMethodChange(method) {
    const amountPaidInput = document.getElementById('amountPaid');
    const total = parseFloat(document.getElementById('totalAmount').textContent.replace('₱', '')) || 0;
    
    if (method === 'gcash') {
        // Auto-fill with exact total for GCash and disable input
        amountPaidInput.value = total.toFixed(2);
        amountPaidInput.disabled = true;
        amountPaidInput.style.backgroundColor = '#f0f0f0';
        amountPaidInput.style.cursor = 'not-allowed';
    } else {
        // Enable input for cash payments and clear value
        amountPaidInput.disabled = false;
        amountPaidInput.value = '';
        amountPaidInput.style.backgroundColor = '';
        amountPaidInput.style.cursor = '';
    }
    
    calculateChange();
}

// Update totals - also handle payment method when totals change
function updateTotals() {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
    const discountAmount = subtotal * (discountPercent / 100);
    const total = subtotal - discountAmount;
    
    document.getElementById('subtotalAmount').textContent = `₱${subtotal.toFixed(2)}`;
    document.getElementById('discountAmount').textContent = `₱${discountAmount.toFixed(2)}`;
    document.getElementById('totalAmount').textContent = `₱${total.toFixed(2)}`;
    
    // If payment method is GCash, update the amount paid automatically
    const paymentMethod = document.getElementById('paymentMethod').value;
    if (paymentMethod === 'gcash') {
        const amountPaidInput = document.getElementById('amountPaid');
        amountPaidInput.value = total.toFixed(2);
    }
    
    calculateChange();
}

// Load products from database
function loadProducts() {
    fetch('includes/get_products_pos.php')
        .then(response => response.json())
        .then(data => {
            products = data;
            displayProducts(products);
        })
        .catch(error => console.error('Error loading products:', error));
}

// Display products in table
function displayProducts(productsToDisplay) {
    const tbody = document.getElementById('productsTableBody');
    tbody.innerHTML = '';
    
    productsToDisplay.forEach(product => {
        const row = document.createElement('tr');
        const stockStatus = product.stock > 0 ? '' : 'Out of Stock';
        const isDisabled = product.stock <= 0;
        
        // Determine status class based on stock levels
        let statusClass = '';
        if (product.stock > 50) {
            statusClass = 'status-green';
        } else if (product.stock > 20) { 
            statusClass = 'status-orange';
        } else {
            statusClass = 'status-red';
        }

        row.innerHTML = `
            <td>${product.product_id}</td>
            <td style="text-align: left;">${product.product_name}</td>
            <td>${product.category}</td>
            <td>₱${parseFloat(product.price).toFixed(2)}</td>
            <td>
                <span class="status-badge ${statusClass}">
                    ${product.stock} ${stockStatus}
                </span>
            </td>
            <td>
                <button class="add-to-cart-btn" onclick="addToCart(${product.product_id})" ${isDisabled ? 'disabled' : ''}>
                    +
                </button>
            </td>
        `;
        tbody.appendChild(row);
    });
}
// Filter products
function filterProducts(searchTerm) {
    const filtered = products.filter(product => 
        product.product_name.toLowerCase().includes(searchTerm.toLowerCase()) ||
        product.category.toLowerCase().includes(searchTerm.toLowerCase())
    );
    displayProducts(filtered);
}

// Add product to cart
function addToCart(productId) {
    console.log('Adding product:', productId); // Debug log
    
    // Ensure productId is a number for comparison
    productId = parseInt(productId);
    
    const product = products.find(p => parseInt(p.product_id) === productId);
    
    if (!product) {
        console.error('Product not found:', productId);
        return;
    }
    
    if (product.stock <= 0) {
        alert('Product is out of stock!');
        return;
    }
    
    const existingItem = cart.find(item => parseInt(item.product_id) === productId);
    
    if (existingItem) {
        if (existingItem.quantity < product.stock) {
            existingItem.quantity++;
        } else {
            alert('Cannot add more. Insufficient stock!');
            return;
        }
    } else {
        cart.push({
            product_id: product.product_id,
            name: product.product_name,
            price: parseFloat(product.price),
            quantity: 1,
            max_stock: product.stock
        });
    }
    
    console.log('Cart after add:', cart); // Debug log
    updateCartDisplay();
    updateTotals();
}

// Update cart display
function updateCartDisplay() {
    const ordersPanel = document.getElementById('ordersPanel');
    
    if (cart.length === 0) {
        ordersPanel.innerHTML = '<div class="empty-cart"><p>No items added</p></div>';
        return;
    }
    
    ordersPanel.innerHTML = cart.map(item => `
        <div class="cart-item">
            <div class="cart-item-header">
                <div class="cart-item-name">${item.name}</div>
                <div class="cart-item-price">₱${item.price.toFixed(2)}</div>
            </div>
            <div class="cart-item-main-row">
                <div class="quantity-controls">
                    <button class="qty-btn" onclick="updateQuantity(${item.product_id}, -1)">−</button>
                    <input type="number" class="quantity-input" value="${item.quantity}" min="1" max="${item.max_stock}" 
                           onchange="updateQuantityInput(${item.product_id}, this.value)" 
                           onblur="validateQuantityInput(${item.product_id}, this)">
                    <button class="qty-btn" onclick="updateQuantity(${item.product_id}, 1)">+</button>
                </div>
                <div class="cart-item-subtotal">₱${(item.price * item.quantity).toFixed(2)}</div>
                <button class="remove-item-btn" onclick="removeFromCart(${item.product_id})">
                    <img src="assets/redbin.png" alt="Remove">
                </button>
            </div>
        </div>
    `).join('');
}

// Update quantity
function updateQuantity(productId, change) {
    productId = parseInt(productId);
    const item = cart.find(i => parseInt(i.product_id) === productId);
    if (!item) return;
    
    const newQuantity = item.quantity + change;
    
    if (newQuantity <= 0) {
        removeFromCart(productId);
        return;
    }
    
    if (newQuantity > item.max_stock) {
        alert('Cannot add more. Insufficient stock!');
        return;
    }
    
    item.quantity = newQuantity;
    updateCartDisplay();
    updateTotals();
}

// Update quantity via input field
function updateQuantityInput(productId, newQuantity) {
    productId = parseInt(productId);
    newQuantity = parseInt(newQuantity);
    
    const item = cart.find(i => parseInt(i.product_id) === productId);
    if (!item) return;
    
    if (newQuantity < 1) {
        removeFromCart(productId);
        return;
    }
    
    if (newQuantity > item.max_stock) {
        alert('Cannot add more. Insufficient stock!');
        document.querySelector(`.quantity-input[value="${item.quantity}"]`).value = item.max_stock;
        newQuantity = item.max_stock;
    }
    
    item.quantity = newQuantity;
    updateCartDisplay();
    updateTotals();
}

// Validate quantity input on blur
function validateQuantityInput(productId, inputElement) {
    productId = parseInt(productId);
    let newQuantity = parseInt(inputElement.value);
    
    const item = cart.find(i => parseInt(i.product_id) === productId);
    if (!item) return;
    
    if (isNaN(newQuantity) || newQuantity < 1) {
        inputElement.value = 1;
        item.quantity = 1;
    } else if (newQuantity > item.max_stock) {
        inputElement.value = item.max_stock;
        item.quantity = item.max_stock;
        alert('Quantity adjusted to available stock');
    } else {
        item.quantity = newQuantity;
    }
    
    updateCartDisplay();
    updateTotals();
}

// Remove item from cart
function removeFromCart(productId) {
    productId = parseInt(productId);
    cart = cart.filter(item => parseInt(item.product_id) !== productId);
    updateCartDisplay();
    updateTotals();
}

// Clear cart
function clearCart() {
    if (cart.length === 0) return;
    
    if (confirm('Are you sure you want to clear the cart?')) {
        cart = [];
        updateCartDisplay();
        updateTotals();
        document.getElementById('amountPaid').value = '';
    }
}

// Update totals
function updateTotals() {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
    const discountAmount = subtotal * (discountPercent / 100);
    const total = subtotal - discountAmount;
    
    document.getElementById('subtotalAmount').textContent = `₱${subtotal.toFixed(2)}`;
    document.getElementById('discountAmount').textContent = `₱${discountAmount.toFixed(2)}`;
    document.getElementById('totalAmount').textContent = `₱${total.toFixed(2)}`;
    
    calculateChange();
}

// Calculate change
function calculateChange() {
    const total = parseFloat(document.getElementById('totalAmount').textContent.replace('₱', ''));
    const amountPaid = parseFloat(document.getElementById('amountPaid').value) || 0;
    const change = amountPaid - total;
    
    document.getElementById('changeAmount').textContent = `₱${Math.max(0, change).toFixed(2)}`;
}

// Process payment
function processPayment() {
    if (cart.length === 0) {
        alert('Cart is empty!');
        return;
    }
    
    const total = parseFloat(document.getElementById('totalAmount').textContent.replace('₱', ''));
    const amountPaid = parseFloat(document.getElementById('amountPaid').value) || 0;
    const paymentMethod = document.getElementById('paymentMethod').value;
    
    // For GCash, ensure amount paid equals total
    if (paymentMethod === 'gcash') {
        if (amountPaid !== total) {
            alert('For GCash payments, amount paid must equal the total amount.');
            return;
        }
    } else {
        // For cash, check if payment is sufficient
        if (amountPaid < total) {
            alert('Insufficient payment amount!');
            return;
        }
    }
    
    const change = amountPaid - total;
    const discountPercent = parseFloat(document.getElementById('discountPercent').value) || 0;
    
    const saleData = {
        items: cart,
        total_amount: total,
        payment_method: paymentMethod,
        amount_paid: amountPaid,
        change_amount: change,
        discount_percent: discountPercent
    };
    
    // Disable button
    const btn = document.querySelector('.process-payment-btn');
    btn.disabled = true;
    btn.textContent = 'Processing...';
    
    fetch('includes/process_sale.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(saleData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Payment processed successfully!');
            printReceipt(data.sale_id, saleData);
            clearCart();
            loadProducts(); // Reload to update stock
            document.getElementById('amountPaid').value = '';
            document.getElementById('discountPercent').value = '0';
            // Reset payment method to cash after successful payment
            document.getElementById('paymentMethod').value = 'cash';
            handlePaymentMethodChange('cash');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while processing payment');
    })
    .finally(() => {
        btn.disabled = false;
        btn.textContent = 'Process Payment';
    });
}

// Load products from database
function loadProducts() {
    fetch('includes/get_products_pos.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            console.log('Products loaded:', data); // Debug log
            products = data;
            displayProducts(products);
        })
        .catch(error => {
            console.error('Error loading products:', error);
            alert('Error loading products. Please check console for details.');
        });
}

// Print receipt
function printReceipt(saleId, saleData) {
    const printWindow = window.open('', '_blank', 'width=400,height=700,scrollbars=yes');
    const receiptDate = new Date().toLocaleString();
    
    let receiptHTML = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt #${saleId}</title>
            <style>
                body { 
                    font-family: 'Courier New', monospace; 
                    padding: 25px;
                    margin: 0;
                    background: white;
                }
                .header { 
                    text-align: center; 
                    margin-bottom: 25px; 
                }
                .logo { 
                    width: 200px; 
                    margin-bottom: 10px;
                }
                table { 
                    width: 100%; 
                    margin: 15px 0; 
                    border-collapse: collapse;
                }
                .item-row td { 
                    padding: 8px 0; 
                    border-bottom: 1px dashed #ddd;
                }
                th {
                    padding: 10px 0;
                    border-bottom: 2px dashed #000;
                }
                .totals { 
                    border-top: 2px dashed #000; 
                    margin-top: 20px; 
                    padding-top: 15px; 
                }
                .total-row { 
                    display: flex; 
                    justify-content: space-between; 
                    margin: 8px 0; 
                }
                .grand-total { 
                    font-weight: bold; 
                    font-size: 18px; 
                    margin-top: 15px; 
                }
                .footer { 
                    text-align: center; 
                    margin-top: 25px; 
                    font-size: 14px; 
                }
                .receipt-info {
                    margin: 10px 0;
                    font-size: 14px;
                }
                .print-button {
                    display: block;
                    margin: 30px auto 0 auto;
                    background: #C92126;
                    color: white;
                    border: none;
                    padding: 12px 30px;
                    border-radius: 5px;
                    font-size: 16px;
                    font-weight: bold;
                    cursor: pointer;
                    width: 200px;
                }
                .print-button:hover {
                    background: #A81C20;
                }
                @media print {
                    .print-button {
                        display: none;
                    }
                }
            </style>
        </head>
        <body>
            <div class="header">
                <img src="assets/bethel_logo.png" alt="Logo" class="logo">
                <h2>BETHEL PHARMACY</h2>
                <p>Official Receipt</p>
                <div class="receipt-info">
                    <p><strong>Receipt #${saleId}</strong></p>
                    <p>${receiptDate}</p>
                </div>
            </div>
            
            <table>
                <tr>
                    <th align="left">Item</th>
                    <th>Qty</th>
                    <th align="right">Price</th>
                    <th align="right">Total</th>
                </tr>
    `;
    
    saleData.items.forEach(item => {
        receiptHTML += `
                <tr class="item-row">
                    <td>${item.name}</td>
                    <td align="center">${item.quantity}</td>
                    <td align="right">₱${item.price.toFixed(2)}</td>
                    <td align="right">₱${(item.price * item.quantity).toFixed(2)}</td>
                </tr>
        `;
    });
    
    const subtotal = saleData.items.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const discount = subtotal * (saleData.discount_percent / 100);
    
    receiptHTML += `
            </table>
            
            <div class="totals">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>₱${subtotal.toFixed(2)}</span>
                </div>
    `;
    
    if (saleData.discount_percent > 0) {
        receiptHTML += `
                <div class="total-row">
                    <span>Discount (${saleData.discount_percent}%):</span>
                    <span>-₱${discount.toFixed(2)}</span>
                </div>
        `;
    }
    
    receiptHTML += `
                <div class="total-row grand-total">
                    <span>TOTAL:</span>
                    <span>₱${saleData.total_amount.toFixed(2)}</span>
                </div>
                <div class="total-row">
                    <span>Payment (${saleData.payment_method.toUpperCase()}):</span>
                    <span>₱${saleData.amount_paid.toFixed(2)}</span>
                </div>
                <div class="total-row">
                    <span>Change:</span>
                    <span>₱${saleData.change_amount.toFixed(2)}</span>
                </div>
            </div>
            
            <div class="footer">
                <p>Thank you for your purchase!</p>
                <p>This serves as your official receipt</p>
            </div>
            
            <button class="print-button" onclick="window.print()">🖨️ Print Receipt</button>
        </body>
        </html>
    `;
    
    printWindow.document.write(receiptHTML);
    printWindow.document.close();
    printWindow.focus();
}