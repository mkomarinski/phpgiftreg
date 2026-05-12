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
	set_time_limit(0);
	$tempBase = tempnam(sys_get_temp_dir(), "phpgiftreg_backup_");
	if ($tempBase === false) {
		$error = "Unable to create a temporary backup file.";
	} else {
		@unlink($tempBase);
		$archivePath = null;
		$archiveType = null;
		if (class_exists('ZipArchive')) {
			$archivePath = $tempBase . '.zip';
			$archiveType = 'zip';
			$error = createZipBackup($archivePath, $smarty->dbh(), $settingsFile, $imageDir);
		} else if (class_exists('PharData')) {
			$archivePath = $tempBase . '.tar';
			$archiveType = 'tar';
			$error = createTarBackup($archivePath, $smarty->dbh(), $settingsFile, $imageDir);
		} else {
			$error = "Neither ZipArchive nor PharData is available on this server. Backup requires one of those extensions.";
		}

		if (!$error && $archivePath && file_exists($archivePath)) {
			if ($archiveType === 'zip') {
				header('Content-Type: application/zip');
				header('Content-Disposition: attachment; filename="phpgiftreg-backup-' . date('Ymd_His') . '.zip"');
			} else {
				header('Content-Type: application/x-tar');
				header('Content-Disposition: attachment; filename="phpgiftreg-backup-' . date('Ymd_His') . '.tar"');
			}
			header('Content-Length: ' . filesize($archivePath));
			readfile($archivePath);
			unlink($archivePath);
			exit;
		}

		if ($archivePath && file_exists($archivePath)) {
			@unlink($archivePath);
		}
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

function createZipBackup($archivePath, PDO $dbh, $settingsFile, $imageDir) {
	$zip = new ZipArchive();
	if ($zip->open($archivePath, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
		return "Unable to create the backup archive.";
	}

	try {
		$zip->addFromString("database.sql", createDatabaseSqlDump($dbh));
	} catch (Exception $e) {
		$zip->close();
		return "Database backup failed: " . $e->getMessage();
	}

	if (file_exists($settingsFile)) {
		$zip->addFile($settingsFile, "config_settings.php");
	}

	if (is_dir($imageDir)) {
		addDirectoryToZip($zip, $imageDir, basename($imageDir));
	}

	$zip->close();
	return null;
}

function createTarBackup($archivePath, PDO $dbh, $settingsFile, $imageDir) {
	try {
		$tar = new PharData($archivePath);
		$tar->addFromString("database.sql", createDatabaseSqlDump($dbh));

		if (file_exists($settingsFile)) {
			$tar->addFile($settingsFile, "config_settings.php");
		}

		if (is_dir($imageDir)) {
			addDirectoryToPhar($tar, $imageDir, basename($imageDir));
		}

		return null;
	} catch (Exception $e) {
		return "Tar backup failed: " . $e->getMessage();
	}
}

function addDirectoryToPhar(PharData $phar, $directory, $pharPath) {
	$directory = rtrim($directory, '/\\');
	$baseLength = strlen($directory) + 1;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ($iterator as $file) {
		$relativePath = substr($file->getPathname(), $baseLength);
		$localPath = $pharPath . '/' . str_replace('\\', '/', $relativePath);
		if ($file->isDir()) {
			$phar->addEmptyDir($localPath);
		} else {
			$phar->addFile($file->getPathname(), $localPath);
		}
	}
}
