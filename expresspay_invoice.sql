CREATE TABLE `expresspay_invoice` (
	`id` int(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
	`type` varchar(50) NOT NULL,
	`amount` double NOT NULL,
	`currency` char(3) NOT NULL,
	`description` varchar(1024) NOT NULL,
	`created_time` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;