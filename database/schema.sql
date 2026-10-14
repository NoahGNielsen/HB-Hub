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
-- Table structure for table `HBHub-BlackjackInvites`
--

CREATE TABLE `HBHub-BlackjackInvites` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who is invited',
  `invitedByUserId` int UNSIGNED DEFAULT NULL COMMENT 'The host who invited them, NULL once that account is gone',
  `inviteTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-BlackjackPlayerRounds`
--

CREATE TABLE `HBHub-BlackjackPlayerRounds` (
  `playerRoundId` bigint UNSIGNED NOT NULL,
  `roundId` bigint UNSIGNED NOT NULL,
  `roomId` int UNSIGNED DEFAULT NULL COMMENT 'NULL once the room is gone',
  `roomType` tinyint UNSIGNED NOT NULL COMMENT 'The room''s type then: 1 Public, 2 Invite-only, 3 Code, 4 Solo',
  `userId` int UNSIGNED NOT NULL,
  `seatNumber` tinyint UNSIGNED NOT NULL,
  `playersInRound` tinyint UNSIGNED NOT NULL COMMENT 'How many players were dealt in, this one included',
  `playerBet` bigint UNSIGNED NOT NULL COMMENT 'The bet the hand was dealt with',
  `playerWagered` bigint UNSIGNED NOT NULL COMMENT 'Everything bet on the hands: the bet, doubles and splits (not insurance)',
  `playerInsurance` bigint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'What insurance cost, 0 without',
  `playerPayout` bigint UNSIGNED NOT NULL COMMENT 'What the hands paid back, the bets included',
  `playerInsurancePayout` bigint UNSIGNED NOT NULL DEFAULT '0',
  `playerNet` bigint NOT NULL COMMENT 'Chips won (positive) or lost (negative) in the round, insurance included',
  `playerResult` varchar(9) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'From the hands, not insurance: blackjack, otherwise win, loss or push by whether the hands won or lost chips together',
  `playerHands` tinyint UNSIGNED NOT NULL COMMENT '1, or up to 3 after splitting',
  `playerHits` smallint UNSIGNED NOT NULL DEFAULT '0',
  `playerStands` smallint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Stood by the player (not counting doubles, or 21 standing by itself)',
  `playerDoubles` smallint UNSIGNED NOT NULL DEFAULT '0',
  `playerSplits` smallint UNSIGNED NOT NULL DEFAULT '0',
  `playerAutoStands` smallint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Stood for the player: out of time, away or gone',
  `playerInsuranceTaken` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 when insurance was offered and taken',
  `playerHandsDetail` json NOT NULL COMMENT 'Every hand: [{"cards": ["AS", "KD"], "bet": 10, "doubled": false, "result": "blackjack", "payout": 25}]',
  `playerTimestamp` datetime NOT NULL COMMENT 'UTC, when the round was paid out'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-BlackjackRooms`
--

CREATE TABLE `HBHub-BlackjackRooms` (
  `roomId` int UNSIGNED NOT NULL,
  `roomName` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `roomType` tinyint UNSIGNED NOT NULL COMMENT '1 Public (listed in the lobby)\r\n2 Invite-only (only invited users can join, also with the code)\r\n3 Code (anyone with the code can join)\r\n4 Solo (just the one who made it)',
  `roomCode` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Join code: capital letters and digits, without 0 O 1 I L',
  `roomMinBet` int UNSIGNED NOT NULL,
  `roomHostUserId` int UNSIGNED DEFAULT NULL,
  `roomPhase` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Waiting for bets\r\n2 Betting\r\n3 Insurance\r\n4 Players'' turns\r\n5 Dealer''s turn\r\n6 Results (the same as in roomState)',
  `roomRevision` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Goes up on every change, so the table can tell when nothing changed since it last asked',
  `roomDeadline` bigint UNSIGNED DEFAULT NULL COMMENT 'Unix time in milliseconds when a timer runs out and the round moves on by itself, NULL when nothing is timed',
  `roomState` json NOT NULL COMMENT 'The shoe, bets and hands (src/assets/php/casino/blackjack/round.php). Never sent to players as it is, as it has the hole card and the order of the cards',
  `roomCreatedTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-BlackjackRounds`
--

CREATE TABLE `HBHub-BlackjackRounds` (
  `roundId` bigint UNSIGNED NOT NULL,
  `roomId` int UNSIGNED DEFAULT NULL COMMENT 'NULL once the room is gone',
  `roundStartedTimestamp` datetime NOT NULL COMMENT 'UTC, the first bet on it',
  `roundDealtTimestamp` datetime DEFAULT NULL COMMENT 'UTC, NULL when it was never dealt (every bet was taken back)',
  `roundEndedTimestamp` datetime DEFAULT NULL COMMENT 'UTC, when it was paid out',
  `roundDealerCards` varchar(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'The dealer''s cards once it has ended, like "AS 9H" (rank and suit)',
  `roundDealerTotal` tinyint UNSIGNED DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-BlackjackSeats`
--

CREATE TABLE `HBHub-BlackjackSeats` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `seatNumber` tinyint UNSIGNED NOT NULL COMMENT '1-5, left to right, and the order players take their turns in',
  `seatSittingOut` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 Sitting out, so the next hands are dealt without them',
  `seatJoinedTimestamp` datetime NOT NULL COMMENT 'UTC',
  `seatLastSeenTimestamp` datetime NOT NULL COMMENT 'UTC, kept up to date while the table is open, to tell who is away'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-CasinoTransactions`
--

CREATE TABLE `HBHub-CasinoTransactions` (
  `transactionId` bigint UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `transactionAmount` bigint NOT NULL COMMENT 'Chips added (positive) or taken (negative)',
  `transactionBalance` bigint NOT NULL COMMENT 'walletChips right after the change',
  `transactionReason` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'start (starting chips), dailyBonus, or the game''s own, like bet and payout',
  `transactionGame` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'NULL for the casino''s own changes (start, dailyBonus), otherwise the game, like blackjack',
  `transactionReference` bigint UNSIGNED DEFAULT NULL COMMENT 'The game''s id for what it was about, like the blackjack round',
  `transactionTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-CasinoWallets`
--

CREATE TABLE `HBHub-CasinoWallets` (
  `userId` int UNSIGNED NOT NULL,
  `walletChips` bigint NOT NULL COMMENT 'Only changed through hbHubCasinoChange() (src/assets/php/casino/wallet.php), which logs it in HBHub-CasinoTransactions',
  `walletDailyDay` date DEFAULT NULL COMMENT 'The casino day (Danish date, starting at 3 AM) the daily chips were last collected on, NULL if never',
  `walletCreatedTimestamp` datetime NOT NULL COMMENT 'UTC',
  CONSTRAINT `chk_casinowallets_chips` CHECK (`walletChips` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-ChatMembers`
--

CREATE TABLE `HBHub-ChatMembers` (
  `chatId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `memberRole` int UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Normal Member\r\n2 Group Admin\r\n3 Group Owner',
  `lastReadMessageId` int UNSIGNED DEFAULT NULL,
  `memberJoinedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `memberNickname` varchar(64) DEFAULT NULL,
  `memberMutedUntil` timestamp NULL DEFAULT NULL,
  `userAcknowledgedJoin` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 Message request, not accepted yet\r\n1 Accepted'
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
  `messageDeletedByAdmin` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 Deleted by the sender (or not deleted)\r\n1 Deleted by a group admin',
  `messageIsEdited` tinyint NOT NULL DEFAULT '0',
  `messageReplyToId` bigint UNSIGNED DEFAULT NULL,
  `messageEvent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 A normal message\r\n1 A notice about a change in the chat, made by userId (messageEventType says which)',
  `messageEventType` tinyint UNSIGNED DEFAULT NULL COMMENT 'NULL for a normal message\r\n1 Renamed the group (messageContent is the new name, NULL when taken away)\r\n2 Changed the group icon\r\n3 Removed the group icon\r\n4 Changed the background\r\n5 Reset the background\r\n6 Made messageEventUserId a group admin\r\n7 Removed messageEventUserId as group admin\r\n8 Kicked messageEventUserId\r\n9 Joined the group\r\n10 Left the chat\r\n11 Added messageEventUserId to the group\r\n12 Became the group owner when the owner left',
  `messageEventUserId` int UNSIGNED DEFAULT NULL COMMENT 'Who the notice is about (messageEventType 6, 7, 8, 11 and 12)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Notifications`
--

CREATE TABLE `HBHub-Notifications` (
  `notificationId` bigint UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who gets the notification',
  `notificationType` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'section.kind, like chat.kicked (src/assets/php/notificationTypes/ lists them)',
  `notificationByUserId` int UNSIGNED DEFAULT NULL COMMENT 'Who did it, NULL when nobody did (like a game result)',
  `notificationData` json DEFAULT NULL COMMENT 'What the kind needs to say what happened, like {"chatId": 5, "chatName": "Gamers"}',
  `notificationCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notificationReadTimestamp` datetime DEFAULT NULL COMMENT 'NULL until the user opens the notifications'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Sessions`
--

CREATE TABLE `HBHub-Sessions` (
  `sessionId` char(32) NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `sessionGenerated` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sessionValidUntil` datetime NOT NULL,
  `sessionTotpPending` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 Logged in\r\n1 Right password, waiting for the 2FA code'
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Users`
--

CREATE TABLE `HBHub-Users` (
  `userId` int UNSIGNED NOT NULL,
  `userName` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `userNameLower` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'NULL for a deleted account',
  `userDescription` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `userSettings` json DEFAULT NULL,
  `userAvatarAttachmentId` int UNSIGNED DEFAULT NULL,
  `userIpv4AdresseOnAccountCreate` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `userIpv6AdresseOnAccountCreate` varchar(62) DEFAULT NULL,
  `userIpv4AdresseLastAccessed` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `userIpv6AdresseLastAccessed` varchar(62) DEFAULT NULL,
  `userPasswordHash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'NULL for a deleted account',
  `userTotpSecret` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'Base32 TOTP secret, NULL when 2FA is off',
  `userTotpLastStep` int UNSIGNED DEFAULT NULL COMMENT 'Time step of the last accepted 2FA code, so a code only works once',
  `userFailedLoginCount` smallint UNSIGNED DEFAULT '0' COMMENT 'NULL for a deleted account',
  `userStatus` tinyint UNSIGNED DEFAULT '1' COMMENT '1 Online\r\n2 Offiline\r\n3 Account Locked\r\n4 Banned\r\nNULL for a deleted account',
  `userRole` tinyint UNSIGNED DEFAULT '1' COMMENT '1 Normal User\r\n2 Admin\r\nNULL for a deleted account',
  `userCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userLastSeenTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userAccountLockedTimestamp` datetime DEFAULT NULL,
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
-- Indexes for table `HBHub-BlackjackInvites`
--
ALTER TABLE `HBHub-BlackjackInvites`
  ADD PRIMARY KEY (`roomId`,`userId`),
  ADD KEY `userId` (`userId`),
  ADD KEY `fk_blackjackinvites_by` (`invitedByUserId`);

--
-- Indexes for table `HBHub-BlackjackPlayerRounds`
--
ALTER TABLE `HBHub-BlackjackPlayerRounds`
  ADD PRIMARY KEY (`playerRoundId`),
  ADD UNIQUE KEY `roundUser` (`roundId`,`userId`),
  ADD KEY `userTime` (`userId`,`playerTimestamp`),
  ADD KEY `playerTimestamp` (`playerTimestamp`),
  ADD KEY `fk_blackjackplayerrounds_room` (`roomId`);

--
-- Indexes for table `HBHub-BlackjackRooms`
--
ALTER TABLE `HBHub-BlackjackRooms`
  ADD PRIMARY KEY (`roomId`),
  ADD UNIQUE KEY `roomCode` (`roomCode`),
  ADD KEY `roomType` (`roomType`),
  ADD KEY `fk_blackjackrooms_host` (`roomHostUserId`);

--
-- Indexes for table `HBHub-BlackjackRounds`
--
ALTER TABLE `HBHub-BlackjackRounds`
  ADD PRIMARY KEY (`roundId`),
  ADD KEY `fk_blackjackrounds_room` (`roomId`);

--
-- Indexes for table `HBHub-BlackjackSeats`
--
ALTER TABLE `HBHub-BlackjackSeats`
  ADD PRIMARY KEY (`roomId`,`seatNumber`),
  ADD UNIQUE KEY `userId` (`userId`) COMMENT 'One table at a time',
  ADD KEY `seatLastSeen` (`seatLastSeenTimestamp`);

--
-- Indexes for table `HBHub-CasinoTransactions`
--
ALTER TABLE `HBHub-CasinoTransactions`
  ADD PRIMARY KEY (`transactionId`),
  ADD KEY `userTime` (`userId`,`transactionTimestamp`),
  ADD KEY `gameReference` (`transactionGame`,`transactionReference`);

--
-- Indexes for table `HBHub-CasinoWallets`
--
ALTER TABLE `HBHub-CasinoWallets`
  ADD PRIMARY KEY (`userId`);

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
  ADD KEY `fk_messages_replyto` (`messageReplyToId`),
  ADD KEY `fk_messages_eventuser` (`messageEventUserId`);

--
-- Indexes for table `HBHub-Notifications`
--
ALTER TABLE `HBHub-Notifications`
  ADD PRIMARY KEY (`notificationId`),
  ADD KEY `userUnread` (`userId`,`notificationReadTimestamp`),
  ADD KEY `fk_notifications_by` (`notificationByUserId`);

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
-- AUTO_INCREMENT for table `HBHub-BlackjackPlayerRounds`
--
ALTER TABLE `HBHub-BlackjackPlayerRounds`
  MODIFY `playerRoundId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-BlackjackRooms`
--
ALTER TABLE `HBHub-BlackjackRooms`
  MODIFY `roomId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-BlackjackRounds`
--
ALTER TABLE `HBHub-BlackjackRounds`
  MODIFY `roundId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-CasinoTransactions`
--
ALTER TABLE `HBHub-CasinoTransactions`
  MODIFY `transactionId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `HBHub-Notifications`
--
ALTER TABLE `HBHub-Notifications`
  MODIFY `notificationId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Users`
--
ALTER TABLE `HBHub-Users`
  MODIFY `userId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `HBHub-BlackjackInvites`
--
ALTER TABLE `HBHub-BlackjackInvites`
  ADD CONSTRAINT `fk_blackjackinvites_by` FOREIGN KEY (`invitedByUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_blackjackinvites_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-BlackjackRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_blackjackinvites_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-BlackjackPlayerRounds`
--
ALTER TABLE `HBHub-BlackjackPlayerRounds`
  ADD CONSTRAINT `fk_blackjackplayerrounds_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-BlackjackRooms` (`roomId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_blackjackplayerrounds_round` FOREIGN KEY (`roundId`) REFERENCES `HBHub-BlackjackRounds` (`roundId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_blackjackplayerrounds_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-BlackjackRooms`
--
ALTER TABLE `HBHub-BlackjackRooms`
  ADD CONSTRAINT `fk_blackjackrooms_host` FOREIGN KEY (`roomHostUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-BlackjackRounds`
--
ALTER TABLE `HBHub-BlackjackRounds`
  ADD CONSTRAINT `fk_blackjackrounds_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-BlackjackRooms` (`roomId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-BlackjackSeats`
--
ALTER TABLE `HBHub-BlackjackSeats`
  ADD CONSTRAINT `fk_blackjackseats_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-BlackjackRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_blackjackseats_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-CasinoTransactions`
--
ALTER TABLE `HBHub-CasinoTransactions`
  ADD CONSTRAINT `fk_casinotransactions_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-CasinoWallets`
--
ALTER TABLE `HBHub-CasinoWallets`
  ADD CONSTRAINT `fk_casinowallets_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

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
  ADD CONSTRAINT `fk_messages_eventuser` FOREIGN KEY (`messageEventUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_messages_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-Notifications`
--
ALTER TABLE `HBHub-Notifications`
  ADD CONSTRAINT `fk_notifications_by` FOREIGN KEY (`notificationByUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

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
