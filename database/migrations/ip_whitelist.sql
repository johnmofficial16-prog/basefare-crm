CREATE TABLE IF NOT EXISTS `ip_whitelist` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `location_name` varchar(255) NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `fk_ip_whitelist_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Insert the global config key for the toggle switch if it doesn't exist
INSERT INTO `system_config` (`key`, `value`, `updated_at`) 
SELECT 'ip_whitelisting_enabled', '1', NOW() 
WHERE NOT EXISTS (SELECT 1 FROM `system_config` WHERE `key` = 'ip_whitelisting_enabled');
