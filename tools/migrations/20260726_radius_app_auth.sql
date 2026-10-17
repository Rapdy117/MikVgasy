-- Migration MariaDB à exécuter APRÈS l'import du dump transmis.
-- Le dump contient les tables et les données, mais pas les comptes MariaDB.
-- Exécuter avec un compte administrateur MariaDB (root via sudo, par exemple).
-- Mot de passe demandé pour radius_app : Secret123!

CREATE DATABASE IF NOT EXISTS `radius_manager`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

USE `radius_manager`;

CREATE USER IF NOT EXISTS 'radius_app'@'localhost'
  IDENTIFIED BY 'Secret123!';

ALTER USER 'radius_app'@'localhost'
  IDENTIFIED BY 'Secret123!';

GRANT ALL PRIVILEGES ON `radius_manager`.* TO 'radius_app'@'localhost';
FLUSH PRIVILEGES;

-- Contrôle attendu après exécution :
-- SHOW GRANTS FOR 'radius_app'@'localhost';
