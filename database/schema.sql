-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: xxx
-- Generation Time: Oct 06, 2026 at 06:48 PM
-- Server version: 8.4.11-11
-- PHP Version: 8.4.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `xxx`
--

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Attachments`
--

CREATE TABLE `HBHub-Attachments` (
  `attachmentId` int UNSIGNED NOT NULL,
  `attachmentType` varchar(25) NOT NULL,
  `attachmentFile` longblob NOT NULL,
  `attachmentUploadTime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-ChatMembers`
--

CREATE TABLE `HBHub-ChatMembers` (
  `chatId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `memberRole` int UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Normal Member\r\n2 Group Admin',
  `lastReadMessageId` int UNSIGNED DEFAULT NULL,
  `memberJoinedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `memberNickname` varchar(64) DEFAULT NULL,
  `memberMutedUntil` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Chats`
--

CREATE TABLE `HBHub-Chats` (
  `chatId` int UNSIGNED NOT NULL,
  `chatName` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `chatType` tinyint NOT NULL COMMENT '1 DM''s\r\n2 GDM''s',
  `chatStatus` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Active\r\n2 Deleted\r\n3 Banned by Admin',
  `chatCreatedBy` int UNSIGNED NOT NULL,
  `chatSettings` json DEFAULT NULL,
  `chatIconAttachmentId` int UNSIGNED DEFAULT NULL,
  `chatCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastMessageTimestamp` datetime DEFAULT NULL,
  `chatBannedTimestamp` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Messages`
--

CREATE TABLE `HBHub-Messages` (
  `messageId` bigint UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `chatId` int UNSIGNED NOT NULL,
  `messageContent` varchar(2500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `attachmentId` int UNSIGNED DEFAULT NULL,
  `messageSentTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `messageDeletedTimestamp` datetime DEFAULT NULL,
  `messageIsEdited` tinyint NOT NULL DEFAULT '0',
  `messageReplyToId` bigint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Sessions`
--

CREATE TABLE `HBHub-Sessions` (
  `sessionId` char(32) NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `sessionGenerated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sessionValidUntil` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Users`
--

CREATE TABLE `HBHub-Users` (
  `userId` int UNSIGNED NOT NULL,
  `userName` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `userNameLower` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `userDescription` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `userSettings` json DEFAULT NULL,
  `userAvatarAttachmentId` int UNSIGNED DEFAULT NULL,
  `userIpv4AdresseOnAccountCreate` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `userIpv6AdresseOnAccountCreate` varchar(62) DEFAULT NULL,
  `userIpv4AdresseLastAccessed` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `userIpv6AdresseLastAccessed` varchar(62) DEFAULT NULL,
  `userPasswordHash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `userFailedLoginCount` smallint UNSIGNED NOT NULL DEFAULT '0',
  `userStatus` tinyint UNSIGNED NOT NULL DEFAULT '1',
  `userRole` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Normal User\r\n2 Admin\r\n3 Banned',
  `userCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userLastSeenTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userBannedTimestamp` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `HBHub-Attachments`
--
ALTER TABLE `HBHub-Attachments`
  ADD PRIMARY KEY (`attachmentId`),
  ADD KEY `attachmentType` (`attachmentType`);

--
-- Indexes for table `HBHub-ChatMembers`
--
ALTER TABLE `HBHub-ChatMembers`
  ADD PRIMARY KEY (`chatId`,`userId`),
  ADD KEY `fk_members_user` (`userId`);

--
-- Indexes for table `HBHub-Chats`
--
ALTER TABLE `HBHub-Chats`
  ADD PRIMARY KEY (`chatId`),
  ADD KEY `chatName` (`chatName`),
  ADD KEY `chatCreatedBy` (`chatCreatedBy`),
  ADD KEY `fk_chats_icon` (`chatIconAttachmentId`);

--
-- Indexes for table `HBHub-Messages`
--
ALTER TABLE `HBHub-Messages`
  ADD PRIMARY KEY (`messageId`),
  ADD UNIQUE KEY `messageId` (`messageId`),
  ADD KEY `userId` (`userId`),
  ADD KEY `chatId` (`chatId`),
  ADD KEY `fk_messages_attachment` (`attachmentId`),
  ADD KEY `messageTimestamp` (`messageSentTimestamp`,`chatId`),
  ADD KEY `fk_messages_replyto` (`messageReplyToId`);

--
-- Indexes for table `HBHub-Sessions`
--
ALTER TABLE `HBHub-Sessions`
  ADD PRIMARY KEY (`sessionId`),
  ADD KEY `userId` (`userId`);

--
-- Indexes for table `HBHub-Users`
--
ALTER TABLE `HBHub-Users`
  ADD PRIMARY KEY (`userId`),
  ADD UNIQUE KEY `userNameLower` (`userNameLower`),
  ADD UNIQUE KEY `userName` (`userName`) USING BTREE,
  ADD KEY `fk_users_avatar` (`userAvatarAttachmentId`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `HBHub-Attachments`
--
ALTER TABLE `HBHub-Attachments`
  MODIFY `attachmentId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Chats`
--
ALTER TABLE `HBHub-Chats`
  MODIFY `chatId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Messages`
--
ALTER TABLE `HBHub-Messages`
  MODIFY `messageId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Users`
--
ALTER TABLE `HBHub-Users`
  MODIFY `userId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `HBHub-ChatMembers`
--
ALTER TABLE `HBHub-ChatMembers`
  ADD CONSTRAINT `fk_members_chat` FOREIGN KEY (`chatId`) REFERENCES `HBHub-Chats` (`chatId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_members_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-Chats`
--
ALTER TABLE `HBHub-Chats`
  ADD CONSTRAINT `fk_chats_icon` FOREIGN KEY (`chatIconAttachmentId`) REFERENCES `HBHub-Attachments` (`attachmentId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-Messages`
--
ALTER TABLE `HBHub-Messages`
  ADD CONSTRAINT `fk_messages_attachment` FOREIGN KEY (`attachmentId`) REFERENCES `HBHub-Attachments` (`attachmentId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_messages_chat` FOREIGN KEY (`chatId`) REFERENCES `HBHub-Chats` (`chatId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_messages_replyto` FOREIGN KEY (`messageReplyToId`) REFERENCES `HBHub-Messages` (`messageId`),
  ADD CONSTRAINT `fk_messages_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-Sessions`
--
ALTER TABLE `HBHub-Sessions`
  ADD CONSTRAINT `fk_sessions_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-Users`
--
ALTER TABLE `HBHub-Users`
  ADD CONSTRAINT `fk_users_avatar` FOREIGN KEY (`userAvatarAttachmentId`) REFERENCES `HBHub-Attachments` (`attachmentId`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
