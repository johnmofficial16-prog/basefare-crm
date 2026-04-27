ALTER TABLE `users`
  MODIFY COLUMN `role` ENUM('admin','manager','supervisor','agent','csa') NOT NULL DEFAULT 'agent';
