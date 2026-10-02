-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 08, 2026 at 09:40 PM
-- Server version: 10.11.18-MariaDB-cll-lve-log
-- PHP Version: 8.4.24

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `quranfellow_share`
--

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

CREATE TABLE `members` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `shares` int(11) NOT NULL DEFAULT 1,
  `phone` varchar(50) NOT NULL DEFAULT '',
  `email` varchar(255) NOT NULL DEFAULT '',
  `pin_hash` varchar(255) NOT NULL,
  `pref_month` int(11) NOT NULL DEFAULT 0,
  `deleted_at` bigint(20) DEFAULT NULL,
  `deleted_by` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `members`
--

INSERT INTO `members` (`id`, `name`, `shares`, `phone`, `email`, `pin_hash`, `pref_month`, `deleted_at`, `deleted_by`) VALUES
(1, 'Aitsam Haider', 20, 'admin', '', '$2y$10$VPhVKpBmwIXKlNQDeEPJs.JWnlLE4JpSFXqAbEHrRopuBY4NmlRhG', 0, NULL, NULL),
(2, 'Samin Javed', 20, '', '', '$2y$12$pUDeAd2wll7TzPsduSTQZ.2pVKCVyhdirGVdtxJ3CUh5qoPqeWxk6', 0, NULL, NULL),
(3, 'Mouzzama', 22, '', '', '$2y$12$OUPU9ERa26YIBStGO//jq.oVBTleaPU/16XRZQ540e.JMOEin1NE6', 0, NULL, NULL),
(4, 'Naib Ali', 20, '', '', '$2y$10$VQrAEFoPjxLO8BvmZub/EeeSWprX8/Zovxl5B57a9tFiXfwKLtjqO', 0, NULL, NULL),
(5, 'Syed Zaidi', 30, '', '', '$2y$12$la0pkBJiq5Hu0z.CZnApJORmRY.1UMGuVSatL9Suikswp/IPzJZ3e', 0, NULL, NULL),
(6, 'Adeel', 25, '', '', '$2y$12$W5vYULKnwjJQFzVqfFzrhuobcuhvbxm/NzqnnZXSajxwlsdg/R4WK', 0, NULL, NULL),
(7, 'Usman Libeerty', 25, '', '', '$2y$12$N9ce6tewwxbIhHYoJAXmaeH7996rl9.KQjXdxPVfKIv9OaAD8.h9S', 5, NULL, NULL),
(8, 'Qasim Ali', 10, '', '', '$2y$12$LPYmEt2QcKCK1mFmss42Z.NJujV2irgts3W2G4sjtXhabdAtkGGmC', 0, NULL, NULL),
(9, 'Ali Abbas', 6, '', '', '$2y$12$EoWXxJQyL2.kL4SnvUXCjedlToxavxUHZNYn.avuAnX9qXnXltx5W', 8, NULL, NULL),
(10, 'Khurram Abbas', 8, '', '', '$2y$12$AfbQmvERKlhDALH2KvRt2uoine/NSDSsVl.0hqX.53QjwUZud1Y4C', 0, NULL, NULL),
(11, 'zaman', 2, '', '', '$2y$12$/cNGvneyDQp4MW7uX8sMuO39JO2h1a/dJP89M4jaRmbA7RHoRT2Oq', 0, NULL, NULL),
(12, 'Nuzhat Zargham', 2, '', '', '$2y$12$eqrtczTL72rtKlxNEikf1uNnWzxoKHnohcjDGEY6jKLGkLdD5Xtx6', 0, NULL, NULL),
(13, 'Nusrat', 1, '', '', '$2y$12$ld9Aga/seLKtT74ncSeTmeWp6LrrBeZ64o2L9dbeG7vFBmDTc3DrS', 0, NULL, NULL),
(14, 'Miss Uzma', 2, '', '', '$2y$12$gg/ZoShEZZHjUZUVoyJjRuWLNowNllxl1OXJUQb18QAHyd2IO1Dc.', 0, NULL, NULL),
(15, 'Nabeela', 12, '', '', '$2y$12$g6MyvcYVSl2k72zMgy/43ONcOzIMukq2AbfYrB1BdVX1sdhEHTDzW', 0, NULL, NULL),
(16, 'Miss Sobia', 2, '', '', '$2y$12$InK4kjndjlDZYtFO3dabcu/SaLWs.i9BEddbyuRAA6ZCQVGd2/2B2', 0, NULL, NULL),
(17, 'Miss Robina', 7, '', '', '$2y$12$LWJ.ciYHHciN/di7lgKEuuQCpWiybAoLZ7QH9pY1O3f34rKDiaK0u', 3, NULL, NULL),
(18, 'Miss Sajeela', 2, '', '', '$2y$12$8zo8M8b5z2gOfmCQ7C7CT.DdQMQCd9/COMtz7VThhmDgCznWLENWW', 0, NULL, NULL),
(19, 'Rashida', 7, '', '', '$2y$12$cACx4fCMMCSlROCNLkk7W.HASf2fw.1BlrbNmzp04cGA3KJBPd.KC', 0, NULL, NULL),
(20, 'Osama', 1, '', '', '$2y$12$Mh5WlsnnRw3jj.D0oLERSOAEZ8GTQEB699YFyDJSMG/X.vSROAb9e', 0, NULL, NULL),
(21, 'Mishal', 6, '', '', '$2y$12$Pg1v7ydTp9rlpOsiYxGQXO6GktEWNf/ALbg.umfdY4WJOe08b9x5O', 0, NULL, NULL),
(22, 'Ishrat', 14, '', '', '$2y$12$mthwlpWrLf4IGx96F8JrJuMYnhcpNLcz4SE1MRNeLuA9Lrstnt2y2', 0, NULL, NULL),
(23, 'Amina Qariya', 4, '', '', '$2y$12$qeOCkpSSe6gtAcGo3lmZHOWusDuqRfuhYP0aApzwnkS0qyyl2hDcq', 0, NULL, NULL),
(24, 'Muqadasa', 2, '', '', '$2y$12$taIru3yq1vm/WjXeQCsb2OGXx8KgLp95zg9JEtI3YEPSmjNTA.Ybi', 0, NULL, NULL),
(25, 'Hira', 1, '', '', '$2y$10$1iKrB4dzlHhTURtIRtO4meg7.5MRGK0CBciPCt0FGSryaqgcAIEAG', 0, NULL, NULL),
(26, 'Fatima Maid', 8, '', '', '$2y$12$7tHq/9bHlMkYTUc7wbmQRuKmHXLFCQMpHVwRZpGIV2/ZzDajLYSz6', 0, NULL, NULL),
(27, 'Zunaeera', 7, '', '', '$2y$12$1Udrol23LLHOQ7CAIUkqsOCulm.V.5NbTF9OnsKWmY8fIgA/idobS', 3, NULL, NULL),
(28, 'Raza', 2, '', '', '$2y$12$q3Zr9FdXeb1Hw2yykhsi9O05WS8qqxRQIKy5O5jxoJUoz2vd7Be0C', 0, NULL, NULL),
(29, 'FARWA KAMRAN', 5, '', '', '$2y$10$iVVuYiDa4He03orHsUoCDeYiQLxsO51Gdt/lUK/vrSM2cttd.dCWu', 0, NULL, NULL),
(30, 'Miss Naseeha', 1, '', '', '$2y$10$yeW.707vz3.RTwZGlIhlnepD4NBf8yEvgzEzJ/nh55A8TSBXGSFHW', 0, NULL, NULL),
(31, 'Kalsoom maid', 1, '', '', '$2y$10$.UIA1.BmNhUuRHNk27Ag6ezpReLvi8fkOuJbKTHOJlVRDFpfrWDZm', 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token_hash` varchar(255) NOT NULL,
  `expires_at` bigint(20) NOT NULL,
  `created_at` bigint(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `member_id` int(11) NOT NULL,
  `month_num` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`member_id`, `month_num`) VALUES
(1, 1),
(1, 2),
(1, 3),
(1, 4),
(1, 5),
(2, 1),
(2, 2),
(2, 3),
(2, 4),
(2, 5),
(3, 1),
(3, 2),
(3, 3),
(3, 4),
(4, 1),
(4, 2),
(4, 3),
(4, 4),
(4, 5),
(5, 1),
(5, 2),
(5, 3),
(5, 4),
(5, 5),
(6, 1),
(6, 2),
(6, 3),
(6, 4),
(6, 5),
(7, 1),
(7, 2),
(7, 3),
(7, 4),
(7, 5),
(8, 1),
(8, 2),
(8, 3),
(8, 4),
(9, 1),
(9, 2),
(9, 3),
(9, 4),
(10, 1),
(10, 2),
(10, 3),
(10, 4),
(11, 1),
(11, 2),
(11, 3),
(11, 4),
(12, 1),
(12, 2),
(12, 3),
(12, 4),
(13, 1),
(13, 2),
(13, 3),
(13, 4),
(14, 1),
(14, 2),
(14, 3),
(14, 4),
(15, 1),
(15, 2),
(15, 3),
(15, 4),
(16, 1),
(16, 2),
(16, 3),
(16, 4),
(17, 1),
(17, 2),
(17, 3),
(17, 4),
(18, 1),
(18, 2),
(18, 3),
(18, 4),
(19, 1),
(19, 2),
(19, 3),
(19, 4),
(20, 1),
(20, 2),
(20, 3),
(20, 4),
(20, 5),
(21, 1),
(21, 2),
(21, 3),
(21, 4),
(22, 1),
(22, 2),
(22, 3),
(22, 4),
(23, 1),
(23, 2),
(23, 3),
(23, 4),
(24, 1),
(24, 2),
(24, 3),
(24, 4),
(25, 1),
(25, 2),
(25, 3),
(25, 4),
(26, 1),
(26, 2),
(26, 3),
(26, 4),
(27, 1),
(27, 2),
(27, 3),
(27, 4),
(28, 1),
(28, 2),
(28, 3),
(28, 4),
(29, 1),
(29, 2),
(29, 3),
(29, 4),
(30, 1),
(30, 2),
(30, 3),
(30, 4),
(31, 1),
(31, 2),
(31, 3),
(31, 4);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `committee_name` varchar(255) NOT NULL DEFAULT 'DIGLIP Committee',
  `committee_subtitle` varchar(255) NOT NULL DEFAULT 'Committee Management System',
  `admin_user` varchar(100) NOT NULL DEFAULT 'admin',
  `admin_pass_hash` varchar(255) NOT NULL,
  `admin_email` varchar(255) NOT NULL DEFAULT '',
  `recovery_contact` varchar(255) NOT NULL DEFAULT '',
  `recovery_note` text NOT NULL,
  `amt_per_share` int(11) NOT NULL DEFAULT 2000,
  `total_months` int(11) NOT NULL DEFAULT 25,
  `prize_per_share` int(11) NOT NULL DEFAULT 50000,
  `start_month` char(7) NOT NULL DEFAULT '',
  `current_month` int(11) NOT NULL DEFAULT 1,
  `next_id` int(11) NOT NULL DEFAULT 251,
  `updated_at` bigint(20) NOT NULL DEFAULT 0,
  `committee_rules` text DEFAULT NULL
) ;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `committee_name`, `committee_subtitle`, `admin_user`, `admin_pass_hash`, `admin_email`, `recovery_contact`, `recovery_note`, `amt_per_share`, `total_months`, `prize_per_share`, `start_month`, `current_month`, `next_id`, `updated_at`, `committee_rules`) VALUES
(1, 'Commite Manager', 'Committee Management System', 'admin', '$2y$10$9baNNFmLwD70kZdZ8Ow1d.oHgHY3/BszKGkX1ikSySrRhQ4dL9.4e', 'buildthecode.ai@gmail.com', '03227867966', 'Contact the committee admin to reset your password or PIN.', 2000, 25, 50000, '2026-05', 3, 32, 1788884891859, '{\"rules\":{\"maxPrizePerMonth\":550000,\"maxSharesPerMonth\":11,\"maxWinnersPerMonth\":0,\"maxWinsPerMember\":0,\"minPaymentBeforeWinning\":0,\"allowPartialPrize\":true,\"allowMultipleWinners\":true,\"winnerDeclarationLock\":false,\"autoPrizeCalculation\":true,\"requireConfirmation\":true},\"auditLog\":[{\"action\":\"Maximum Prize Amount Per Month\",\"oldValue\":0,\"newValue\":500000,\"admin\":\"admin\",\"at\":1783464546223,\"ip\":\"local\"},{\"action\":\"Maximum Prize Amount Per Month\",\"oldValue\":500000,\"newValue\":550000,\"admin\":\"admin\",\"at\":1783464653817,\"ip\":\"local\"}]}');

-- --------------------------------------------------------

--
-- Table structure for table `winners`
--

CREATE TABLE `winners` (
  `id` int(11) NOT NULL,
  `member_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `month_num` int(11) NOT NULL,
  `shares_won` int(11) NOT NULL DEFAULT 1,
  `amount` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `winners`
--

INSERT INTO `winners` (`id`, `member_id`, `name`, `month_num`, `shares_won`, `amount`) VALUES
(1487, 4, 'Naib Ali', 1, 10, 500000),
(1488, 4, 'Naib Ali', 1, 1, 50000),
(1489, 10, 'Khurram Abbas', 2, 6, 300000),
(1490, 11, 'zaman', 2, 1, 50000),
(1491, 15, 'Nabeela', 2, 4, 200000),
(1492, 17, 'Miss Robina', 3, 2, 100000),
(1493, 27, 'Zunaeera', 3, 3, 150000),
(1494, 29, 'FARWA KAMRAN', 3, 3, 150000),
(1495, 19, 'Rashida', 3, 2, 100000),
(1496, 4, 'Naib Ali', 3, 1, 50000),
(1497, 4, 'Naib Ali', 4, 8, 400000),
(1498, 5, 'Syed Zaidi', 4, 3, 150000),
(1499, 15, 'Nabeela', 5, 4, 200000);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`member_id`,`month_num`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `winners`
--
ALTER TABLE `winners`
  ADD PRIMARY KEY (`id`),
  ADD KEY `member_id` (`member_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `winners`
--
ALTER TABLE `winners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1500;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `winners`
--
ALTER TABLE `winners`
  ADD CONSTRAINT `winners_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
