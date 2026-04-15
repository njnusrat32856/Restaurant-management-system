-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 19, 2026 at 09:28 PM
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
-- Database: `restaurant_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `billing`
--

CREATE TABLE `billing` (
  `bill_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `tax_amount` decimal(10,2) DEFAULT 0.00,
  `discount_amount` decimal(10,2) DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL,
  `payment_method` enum('cash','card','digital') NOT NULL,
  `payment_status` enum('pending','paid') DEFAULT 'pending',
  `bill_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`category_id`, `category_name`, `description`, `status`, `created_at`) VALUES
(1, 'Appetizers', 'Starters and small plates', 'active', '2025-11-13 08:12:28'),
(2, 'Main Course', 'Primary dishes', 'active', '2025-11-13 08:12:28'),
(3, 'Desserts', 'Sweet treats', 'active', '2025-11-13 08:12:28'),
(4, 'Beverages', 'Drinks and refreshments', 'active', '2025-11-13 08:12:28');

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `inventory_id` int(11) NOT NULL,
  `item_name` varchar(100) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `reorder_level` int(11) DEFAULT 10,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--

INSERT INTO `inventory` (`inventory_id`, `item_name`, `quantity`, `unit`, `reorder_level`, `last_updated`) VALUES
(1, 'Rice', 50, 'kg', 10, '2026-02-19 20:17:42'),
(2, 'Tomatoes', 20, 'kg', 5, '2026-02-19 20:18:09'),
(3, 'Oil', 10, 'liters', 3, '2026-02-19 20:18:43');

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

CREATE TABLE `login_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `login_time` datetime NOT NULL,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_logs`
--

INSERT INTO `login_logs` (`log_id`, `user_id`, `login_time`, `logout_time`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, '2025-11-13 15:10:44', '2025-11-13 15:16:40', '::1', NULL, '2025-11-13 09:10:44'),
(2, 1, '2025-11-13 15:18:52', '2025-11-13 15:26:53', '::1', NULL, '2025-11-13 09:18:52'),
(3, 1, '2025-11-13 15:32:40', '2025-11-13 15:47:52', '::1', NULL, '2025-11-13 09:32:40'),
(4, 1, '2025-11-13 16:54:10', NULL, '::1', NULL, '2025-11-13 10:54:10'),
(5, 1, '2025-11-21 22:31:48', '2025-11-21 22:35:32', '::1', NULL, '2025-11-21 16:31:48'),
(6, 1, '2025-11-21 22:37:48', '2025-11-21 22:38:00', '::1', NULL, '2025-11-21 16:37:48'),
(7, 1, '2026-02-17 21:35:38', '2026-02-17 23:12:59', '::1', NULL, '2026-02-17 15:35:38'),
(8, 2, '2026-02-17 23:13:21', '2026-02-17 23:13:46', '::1', NULL, '2026-02-17 17:13:21'),
(9, 1, '2026-02-17 23:24:50', '2026-02-17 23:30:58', '::1', NULL, '2026-02-17 17:24:50'),
(10, 1, '2026-02-17 23:31:17', '2026-02-18 00:18:08', '::1', NULL, '2026-02-17 17:31:17'),
(11, 1, '2026-02-20 01:35:11', '2026-02-20 02:09:10', '::1', NULL, '2026-02-19 19:35:11'),
(12, 1, '2026-02-20 02:17:00', '2026-02-20 02:19:33', '::1', NULL, '2026-02-19 20:17:00');

-- --------------------------------------------------------

--
-- Table structure for table `menu_items`
--

CREATE TABLE `menu_items` (
  `item_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `item_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `availability` enum('available','unavailable') DEFAULT 'available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `menu_items`
--

INSERT INTO `menu_items` (`item_id`, `category_id`, `item_name`, `description`, `price`, `image_url`, `availability`, `created_at`) VALUES
(1, 1, 'Spring Rolls', 'Crispy vegetable spring rolls served with sweet chili sauce', 250.00, 'menu/6994914979ff3_1771344201.jpg', 'available', '2025-11-13 11:35:59'),
(2, 1, 'Chicken Wings', 'Spicy buffalo wings with blue cheese dip', 350.00, 'menu/699490787d4dd_1771343992.jpg', 'available', '2025-11-13 11:35:59'),
(3, 1, 'Nachos Supreme', 'Loaded nachos with cheese, jalapeños, and sour cream', 400.00, 'menu/699491030234b_1771344131.jpg', 'available', '2025-11-13 11:35:59'),
(4, 2, 'Grilled Chicken', 'Tender grilled chicken breast with herbs', 550.00, 'menu/699490ba4286a_1771344058.jpg', 'available', '2025-11-13 11:35:59'),
(5, 2, 'Beef Burger', 'Classic beef burger with cheese and fries', 450.00, 'menu/6994904844ecf_1771343944.jpg', 'available', '2025-11-13 11:35:59'),
(6, 2, 'Vegetable Pasta', 'Fresh pasta with seasonal vegetables in tomato sauce', 380.00, 'menu/69949166633ec_1771344230.jpg', 'available', '2025-11-13 11:35:59'),
(7, 3, 'Chocolate Cake', 'Rich chocolate cake with vanilla ice cream', 280.00, 'menu/699490886d954_1771344008.jpg', 'available', '2025-11-13 11:35:59'),
(8, 3, 'Cheesecake', 'New York style cheesecake with berry compote', 320.00, 'menu/699490648dfa3_1771343972.jpg', 'available', '2025-11-13 11:35:59'),
(9, 4, 'Fresh Juice', 'Freshly squeezed orange juice', 150.00, 'menu/699490ac0ce44_1771344044.jpg', 'available', '2025-11-13 11:35:59'),
(10, 4, 'Coffee', 'Hot brewed coffee', 120.00, 'menu/699490972bc1f_1771344023.jpg', 'available', '2025-11-13 11:35:59'),
(11, 4, 'Soft Drinks', 'Assorted soft drinks', 80.00, 'menu/69949126c8ef0_1771344166.jpg', 'available', '2025-11-13 11:35:59');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL,
  `table_id` int(11) DEFAULT NULL,
  `staff_id` int(11) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `order_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','preparing','served','completed','cancelled') DEFAULT 'pending',
  `total_amount` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `order_item_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `item_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `special_instructions` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expiry` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token`, `expiry`, `used`, `created_at`) VALUES
