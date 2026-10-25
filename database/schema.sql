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
-- Table structure for table `HBHub-AdminLog`
--

CREATE TABLE `HBHub-AdminLog` (
  `adminLogId` bigint UNSIGNED NOT NULL,
  `adminLogByUserId` int UNSIGNED DEFAULT NULL COMMENT 'The site admin who did it, NULL for an automatic ban (user.autoban) or when the admin''s account is gone',
  `adminLogAction` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'What was done, like user.view or chat.kick (ADMIN_LOG_ACTIONS in src/assets/php/adminLog.php)',
  `adminLogUserId` int UNSIGNED DEFAULT NULL COMMENT 'The user it was about, NULL when it was not about one',
  `adminLogChatId` int UNSIGNED DEFAULT NULL COMMENT 'The chat it was about, NULL when it was not about one',
  `adminLogData` json DEFAULT NULL COMMENT 'Details, like {"amount": -500} for chips or {"messageId": 12}. Never a password, name or message text',
  `adminLogReason` varchar(500) DEFAULT NULL COMMENT 'Why, as the admin typed it in the popup before doing it. NULL when none was given, and for opening a page or an automatic ban',
  `adminLogTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Everything site admins do in the admin panel, opening a user or chat included, and automatic bans for a banned profile picture. Kept for a year (src/assets/php/adminLog.php)';

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
-- Table structure for table `HBHub-BaccaratInvites`
--

CREATE TABLE `HBHub-BaccaratInvites` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who is invited',
  `invitedByUserId` int UNSIGNED DEFAULT NULL COMMENT 'The host who invited them, NULL once that account is gone',
  `inviteTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-BaccaratPlayerRounds`
--

CREATE TABLE `HBHub-BaccaratPlayerRounds` (
  `playerRoundId` bigint UNSIGNED NOT NULL,
  `roundId` bigint UNSIGNED NOT NULL,
  `roomId` int UNSIGNED DEFAULT NULL COMMENT 'NULL once the room is gone',
  `roomType` tinyint UNSIGNED NOT NULL COMMENT 'The room''s type then: 1 Public, 2 Invite-only, 3 Code, 4 Solo',
  `userId` int UNSIGNED NOT NULL,
  `seatNumber` tinyint UNSIGNED NOT NULL,
  `playersInRound` tinyint UNSIGNED NOT NULL COMMENT 'How many players bet on the hand, this one included',
  `playerBetPlayer` bigint UNSIGNED NOT NULL DEFAULT '0',
  `playerBetBanker` bigint UNSIGNED NOT NULL DEFAULT '0',
  `playerBetTie` bigint UNSIGNED NOT NULL DEFAULT '0',
  `playerBetPlayerPair` bigint UNSIGNED NOT NULL DEFAULT '0',
  `playerBetBankerPair` bigint UNSIGNED NOT NULL DEFAULT '0',
  `playerWagered` bigint UNSIGNED NOT NULL COMMENT 'All the bets together',
  `playerPayout` bigint UNSIGNED NOT NULL COMMENT 'What the bets paid back, the bets included',
  `playerNet` bigint NOT NULL COMMENT 'Chips won (positive) or lost (negative) in the round',
  `playerResult` varchar(4) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'win, loss or push, by whether the bets won or lost chips together',
  `playerTimestamp` datetime NOT NULL COMMENT 'UTC, when the round was paid out'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-BaccaratRooms`
--

CREATE TABLE `HBHub-BaccaratRooms` (
  `roomId` int UNSIGNED NOT NULL,
  `roomName` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `roomType` tinyint UNSIGNED NOT NULL COMMENT '1 Public (listed in the lobby)\r\n2 Invite-only (only invited users can join, also with the code)\r\n3 Code (anyone with the code can join)\r\n4 Solo (just the one who made it)',
  `roomCode` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Join code: capital letters and digits, without 0 O 1 I L',
  `roomMinBet` int UNSIGNED NOT NULL COMMENT 'The least a player''s bets on a hand can be together',
  `roomHostUserId` int UNSIGNED DEFAULT NULL,
  `roomPhase` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Waiting for bets\r\n2 Betting\r\n3 Dealing\r\n4 Results (the same as in roomState)',
  `roomRevision` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Goes up on every change, so the table can tell when nothing changed since it last asked',
  `roomDeadline` bigint UNSIGNED DEFAULT NULL COMMENT 'Unix time in milliseconds when a timer runs out and the round moves on by itself, NULL when nothing is timed',
  `roomState` json NOT NULL COMMENT 'The shoe, bets and cards (src/assets/php/casino/baccarat/round.php). Never sent to players as it is, as it has the order of the cards',
  `roomCreatedTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-BaccaratRounds`
--

CREATE TABLE `HBHub-BaccaratRounds` (
  `roundId` bigint UNSIGNED NOT NULL,
  `roomId` int UNSIGNED DEFAULT NULL COMMENT 'NULL once the room is gone',
  `roundStartedTimestamp` datetime NOT NULL COMMENT 'UTC, the first bet on it',
  `roundDealtTimestamp` datetime DEFAULT NULL COMMENT 'UTC, NULL when it was never dealt (every bet was taken back)',
  `roundEndedTimestamp` datetime DEFAULT NULL COMMENT 'UTC, when it was paid out',
  `roundPlayerCards` varchar(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'The Player''s cards once it has ended, like "AS 9H" (rank and suit)',
  `roundBankerCards` varchar(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'The Banker''s cards once it has ended',
  `roundPlayerTotal` tinyint UNSIGNED DEFAULT NULL,
  `roundBankerTotal` tinyint UNSIGNED DEFAULT NULL,
  `roundWinner` varchar(6) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'player, banker or tie, NULL until it has ended',
  `roundPlayerPair` tinyint(1) DEFAULT NULL COMMENT '1 when the Player''s first two cards were a pair',
  `roundBankerPair` tinyint(1) DEFAULT NULL COMMENT '1 when the Banker''s first two cards were a pair'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-BaccaratSeats`
--

CREATE TABLE `HBHub-BaccaratSeats` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `seatNumber` tinyint UNSIGNED NOT NULL COMMENT '1-6, left to right',
  `seatSittingOut` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 Sitting out, so the next hands are dealt without them',
  `seatJoinedTimestamp` datetime NOT NULL COMMENT 'UTC',
  `seatLastSeenTimestamp` datetime NOT NULL COMMENT 'UTC, kept up to date while the table is open, to tell who is away'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-BannedAvatars`
--

CREATE TABLE `HBHub-BannedAvatars` (
  `bannedAvatarId` int UNSIGNED NOT NULL,
  `bannedAvatarHash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 of the file, as lowercase hex',
  `bannedAvatarType` varchar(25) NOT NULL,
  `bannedAvatarFile` longblob NOT NULL COMMENT 'A copy, so it can still be seen after the account changes or loses its picture',
  `bannedAvatarFingerprints` blob DEFAULT NULL COMMENT '32-byte difference hashes of the picture and crops of it, for similar copies. NULL until worked out, empty when it can''t be read or is too plain',
  `bannedAvatarFromUserId` int UNSIGNED DEFAULT NULL COMMENT 'The banned account it was the picture of, NULL when an admin uploaded it',
  `bannedAvatarAddedByUserId` int UNSIGNED DEFAULT NULL COMMENT 'The site admin who banned the account or uploaded the picture',
  `bannedAvatarMatchCount` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'How many uploads it has caught (and banned)',
  `bannedAvatarLastMatchTimestamp` datetime DEFAULT NULL,
  `bannedAvatarCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Profile pictures that ban the account uploading them (src/assets/php/bannedAvatars.php)';

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
  `transactionReason` varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'start (starting chips), dailyBonus, admin (added or taken by a site admin), or the game''s own, like bet and payout',
  `transactionGame` varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'NULL for the casino''s own changes (start, dailyBonus, admin), otherwise the game, like blackjack',
  `transactionReference` bigint UNSIGNED DEFAULT NULL COMMENT 'The game''s id for what it was about, like the blackjack round, or for admin the userId of the site admin who did it',
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
  `walletDailyStreak` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT 'The day (1-30) on the daily chips calendar collected on walletDailyDay, 0 if never (HBHUB_CASINO_DAILY_CALENDAR)',
  `walletCreatedTimestamp` datetime NOT NULL COMMENT 'UTC',
  CONSTRAINT `chk_casinowallets_chips` CHECK (`walletChips` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-ChatAttachments`
--

CREATE TABLE `HBHub-ChatAttachments` (
  `attachmentId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who sent it, whose tickets it takes',
  `chatId` int UNSIGNED NOT NULL,
  `messageId` bigint UNSIGNED DEFAULT NULL COMMENT 'The message it was sent in (one message can have up to 10), only NULL if that message row is ever removed',
  `attachmentKey` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Random name of the file on disk, in attachments_path from the config',
  `attachmentName` varchar(255) DEFAULT NULL COMMENT 'The file name as sent (ending in .jxl when converted), NULL once the file is gone',
  `attachmentType` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Content-Type it is sent back with: a picture type, or application/octet-stream for any other file',
  `attachmentKind` tinyint UNSIGNED NOT NULL COMMENT '0 Picture, stored as sent\r\n1 Picture the browser converted to lossless JPEG XL\r\n2 Other file, stored zipped\r\n3 Other file, stored as sent (zipping did not make it smaller)',
  `attachmentSize` bigint UNSIGNED NOT NULL COMMENT 'Bytes when downloaded (unzipped)',
  `attachmentStoredSize` bigint UNSIGNED NOT NULL COMMENT 'Bytes on disk, what attachments_max_total_bytes counts',
  `attachmentTickets` tinyint UNSIGNED NOT NULL COMMENT 'One per started 5 MB of attachmentSize',
  `attachmentStatus` tinyint UNSIGNED NOT NULL DEFAULT '0' COMMENT '0 The file is there and takes its tickets\r\n1 Deleted (with its message, from the attachments popup, or with its chat or account)\r\n2 Expired',
  `attachmentUploadedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `attachmentExpiresTimestamp` datetime NOT NULL COMMENT '90 days after upload, 3 days when it takes more than 5 tickets',
  `attachmentRemovedTimestamp` datetime DEFAULT NULL COMMENT 'When attachmentStatus stopped being 0'
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
  `memberLastViewedTimestamp` datetime DEFAULT NULL COMMENT 'UTC, last time the member had the chat open and in view',
  `memberTypingTimestamp` datetime DEFAULT NULL COMMENT 'UTC, last time the member said they were typing a message in the chat, NULL once they stopped or sent it',
  `userAcknowledgedJoin` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 Message request, not accepted yet\r\n1 Accepted',
  `memberIsGhost` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 Invited by a ghost-banned user (a new chat or Add to chat), so they never see the chat until someone else invites them or they start a DM with that user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Chats`
--

CREATE TABLE `HBHub-Chats` (
  `chatId` int UNSIGNED NOT NULL,
  `chatName` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `chatType` tinyint NOT NULL COMMENT '1 DM''s\r\n2 GDM''s',
  `chatStatus` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Active\r\n2 Deleted (everyone left, or a group admin deleted it)\r\n3 Deleted by a site admin (admin panel), at chatBannedTimestamp',
  `chatCreatedBy` int UNSIGNED NOT NULL,
  `chatSettings` json DEFAULT NULL,
  `chatIconAttachmentId` int UNSIGNED DEFAULT NULL,
  `chatCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastMessageTimestamp` datetime DEFAULT NULL,
  `chatBannedTimestamp` datetime DEFAULT NULL,
  `chatClassKey` varchar(16) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'Class chat: the school year the class started 1. year in and its field of study, like 2026-X. NULL for any other chat, and once a class chat is deleted'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-ChessChat`
--

CREATE TABLE `HBHub-ChessChat` (
  `chatMessageId` int UNSIGNED NOT NULL,
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who wrote it',
  `chatText` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `chatTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-ChessInvites`
--

CREATE TABLE `HBHub-ChessInvites` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who is invited',
  `invitedByUserId` int UNSIGNED DEFAULT NULL COMMENT 'The host who invited them, NULL once that account is gone',
  `inviteTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-ChessResults`
--

CREATE TABLE `HBHub-ChessResults` (
  `resultId` bigint UNSIGNED NOT NULL,
  `roomId` int UNSIGNED DEFAULT NULL COMMENT 'The room it was played in, NULL once the room is gone',
  `roomType` tinyint UNSIGNED NOT NULL COMMENT 'The room''s type then: 1 Public, 2 Invite-only, 3 Code',
  `userId` int UNSIGNED NOT NULL,
  `opponentUserId` int UNSIGNED DEFAULT NULL COMMENT 'Who they played, NULL once that account is gone',
  `playerColour` char(1) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'w White, b Black',
  `playerResult` tinyint UNSIGNED NOT NULL COMMENT '0 Lost\r\n1 Draw\r\n2 Won',
  `resultReason` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'How it ended: checkmate, resign, time, abandoned, left, stalemate, repetition, fiftyMoves, material or agreement',
  `gameMoves` smallint UNSIGNED NOT NULL COMMENT 'Full moves played (White''s and Black''s count as one)',
  `gameClock` varchar(8) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'The time control, like 10+0 (minutes + seconds per move), NULL without a clock',
  `resultTimestamp` datetime NOT NULL COMMENT 'UTC, when the game ended'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-ChessRooms`
--

CREATE TABLE `HBHub-ChessRooms` (
  `roomId` int UNSIGNED NOT NULL,
  `roomName` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `roomType` tinyint UNSIGNED NOT NULL COMMENT '1 Public (listed in the lobby)\r\n2 Invite-only (only invited users can join, also with the code)\r\n3 Code (anyone with the code can join)',
  `roomCode` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Join code: capital letters and digits, without 0 O 1 I L',
  `roomHostUserId` int UNSIGNED DEFAULT NULL,
  `roomPhase` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Waiting for a player\r\n2 Being played\r\n3 Over, both players still there (the same as in roomState)',
  `roomRevision` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Goes up on every change, so the game can tell when nothing changed since it last asked',
  `roomState` json NOT NULL COMMENT 'The game (src/assets/php/games/chess/game.php)',
  `roomCreatedTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-ChessSeats`
--

CREATE TABLE `HBHub-ChessSeats` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `seatNumber` tinyint UNSIGNED NOT NULL COMMENT '1-2, the player''s seat in roomState (the colours are drawn when a game starts)',
  `seatJoinedTimestamp` datetime NOT NULL COMMENT 'UTC',
  `seatLastSeenTimestamp` datetime NOT NULL COMMENT 'UTC, kept up to date while the game is open, to tell who is away'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Feedback`
--

CREATE TABLE `HBHub-Feedback` (
  `feedbackId` int UNSIGNED NOT NULL,
  `feedbackUserId` int UNSIGNED DEFAULT NULL COMMENT 'Who sent it, NULL once that account is gone',
  `feedbackContent` varchar(2000) NOT NULL COMMENT 'As it was typed',
  `feedbackPage` varchar(255) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL COMMENT 'The path of the page it was sent from (no query), NULL when not known',
  `feedbackDoneByUserId` int UNSIGNED DEFAULT NULL COMMENT 'The site admin who marked it as done',
  `feedbackDoneTimestamp` datetime DEFAULT NULL COMMENT 'NULL while it is new',
  `feedbackCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Feedback sent from the Feedback button in the side bar, read on the admin panel (src/assets/php/feedback.php)';

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-GifCache`
--

CREATE TABLE `HBHub-GifCache` (
  `gifCacheKey` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 of the cleaned search, or the empty string for trending',
  `gifCacheGifs` json NOT NULL COMMENT 'The GIFs /chat/gifs sends: [{id, title, preview, width, height}]',
  `gifCacheExpiresTimestamp` datetime NOT NULL COMMENT 'UTC, 15 minutes after Giphy was asked'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Giphy answers kept for 15 minutes to save API calls (src/assets/php/giphy.php)';

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-LoginDevices`
--

CREATE TABLE `HBHub-LoginDevices` (
  `deviceTokenHash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'SHA-256 of the hbHubDevice cookie',
  `userId` int UNSIGNED NOT NULL,
  `deviceFailedLoginCount` smallint UNSIGNED NOT NULL DEFAULT '0',
  `deviceLockedTimestamp` datetime DEFAULT NULL,
  `deviceCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `deviceLastUsedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Last login from it, a known device for 180 days after'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Browsers that have logged in to the account, with their own login lock (src/assets/php/loginLimit.php)';

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-MatadorChat`
--

CREATE TABLE `HBHub-MatadorChat` (
  `chatMessageId` int UNSIGNED NOT NULL,
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who wrote it',
  `chatText` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `chatTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-MatadorInvites`
--

CREATE TABLE `HBHub-MatadorInvites` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who is invited',
  `invitedByUserId` int UNSIGNED DEFAULT NULL COMMENT 'The host who invited them, NULL for their own seat kept after they left, or once that account is gone',
  `inviteSeat` tinyint UNSIGNED DEFAULT NULL COMMENT 'The seat kept for them in a game that has started, NULL for an invite before it starts',
  `inviteTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-MatadorResults`
--

CREATE TABLE `HBHub-MatadorResults` (
  `resultId` bigint UNSIGNED NOT NULL,
  `roomId` int UNSIGNED DEFAULT NULL COMMENT 'The room it was won in, NULL once the room is gone',
  `roomType` tinyint UNSIGNED NOT NULL COMMENT 'The room''s type then: 1 Public, 2 Invite-only, 3 Code',
  `userId` int UNSIGNED NOT NULL COMMENT 'Who played the seat at the end (who took it over, when the host gave it away)',
  `seatNumber` tinyint UNSIGNED NOT NULL,
  `playersInGame` tinyint UNSIGNED NOT NULL COMMENT 'How many players the game had, this one included',
  `playerWon` tinyint(1) NOT NULL COMMENT '1 for the winner, 0 for everyone who went bankrupt',
  `gameRounds` smallint UNSIGNED NOT NULL COMMENT 'The round the game was won in',
  `resultTimestamp` datetime NOT NULL COMMENT 'UTC, when the game was won'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-MatadorRooms`
--

CREATE TABLE `HBHub-MatadorRooms` (
  `roomId` int UNSIGNED NOT NULL,
  `roomName` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `roomType` tinyint UNSIGNED NOT NULL COMMENT '1 Public (listed in the lobby)\r\n2 Invite-only (only invited users can join, also with the code)\r\n3 Code (anyone with the code can join)',
  `roomCode` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Join code: capital letters and digits, without 0 O 1 I L',
  `roomHostUserId` int UNSIGNED DEFAULT NULL,
  `roomOwnerUserId` int UNSIGNED DEFAULT NULL COMMENT 'Who opened the game: it''s saved in their slot in HBHub-MatadorSaves',
  `roomPhase` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Waiting for players\r\n2 A saved game waiting for its players\r\n3 Being played\r\n4 Won (the same as in roomState)',
  `roomRevision` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Goes up on every change, so the game can tell when nothing changed since it last asked',
  `roomState` json NOT NULL COMMENT 'The game (src/assets/php/games/matador/game.php). Never sent to players as it is, as it has the order of the cards',
  `roomCreatedTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-MatadorSaves`
--

CREATE TABLE `HBHub-MatadorSaves` (
  `userId` int UNSIGNED NOT NULL COMMENT 'Whose save slot: one saved game per user',
  `saveName` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `saveState` json NOT NULL COMMENT 'The game as roomState had it',
  `saveSummary` json NOT NULL COMMENT 'The players and the round, for the Matador page',
  `saveRoomId` int UNSIGNED DEFAULT NULL COMMENT 'The room playing it right now, which saves to it after every change. NULL when nobody is',
  `saveTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-MatadorSeats`
--

CREATE TABLE `HBHub-MatadorSeats` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `seatNumber` tinyint UNSIGNED NOT NULL COMMENT '1-4, the player''s seat in roomState (the turn order is drawn when the game starts)',
  `seatJoinedTimestamp` datetime NOT NULL COMMENT 'UTC',
  `seatLastSeenTimestamp` datetime NOT NULL COMMENT 'UTC, kept up to date while the game is open, to tell who is away'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-MessageReactions`
--

CREATE TABLE `HBHub-MessageReactions` (
  `reactionId` bigint UNSIGNED NOT NULL,
  `messageId` bigint UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who reacted',
  `reactionEmoji` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'One emoji, like 👍. Compared exactly (_bin), so 👍 and 👍🏽 are two different reactions',
  `reactionTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reactionIsGhost` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 Made while the user was ghost banned, so only they see it'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Emoji reactions under chat messages (chat/index.php). Taking one back deletes its row';

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Messages`
--

CREATE TABLE `HBHub-Messages` (
  `messageId` bigint UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `chatId` int UNSIGNED NOT NULL,
  `messageContent` varchar(2500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `messageSentTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `messageDeletedTimestamp` datetime DEFAULT NULL,
  `messageDeletedByAdmin` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 Deleted by the sender (or not deleted)\r\n1 Deleted by a group admin\r\n2 Deleted by a site admin (admin panel)',
  `messageIsEdited` tinyint NOT NULL DEFAULT '0',
  `messageReplyToId` bigint UNSIGNED DEFAULT NULL,
  `messageEvent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 A normal message\r\n1 A notice about a change in the chat, made by userId (messageEventType says which)',
  `messageEventType` tinyint UNSIGNED DEFAULT NULL COMMENT 'NULL for a normal message\r\n1 Renamed the group (messageContent is the new name, NULL when taken away)\r\n2 Changed the group icon\r\n3 Removed the group icon\r\n4 Changed the background\r\n5 Reset the background\r\n6 Made messageEventUserId a group admin\r\n7 Removed messageEventUserId as group admin\r\n8 Kicked messageEventUserId\r\n9 Joined the group\r\n10 Left the chat\r\n11 Added messageEventUserId to the group\r\n12 Became the group owner when the owner left\r\n100 + one of these: the same change made by a site admin from the admin panel (101 renamed the group), the notice says "A site admin"',
  `messageEventUserId` int UNSIGNED DEFAULT NULL COMMENT 'Who the notice is about (messageEventType 6, 7, 8, 11 and 12)',
  `messageIsGhost` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 Sent while the user was ghost banned, so only they (and site admins) see it',
  `messageGhostContent` varchar(2500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'An edit made while ghost banned to a message everyone sees: shown only to the sender, NULL otherwise'
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
-- Table structure for table `HBHub-WordleGames`
--

CREATE TABLE `HBHub-WordleGames` (
  `userId` int UNSIGNED NOT NULL,
  `gameLanguage` char(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'en English, da Danish',
  `gameDate` date NOT NULL COMMENT 'The day of the word, in Danish time',
  `gameGuesses` varchar(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'The guesses so far, oldest first and lowercase, like crane,slate (up to 6 words of 5 letters)',
  `gameResult` tinyint UNSIGNED NOT NULL COMMENT '0 Playing (counted as lost once its day is over)\r\n1 Won\r\n2 Lost',
  `gameStartTimestamp` datetime NOT NULL COMMENT 'UTC, the first guess',
  `gameEndTimestamp` datetime DEFAULT NULL COMMENT 'UTC, when it was won or lost, NULL while playing'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-WordleWords`
--

CREATE TABLE `HBHub-WordleWords` (
  `wordLanguage` char(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'en English, da Danish',
  `wordDate` date NOT NULL COMMENT 'The day, in Danish time',
  `wordText` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'The word everyone guesses that day, lowercase, from src/assets/php/games/wordle/words/<language>-answers.txt'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-MinerCrashReports`
--

CREATE TABLE `HBHub-MinerCrashReports` (
  `crashReportId` int UNSIGNED NOT NULL,
  `crashReportUserId` int UNSIGNED NOT NULL COMMENT 'Who sent it (deleted with the account)',
  `crashReportKind` varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'hang (the page froze) or closed (it went away while being looked at), HBHUB_MINER_CRASH_KINDS',
  `crashReportData` json NOT NULL COMMENT 'What the page wrote down while it ran: what it was doing, the last things that happened, memory used, the save (see src/assets/php/games/spaceOreMiner/crashReports.php)',
  `crashReportUserAgent` varchar(255) DEFAULT NULL COMMENT 'The browser''s user agent, as it sent the report',
  `crashReportCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Deleted after HBHUB_MINER_CRASH_KEEP_DAYS'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Ore Mining Inc. crash reports, only sent when the player says yes, read on the admin panel (src/admin/crashes.php)';

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-MinerSaves`
--

CREATE TABLE `HBHub-MinerSaves` (
  `userId` int UNSIGNED NOT NULL,
  `saveState` json NOT NULL COMMENT 'The whole Ore Mining Inc. game: ore, buildings, upgrades, Dark Matter, Antimatter, Strange Matter, skills, comets, achievements (hbHubMinerNewState() in src/assets/php/games/spaceOreMiner/miner.php)',
  `saveTab` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'The random id of the browser tab playing it; saves from any other tab are turned away',
  `saveOreAllTime` double NOT NULL DEFAULT 0 COMMENT 'For the leaderboard: ore mined ever, in every galaxy',
  `saveOrePerSecond` double NOT NULL DEFAULT 0 COMMENT 'For the leaderboard: ore per second at the last save, without comets',
  `saveDarkMatter` double NOT NULL DEFAULT 0 COMMENT 'For the leaderboard: Dark Matter earned in this universe (since the last Big Crunch). A double, as it can grow past what an int holds',
  `saveAntimatter` double NOT NULL DEFAULT 0 COMMENT 'For the leaderboard: Antimatter earned in this multiverse (since the last Big Rip). A double, like saveDarkMatter',
  `saveStrangeMatter` int UNSIGNED NOT NULL DEFAULT 0 COMMENT 'For the leaderboard: Strange Matter ever earned',
  `saveWarps` int UNSIGNED NOT NULL DEFAULT 0 COMMENT 'For the leaderboard: Warp Jumps made',
  `saveClicks` bigint UNSIGNED NOT NULL DEFAULT 0 COMMENT 'For the leaderboard: clicks on the asteroid ever',
  `saveAchievements` smallint UNSIGNED NOT NULL DEFAULT 0 COMMENT 'For the leaderboard: achievements done',
  `saveStartTimestamp` datetime NOT NULL COMMENT 'UTC, the first save',
  `saveUpdatedTimestamp` datetime NOT NULL COMMENT 'UTC, the last save',
  `saveRateCount` smallint UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Saves since saveRateTimestamp, so no more than HBHUB_MINER_SAVES_MAX are made in HBHUB_MINER_SAVES_SECONDS',
  `saveRateTimestamp` datetime DEFAULT NULL COMMENT 'UTC, the first save counted in saveRateCount'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-RouletteSpins`
--

CREATE TABLE `HBHub-RouletteSpins` (
  `spinId` bigint UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `spinNumber` tinyint UNSIGNED NOT NULL COMMENT '0-36, where the ball landed',
  `spinWagered` bigint UNSIGNED NOT NULL COMMENT 'Every bet on the spin together',
  `spinPayout` bigint UNSIGNED NOT NULL COMMENT 'What the winning bets paid back, the bets included, 0 when none won',
  `spinNet` bigint NOT NULL COMMENT 'Chips won (positive) or lost (negative) on the spin',
  `spinBets` json NOT NULL COMMENT 'Every bet and its chips, like {"17": 10, "0-1-2-3": 20, "red": 50} (src/assets/php/casino/roulette/roulette.php)',
  `spinTimestamp` datetime NOT NULL COMMENT 'UTC'
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
  `sessionTotpPending` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0 Logged in\r\n1 Right password, waiting for the 2FA code',
  `sessionDevice` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'Browser and system, like "Chrome on Windows" (never the whole user agent)',
  `sessionLastSeen` datetime DEFAULT NULL COMMENT 'Last request with this session, written at most once a minute'
)ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-UnoChat`
--

CREATE TABLE `HBHub-UnoChat` (
  `chatMessageId` int UNSIGNED NOT NULL,
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who wrote it',
  `chatText` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `chatTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-UnoInvites`
--

CREATE TABLE `HBHub-UnoInvites` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL COMMENT 'Who is invited',
  `invitedByUserId` int UNSIGNED DEFAULT NULL COMMENT 'The host who invited them, NULL once that account is gone',
  `inviteTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-UnoResults`
--

CREATE TABLE `HBHub-UnoResults` (
  `resultId` bigint UNSIGNED NOT NULL,
  `roomId` int UNSIGNED DEFAULT NULL COMMENT 'The room it was played in, NULL once the room is gone',
  `roomType` tinyint UNSIGNED NOT NULL COMMENT 'The room''s type then: 1 Public, 2 Invite-only, 3 Code',
  `userId` int UNSIGNED NOT NULL,
  `playerResult` tinyint UNSIGNED NOT NULL COMMENT '0 Lost\r\n1 Won\r\n2 Left the game before it was over (counts as a loss)',
  `playerPoints` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'What the winner scored: the value of the cards left in everyone else''s hands. 0 for everyone else',
  `playerCardsLeft` tinyint UNSIGNED NOT NULL COMMENT 'The cards left in their hand when the game ended (0 for the winner and for players who left)',
  `gamePlayers` tinyint UNSIGNED NOT NULL COMMENT 'How many were dealt in (2-6)',
  `gameTurns` int UNSIGNED NOT NULL COMMENT 'Cards played in the game',
  `resultTimestamp` datetime NOT NULL COMMENT 'UTC, when the game ended'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-UnoRooms`
--

CREATE TABLE `HBHub-UnoRooms` (
  `roomId` int UNSIGNED NOT NULL,
  `roomName` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `roomType` tinyint UNSIGNED NOT NULL COMMENT '1 Public (listed in the lobby)\r\n2 Invite-only (only invited users can join, also with the code)\r\n3 Code (anyone with the code can join)',
  `roomCode` char(6) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT 'Join code: capital letters and digits, without 0 O 1 I L',
  `roomHostUserId` int UNSIGNED DEFAULT NULL,
  `roomPhase` tinyint UNSIGNED NOT NULL DEFAULT '1' COMMENT '1 Waiting for players\r\n2 Being played\r\n3 Over, the players still there (the same as in roomState)',
  `roomRevision` int UNSIGNED NOT NULL DEFAULT '0' COMMENT 'Goes up on every change, so the game can tell when nothing changed since it last asked',
  `roomState` json NOT NULL COMMENT 'The game (src/assets/php/games/uno/game.php). Never sent to players as it is, as it has everyone''s cards and the order of the deck',
  `roomCreatedTimestamp` datetime NOT NULL COMMENT 'UTC'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-UnoSeats`
--

CREATE TABLE `HBHub-UnoSeats` (
  `roomId` int UNSIGNED NOT NULL,
  `userId` int UNSIGNED NOT NULL,
  `seatNumber` tinyint UNSIGNED NOT NULL COMMENT '1-6, the player''s seat in roomState (the order of play)',
  `seatJoinedTimestamp` datetime NOT NULL COMMENT 'UTC',
  `seatLastSeenTimestamp` datetime NOT NULL COMMENT 'UTC, kept up to date while the game is open, to tell who is away'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `HBHub-Users`
--

CREATE TABLE `HBHub-Users` (
  `userId` int UNSIGNED NOT NULL,
  `userName` varchar(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `userNameLower` varchar(35) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL COMMENT 'NULL for a deleted account',
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
  `userStatus` tinyint UNSIGNED DEFAULT '1' COMMENT '1 Normal\r\n2 Normal (unlocked or unbanned by a site admin, not logged in since)\r\n3 Account Locked\r\n4 Banned\r\nNULL for a deleted account',
  `userRole` tinyint UNSIGNED DEFAULT '1' COMMENT '1 Normal User\r\n2 Admin\r\nNULL for a deleted account',
  `userCreatedTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userLastSeenTimestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `userAccountLockedTimestamp` datetime DEFAULT NULL,
  `userBannedTimestamp` datetime DEFAULT NULL,
  `userBannedReason` tinyint UNSIGNED DEFAULT NULL COMMENT '1 By a site admin\r\n2 Automatically, for uploading a banned profile picture\r\nNULL when not banned',
  `userGhostBannedTimestamp` datetime DEFAULT NULL COMMENT 'When a site admin ghost banned the account: what they do in chats only shows to themselves. NULL when not ghost banned',
  `userAttachmentTicketsExtra` smallint NOT NULL DEFAULT '0' COMMENT 'Chat attachment tickets a site admin added (positive) or took away (negative), on top of the 10 everyone has'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `HBHub-AdminLog`
--
ALTER TABLE `HBHub-AdminLog`
  ADD PRIMARY KEY (`adminLogId`),
  ADD KEY `byTime` (`adminLogByUserId`,`adminLogTimestamp`),
  ADD KEY `user` (`adminLogUserId`),
  ADD KEY `chat` (`adminLogChatId`),
  ADD KEY `time` (`adminLogTimestamp`);

--
-- Indexes for table `HBHub-Attachments`
--
ALTER TABLE `HBHub-Attachments`
  ADD PRIMARY KEY (`attachmentId`),
  ADD KEY `attachmentType` (`attachmentType`);

--
-- Indexes for table `HBHub-BaccaratInvites`
--
ALTER TABLE `HBHub-BaccaratInvites`
  ADD PRIMARY KEY (`roomId`,`userId`),
  ADD KEY `userId` (`userId`),
  ADD KEY `fk_baccaratinvites_by` (`invitedByUserId`);

--
-- Indexes for table `HBHub-BaccaratPlayerRounds`
--
ALTER TABLE `HBHub-BaccaratPlayerRounds`
  ADD PRIMARY KEY (`playerRoundId`),
  ADD UNIQUE KEY `roundUser` (`roundId`,`userId`),
  ADD KEY `userTime` (`userId`,`playerTimestamp`),
  ADD KEY `playerTimestamp` (`playerTimestamp`),
  ADD KEY `fk_baccaratplayerrounds_room` (`roomId`);

--
-- Indexes for table `HBHub-BaccaratRooms`
--
ALTER TABLE `HBHub-BaccaratRooms`
  ADD PRIMARY KEY (`roomId`),
  ADD UNIQUE KEY `roomCode` (`roomCode`),
  ADD KEY `roomType` (`roomType`),
  ADD KEY `fk_baccaratrooms_host` (`roomHostUserId`);

--
-- Indexes for table `HBHub-BaccaratRounds`
--
ALTER TABLE `HBHub-BaccaratRounds`
  ADD PRIMARY KEY (`roundId`),
  ADD KEY `fk_baccaratrounds_room` (`roomId`);

--
-- Indexes for table `HBHub-BaccaratSeats`
--
ALTER TABLE `HBHub-BaccaratSeats`
  ADD PRIMARY KEY (`roomId`,`seatNumber`),
  ADD UNIQUE KEY `userId` (`userId`) COMMENT 'One Baccarat table at a time',
  ADD KEY `seatLastSeen` (`seatLastSeenTimestamp`);

--
-- Indexes for table `HBHub-BannedAvatars`
--
ALTER TABLE `HBHub-BannedAvatars`
  ADD PRIMARY KEY (`bannedAvatarId`),
  ADD UNIQUE KEY `bannedAvatarHash` (`bannedAvatarHash`),
  ADD KEY `fk_bannedavatars_from` (`bannedAvatarFromUserId`),
  ADD KEY `fk_bannedavatars_by` (`bannedAvatarAddedByUserId`);

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
-- Indexes for table `HBHub-ChatAttachments`
--
ALTER TABLE `HBHub-ChatAttachments`
  ADD PRIMARY KEY (`attachmentId`),
  ADD UNIQUE KEY `attachmentKey` (`attachmentKey`),
  ADD KEY `userStatus` (`userId`,`attachmentStatus`),
  ADD KEY `statusExpires` (`attachmentStatus`,`attachmentExpiresTimestamp`),
  ADD KEY `fk_chatattachments_chat` (`chatId`),
  ADD KEY `fk_chatattachments_message` (`messageId`);

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
  ADD KEY `fk_chats_icon` (`chatIconAttachmentId`),
  ADD UNIQUE KEY `chatClassKey` (`chatClassKey`);

--
-- Indexes for table `HBHub-ChessChat`
--
ALTER TABLE `HBHub-ChessChat`
  ADD PRIMARY KEY (`chatMessageId`),
  ADD KEY `roomMessage` (`roomId`,`chatMessageId`),
  ADD KEY `userTime` (`userId`,`chatTimestamp`);

--
-- Indexes for table `HBHub-ChessInvites`
--
ALTER TABLE `HBHub-ChessInvites`
  ADD PRIMARY KEY (`roomId`,`userId`),
  ADD KEY `userId` (`userId`),
  ADD KEY `fk_chessinvites_by` (`invitedByUserId`);

--
-- Indexes for table `HBHub-ChessResults`
--
ALTER TABLE `HBHub-ChessResults`
  ADD PRIMARY KEY (`resultId`),
  ADD KEY `userResult` (`userId`,`playerResult`),
  ADD KEY `resultTimestamp` (`resultTimestamp`),
  ADD KEY `fk_chessresults_room` (`roomId`),
  ADD KEY `fk_chessresults_opponent` (`opponentUserId`);

--
-- Indexes for table `HBHub-ChessRooms`
--
ALTER TABLE `HBHub-ChessRooms`
  ADD PRIMARY KEY (`roomId`),
  ADD UNIQUE KEY `roomCode` (`roomCode`),
  ADD KEY `typePhase` (`roomType`,`roomPhase`),
  ADD KEY `fk_chessrooms_host` (`roomHostUserId`);

--
-- Indexes for table `HBHub-ChessSeats`
--
ALTER TABLE `HBHub-ChessSeats`
  ADD PRIMARY KEY (`roomId`,`seatNumber`),
  ADD UNIQUE KEY `userId` (`userId`) COMMENT 'One chess game at a time',
  ADD KEY `seatLastSeen` (`seatLastSeenTimestamp`);

--
-- Indexes for table `HBHub-Feedback`
--
ALTER TABLE `HBHub-Feedback`
  ADD PRIMARY KEY (`feedbackId`),
  ADD KEY `userTime` (`feedbackUserId`,`feedbackCreatedTimestamp`),
  ADD KEY `fk_feedback_done` (`feedbackDoneByUserId`);

--
-- Indexes for table `HBHub-GifCache`
--
ALTER TABLE `HBHub-GifCache`
  ADD PRIMARY KEY (`gifCacheKey`),
  ADD KEY `gifCacheExpires` (`gifCacheExpiresTimestamp`);

--
-- Indexes for table `HBHub-LoginDevices`
--
ALTER TABLE `HBHub-LoginDevices`
  ADD PRIMARY KEY (`deviceTokenHash`,`userId`),
  ADD KEY `userLastUsed` (`userId`,`deviceLastUsedTimestamp`);

--
-- Indexes for table `HBHub-MatadorChat`
--
ALTER TABLE `HBHub-MatadorChat`
  ADD PRIMARY KEY (`chatMessageId`),
  ADD KEY `roomMessage` (`roomId`,`chatMessageId`),
  ADD KEY `userTime` (`userId`,`chatTimestamp`);

--
-- Indexes for table `HBHub-MatadorInvites`
--
ALTER TABLE `HBHub-MatadorInvites`
  ADD PRIMARY KEY (`roomId`,`userId`),
  ADD KEY `userId` (`userId`),
  ADD KEY `fk_matadorinvites_by` (`invitedByUserId`);

--
-- Indexes for table `HBHub-MatadorResults`
--
ALTER TABLE `HBHub-MatadorResults`
  ADD PRIMARY KEY (`resultId`),
  ADD KEY `userWon` (`userId`,`playerWon`),
  ADD KEY `resultTimestamp` (`resultTimestamp`),
  ADD KEY `fk_matadorresults_room` (`roomId`);

--
-- Indexes for table `HBHub-MatadorRooms`
--
ALTER TABLE `HBHub-MatadorRooms`
  ADD PRIMARY KEY (`roomId`),
  ADD UNIQUE KEY `roomCode` (`roomCode`),
  ADD KEY `typePhase` (`roomType`,`roomPhase`),
  ADD KEY `fk_matadorrooms_host` (`roomHostUserId`),
  ADD KEY `fk_matadorrooms_owner` (`roomOwnerUserId`);

--
-- Indexes for table `HBHub-MatadorSaves`
--
ALTER TABLE `HBHub-MatadorSaves`
  ADD PRIMARY KEY (`userId`),
  ADD KEY `fk_matadorsaves_room` (`saveRoomId`);

--
-- Indexes for table `HBHub-MatadorSeats`
--
ALTER TABLE `HBHub-MatadorSeats`
  ADD PRIMARY KEY (`roomId`,`seatNumber`),
  ADD UNIQUE KEY `userId` (`userId`) COMMENT 'One game at a time',
  ADD KEY `seatLastSeen` (`seatLastSeenTimestamp`);

--
-- Indexes for table `HBHub-MessageReactions`
--
ALTER TABLE `HBHub-MessageReactions`
  ADD PRIMARY KEY (`reactionId`),
  ADD UNIQUE KEY `messageUserEmoji` (`messageId`,`userId`,`reactionEmoji`) COMMENT 'Each emoji once per person on a message',
  ADD KEY `fk_messagereactions_user` (`userId`);

--
-- Indexes for table `HBHub-Messages`
--
ALTER TABLE `HBHub-Messages`
  ADD PRIMARY KEY (`messageId`),
  ADD KEY `userId` (`userId`),
  ADD KEY `chatId` (`chatId`),
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
-- Indexes for table `HBHub-WordleGames`
--
ALTER TABLE `HBHub-WordleGames`
  ADD PRIMARY KEY (`userId`,`gameLanguage`,`gameDate`) COMMENT 'One game per user, language and day',
  ADD KEY `languageDate` (`gameLanguage`,`gameDate`);

--
-- Indexes for table `HBHub-WordleWords`
--
ALTER TABLE `HBHub-WordleWords`
  ADD PRIMARY KEY (`wordLanguage`,`wordDate`),
  ADD KEY `languageWord` (`wordLanguage`,`wordText`);

--
-- Indexes for table `HBHub-MinerCrashReports`
--
ALTER TABLE `HBHub-MinerCrashReports`
  ADD PRIMARY KEY (`crashReportId`),
  ADD KEY `userTime` (`crashReportUserId`,`crashReportCreatedTimestamp`),
  ADD KEY `createdTimestamp` (`crashReportCreatedTimestamp`);

--
-- Indexes for table `HBHub-MinerSaves`
--
ALTER TABLE `HBHub-MinerSaves`
  ADD PRIMARY KEY (`userId`) COMMENT 'One save per user',
  ADD KEY `oreAllTime` (`saveOreAllTime`);

--
-- Indexes for table `HBHub-RouletteSpins`
--
ALTER TABLE `HBHub-RouletteSpins`
  ADD PRIMARY KEY (`spinId`),
  ADD KEY `userTime` (`userId`,`spinTimestamp`);

--
-- Indexes for table `HBHub-Sessions`
--
ALTER TABLE `HBHub-Sessions`
  ADD PRIMARY KEY (`sessionId`),
  ADD KEY `userId` (`userId`);

--
-- Indexes for table `HBHub-UnoChat`
--
ALTER TABLE `HBHub-UnoChat`
  ADD PRIMARY KEY (`chatMessageId`),
  ADD KEY `roomMessage` (`roomId`,`chatMessageId`),
  ADD KEY `userTime` (`userId`,`chatTimestamp`);

--
-- Indexes for table `HBHub-UnoInvites`
--
ALTER TABLE `HBHub-UnoInvites`
  ADD PRIMARY KEY (`roomId`,`userId`),
  ADD KEY `userId` (`userId`),
  ADD KEY `fk_unoinvites_by` (`invitedByUserId`);

--
-- Indexes for table `HBHub-UnoResults`
--
ALTER TABLE `HBHub-UnoResults`
  ADD PRIMARY KEY (`resultId`),
  ADD KEY `userResult` (`userId`,`playerResult`),
  ADD KEY `resultTimestamp` (`resultTimestamp`),
  ADD KEY `fk_unoresults_room` (`roomId`);

--
-- Indexes for table `HBHub-UnoRooms`
--
ALTER TABLE `HBHub-UnoRooms`
  ADD PRIMARY KEY (`roomId`),
  ADD UNIQUE KEY `roomCode` (`roomCode`),
  ADD KEY `typePhase` (`roomType`,`roomPhase`),
  ADD KEY `fk_unorooms_host` (`roomHostUserId`);

--
-- Indexes for table `HBHub-UnoSeats`
--
ALTER TABLE `HBHub-UnoSeats`
  ADD PRIMARY KEY (`roomId`,`seatNumber`),
  ADD UNIQUE KEY `userId` (`userId`) COMMENT 'One UNO game at a time',
  ADD KEY `seatLastSeen` (`seatLastSeenTimestamp`);

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
-- AUTO_INCREMENT for table `HBHub-AdminLog`
--
ALTER TABLE `HBHub-AdminLog`
  MODIFY `adminLogId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Attachments`
--
ALTER TABLE `HBHub-Attachments`
  MODIFY `attachmentId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-BaccaratPlayerRounds`
--
ALTER TABLE `HBHub-BaccaratPlayerRounds`
  MODIFY `playerRoundId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-BaccaratRooms`
--
ALTER TABLE `HBHub-BaccaratRooms`
  MODIFY `roomId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-BaccaratRounds`
--
ALTER TABLE `HBHub-BaccaratRounds`
  MODIFY `roundId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-BannedAvatars`
--
ALTER TABLE `HBHub-BannedAvatars`
  MODIFY `bannedAvatarId` int UNSIGNED NOT NULL AUTO_INCREMENT;

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
-- AUTO_INCREMENT for table `HBHub-ChatAttachments`
--
ALTER TABLE `HBHub-ChatAttachments`
  MODIFY `attachmentId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Chats`
--
ALTER TABLE `HBHub-Chats`
  MODIFY `chatId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-ChessChat`
--
ALTER TABLE `HBHub-ChessChat`
  MODIFY `chatMessageId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-ChessResults`
--
ALTER TABLE `HBHub-ChessResults`
  MODIFY `resultId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-ChessRooms`
--
ALTER TABLE `HBHub-ChessRooms`
  MODIFY `roomId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Feedback`
--
ALTER TABLE `HBHub-Feedback`
  MODIFY `feedbackId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-MatadorChat`
--
ALTER TABLE `HBHub-MatadorChat`
  MODIFY `chatMessageId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-MatadorResults`
--
ALTER TABLE `HBHub-MatadorResults`
  MODIFY `resultId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-MatadorRooms`
--
ALTER TABLE `HBHub-MatadorRooms`
  MODIFY `roomId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-MessageReactions`
--
ALTER TABLE `HBHub-MessageReactions`
  MODIFY `reactionId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Messages`
--
ALTER TABLE `HBHub-Messages`
  MODIFY `messageId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-MinerCrashReports`
--
ALTER TABLE `HBHub-MinerCrashReports`
  MODIFY `crashReportId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Notifications`
--
ALTER TABLE `HBHub-Notifications`
  MODIFY `notificationId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-RouletteSpins`
--
ALTER TABLE `HBHub-RouletteSpins`
  MODIFY `spinId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-UnoChat`
--
ALTER TABLE `HBHub-UnoChat`
  MODIFY `chatMessageId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-UnoResults`
--
ALTER TABLE `HBHub-UnoResults`
  MODIFY `resultId` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-UnoRooms`
--
ALTER TABLE `HBHub-UnoRooms`
  MODIFY `roomId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `HBHub-Users`
--
ALTER TABLE `HBHub-Users`
  MODIFY `userId` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `HBHub-AdminLog`
--
ALTER TABLE `HBHub-AdminLog`
  ADD CONSTRAINT `fk_adminlog_by` FOREIGN KEY (`adminLogByUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_adminlog_chat` FOREIGN KEY (`adminLogChatId`) REFERENCES `HBHub-Chats` (`chatId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_adminlog_user` FOREIGN KEY (`adminLogUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-BaccaratInvites`
--
ALTER TABLE `HBHub-BaccaratInvites`
  ADD CONSTRAINT `fk_baccaratinvites_by` FOREIGN KEY (`invitedByUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_baccaratinvites_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-BaccaratRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_baccaratinvites_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-BaccaratPlayerRounds`
--
ALTER TABLE `HBHub-BaccaratPlayerRounds`
  ADD CONSTRAINT `fk_baccaratplayerrounds_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-BaccaratRooms` (`roomId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_baccaratplayerrounds_round` FOREIGN KEY (`roundId`) REFERENCES `HBHub-BaccaratRounds` (`roundId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_baccaratplayerrounds_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-BaccaratRooms`
--
ALTER TABLE `HBHub-BaccaratRooms`
  ADD CONSTRAINT `fk_baccaratrooms_host` FOREIGN KEY (`roomHostUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-BaccaratRounds`
--
ALTER TABLE `HBHub-BaccaratRounds`
  ADD CONSTRAINT `fk_baccaratrounds_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-BaccaratRooms` (`roomId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-BaccaratSeats`
--
ALTER TABLE `HBHub-BaccaratSeats`
  ADD CONSTRAINT `fk_baccaratseats_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-BaccaratRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_baccaratseats_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-BannedAvatars`
--
ALTER TABLE `HBHub-BannedAvatars`
  ADD CONSTRAINT `fk_bannedavatars_by` FOREIGN KEY (`bannedAvatarAddedByUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_bannedavatars_from` FOREIGN KEY (`bannedAvatarFromUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL;

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
-- Constraints for table `HBHub-ChatAttachments`
--
ALTER TABLE `HBHub-ChatAttachments`
  ADD CONSTRAINT `fk_chatattachments_chat` FOREIGN KEY (`chatId`) REFERENCES `HBHub-Chats` (`chatId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_chatattachments_message` FOREIGN KEY (`messageId`) REFERENCES `HBHub-Messages` (`messageId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_chatattachments_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

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
-- Constraints for table `HBHub-ChessChat`
--
ALTER TABLE `HBHub-ChessChat`
  ADD CONSTRAINT `fk_chesschat_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-ChessRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_chesschat_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-ChessInvites`
--
ALTER TABLE `HBHub-ChessInvites`
  ADD CONSTRAINT `fk_chessinvites_by` FOREIGN KEY (`invitedByUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_chessinvites_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-ChessRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_chessinvites_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-ChessResults`
--
ALTER TABLE `HBHub-ChessResults`
  ADD CONSTRAINT `fk_chessresults_opponent` FOREIGN KEY (`opponentUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_chessresults_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-ChessRooms` (`roomId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_chessresults_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-ChessRooms`
--
ALTER TABLE `HBHub-ChessRooms`
  ADD CONSTRAINT `fk_chessrooms_host` FOREIGN KEY (`roomHostUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-ChessSeats`
--
ALTER TABLE `HBHub-ChessSeats`
  ADD CONSTRAINT `fk_chessseats_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-ChessRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_chessseats_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-Feedback`
--
ALTER TABLE `HBHub-Feedback`
  ADD CONSTRAINT `fk_feedback_done` FOREIGN KEY (`feedbackDoneByUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_feedback_user` FOREIGN KEY (`feedbackUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-LoginDevices`
--
ALTER TABLE `HBHub-LoginDevices`
  ADD CONSTRAINT `fk_logindevices_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-MatadorChat`
--
ALTER TABLE `HBHub-MatadorChat`
  ADD CONSTRAINT `fk_matadorchat_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-MatadorRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_matadorchat_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-MatadorInvites`
--
ALTER TABLE `HBHub-MatadorInvites`
  ADD CONSTRAINT `fk_matadorinvites_by` FOREIGN KEY (`invitedByUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_matadorinvites_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-MatadorRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_matadorinvites_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-MatadorResults`
--
ALTER TABLE `HBHub-MatadorResults`
  ADD CONSTRAINT `fk_matadorresults_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-MatadorRooms` (`roomId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_matadorresults_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-MatadorRooms`
--
ALTER TABLE `HBHub-MatadorRooms`
  ADD CONSTRAINT `fk_matadorrooms_host` FOREIGN KEY (`roomHostUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_matadorrooms_owner` FOREIGN KEY (`roomOwnerUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-MatadorSaves`
--
ALTER TABLE `HBHub-MatadorSaves`
  ADD CONSTRAINT `fk_matadorsaves_room` FOREIGN KEY (`saveRoomId`) REFERENCES `HBHub-MatadorRooms` (`roomId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_matadorsaves_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-MatadorSeats`
--
ALTER TABLE `HBHub-MatadorSeats`
  ADD CONSTRAINT `fk_matadorseats_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-MatadorRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_matadorseats_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-MessageReactions`
--
ALTER TABLE `HBHub-MessageReactions`
  ADD CONSTRAINT `fk_messagereactions_message` FOREIGN KEY (`messageId`) REFERENCES `HBHub-Messages` (`messageId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_messagereactions_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-Messages`
--
ALTER TABLE `HBHub-Messages`
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
-- Constraints for table `HBHub-WordleGames`
--
ALTER TABLE `HBHub-WordleGames`
  ADD CONSTRAINT `fk_wordlegames_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-MinerCrashReports`
--
ALTER TABLE `HBHub-MinerCrashReports`
  ADD CONSTRAINT `fk_minercrashreports_user` FOREIGN KEY (`crashReportUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-MinerSaves`
--
ALTER TABLE `HBHub-MinerSaves`
  ADD CONSTRAINT `fk_minersaves_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-RouletteSpins`
--
ALTER TABLE `HBHub-RouletteSpins`
  ADD CONSTRAINT `fk_roulettespins_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-Sessions`
--
ALTER TABLE `HBHub-Sessions`
  ADD CONSTRAINT `fk_sessions_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-UnoChat`
--
ALTER TABLE `HBHub-UnoChat`
  ADD CONSTRAINT `fk_unochat_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-UnoRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_unochat_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-UnoInvites`
--
ALTER TABLE `HBHub-UnoInvites`
  ADD CONSTRAINT `fk_unoinvites_by` FOREIGN KEY (`invitedByUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_unoinvites_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-UnoRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_unoinvites_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-UnoResults`
--
ALTER TABLE `HBHub-UnoResults`
  ADD CONSTRAINT `fk_unoresults_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-UnoRooms` (`roomId`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_unoresults_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-UnoRooms`
--
ALTER TABLE `HBHub-UnoRooms`
  ADD CONSTRAINT `fk_unorooms_host` FOREIGN KEY (`roomHostUserId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE SET NULL;

--
-- Constraints for table `HBHub-UnoSeats`
--
ALTER TABLE `HBHub-UnoSeats`
  ADD CONSTRAINT `fk_unoseats_room` FOREIGN KEY (`roomId`) REFERENCES `HBHub-UnoRooms` (`roomId`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_unoseats_user` FOREIGN KEY (`userId`) REFERENCES `HBHub-Users` (`userId`) ON DELETE CASCADE;

--
-- Constraints for table `HBHub-Users`
--
ALTER TABLE `HBHub-Users`
  ADD CONSTRAINT `fk_users_avatar` FOREIGN KEY (`userAvatarAttachmentId`) REFERENCES `HBHub-Attachments` (`attachmentId`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
