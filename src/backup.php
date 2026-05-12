<?php
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.

require_once(dirname(__FILE__) . "/includes/funcLib.php");
require_once(dirname(__FILE__) . "/includes/MySmarty.class.php");

session_start();
if (!isset($_SESSION["userid"])) {
	header("Location: " . getFullPath("login.php"));
	exit;
}
if ($_SESSION["admin"] != 1) {
	echo "You don't have admin privileges.";
	exit;
}

$smarty = new MySmarty();
$opt = $smarty->opt();
$settingsFile = dirname(__FILE__) . "/includes/config_settings.php";
$imageDir = dirname(__FILE__) . "/" . $opt["image_subdir"];
$error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "download_backup") {
	if (!class_exists('ZipArchive')) {
		$error = "ZipArchive is not available on this server.";
	} else {
		set_time_limit(0);
		$tempFile = tempnam(sys_get_temp_dir(), "phpgiftreg_backup_");
		$zip = new ZipArchive();
		if ($zip->open($tempFile, ZipArchive::OVERWRITE) !== true) {
			$error = "Unable to create the backup archive.";
		} else {
			try {
				$zip->addFromString("database.sql", createDatabaseSqlDump($smarty->dbh()));
			} catch (Exception $e) {
				$error = "Database backup failed: " . $e->getMessage();
			}

			if (!$error && file_exists($settingsFile)) {
				$zip->addFile($settingsFile, "config_settings.php");
			}

			if (!$error && is_dir($imageDir)) {
				addDirectoryToZip($zip, $imageDir, basename($imageDir));
			}

			$zip->close();

			if (!$error && file_exists($tempFile)) {
				header('Content-Type: application/zip');
				header('Content-Disposition: attachment; filename="phpgiftreg-backup-' . date('Ymd_His') . '.zip"');
				header('Content-Length: ' . filesize($tempFile));
				readfile($tempFile);
				unlink($tempFile);
				exit;
			}
		}
	}

	if (file_exists($tempFile)) {
		@unlink($tempFile);
	}
}

$smarty->assign('image_dir', $opt["image_subdir"]);
$smarty->assign('config_file', 'includes/config_settings.php');
$smarty->assign('error', $error);
$smarty->assign('action', 'backup');
$smarty->display('backup.tpl');

function createDatabaseSqlDump(PDO $dbh) {
	$dump = "-- phpGiftReg backup\n";
	$dump .= "-- Generated on " . date('Y-m-d H:i:s') . "\n\n";
	$dump .= "SET NAMES utf8mb4;\n";
	$dump .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

	$tables = $dbh->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
	foreach ($tables as $table) {
		$tableName = str_replace('`', '``', $table);
		$createRow = $dbh->query("SHOW CREATE TABLE `{$tableName}`")->fetch(PDO::FETCH_ASSOC);
		if (!$createRow || !isset($createRow['Create Table'])) {
			throw new Exception('Unable to read CREATE TABLE for ' . $tableName);
		}

		$dump .= "DROP TABLE IF EXISTS `{$tableName}`;\n";
		$dump .= $createRow['Create Table'] . ";\n\n";

		$dataStmt = $dbh->query("SELECT * FROM `{$tableName}`");
		while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
			$columns = array();
			$values = array();
			foreach ($row as $column => $value) {
				$columns[] = "`" . str_replace('`', '``', $column) . "`";
				$values[] = escapeSqlValue($value);
			}
			$dump .= "INSERT INTO `{$tableName}` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
		}
		$dump .= "\n";
	}

	$dump .= "SET FOREIGN_KEY_CHECKS = 1;\n";
	return $dump;
}

function escapeSqlValue($value) {
	if ($value === null) {
		return 'NULL';
	}
	$value = str_replace(array("\\", "\0", "\n", "\r", "\x1a", "'", '"'), array('\\\\', '\\0', '\\n', '\\r', '\\Z', "\\'", '\\"'), $value);
	return "'" . $value . "'";
}

function addDirectoryToZip(ZipArchive $zip, $directory, $zipPath) {
	$directory = rtrim($directory, '/\\');
	$baseLength = strlen($directory) + 1;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($iterator as $file) {
		$relativePath = substr($file->getPathname(), $baseLength);
		$localPath = $zipPath . '/' . str_replace('\\', '/', $relativePath);
		if ($file->isDir()) {
			$zip->addEmptyDir($localPath);
		} else {
			$zip->addFile($file->getPathname(), $localPath);
		}
	}
}