(1, 1, 'aa5d8b8165948f032b698168b5e94da48c597bb67cba5fa66bb21238ed90194f', '2025-11-13 10:25:32', 0, '2025-11-13 08:25:32');

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expiry` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reservations`
--

CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `table_id` int(11) DEFAULT NULL,
  `reservation_date` date NOT NULL,
  `reservation_time` time NOT NULL,
  `number_of_guests` int(11) NOT NULL,
  `status` enum('pending','confirmed','cancelled','completed') DEFAULT 'pending',
  `special_requests` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `restaurant_tables`
--

CREATE TABLE `restaurant_tables` (
  `table_id` int(11) NOT NULL,
  `table_number` varchar(10) NOT NULL,
  `seating_capacity` int(11) NOT NULL,
  `status` enum('available','occupied','reserved') DEFAULT 'available',
  `location` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `restaurant_tables`
--

INSERT INTO `restaurant_tables` (`table_id`, `table_number`, `seating_capacity`, `status`, `location`) VALUES
(1, 'T1', 2, 'available', 'Window Side'),
(2, 'T2', 4, 'available', 'Center'),
(3, 'T3', 4, 'occupied', 'Corner'),
(4, 'T4', 6, 'reserved', 'Private Room'),
(5, 'T5', 2, 'available', 'Bar Area'),
(6, 'TT6', 6, 'available', 'Window Side');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `role` enum('admin','staff','customer') DEFAULT 'customer',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `full_name`, `email`, `phone`, `role`, `status`, `created_at`) VALUES
(1, 'admin', '$2a$12$ybmpRTl93MXE.7m/kASS7uWz6wpArYz6XVThhUWb42RyZfh7QK3ze', 'System Administrator', 'admin@restaurant.com', '01847307407', 'admin', 'active', '2025-11-13 08:12:28'),
(2, 'staff', '$2a$12$Q1qTUCRwKgst7u/jLVYumuFn5HP2ZgKVBt1o7uWB26nMCiGsJKcg.', 'Restaurant Staff', 'staff@restaurant.com', NULL, 'staff', 'active', '2025-11-13 08:12:28'),
(3, 'customer', '$2a$12$vidLLH5fCa/dH6dVV7r0PORDoJNZqcKYEfUCK84rXmJkSA3K.8msO', 'John Customer', 'customer@restaurant.com', NULL, 'customer', 'active', '2025-11-13 08:12:28');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `billing`
--
ALTER TABLE `billing`
  ADD PRIMARY KEY (`bill_id`),
  ADD UNIQUE KEY `order_id` (`order_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`inventory_id`);

--
-- Indexes for table `login_logs`
--
ALTER TABLE `login_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`),
  ADD KEY `table_id` (`table_id`),
  ADD KEY `staff_id` (`staff_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`order_item_id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `table_id` (`table_id`);

--
-- Indexes for table `restaurant_tables`
--
ALTER TABLE `restaurant_tables`
  ADD PRIMARY KEY (`table_id`),
  ADD UNIQUE KEY `table_number` (`table_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `billing`
--
ALTER TABLE `billing`
  MODIFY `bill_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `inventory_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `login_logs`
--
ALTER TABLE `login_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `menu_items`
--
ALTER TABLE `menu_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `order_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reservations`
--
ALTER TABLE `reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `restaurant_tables`
--
ALTER TABLE `restaurant_tables`
  MODIFY `table_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `billing`
--
ALTER TABLE `billing`
  ADD CONSTRAINT `billing_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE;

--
-- Constraints for table `login_logs`
--
ALTER TABLE `login_logs`
  ADD CONSTRAINT `login_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `menu_items`
--
ALTER TABLE `menu_items`
  ADD CONSTRAINT `menu_items_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON DELETE SET NULL;

--
-- Constraints for table `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`table_id`) REFERENCES `restaurant_tables` (`table_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `orders_ibfk_2` FOREIGN KEY (`staff_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `order_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`table_id`) REFERENCES `restaurant_tables` (`table_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
