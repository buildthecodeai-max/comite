<?php

declare(strict_types=1);

/**
 * Export the live MySQL database to a cPanel-friendly SQL file.
 * Usage: php scripts/export_sql.php [output-path]
 */

require_once dirname(__DIR__) . '/src/bootstrap.php';

use CommitteeManager\Database;

$outPath = $argv[1] ?? (dirname(__DIR__) . '/sql/committee_manager.sql');
$pdo = Database::connection();
$dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
$timestamp = date('Y-m-d H:i:s');

function sqlQuote(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return $pdo->quote((string) $value);
}

function exportTable(PDO $pdo, string $table): string
{
    $sql = '';

    $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`')->fetch(PDO::FETCH_ASSOC);
    $createSql = $create['Create Table'] ?? '';
    $sql .= "\n--\n-- Table structure for `{$table}`\n--\nDROP TABLE IF EXISTS `{$table}`;\n";
    $sql .= $createSql . ";\n\n";

    $rows = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $table) . '`')->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        $sql .= "-- No data for `{$table}`\n";
        return $sql;
    }

    $columns = array_keys($rows[0]);
    $colList = '`' . implode('`, `', $columns) . '`';
    $sql .= "--\n-- Dumping data for `{$table}` (" . count($rows) . " rows)\n--\n";
    $sql .= "LOCK TABLES `{$table}` WRITE;\n";
    $sql .= "/*!40000 ALTER TABLE `{$table}` DISABLE KEYS */;\n";

    $chunk = [];
    foreach ($rows as $row) {
        $vals = [];
        foreach ($columns as $col) {
            $vals[] = sqlQuote($pdo, $row[$col]);
        }
        $chunk[] = '(' . implode(', ', $vals) . ')';
        if (count($chunk) >= 100) {
            $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $chunk) . ";\n";
            $chunk = [];
        }
    }
    if ($chunk) {
        $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $chunk) . ";\n";
    }

    $sql .= "/*!40000 ALTER TABLE `{$table}` ENABLE KEYS */;\n";
    $sql .= "UNLOCK TABLES;\n";

    return $sql;
}

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tables);

$out = "-- Committee Manager MySQL dump\n";
$out .= "-- Generated: {$timestamp}\n";
$out .= "-- Database: {$dbName}\n";
$out .= "-- Import this file in cPanel → phpMyAdmin → Import\n";
$out .= "--\n";
$out .= "-- NOTE: Create an empty database in cPanel first, then select it in phpMyAdmin\n";
$out .= "-- before importing. Or uncomment the CREATE/USE lines below and edit the name.\n\n";
$out .= "SET NAMES utf8mb4;\n";
$out .= "SET FOREIGN_KEY_CHECKS = 0;\n";
$out .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
$out .= "SET time_zone = '+00:00';\n\n";
$out .= "-- CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\n";
$out .= "-- USE `{$dbName}`;\n";

foreach ($tables as $table) {
    $out .= exportTable($pdo, (string) $table);
}

$out .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

$dir = dirname($outPath);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

file_put_contents($outPath, $out);
echo "Exported " . count($tables) . " tables to {$outPath}\n";
echo "Size: " . number_format(filesize($outPath)) . " bytes\n";
