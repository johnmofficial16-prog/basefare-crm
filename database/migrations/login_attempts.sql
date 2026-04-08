CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `login_attempts_ip_index` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
