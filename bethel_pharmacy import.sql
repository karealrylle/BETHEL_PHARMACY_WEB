-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 20, 2025 at 10:46 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `bethel_pharmacy`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_add_batch` (IN `p_product_id` INT, IN `p_batch_number` VARCHAR(50), IN `p_quantity` INT, IN `p_manufactured_date` DATE, IN `p_expiry_date` DATE, IN `p_supplier_name` VARCHAR(255), IN `p_purchase_price` DECIMAL(10,2), IN `p_user_id` INT)   BEGIN
    DECLARE v_batch_id INT;
    
    -- Insert the new batch
    INSERT INTO product_batches (
        product_id,
        batch_number,
        quantity,
        original_quantity,
        manufactured_date,
        expiry_date,
        supplier_name,
        purchase_price,
        received_date,
        status
    )
    VALUES (
        p_product_id,
        p_batch_number,
        p_quantity,
        p_quantity,
        p_manufactured_date,
        p_expiry_date,
        p_supplier_name,
        p_purchase_price,
        CURDATE(),
        'available'
    );
    
    SET v_batch_id = LAST_INSERT_ID();
    
    -- Record the movement
    INSERT INTO batch_movements (
        batch_id,
        sale_item_id,
        movement_type,
        quantity,
        remaining_quantity,
        performed_by,
        notes
    )
    VALUES (
        v_batch_id,
        NULL,
        'restock',
        p_quantity,
        p_quantity,
        p_user_id,
        CONCAT('New batch added: ', p_batch_number)
    );
    
    -- Update legacy current_stock for backward compatibility
    UPDATE products
    SET current_stock = current_stock + p_quantity
    WHERE product_id = p_product_id;
    
    SELECT v_batch_id AS batch_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_process_sale_fifo` (IN `p_sale_id` INT, IN `p_product_id` INT, IN `p_quantity` INT, IN `p_user_id` INT)   BEGIN
    DECLARE v_remaining_qty INT DEFAULT p_quantity;
    DECLARE v_batch_id INT;
    DECLARE v_batch_qty INT;
    DECLARE v_qty_to_deduct INT;
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_sale_item_id INT;
    
    -- Cursor to get batches in FIFO order (earliest expiry first)
    DECLARE batch_cursor CURSOR FOR
        SELECT batch_id, quantity
        FROM product_batches
        WHERE product_id = p_product_id
            AND quantity > 0
            AND status IN ('available', 'low_stock')
            AND expiry_date > CURDATE()
        ORDER BY expiry_date ASC, received_date ASC, batch_id ASC;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Get sale_item_id for tracking
    SELECT sale_item_id INTO v_sale_item_id
    FROM sale_items
    WHERE sale_id = p_sale_id AND product_id = p_product_id
    ORDER BY sale_item_id DESC
    LIMIT 1;
    
    OPEN batch_cursor;
    
    batch_loop: LOOP
        FETCH batch_cursor INTO v_batch_id, v_batch_qty;
        
        IF done OR v_remaining_qty <= 0 THEN
            LEAVE batch_loop;
        END IF;
        
        -- Determine how much to deduct from this batch
        SET v_qty_to_deduct = LEAST(v_batch_qty, v_remaining_qty);
        
        -- Update batch quantity
        UPDATE product_batches
        SET quantity = quantity - v_qty_to_deduct,
            status = CASE 
                WHEN quantity - v_qty_to_deduct = 0 THEN 'depleted'
                WHEN quantity - v_qty_to_deduct <= 10 THEN 'low_stock'
                ELSE 'available'
            END,
            updated_at = CURRENT_TIMESTAMP
        WHERE batch_id = v_batch_id;
        
        -- Record the movement
        INSERT INTO batch_movements (
            batch_id, 
            sale_item_id, 
            movement_type, 
            quantity, 
            remaining_quantity,
            performed_by,
            movement_date
        )
        VALUES (
            v_batch_id,
            v_sale_item_id,
            'sale',
            -v_qty_to_deduct,
            v_batch_qty - v_qty_to_deduct,
            p_user_id,
            CURRENT_TIMESTAMP
        );
        
        SET v_remaining_qty = v_remaining_qty - v_qty_to_deduct;
    END LOOP;
    
    CLOSE batch_cursor;
    
    -- Check if we couldn't fulfill the entire order
    IF v_remaining_qty > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Insufficient stock to complete sale';
    END IF;
    
    -- Also update legacy current_stock for backward compatibility
    UPDATE products
    SET current_stock = current_stock - p_quantity
    WHERE product_id = p_product_id;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_update_shift_stats` (IN `p_shift_id` INT)   BEGIN
    DECLARE v_user_id INT;
    DECLARE v_clock_in DATETIME;
    DECLARE v_clock_out DATETIME;
    
    -- Get shift details
    SELECT user_id, clock_in, clock_out 
    INTO v_user_id, v_clock_in, v_clock_out
    FROM staff_shifts 
    WHERE shift_id = p_shift_id;
    
    -- Update shift statistics from sales data
    UPDATE staff_shifts ss
    SET 
        total_sales = (
            SELECT COALESCE(SUM(s.total_amount), 0)
            FROM sales s
            WHERE s.user_id = v_user_id
            AND s.sale_date BETWEEN v_clock_in AND IFNULL(v_clock_out, NOW())
        ),
        transactions_count = (
            SELECT COUNT(*)
            FROM sales s
            WHERE s.user_id = v_user_id
            AND s.sale_date BETWEEN v_clock_in AND IFNULL(v_clock_out, NOW())
        ),
        items_sold = (
            SELECT COALESCE(SUM(si.quantity), 0)
            FROM sales s
            JOIN sale_items si ON s.sale_id = si.sale_id
            WHERE s.user_id = v_user_id
            AND s.sale_date BETWEEN v_clock_in AND IFNULL(v_clock_out, NOW())
        ),
        gcash_sales = (
            SELECT COALESCE(SUM(s.total_amount), 0)
            FROM sales s
            WHERE s.user_id = v_user_id
            AND s.payment_method = 'gcash'
            AND s.sale_date BETWEEN v_clock_in AND IFNULL(v_clock_out, NOW())
        ),
        cash_sales = (
            SELECT COALESCE(SUM(s.total_amount), 0)
            FROM sales s
            WHERE s.user_id = v_user_id
            AND s.payment_method = 'cash'
            AND s.sale_date BETWEEN v_clock_in AND IFNULL(v_clock_out, NOW())
        ),
        shift_duration = CASE 
            WHEN v_clock_out IS NOT NULL 
            THEN TIMESTAMPDIFF(MINUTE, v_clock_in, v_clock_out) - break_duration
            ELSE NULL
        END,
        status = CASE 
            WHEN v_clock_out IS NOT NULL THEN 'completed'
            ELSE 'active'
        END
    WHERE shift_id = p_shift_id;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `fn_get_available_stock` (`p_product_id` INT) RETURNS INT(11) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE v_total_stock INT;
    
    SELECT COALESCE(SUM(quantity), 0) INTO v_total_stock
    FROM product_batches
    WHERE product_id = p_product_id
        AND status IN ('available', 'low_stock')
        AND expiry_date > CURDATE();
    
    RETURN v_total_stock;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `batch_movements`
--

CREATE TABLE `batch_movements` (
  `movement_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `sale_item_id` int(11) DEFAULT NULL COMMENT 'NULL for non-sale movements',
  `movement_type` enum('sale','restock','adjustment','return','expiry','damage') NOT NULL,
  `quantity` int(11) NOT NULL COMMENT 'Negative for outgoing, positive for incoming',
  `remaining_quantity` int(11) NOT NULL COMMENT 'Batch quantity after this movement',
  `movement_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `batch_movements`
--

INSERT INTO `batch_movements` (`movement_id`, `batch_id`, `sale_item_id`, `movement_type`, `quantity`, `remaining_quantity`, `movement_date`, `notes`, `performed_by`) VALUES
(1, 1, NULL, 'restock', 20, 20, '2025-11-15 10:15:17', 'New batch added: BTH-202511-214', NULL),
(2, 2, NULL, 'restock', 150, 150, '2025-11-15 11:52:23', 'New batch added: BTH-202511-161', NULL),
(3, 3, NULL, 'restock', 100, 100, '2025-11-15 11:52:50', 'New batch added: BTH-202511-415', NULL),
(4, 4, NULL, 'restock', 100, 100, '2025-11-15 11:54:05', 'New batch added: BTH-202511-483', NULL),
(5, 5, NULL, 'restock', 200, 200, '2025-11-15 15:26:24', 'New batch added: BTH-1763220364620', NULL),
(15, 15, NULL, 'restock', 100, 100, '2025-11-16 09:13:43', 'New batch added: BTH-6001', NULL),
(16, 16, NULL, 'restock', 100, 100, '2025-11-16 09:13:49', 'New batch added: BTH-7001', NULL),
(17, 17, NULL, 'restock', 100, 100, '2025-11-16 09:13:53', 'New batch added: BTH-8001', NULL),
(18, 18, NULL, 'restock', 100, 100, '2025-11-16 09:13:58', 'New batch added: BTH-9001', NULL),
(19, 19, NULL, 'restock', 100, 100, '2025-11-16 09:14:04', 'New batch added: BTH-10001', NULL),
(20, 20, NULL, 'restock', 100, 100, '2025-11-16 09:14:09', 'New batch added: BTH-11001', NULL),
(21, 21, NULL, 'restock', 100, 100, '2025-11-16 09:14:13', 'New batch added: BTH-12001', NULL),
(22, 22, NULL, 'restock', 100, 100, '2025-11-16 09:14:18', 'New batch added: BTH-13001', NULL),
(23, 23, NULL, 'restock', 100, 100, '2025-11-16 09:14:26', 'New batch added: BTH-14001', NULL),
(24, 24, NULL, 'restock', 100, 100, '2025-11-16 09:14:32', 'New batch added: BTH-15001', NULL),
(26, 26, NULL, 'restock', 100, 100, '2025-11-16 09:16:07', 'New batch added: BTH-46001', NULL),
(27, 27, NULL, 'restock', 100, 100, '2025-11-16 09:16:13', 'New batch added: BTH-16001', NULL),
(29, 29, NULL, 'restock', 100, 100, '2025-11-16 09:16:27', 'New batch added: BTH-18001', NULL),
(30, 30, NULL, 'restock', 100, 100, '2025-11-16 09:17:03', 'New batch added: BTH-40001', NULL),
(31, 31, NULL, 'restock', 100, 100, '2025-11-16 09:17:08', 'New batch added: BTH-39001', NULL),
(32, 32, NULL, 'restock', 100, 100, '2025-11-16 09:17:26', 'New batch added: BTH-19001', NULL),
(33, 33, NULL, 'restock', 100, 100, '2025-11-16 09:17:32', 'New batch added: BTH-20001', NULL),
(34, 34, NULL, 'restock', 150, 150, '2025-11-16 09:17:55', 'New batch added: BTH-23001', NULL),
(35, 35, NULL, 'restock', 150, 150, '2025-11-16 09:18:01', 'New batch added: BTH-21001', NULL),
(36, 36, NULL, 'restock', 150, 150, '2025-11-16 09:18:10', 'New batch added: BTH-25001', NULL),
(37, 37, NULL, 'restock', 150, 150, '2025-11-16 09:18:15', 'New batch added: BTH-22001', NULL),
(38, 38, NULL, 'restock', 150, 150, '2025-11-16 09:18:20', 'New batch added: BTH-24001', NULL),
(39, 39, NULL, 'restock', 150, 150, '2025-11-16 09:18:26', 'New batch added: BTH-27001', NULL),
(40, 40, NULL, 'restock', 150, 150, '2025-11-16 09:18:33', 'New batch added: BTH-38001', NULL),
(41, 41, NULL, 'restock', 150, 150, '2025-11-16 09:18:47', 'New batch added: BTH-37001', NULL),
(42, 42, NULL, 'restock', 150, 150, '2025-11-16 09:18:51', 'New batch added: BTH-36001', NULL),
(43, 43, NULL, 'restock', 150, 150, '2025-11-16 09:18:57', 'New batch added: BTH-31001', NULL),
(44, 44, NULL, 'restock', 150, 150, '2025-11-16 09:19:02', 'New batch added: BTH-28001', NULL),
(45, 45, NULL, 'restock', 150, 150, '2025-11-16 09:19:20', 'New batch added: BTH-29001', NULL),
(46, 46, NULL, 'restock', 150, 150, '2025-11-16 09:19:39', 'New batch added: BTH-30001', NULL),
(47, 47, NULL, 'restock', 120, 120, '2025-11-16 09:19:52', 'New batch added: BTH-33001', NULL),
(48, 48, NULL, 'restock', 50, 50, '2025-11-16 09:20:29', 'New batch added: BTH-32001', NULL),
(49, 49, NULL, 'restock', 50, 50, '2025-11-16 09:20:36', 'New batch added: BTH-34001', NULL),
(50, 50, NULL, 'restock', 50, 50, '2025-11-16 09:20:41', 'New batch added: BTH-35001', NULL),
(52, 52, NULL, 'restock', 100, 100, '2025-11-16 16:18:07', 'New batch added: BTH-4011', NULL),
(55, 15, NULL, 'expiry', -100, 0, '2025-11-16 17:05:05', 'Batch disposed: BTH-6001', NULL),
(56, 27, NULL, 'expiry', -100, 0, '2025-11-16 17:17:17', 'Batch disposed: BTH-16001', NULL),
(57, 27, NULL, 'expiry', 0, 0, '2025-11-16 17:17:20', 'Batch disposed: BTH-16001', NULL),
(62, 57, NULL, 'restock', 150, 150, '2025-11-19 13:58:05', 'New batch added: BTH-6011', 1),
(63, 16, NULL, 'expiry', 100, 0, '2025-11-19 13:58:36', 'Batch disposed due to expiry', 1),
(64, 17, NULL, 'expiry', 100, 0, '2025-11-19 13:59:34', 'Batch disposed due to expiry', 2),
(65, 58, NULL, 'restock', 150, 150, '2025-11-19 13:59:51', 'New batch added: BTH-7011', 2),
(66, 2, 1, 'sale', -3, 147, '2025-11-19 14:00:58', NULL, 2),
(67, 58, 2, 'sale', -2, 148, '2025-11-19 14:00:58', NULL, 2),
(68, 48, 3, 'sale', -1, 49, '2025-11-19 14:00:58', NULL, 2),
(69, 52, 4, 'sale', -5, 95, '2025-11-19 14:01:44', NULL, 2),
(70, 59, NULL, 'restock', 150, 150, '2025-11-19 16:02:49', 'New batch added: BTH-8011', 1),
(71, 60, NULL, 'restock', 20, 20, '2025-11-19 16:03:38', 'New batch added: BTH-16011', 1),
(72, 57, 5, 'sale', -3, 147, '2025-11-20 11:13:42', NULL, 2),
(73, 52, 6, 'sale', -5, 90, '2025-11-20 11:13:42', NULL, 2),
(74, 5, 7, 'sale', -4, 196, '2025-11-20 11:13:42', NULL, 2),
(75, 2, 8, 'sale', -3, 144, '2025-11-20 11:13:42', NULL, 2),
(76, 1, 9, 'sale', -2, 18, '2025-11-20 11:13:42', NULL, 2),
(77, 4, 10, 'sale', -20, 80, '2025-11-20 11:14:16', NULL, 2),
(78, 1, 11, 'sale', -3, 15, '2025-11-20 13:10:02', NULL, 2),
(79, 48, 12, 'sale', -3, 46, '2025-11-20 13:10:02', NULL, 2),
(80, 57, 13, 'sale', -8, 139, '2025-11-20 13:10:20', NULL, 2),
(81, 59, NULL, 'expiry', 150, 0, '2025-11-20 14:17:02', 'Batch disposed due to expiry', 1),
(82, 61, NULL, 'expiry', 50, 0, '2025-11-20 14:17:18', 'Batch disposed due to expiry', 1),
(83, 1, 14, 'sale', -1, 14, '2025-11-20 14:20:10', NULL, 2),
(84, 2, 15, 'sale', -1, 143, '2025-11-20 14:20:10', NULL, 2),
(85, 49, 16, 'sale', -1, 49, '2025-11-20 14:20:10', NULL, 2),
(86, 58, 17, 'sale', -1, 147, '2025-11-20 14:20:10', NULL, 2),
(87, 66, NULL, 'restock', 150, 150, '2025-11-20 14:20:39', 'New batch added: BTH-1031', 2),
(88, 2, 18, 'sale', -100, 43, '2025-11-20 15:46:22', NULL, 3),
(89, 58, 19, 'sale', -20, 127, '2025-11-20 15:46:53', NULL, 3),
(90, 5, 20, 'sale', -6, 190, '2025-11-20 16:06:12', NULL, 3),
(91, 67, NULL, 'restock', 150, 150, '2025-11-20 20:34:21', 'New batch added: BTH-8021', 1),
(92, 68, NULL, 'restock', 15, 15, '2025-11-20 20:35:01', 'New batch added: BTH-30011', 1),
(93, 69, NULL, 'restock', 100, 100, '2025-11-20 20:35:40', 'New batch added: BTH-46011', 1);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `category` enum('Prescription Medicines','Over-the-Counter (OTC) Products','Health & Personal Care','Medical Supplies & Equipment') NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `current_stock` int(11) NOT NULL DEFAULT 0,
  `manufactured_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `how_to_use` text DEFAULT NULL,
  `side_effects` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reorder_level` int(11) DEFAULT 50,
  `reorder_quantity` int(11) DEFAULT 100
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `product_name`, `category`, `price`, `current_stock`, `manufactured_date`, `expiry_date`, `how_to_use`, `side_effects`, `created_at`, `updated_at`, `reorder_level`, `reorder_quantity`) VALUES
(1, 'Amoxicillin 500mg', 'Prescription Medicines', 19.00, 293, '2024-01-15', '2022-01-15', 'adults 21 yrs old above - Take 1 capsule every 8 hours with or without food\r\nchildren 12 to 20 yrs old- Take 1 capsule every 12 hours with or without food', 'Nausea, diarrhea, allergic reactions, skin rash', '2025-11-12 18:40:48', '2025-11-20 15:46:22', 30, 100),
(2, 'Metformin 500mg', 'Prescription Medicines', 8.75, 80, '2024-02-10', '2026-02-10', 'Take 1 tablet twice daily with meals', 'Stomach upset, diarrhea, metallic taste, vitamin B12 deficiency', '2025-11-12 18:40:48', '2025-11-20 11:14:16', 30, 100),
(3, 'Losartan 50mg', 'Prescription Medicines', 12.00, 190, '2024-03-05', '2026-03-05', 'Take 1 tablet once daily, with or without food', 'Dizziness, fatigue, low blood pressure, elevated potassium', '2025-11-12 18:40:48', '2025-11-20 16:06:12', 30, 100),
(4, 'Omeprazole 20mg', 'Prescription Medicines', 10.25, 90, '2024-01-20', '2026-01-20', 'Take 1 capsule 30 minutes before breakfast', 'Headache, stomach pain, nausea, diarrhea, constipation', '2025-11-12 18:40:48', '2025-11-20 11:13:42', 30, 100),
(6, 'Simvastatin 20mg', 'Prescription Medicines', 14.00, 139, '2024-03-10', '2026-03-10', 'Take 1 tablet in the evening with or without food', 'Muscle pain, headache, nausea, constipation, liver enzyme elevation', '2025-11-12 18:40:48', '2025-11-20 13:10:20', 30, 100),
(7, 'Cetirizine 10mg', 'Prescription Medicines', 6.50, 127, '2024-01-25', '2026-01-25', 'Take 1 tablet once daily, preferably in the evening', 'Drowsiness, dry mouth, fatigue, headache', '2025-11-12 18:40:48', '2025-11-20 15:46:53', 30, 100),
(8, 'Levothyroxine 50mcg', 'Prescription Medicines', 11.75, 150, '2024-02-20', '2026-02-20', 'Take 1 tablet in the morning 30-60 minutes before breakfast', 'Hair loss, weight changes, increased appetite, nervousness', '2025-11-12 18:40:48', '2025-11-20 20:34:21', 30, 100),
(9, 'Atorvastatin 10mg', 'Prescription Medicines', 16.50, 100, '2024-03-15', '2026-03-15', 'Take 1 tablet once daily at any time of day', 'Muscle pain, joint pain, diarrhea, cold-like symptoms', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 30, 100),
(10, 'Lisinopril 10mg', 'Prescription Medicines', 13.25, 100, '2024-01-30', '2026-01-30', 'Take 1 tablet once daily at the same time', 'Dry cough, dizziness, headache, fatigue, low blood pressure', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 30, 100),
(11, 'Paracetamol 500mg', 'Over-the-Counter (OTC) Products', 5.00, 100, '2024-04-01', '2026-04-01', 'Take 1-2 tablets every 4-6 hours as needed, maximum 8 tablets per day', 'Rare: liver damage with overdose, allergic reactions', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 50, 150),
(12, 'Ibuprofen 200mg', 'Over-the-Counter (OTC) Products', 7.25, 100, '2024-04-05', '2026-04-05', 'Take 1-2 tablets every 4-6 hours with food, maximum 6 tablets per day', 'Stomach upset, heartburn, nausea, dizziness', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 50, 150),
(13, 'Vitamin C 500mg', 'Over-the-Counter (OTC) Products', 3.50, 100, '2024-05-01', '2027-05-01', 'Take 1 tablet daily with or without food', 'Stomach cramps, nausea, diarrhea with high doses', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 50, 150),
(14, 'Antacid Tablets', 'Over-the-Counter (OTC) Products', 4.75, 100, '2024-04-10', '2026-04-10', 'Chew 1-2 tablets when experiencing heartburn, maximum 8 tablets per day', 'Constipation, diarrhea, chalky taste', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 50, 150),
(15, 'Cough Syrup 120ml', 'Over-the-Counter (OTC) Products', 12.00, 100, '2024-03-20', '2025-09-20', 'Take 10ml every 4-6 hours, do not exceed 60ml per day', 'Drowsiness, dizziness, nausea, constipation', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 50, 150),
(16, 'Loperamide 2mg', 'Over-the-Counter (OTC) Products', 6.00, 0, '2024-04-15', '2026-04-15', 'Take 2 capsules initially, then 1 after each loose stool, maximum 8 per day', 'Constipation, dizziness, drowsiness, nausea', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 50, 150),
(18, 'Antihistamine Tablets', 'Over-the-Counter (OTC) Products', 5.50, 100, '2024-04-20', '2026-04-20', 'Take 1 tablet every 4-6 hours as needed, maximum 6 tablets per day', 'Drowsiness, dry mouth, blurred vision, dizziness', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 50, 150),
(19, 'Multivitamins', 'Over-the-Counter (OTC) Products', 9.00, 100, '2024-05-05', '2027-05-05', 'Take 1 tablet daily with food', 'Stomach upset, headache, unusual taste in mouth', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 50, 150),
(20, 'Throat Lozenges', 'Over-the-Counter (OTC) Products', 3.25, 100, '2024-04-25', '2026-04-25', 'Dissolve 1 lozenge in mouth every 2-3 hours as needed', 'Mouth irritation, allergic reactions (rare)', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 50, 150),
(21, 'Alcohol 70% 500ml', 'Health & Personal Care', 45.00, 150, '2024-06-01', '2026-06-01', 'Apply to hands and rub until dry, use as needed', 'Skin dryness, irritation with excessive use', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 10, 50),
(22, 'Hand Sanitizer 60ml', 'Health & Personal Care', 25.00, 150, '2024-06-05', '2026-06-05', 'Apply small amount to hands and rub thoroughly', 'Dry skin, mild irritation', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 10, 50),
(23, 'Betadine Solution 120ml', 'Health & Personal Care', 85.00, 150, '2024-05-10', '2026-05-10', 'Apply to affected area 1-3 times daily', 'Skin irritation, allergic reactions, staining', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 10, 50),
(24, 'Hydrogen Peroxide 120ml', 'Health & Personal Care', 35.00, 150, '2024-06-10', '2026-06-10', 'Apply to minor cuts and wounds, let bubble then rinse', 'Mild stinging, skin whitening (temporary)', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 10, 50),
(25, 'Cotton Balls 100pcs', 'Health & Personal Care', 28.00, 150, '2024-07-01', '2028-07-01', 'Use for applying medications or cleaning wounds', 'None', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 10, 50),
(26, 'Adhesive Bandages 100pcs', 'Health & Personal Care', 65.00, 14, '2024-07-05', '2028-07-05', 'Apply to small cuts and wounds after cleaning', 'Skin irritation, allergic reactions (rare)', '2025-11-12 18:40:48', '2025-11-20 14:20:10', 40, 120),
(27, 'Medical Tape 1inch', 'Health & Personal Care', 40.00, 150, '2024-06-15', '2027-06-15', 'Use to secure bandages and dressings', 'Skin irritation, adhesive residue', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 40, 120),
(28, 'Surgical Mask 50pcs', 'Health & Personal Care', 120.00, 150, '2024-08-01', '2029-08-01', 'Wear over nose and mouth, replace when soiled', 'Skin irritation, breathing discomfort with prolonged use', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 40, 120),
(29, 'Face Shield', 'Health & Personal Care', 35.00, 150, '2024-07-10', '2029-07-10', 'Wear over face, clean after each use', 'None', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 40, 120),
(30, 'Thermometer Digital', 'Health & Personal Care', 150.00, 165, '2024-06-20', '2029-06-20', 'Place under tongue or armpit, wait for beep', 'None', '2025-11-12 18:40:48', '2025-11-20 20:35:01', 40, 120),
(31, 'Syringe 3ml 100pcs', 'Medical Supplies & Equipment', 250.00, 150, '2024-08-05', '2029-08-05', 'For medical use only, dispose after single use', 'None', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 20, 50),
(32, 'Gauze Pads 4x4 100pcs', 'Medical Supplies & Equipment', 180.00, 46, '2024-08-10', '2028-08-10', 'Apply to wounds, change regularly', 'None', '2025-11-12 18:40:48', '2025-11-20 13:10:02', 20, 50),
(33, 'Elastic Bandage 3inch', 'Medical Supplies & Equipment', 55.00, 120, '2024-07-15', '2027-07-15', 'Wrap around injured area, secure with clips', 'Circulation problems if wrapped too tight', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 20, 50),
(34, 'Blood Pressure Monitor', 'Medical Supplies & Equipment', 1800.00, 49, '2024-09-01', '2029-09-01', 'Wrap cuff around arm, press start button', 'None', '2025-11-12 18:40:48', '2025-11-20 14:20:10', 20, 50),
(35, 'Nebulizer Machine', 'Medical Supplies & Equipment', 2500.00, 50, '2024-09-05', '2029-09-05', 'Add medication to chamber, inhale mist for 10-15 minutes', 'None', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 20, 50),
(36, 'Glucometer Kit', 'Medical Supplies & Equipment', 950.00, 150, '2024-08-15', '2029-08-15', 'Insert test strip, prick finger, apply blood to strip', 'Finger soreness from pricking', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 20, 50),
(37, 'Wheelchair Standard', 'Medical Supplies & Equipment', 5500.00, 150, '2024-10-01', '2034-10-01', 'Adjust to patient comfort, lock wheels when stationary', 'None', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 20, 50),
(38, 'Crutches Pair', 'Medical Supplies & Equipment', 850.00, 150, '2024-09-10', '2034-09-10', 'Adjust height to patient, use for support when walking', 'Underarm discomfort, hand fatigue', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 20, 50),
(39, 'Oxygen Tank Portable', 'Medical Supplies & Equipment', 3200.00, 100, '2024-10-05', '2034-10-05', 'Connect nasal cannula, turn valve to prescribed flow rate', 'Nasal dryness, nosebleeds', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 20, 50),
(40, 'IV Stand Stainless', 'Medical Supplies & Equipment', 1200.00, 100, '2024-09-15', '2034-09-15', 'Hang IV bags, adjust height as needed', 'None', '2025-11-12 18:40:48', '2025-11-20 10:50:39', 20, 50),
(46, 'Biogesic', 'Over-the-Counter (OTC) Products', 53.00, 200, '2025-11-14', '2025-11-05', 'asffafsa', 'fsdagdj', '2025-11-14 11:11:03', '2025-11-20 20:35:40', 50, 150);

-- --------------------------------------------------------

--
-- Table structure for table `product_batches`
--

CREATE TABLE `product_batches` (
  `batch_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL COMMENT 'Links to products table',
  `batch_number` varchar(50) NOT NULL COMMENT 'Unique batch identifier (e.g., BTH2025001)',
  `quantity` int(11) NOT NULL DEFAULT 0 COMMENT 'Current quantity in this batch',
  `original_quantity` int(11) NOT NULL COMMENT 'Initial quantity when received',
  `manufactured_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `purchase_price` decimal(10,2) DEFAULT NULL COMMENT 'Cost per unit from supplier',
  `received_date` date NOT NULL DEFAULT curdate(),
  `status` enum('available','low_stock','expired','depleted') DEFAULT 'available',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `product_batches`
--

INSERT INTO `product_batches` (`batch_id`, `product_id`, `batch_number`, `quantity`, `original_quantity`, `manufactured_date`, `expiry_date`, `supplier_name`, `purchase_price`, `received_date`, `status`, `notes`, `created_at`, `updated_at`) VALUES
(1, 26, 'BTH-202511-214', 14, 20, '0000-00-00', '2027-11-15', 'n/a', 5000.00, '2025-11-15', 'available', NULL, '2025-11-15 10:15:17', '2025-11-20 14:20:10'),
(2, 1, 'BTH-202511-161', 43, 150, '0000-00-00', '2027-11-15', 'asjhfkadsjfs', 8400.00, '2025-11-15', 'available', NULL, '2025-11-15 11:52:23', '2025-11-20 15:46:22'),
(3, 1, 'BTH-202511-415', 100, 100, '0000-00-00', '2027-11-15', 'asjhfkadsjfs', 8000.00, '2025-11-15', 'available', NULL, '2025-11-15 11:52:50', '2025-11-15 11:52:50'),
(4, 2, 'BTH-202511-483', 80, 100, '0000-00-00', '2027-10-15', 'asjhfkadsjfs', 8000.00, '2025-11-15', 'available', NULL, '2025-11-15 11:54:05', '2025-11-20 11:14:16'),
(5, 3, 'BTH-1763220364620', 190, 200, '0000-00-00', '2027-07-15', NULL, NULL, '2025-11-15', 'available', NULL, '2025-11-15 15:26:24', '2025-11-20 16:06:12'),
(15, 6, 'BTH-6001', 0, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'depleted', NULL, '2025-11-16 09:13:43', '2025-11-16 17:05:05'),
(16, 7, 'BTH-7001', 0, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'depleted', NULL, '2025-11-16 09:13:49', '2025-11-19 13:58:36'),
(17, 8, 'BTH-8001', 0, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'depleted', NULL, '2025-11-16 09:13:53', '2025-11-19 13:59:34'),
(18, 9, 'BTH-9001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:13:58', '2025-11-16 09:13:58'),
(19, 10, 'BTH-10001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:14:04', '2025-11-16 09:14:04'),
(20, 11, 'BTH-11001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:14:09', '2025-11-16 09:14:09'),
(21, 12, 'BTH-12001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:14:13', '2025-11-16 09:14:13'),
(22, 13, 'BTH-13001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:14:18', '2025-11-16 09:14:18'),
(23, 14, 'BTH-14001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:14:26', '2025-11-16 09:14:26'),
(24, 15, 'BTH-15001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:14:32', '2025-11-16 09:14:32'),
(26, 46, 'BTH-46001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:16:07', '2025-11-16 09:16:07'),
(27, 16, 'BTH-16001', 0, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'depleted', NULL, '2025-11-16 09:16:13', '2025-11-16 17:17:17'),
(29, 18, 'BTH-18001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:16:27', '2025-11-16 09:16:27'),
(30, 40, 'BTH-40001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:17:03', '2025-11-16 09:17:03'),
(31, 39, 'BTH-39001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:17:08', '2025-11-16 09:17:08'),
(32, 19, 'BTH-19001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:17:26', '2025-11-16 09:17:26'),
(33, 20, 'BTH-20001', 100, 100, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:17:32', '2025-11-16 09:17:32'),
(34, 23, 'BTH-23001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:17:55', '2025-11-16 09:17:55'),
(35, 21, 'BTH-21001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:18:01', '2025-11-16 09:18:01'),
(36, 25, 'BTH-25001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:18:10', '2025-11-16 09:18:10'),
(37, 22, 'BTH-22001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:18:15', '2025-11-16 09:18:15'),
(38, 24, 'BTH-24001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:18:20', '2025-11-16 09:18:20'),
(39, 27, 'BTH-27001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:18:26', '2025-11-16 09:18:26'),
(40, 38, 'BTH-38001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:18:33', '2025-11-16 09:18:33'),
(41, 37, 'BTH-37001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:18:47', '2025-11-16 09:18:47'),
(42, 36, 'BTH-36001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:18:51', '2025-11-16 09:18:51'),
(43, 31, 'BTH-31001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:18:57', '2025-11-16 09:18:57'),
(44, 28, 'BTH-28001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:19:02', '2025-11-16 09:19:02'),
(45, 29, 'BTH-29001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:19:20', '2025-11-16 09:19:20'),
(46, 30, 'BTH-30001', 150, 150, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:19:38', '2025-11-16 09:19:38'),
(47, 33, 'BTH-33001', 120, 120, '0000-00-00', '2026-03-14', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:19:52', '2025-11-16 09:19:52'),
(48, 32, 'BTH-32001', 46, 50, '0000-00-00', '2027-01-11', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:20:29', '2025-11-20 13:10:02'),
(49, 34, 'BTH-34001', 49, 50, '0000-00-00', '2027-01-11', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:20:36', '2025-11-20 14:20:10'),
(50, 35, 'BTH-35001', 50, 50, '0000-00-00', '2027-01-11', NULL, NULL, '2025-11-16', 'available', NULL, '2025-11-16 09:20:41', '2025-11-16 09:20:41'),
(52, 4, 'BTH-4011', 90, 100, '0000-00-00', '2027-11-17', NULL, NULL, '2025-11-17', 'available', NULL, '2025-11-16 16:18:07', '2025-11-20 11:13:42'),
(57, 6, 'BTH-6011', 139, 150, '2025-11-19', '2027-11-19', NULL, NULL, '2025-11-19', 'available', NULL, '2025-11-19 13:58:05', '2025-11-20 13:10:20'),
(58, 7, 'BTH-7011', 127, 150, '2025-11-19', '2027-11-19', NULL, NULL, '2025-11-19', 'available', NULL, '2025-11-19 13:59:51', '2025-11-20 15:46:53'),
(59, 8, 'BTH-8011', 0, 150, '2024-01-15', '2025-11-20', NULL, NULL, '2025-11-20', 'depleted', NULL, '2025-11-19 16:02:49', '2025-11-20 14:17:02'),
(60, 16, 'BTH-16011', 20, 20, '2024-02-01', '2025-11-20', NULL, NULL, '2025-11-20', 'available', NULL, '2025-11-19 16:03:38', '2025-11-19 16:03:38'),
(61, 1, 'BTH-EXP-AMOX-001', 0, 50, '2024-01-15', '2025-09-15', 'Pharma Supplier A', 15.00, '2024-02-01', 'depleted', NULL, '2025-11-20 13:48:11', '2025-11-20 14:17:18'),
(62, 11, 'BTH-EXP-PARA-001', 75, 75, '2024-03-01', '2025-10-01', 'Med Supplies Inc', 3.50, '2024-04-01', 'expired', NULL, '2025-11-20 13:48:11', '2025-11-20 13:48:11'),
(63, 12, 'BTH-EXP-IBU-001', 30, 30, '2024-04-01', '2025-11-12', 'Health Distributors', 5.00, '2024-05-01', 'expired', NULL, '2025-11-20 13:48:11', '2025-11-20 13:48:11'),
(64, 13, 'BTH-EXP-VITC-001', 25, 25, '2024-05-01', '2025-11-19', 'Wellness Corp', 2.00, '2024-06-01', 'expired', NULL, '2025-11-20 13:48:11', '2025-11-20 13:48:11'),
(65, 14, 'BTH-EXP-ANT-001', 40, 40, '2024-02-01', '2025-08-01', 'Pharma Supplier B', 3.00, '2024-03-01', 'expired', NULL, '2025-11-20 13:48:11', '2025-11-20 13:48:11'),
(66, 1, 'BTH-1031', 150, 150, '2025-11-20', '2027-11-20', NULL, NULL, '2025-11-20', 'available', NULL, '2025-11-20 14:20:39', '2025-11-20 14:20:39'),
(67, 8, 'BTH-8021', 150, 150, '2025-11-21', '2027-10-21', NULL, NULL, '2025-11-21', 'available', NULL, '2025-11-20 20:34:21', '2025-11-20 20:34:21'),
(68, 30, 'BTH-30011', 15, 15, '2025-11-21', '2035-11-21', NULL, NULL, '2025-11-21', 'available', NULL, '2025-11-20 20:35:01', '2025-11-20 20:35:01'),
(69, 46, 'BTH-46011', 100, 100, '2025-11-21', '2027-10-22', NULL, NULL, '2025-11-21', 'available', NULL, '2025-11-20 20:35:40', '2025-11-20 20:35:40');

--
-- Triggers `product_batches`
--
DELIMITER $$
CREATE TRIGGER `trg_batch_status_update` BEFORE UPDATE ON `product_batches` FOR EACH ROW BEGIN
    IF NEW.quantity = 0 THEN
        SET NEW.status = 'depleted';
    ELSEIF NEW.expiry_date < CURDATE() THEN
        SET NEW.status = 'expired';
    ELSEIF NEW.quantity <= 10 THEN
        SET NEW.status = 'low_stock';
    ELSE
        SET NEW.status = 'available';
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `sale_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'Staff who processed the sale',
  `customer_name` varchar(255) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','gcash') DEFAULT 'cash',
  `amount_paid` decimal(10,2) DEFAULT NULL,
  `change_amount` decimal(10,2) DEFAULT NULL,
  `sale_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`sale_id`, `user_id`, `customer_name`, `total_amount`, `payment_method`, `amount_paid`, `change_amount`, `sale_date`, `notes`) VALUES
(1, 2, NULL, 200.00, 'cash', 500.00, 300.00, '2025-11-19 14:00:58', NULL),
(2, 2, NULL, 51.25, 'gcash', 51.25, 0.00, '2025-11-19 14:01:44', NULL),
(3, 2, NULL, 328.25, 'cash', 400.00, 71.75, '2025-11-20 11:13:42', NULL),
(4, 2, NULL, 175.00, 'gcash', 175.00, 0.00, '2025-11-20 11:14:16', NULL),
(5, 2, NULL, 735.00, 'gcash', 735.00, 0.00, '2025-11-20 13:10:02', NULL),
(6, 2, NULL, 112.00, 'cash', 150.00, 38.00, '2025-11-20 13:10:20', NULL),
(7, 2, NULL, 1512.40, 'cash', 1600.00, 87.60, '2025-11-20 14:20:10', NULL),
(8, 3, NULL, 1520.00, 'gcash', 1520.00, 0.00, '2025-11-20 15:46:22', NULL),
(9, 3, NULL, 130.00, 'cash', 200.00, 70.00, '2025-11-20 15:46:53', NULL),
(10, 3, NULL, 72.00, 'cash', 80.00, 8.00, '2025-11-20 16:06:12', NULL);

--
-- Triggers `sales`
--
DELIMITER $$
CREATE TRIGGER `trg_update_shift_on_sale` AFTER INSERT ON `sales` FOR EACH ROW BEGIN
    DECLARE v_shift_id INT;
    
    -- Find active shift for this user
    SELECT shift_id INTO v_shift_id
    FROM staff_shifts
    WHERE user_id = NEW.user_id
    AND status = 'active'
    AND NEW.sale_date >= clock_in
    AND (clock_out IS NULL OR NEW.sale_date <= clock_out)
    ORDER BY clock_in DESC
    LIMIT 1;
    
    -- Update shift stats if found
    IF v_shift_id IS NOT NULL THEN
        CALL sp_update_shift_stats(v_shift_id);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `sale_item_id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `product_id`, `quantity`, `unit_price`, `subtotal`, `created_at`) VALUES
