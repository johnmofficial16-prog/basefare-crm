ALTER TABLE `acceptance_requests`
ADD COLUMN `ip_city` VARCHAR(100) NULL AFTER `ip_address`,
ADD COLUMN `ip_country` VARCHAR(100) NULL AFTER `ip_city`,
ADD COLUMN `ip_isp` VARCHAR(150) NULL AFTER `ip_country`,
ADD COLUMN `ip_zip` VARCHAR(20) NULL AFTER `ip_isp`;
