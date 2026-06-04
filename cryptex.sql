-- phpMyAdmin SQL Dump
-- version 5.2.2deb1+deb13u1
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost:3306
-- Généré le : jeu. 04 juin 2026 à 12:40
-- Version du serveur : 11.8.6-MariaDB-0+deb13u1 from Debian-log
-- Version de PHP : 8.4.21

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `cryptex`
--

-- --------------------------------------------------------

--
-- Structure de la table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `token` varchar(128) DEFAULT NULL COMMENT 'Token concerné',
  `action` varchar(100) NOT NULL COMMENT 'created | viewed | access_denied | decrypt_error | expired',
  `actor` varchar(200) DEFAULT NULL COMMENT 'Email de l acteur',
  `ip` varchar(64) DEFAULT NULL COMMENT 'Adresse IP',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `secrets`
--

CREATE TABLE `secrets` (
  `id` int(11) NOT NULL,
  `token` varchar(128) DEFAULT NULL,
  `content_encrypted` mediumtext NOT NULL COMMENT 'Contenu chiffré AES-256-GCM (base64)',
  `nonce` varchar(64) NOT NULL COMMENT 'Nonce GCM (base64)',
  `title` varchar(200) DEFAULT NULL COMMENT 'Objet / titre du secret',
  `sender_name` varchar(200) NOT NULL COMMENT 'Nom complet de l expéditeur (SSO)',
  `sender_email` varchar(200) NOT NULL COMMENT 'Email de l expéditeur (SSO)',
  `recipient_email` varchar(200) DEFAULT NULL,
  `recipient_name` varchar(200) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL COMMENT 'Date/heure d expiration',
  `destroy_on_read` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = détruit après la 1ère lecture',
  `read_at` datetime DEFAULT NULL,
  `is_destroyed` tinyint(1) DEFAULT 0,
  `ip_sender` varchar(64) DEFAULT NULL COMMENT 'IP de l expéditeur (audit)',
  `is_globally_destroyed` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `secret_files`
--

CREATE TABLE `secret_files` (
  `id` int(11) NOT NULL,
  `secret_id` int(11) NOT NULL,
  `filename_original` varchar(255) NOT NULL COMMENT 'Nom original du fichier',
  `filename_stored` varchar(64) NOT NULL COMMENT 'Nom UUID sur disque (.enc)',
  `mime_type` varchar(127) NOT NULL DEFAULT 'application/octet-stream',
  `file_size` int(11) NOT NULL DEFAULT 0 COMMENT 'Taille en octets (avant chiffrement)',
  `file_nonce` varchar(32) NOT NULL COMMENT 'Nonce GCM base64',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Pièces jointes chiffrées AES-256-GCM';

-- --------------------------------------------------------

--
-- Structure de la table `secret_recipients`
--

CREATE TABLE `secret_recipients` (
  `id` int(11) NOT NULL,
  `secret_id` int(11) NOT NULL,
  `token` varchar(128) NOT NULL,
  `recipient_email` varchar(200) NOT NULL,
  `recipient_name` varchar(200) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `is_destroyed` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `samaccountname` varchar(100) NOT NULL,
  `email` varchar(200) NOT NULL,
  `nom` varchar(100) DEFAULT NULL,
  `prenom` varchar(100) DEFAULT NULL,
  `service` varchar(200) DEFAULT NULL,
  `poste` varchar(200) DEFAULT NULL,
  `site` varchar(200) DEFAULT NULL,
  `telephone` varchar(50) DEFAULT NULL,
  `role` enum('admin','user','blocked') NOT NULL DEFAULT 'user',
  `source` enum('sso','ad_sync') NOT NULL DEFAULT 'sso',
  `first_login` datetime DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_date` (`created_at`);

--
-- Index pour la table `secrets`
--
ALTER TABLE `secrets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expires` (`expires_at`),
  ADD KEY `idx_recipient` (`recipient_email`),
  ADD KEY `idx_destroyed` (`is_destroyed`);

--
-- Index pour la table `secret_files`
--
ALTER TABLE `secret_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_secret_id` (`secret_id`);

--
-- Index pour la table `secret_recipients`
--
ALTER TABLE `secret_recipients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `secret_id` (`secret_id`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `samaccountname` (`samaccountname`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `secrets`
--
ALTER TABLE `secrets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `secret_files`
--
ALTER TABLE `secret_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `secret_recipients`
--
ALTER TABLE `secret_recipients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `secret_files`
--
ALTER TABLE `secret_files`
  ADD CONSTRAINT `secret_files_ibfk_1` FOREIGN KEY (`secret_id`) REFERENCES `secrets` (`id`) ON DELETE CASCADE;

--
-- Contraintes pour la table `secret_recipients`
--
ALTER TABLE `secret_recipients`
  ADD CONSTRAINT `secret_recipients_ibfk_1` FOREIGN KEY (`secret_id`) REFERENCES `secrets` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