(1, 1, 1, 3, 19.00, 57.00, '2025-11-19 14:00:58'),
(2, 1, 7, 2, 6.50, 13.00, '2025-11-19 14:00:58'),
(3, 1, 32, 1, 180.00, 180.00, '2025-11-19 14:00:58'),
(4, 2, 4, 5, 10.25, 51.25, '2025-11-19 14:01:44'),
(5, 3, 6, 3, 14.00, 42.00, '2025-11-20 11:13:42'),
(6, 3, 4, 5, 10.25, 51.25, '2025-11-20 11:13:42'),
(7, 3, 3, 4, 12.00, 48.00, '2025-11-20 11:13:42'),
(8, 3, 1, 3, 19.00, 57.00, '2025-11-20 11:13:42'),
(9, 3, 26, 2, 65.00, 130.00, '2025-11-20 11:13:42'),
(10, 4, 2, 20, 8.75, 175.00, '2025-11-20 11:14:16'),
(11, 5, 26, 3, 65.00, 195.00, '2025-11-20 13:10:02'),
(12, 5, 32, 3, 180.00, 540.00, '2025-11-20 13:10:02'),
(13, 6, 6, 8, 14.00, 112.00, '2025-11-20 13:10:20'),
(14, 7, 26, 1, 65.00, 65.00, '2025-11-20 14:20:10'),
(15, 7, 1, 1, 19.00, 19.00, '2025-11-20 14:20:10'),
(16, 7, 34, 1, 1800.00, 1800.00, '2025-11-20 14:20:10'),
(17, 7, 7, 1, 6.50, 6.50, '2025-11-20 14:20:10'),
(18, 8, 1, 100, 19.00, 1900.00, '2025-11-20 15:46:22'),
(19, 9, 7, 20, 6.50, 130.00, '2025-11-20 15:46:53'),
(20, 10, 3, 6, 12.00, 72.00, '2025-11-20 16:06:12');

