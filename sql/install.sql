-- Committee Manager MySQL dump
-- Generated: 2026-07-10 04:45:02
-- Database: committee_manager
-- Import this file in cPanel → phpMyAdmin → Import
--
-- NOTE: Create an empty database in cPanel first, then select it in phpMyAdmin
-- before importing. Or uncomment the CREATE/USE lines below and edit the name.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- CREATE DATABASE IF NOT EXISTS `committee_manager` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `committee_manager`;

--
-- Table structure for `members`
--
DROP TABLE IF EXISTS `members`;
CREATE TABLE `members` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `shares` int NOT NULL DEFAULT '1',
  `phone` varchar(50) NOT NULL DEFAULT '',
  `email` varchar(255) NOT NULL DEFAULT '',
  `pin_hash` varchar(255) NOT NULL,
  `pref_month` int NOT NULL DEFAULT '0',
  `deleted_at` bigint DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for `members` (28 rows)
--
LOCK TABLES `members` WRITE;
/*!40000 ALTER TABLE `members` DISABLE KEYS */;
INSERT INTO `members` (`id`, `name`, `shares`, `phone`, `email`, `pin_hash`, `pref_month`, `deleted_at`, `deleted_by`) VALUES
(1, 'Aitsam Haider', 20, '', '', '$2y$12$h/ZbXEbOQmJ/iOXOLgMLvuubOkiLnG.tUKfcJyyRpHwBC/FbhF5se', 0, NULL, NULL),
(2, 'Samin Javed', 20, '', '', '$2y$12$pUDeAd2wll7TzPsduSTQZ.2pVKCVyhdirGVdtxJ3CUh5qoPqeWxk6', 0, NULL, NULL),
(3, 'Mouzzama', 27, '', '', '$2y$12$OUPU9ERa26YIBStGO//jq.oVBTleaPU/16XRZQ540e.JMOEin1NE6', 0, NULL, NULL),
(4, 'Naib Ali', 20, '', '', '$2y$12$MNoXl059AqTL8Ftg/0eyY.DRT7WQD2xR.e2lelGhNV4hyLBxlVPTa', 0, NULL, NULL),
(5, 'Syed Zaidi', 30, '', '', '$2y$12$la0pkBJiq5Hu0z.CZnApJORmRY.1UMGuVSatL9Suikswp/IPzJZ3e', 0, NULL, NULL),
(6, 'Adeel', 25, '', '', '$2y$12$W5vYULKnwjJQFzVqfFzrhuobcuhvbxm/NzqnnZXSajxwlsdg/R4WK', 0, NULL, NULL),
(7, 'Usman Libeerty', 25, '', '', '$2y$12$N9ce6tewwxbIhHYoJAXmaeH7996rl9.KQjXdxPVfKIv9OaAD8.h9S', 5, NULL, NULL),
(8, 'Qasim Ali', 10, '', '', '$2y$12$LPYmEt2QcKCK1mFmss42Z.NJujV2irgts3W2G4sjtXhabdAtkGGmC', 0, NULL, NULL),
(9, 'Ali Abbas', 6, '', '', '$2y$12$EoWXxJQyL2.kL4SnvUXCjedlToxavxUHZNYn.avuAnX9qXnXltx5W', 0, NULL, NULL),
(10, 'Khurram Abbas', 8, '', '', '$2y$12$AfbQmvERKlhDALH2KvRt2uoine/NSDSsVl.0hqX.53QjwUZud1Y4C', 0, NULL, NULL),
(11, 'zaman', 2, '', '', '$2y$12$/cNGvneyDQp4MW7uX8sMuO39JO2h1a/dJP89M4jaRmbA7RHoRT2Oq', 0, NULL, NULL),
(12, 'Nuzhat Zargham', 2, '', '', '$2y$12$eqrtczTL72rtKlxNEikf1uNnWzxoKHnohcjDGEY6jKLGkLdD5Xtx6', 0, NULL, NULL),
(13, 'Nusrat', 1, '', '', '$2y$12$ld9Aga/seLKtT74ncSeTmeWp6LrrBeZ64o2L9dbeG7vFBmDTc3DrS', 0, NULL, NULL),
(14, 'Miss Uzma', 2, '', '', '$2y$12$gg/ZoShEZZHjUZUVoyJjRuWLNowNllxl1OXJUQb18QAHyd2IO1Dc.', 0, NULL, NULL),
(15, 'Nabeela', 11, '', '', '$2y$12$g6MyvcYVSl2k72zMgy/43ONcOzIMukq2AbfYrB1BdVX1sdhEHTDzW', 0, NULL, NULL),
(16, 'Miss Sobia', 2, '', '', '$2y$12$InK4kjndjlDZYtFO3dabcu/SaLWs.i9BEddbyuRAA6ZCQVGd2/2B2', 0, NULL, NULL),
(17, 'Miss Robina', 5, '', '', '$2y$12$LWJ.ciYHHciN/di7lgKEuuQCpWiybAoLZ7QH9pY1O3f34rKDiaK0u', 3, NULL, NULL),
(18, 'Miss Sajeela', 2, '', '', '$2y$12$8zo8M8b5z2gOfmCQ7C7CT.DdQMQCd9/COMtz7VThhmDgCznWLENWW', 0, NULL, NULL),
(19, 'Rashida', 7, '', '', '$2y$12$cACx4fCMMCSlROCNLkk7W.HASf2fw.1BlrbNmzp04cGA3KJBPd.KC', 0, NULL, NULL),
(20, 'Osama', 1, '', '', '$2y$12$Mh5WlsnnRw3jj.D0oLERSOAEZ8GTQEB699YFyDJSMG/X.vSROAb9e', 0, NULL, NULL),
(21, 'Mishal', 6, '', '', '$2y$12$Pg1v7ydTp9rlpOsiYxGQXO6GktEWNf/ALbg.umfdY4WJOe08b9x5O', 0, NULL, NULL),
(22, 'Ishrat', 14, '', '', '$2y$12$mthwlpWrLf4IGx96F8JrJuMYnhcpNLcz4SE1MRNeLuA9Lrstnt2y2', 0, NULL, NULL),
(23, 'Amina Qariya', 3, '', '', '$2y$12$qeOCkpSSe6gtAcGo3lmZHOWusDuqRfuhYP0aApzwnkS0qyyl2hDcq', 0, NULL, NULL),
(24, 'Muqadasa', 2, '', '', '$2y$12$taIru3yq1vm/WjXeQCsb2OGXx8KgLp95zg9JEtI3YEPSmjNTA.Ybi', 0, NULL, NULL),
(25, 'Hira', 1, '', '', '$2y$12$RbHUQg/HXt.UWB8402//CugDMjSFpYRFS3T1NBUCnk5IG37JPVU9e', 0, NULL, NULL),
(26, 'Fatima Maid', 8, '', '', '$2y$12$7tHq/9bHlMkYTUc7wbmQRuKmHXLFCQMpHVwRZpGIV2/ZzDajLYSz6', 0, NULL, NULL),
(27, 'Zunaeera', 7, '', '', '$2y$12$1Udrol23LLHOQ7CAIUkqsOCulm.V.5NbTF9OnsKWmY8fIgA/idobS', 3, NULL, NULL),
(28, 'Raza', 2, '', '', '$2y$12$q3Zr9FdXeb1Hw2yykhsi9O05WS8qqxRQIKy5O5jxoJUoz2vd7Be0C', 0, NULL, NULL);
/*!40000 ALTER TABLE `members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for `password_reset_tokens`
--
DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE `password_reset_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` bigint NOT NULL,
  `created_at` bigint NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No data for `password_reset_tokens`

--
-- Table structure for `payments`
--
DROP TABLE IF EXISTS `payments`;
CREATE TABLE `payments` (
  `member_id` int NOT NULL,
  `month_num` int NOT NULL,
  PRIMARY KEY (`member_id`,`month_num`),
  CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for `payments` (63 rows)
--
LOCK TABLES `payments` WRITE;
/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
INSERT INTO `payments` (`member_id`, `month_num`) VALUES
(1, 1),
(1, 2),
(1, 3),
(2, 1),
(2, 2),
(2, 3),
(3, 1),
(3, 2),
(4, 1),
(4, 2),
(4, 3),
(5, 1),
(5, 2),
(5, 3),
(6, 1),
(6, 2),
(7, 1),
(7, 2),
(7, 3),
(8, 1),
(8, 2),
(9, 1),
(9, 2),
(9, 3),
(10, 1),
(10, 2),
(11, 1),
(11, 2),
(12, 1),
(12, 2),
(13, 1),
(13, 2),
(14, 1),
(14, 2),
(15, 1),
(15, 2),
(16, 1),
(16, 2),
(17, 1),
(17, 2),
(18, 1),
(18, 2),
(19, 1),
(19, 2),
(20, 1),
(20, 2),
(20, 3),
(21, 1),
(21, 2),
(22, 1),
(22, 2),
(23, 1),
(23, 2),
(24, 1),
(24, 2),
(25, 1),
(25, 2),
(26, 1),
(26, 2),
(27, 1),
(27, 2),
(28, 1),
(28, 2);
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for `settings`
--
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` int NOT NULL DEFAULT '1',
  `committee_name` varchar(255) NOT NULL DEFAULT 'DIGLIP Committee',
  `committee_subtitle` varchar(255) NOT NULL DEFAULT 'Committee Management System',
  `admin_user` varchar(100) NOT NULL DEFAULT 'admin',
  `admin_pass_hash` varchar(255) NOT NULL,
  `admin_email` varchar(255) NOT NULL DEFAULT '',
  `recovery_contact` varchar(255) NOT NULL DEFAULT '',
  `recovery_note` text NOT NULL,
  `amt_per_share` int NOT NULL DEFAULT '2000',
  `total_months` int NOT NULL DEFAULT '25',
  `prize_per_share` int NOT NULL DEFAULT '50000',
  `start_month` char(7) NOT NULL DEFAULT '',
  `current_month` int NOT NULL DEFAULT '1',
  `next_id` int NOT NULL DEFAULT '251',
  `updated_at` bigint NOT NULL DEFAULT '0',
  `committee_rules` text,
  PRIMARY KEY (`id`),
  CONSTRAINT `settings_single_row` CHECK ((`id` = 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for `settings` (1 rows)
--
LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` (`id`, `committee_name`, `committee_subtitle`, `admin_user`, `admin_pass_hash`, `admin_email`, `recovery_contact`, `recovery_note`, `amt_per_share`, `total_months`, `prize_per_share`, `start_month`, `current_month`, `next_id`, `updated_at`, `committee_rules`) VALUES
(1, 'Commite Manager', 'Committee Management System', 'admin', '$2y$12$CH1TSx8MhY4ATKKuwTlWNuQDgpLSp1zQSxy3MM3OmYGy1Xy1sK49a', '', '', 'Contact the committee admin to reset your password or PIN.', 2000, 25, 50000, '2026-05', 3, 29, 1783635897563, '{\"rules\":{\"maxPrizePerMonth\":550000,\"maxSharesPerMonth\":11,\"maxWinnersPerMonth\":0,\"maxWinsPerMember\":0,\"minPaymentBeforeWinning\":0,\"allowPartialPrize\":true,\"allowMultipleWinners\":true,\"winnerDeclarationLock\":false,\"autoPrizeCalculation\":true,\"requireConfirmation\":true},\"auditLog\":[{\"action\":\"Maximum Prize Amount Per Month\",\"oldValue\":0,\"newValue\":500000,\"admin\":\"admin\",\"at\":1783464546223,\"ip\":\"local\"},{\"action\":\"Maximum Prize Amount Per Month\",\"oldValue\":500000,\"newValue\":550000,\"admin\":\"admin\",\"at\":1783464653817,\"ip\":\"local\"}]}');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for `winners`
--
DROP TABLE IF EXISTS `winners`;
CREATE TABLE `winners` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `month_num` int NOT NULL,
  `shares_won` int NOT NULL DEFAULT '1',
  `amount` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  CONSTRAINT `winners_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=199 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for `winners` (7 rows)
--
LOCK TABLES `winners` WRITE;
/*!40000 ALTER TABLE `winners` DISABLE KEYS */;
INSERT INTO `winners` (`id`, `member_id`, `name`, `month_num`, `shares_won`, `amount`) VALUES
(192, 4, 'Naib Ali', 1, 10, 500000),
(193, 1, 'Aitsam Haider', 1, 1, 50000),
(194, 10, 'Khurram Abbas', 2, 6, 300000),
(195, 11, 'zaman', 2, 1, 50000),
(196, 15, 'Nabeela', 2, 4, 200000),
(197, 17, 'Miss Robina', 3, 2, 100000),
(198, 27, 'Zunaeera', 3, 3, 150000);
/*!40000 ALTER TABLE `winners` ENABLE KEYS */;
UNLOCK TABLES;

SET FOREIGN_KEY_CHECKS = 1;
