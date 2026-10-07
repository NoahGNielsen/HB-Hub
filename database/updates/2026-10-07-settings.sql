-- Run once on an existing database, before deploying the settings page (2FA, password, notifications and blocking).
-- New databases get these columns from schema.sql.

ALTER TABLE `HBHub-Users`
  ADD COLUMN `userTotpSecret` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'Base32 TOTP secret, NULL when 2FA is off' AFTER `userPasswordHash`,
  ADD COLUMN `userTotpLastStep` int UNSIGNED DEFAULT NULL COMMENT 'Time step of the last accepted 2FA code, so a code only works once' AFTER `userTotpSecret`;

ALTER TABLE `HBHub-Sessions`
  ADD COLUMN `sessionTotpPending` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 Logged in\r\n1 Right password, waiting for the 2FA code' AFTER `sessionValidUntil`;