-- --------------------------------------------------------

--
-- Table structure for table `staff_shifts`
--

CREATE TABLE `staff_shifts` (
  `shift_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL COMMENT 'Links to users table',
  `shift_date` date NOT NULL,
  `clock_in` datetime NOT NULL,
  `clock_out` datetime DEFAULT NULL COMMENT 'NULL if still on shift',
  `expected_clock_out` datetime DEFAULT NULL COMMENT 'Expected end time based on 8-hour shift',
  `shift_duration` int(11) DEFAULT NULL COMMENT 'Duration in minutes, calculated on clock-out',
  `expected_duration` int(11) DEFAULT 480 COMMENT 'Expected shift duration in minutes (default 8 hours)',
  `break_duration` int(11) DEFAULT 0 COMMENT 'Break time in minutes',
  `total_sales` decimal(10,2) DEFAULT 0.00 COMMENT 'Total sales during shift',
  `transactions_count` int(11) DEFAULT 0 COMMENT 'Number of transactions processed',
  `items_sold` int(11) DEFAULT 0 COMMENT 'Total items sold',
  `gcash_sales` decimal(10,2) DEFAULT 0.00,
  `cash_sales` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `status` enum('active','completed','absent') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff_shifts`
--

INSERT INTO `staff_shifts` (`shift_id`, `user_id`, `shift_date`, `clock_in`, `clock_out`, `expected_clock_out`, `shift_duration`, `expected_duration`, `break_duration`, `total_sales`, `transactions_count`, `items_sold`, `gcash_sales`, `cash_sales`, `notes`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, '2025-10-10', '2025-10-10 06:00:00', '2025-10-10 15:00:00', NULL, 480, 480, 60, 0.00, 0, 0, 0.00, 0.00, NULL, 'completed', '2025-11-19 13:37:55', '2025-11-19 13:37:55'),
(2, 2, '2025-10-10', '2025-10-10 06:00:00', '2025-10-10 12:00:00', NULL, 360, 480, 0, 0.00, 0, 0, 0.00, 0.00, NULL, 'completed', '2025-11-19 13:37:55', '2025-11-19 13:37:55'),
(3, 1, '2025-11-19', '2025-11-19 06:00:00', NULL, NULL, NULL, 480, 0, 0.00, 0, 0, 0.00, 0.00, NULL, 'active', '2025-11-19 13:37:55', '2025-11-19 13:37:55'),
(4, 1, '2025-11-20', '2025-11-20 06:00:00', '2025-11-20 15:00:00', NULL, 480, 480, 60, 5245.50, 28, 156, 2100.00, 3145.50, NULL, 'completed', '2025-11-19 19:54:54', '2025-11-19 19:54:54'),
(5, 2, '2025-11-20', '2025-11-20 15:00:00', '2025-11-20 23:00:00', NULL, 420, 480, 60, 3845.00, 24, 158, 1800.00, 2045.00, NULL, 'completed', '2025-11-19 19:54:54', '2025-11-19 19:54:54'),
(6, 3, '2025-11-20', '2025-11-20 06:00:00', '2025-11-20 14:00:00', NULL, 420, 480, 60, 356.00, 12, 13, 0.00, 356.00, NULL, 'completed', '2025-11-19 19:54:54', '2025-11-19 19:54:54'),
(7, 2, '2025-11-20', '2025-11-20 19:34:48', '2025-11-20 22:19:18', '2025-11-21 03:34:48', 164, 480, 0, 847.00, 2, 14, 735.00, 112.00, NULL, 'completed', '2025-11-20 11:34:48', '2025-11-20 14:19:18'),
(8, 2, '2025-11-20', '2025-11-20 22:19:23', '2025-11-20 23:19:00', '2025-11-21 06:19:23', 59, 480, 0, 1512.40, 1, 4, 0.00, 1512.40, NULL, 'completed', '2025-11-20 14:19:23', '2025-11-20 15:19:00'),
(9, 3, '2025-11-20', '2025-11-20 23:44:46', '2025-11-21 01:25:22', '2025-11-21 07:44:46', 100, 480, 0, 1722.00, 3, 126, 1520.00, 202.00, NULL, 'completed', '2025-11-20 15:44:46', '2025-11-20 17:25:22');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `role` enum('admin','staff') DEFAULT 'staff',
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `first_name`, `last_name`, `email`, `phone`, `profile_picture`, `role`, `password`, `created_at`, `last_login`, `status`, `reset_token`, `reset_expiry`) VALUES
(1, 'Karylle', 'Karylle', 'Viray', 'karylleviray111@gmail.com', '09614075024', 'uploads/profiles/user_1_1763662054.png', 'admin', '$2y$10$PSzzkdu2xBXG7CoH8vGGwum8G31SnbtbrqKgqVVnM2oFK9MI6Wz9.', '2025-11-10 19:03:35', '2025-11-20 21:30:05', 'active', '7d4e488161b12ad003eba8a3656069b3df7c0d23d6019b47d881aeeaa6f69a9e', '2025-11-20 22:32:20'),
(2, 'Kellychen', 'Kellychen', 'Aniate', 'kellychensicat@gmail.com', '09123456789', 'uploads/profiles/user_2_1763663823.jpg', 'staff', '$2y$10$DuIkugkPBH7BAwzfy6HryuHkHVfdkgaK7lZz7vKIX.49krztbpqnS', '2025-11-10 19:09:41', '2025-11-20 21:21:12', 'active', NULL, NULL),
(3, 'Avril', NULL, NULL, 'avril@gmail.com', NULL, NULL, 'staff', '$2y$10$Jgc5JdNwilltHIoAJpwYhuvdoAACnYnQsrHeiVsQEiCMrtS6Fbf0a', '2025-11-17 05:41:21', '2025-11-20 17:23:40', 'active', NULL, NULL),
(4, 'Grace', NULL, NULL, 'marygracenapo@gmail.com', NULL, NULL, 'staff', '$2y$10$rWdENyAfuK2SSk6wlRqYauZJ9scHtADW5FCwPh0MI3JSHaj39BtTm', '2025-11-20 14:41:04', NULL, 'active', NULL, NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_expiring_batches`
-- (See below for the actual view)
--
CREATE TABLE `v_expiring_batches` (
`batch_id` int(11)
,`batch_number` varchar(50)
,`product_id` int(11)
,`product_name` varchar(255)
,`category` enum('Prescription Medicines','Over-the-Counter (OTC) Products','Health & Personal Care','Medical Supplies & Equipment')
,`quantity` int(11)
,`expiry_date` date
,`supplier_name` varchar(255)
,`days_until_expiry` int(7)
,`urgency_level` varchar(8)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_product_total_stock`
-- (See below for the actual view)
--
CREATE TABLE `v_product_total_stock` (
`product_id` int(11)
,`product_name` varchar(255)
,`category` enum('Prescription Medicines','Over-the-Counter (OTC) Products','Health & Personal Care','Medical Supplies & Equipment')
,`price` decimal(10,2)
,`legacy_stock` int(11)
,`batch_total_stock` decimal(32,0)
,`active_batches` bigint(21)
,`nearest_expiry` date
,`reorder_level` int(11)
,`reorder_quantity` int(11)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_reorder_list`
-- (See below for the actual view)
--
CREATE TABLE `v_reorder_list` (
`product_id` int(11)
,`product_name` varchar(255)
,`category` enum('Prescription Medicines','Over-the-Counter (OTC) Products','Health & Personal Care','Medical Supplies & Equipment')
,`current_stock` decimal(32,0)
,`reorder_level` int(11)
,`reorder_quantity` int(11)
,`quantity_needed` decimal(33,0)
);

-- --------------------------------------------------------

--
-- Structure for view `v_expiring_batches`
--
DROP TABLE IF EXISTS `v_expiring_batches`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_expiring_batches`  AS SELECT `pb`.`batch_id` AS `batch_id`, `pb`.`batch_number` AS `batch_number`, `p`.`product_id` AS `product_id`, `p`.`product_name` AS `product_name`, `p`.`category` AS `category`, `pb`.`quantity` AS `quantity`, `pb`.`expiry_date` AS `expiry_date`, `pb`.`supplier_name` AS `supplier_name`, to_days(`pb`.`expiry_date`) - to_days(curdate()) AS `days_until_expiry`, CASE WHEN `pb`.`expiry_date` < curdate() THEN 'EXPIRED' WHEN to_days(`pb`.`expiry_date`) - to_days(curdate()) <= 7 THEN 'CRITICAL' WHEN to_days(`pb`.`expiry_date`) - to_days(curdate()) <= 30 THEN 'URGENT' WHEN to_days(`pb`.`expiry_date`) - to_days(curdate()) <= 90 THEN 'WARNING' ELSE 'OK' END AS `urgency_level` FROM (`product_batches` `pb` join `products` `p` on(`pb`.`product_id` = `p`.`product_id`)) WHERE `pb`.`quantity` > 0 AND `pb`.`expiry_date` <= curdate() + interval 90 day AND `pb`.`status` <> 'depleted' ORDER BY `pb`.`expiry_date` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `v_product_total_stock`
--
DROP TABLE IF EXISTS `v_product_total_stock`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_product_total_stock`  AS SELECT `p`.`product_id` AS `product_id`, `p`.`product_name` AS `product_name`, `p`.`category` AS `category`, `p`.`price` AS `price`, `p`.`current_stock` AS `legacy_stock`, coalesce(sum(case when `pb`.`status` = 'available' and `pb`.`expiry_date` > curdate() then `pb`.`quantity` else 0 end),0) AS `batch_total_stock`, count(case when `pb`.`status` = 'available' then `pb`.`batch_id` end) AS `active_batches`, min(case when `pb`.`status` = 'available' then `pb`.`expiry_date` end) AS `nearest_expiry`, `p`.`reorder_level` AS `reorder_level`, `p`.`reorder_quantity` AS `reorder_quantity` FROM (`products` `p` left join `product_batches` `pb` on(`p`.`product_id` = `pb`.`product_id`)) GROUP BY `p`.`product_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_reorder_list`
--
DROP TABLE IF EXISTS `v_reorder_list`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_reorder_list`  AS SELECT `p`.`product_id` AS `product_id`, `p`.`product_name` AS `product_name`, `p`.`category` AS `category`, coalesce(sum(case when `pb`.`status` = 'available' and `pb`.`expiry_date` > curdate() then `pb`.`quantity` else 0 end),0) AS `current_stock`, `p`.`reorder_level` AS `reorder_level`, `p`.`reorder_quantity` AS `reorder_quantity`, `p`.`reorder_quantity`- coalesce(sum(case when `pb`.`status` = 'available' and `pb`.`expiry_date` > curdate() then `pb`.`quantity` else 0 end),0) AS `quantity_needed` FROM (`products` `p` left join `product_batches` `pb` on(`p`.`product_id` = `pb`.`product_id`)) GROUP BY `p`.`product_id` HAVING `current_stock` <= `p`.`reorder_level` ORDER BY coalesce(sum(case when `pb`.`status` = 'available' and `pb`.`expiry_date` > curdate() then `pb`.`quantity` else 0 end),0) ASC ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `batch_movements`
--
ALTER TABLE `batch_movements`
  ADD PRIMARY KEY (`movement_id`),
  ADD KEY `idx_batch` (`batch_id`),
  ADD KEY `idx_sale_item` (`sale_item_id`),
  ADD KEY `idx_movement_date` (`movement_date`),
  ADD KEY `idx_movement_type` (`movement_type`),
  ADD KEY `fk_movement_user` (`performed_by`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`);

--
-- Indexes for table `product_batches`
--
ALTER TABLE `product_batches`
  ADD PRIMARY KEY (`batch_id`),
  ADD UNIQUE KEY `unique_batch` (`product_id`,`batch_number`),
  ADD KEY `idx_product_expiry` (`product_id`,`expiry_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_expiry_date` (`expiry_date`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`sale_id`),
  ADD KEY `idx_user` (`user_id`),
  ADD KEY `idx_sale_date` (`sale_date`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`sale_item_id`),
  ADD KEY `idx_sale` (`sale_id`),
  ADD KEY `idx_product` (`product_id`);

--
-- Indexes for table `staff_shifts`
--
ALTER TABLE `staff_shifts`
  ADD PRIMARY KEY (`shift_id`),
  ADD KEY `idx_user_date` (`user_id`,`shift_date`),
  ADD KEY `idx_shift_date` (`shift_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `batch_movements`
--
ALTER TABLE `batch_movements`
  MODIFY `movement_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=94;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `product_batches`
--
ALTER TABLE `product_batches`
  MODIFY `batch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=70;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `sale_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `sale_item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `staff_shifts`
--
ALTER TABLE `staff_shifts`
  MODIFY `shift_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `batch_movements`
--
ALTER TABLE `batch_movements`
  ADD CONSTRAINT `fk_movement_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`batch_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_movement_sale_item` FOREIGN KEY (`sale_item_id`) REFERENCES `sale_items` (`sale_item_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_movement_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `product_batches`
--
ALTER TABLE `product_batches`
  ADD CONSTRAINT `fk_batch_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sale_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `fk_sale_item_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`),
  ADD CONSTRAINT `fk_sale_item_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`sale_id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_shifts`
--
ALTER TABLE `staff_shifts`
  ADD CONSTRAINT `fk_shift_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
