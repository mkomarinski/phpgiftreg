<?php
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.

// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.

// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
//
// Purpose: Admin page for managing item categories.
//          Requires admin privileges.

require_once(dirname(__FILE__) . "/includes/funcLib.php");
require_once(dirname(__FILE__) . "/includes/MySmarty.class.php");
$smarty = new MySmarty();
$opt = $smarty->opt(); // Get application options from Smarty instance

session_start();
if (!isset($_SESSION["userid"])) {
	header("Location: " . getFullPath("login.php"));
	exit;
}
else if ($_SESSION["admin"] != 1) {
	// Check if the logged-in user is an administrator
	echo "You don't have admin privileges.";
	exit;
}
else { // User is admin
	$userid = $_SESSION["userid"];
}
if (!empty($_GET["message"])) {
    $message = $_GET["message"];
}

$action = isset($_GET["action"]) ? $_GET["action"] : "";
// Note: Using GET for actions that modify data (delete, insert, update) is insecure.
// These actions should ideally use POST requests.

// --- Data Validation for Insert/Update Actions ---
if ($action == "insert" || $action == "update") {
	/* validate the data. */
	$category = trim($_GET["category"]); // Get category name from GET
		
	$haserror = false;
	if ($category == "") {
		$haserror = true;
		$category_error = "A category is required.";
	}
}

// --- Handle Delete Category Action ---
if ($action == "delete") {
	/* first, NULL all category FKs for items that use this category. */
	$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}items SET category = NULL WHERE category = ?");
	// Unlink items from the category being deleted
	$stmt->bindValue(1, (int) $_GET["categoryid"], PDO::PARAM_INT);
	$stmt->execute();

	$stmt = $smarty->dbh()->prepare("DELETE FROM {$opt["table_prefix"]}categories WHERE categoryid = ?");
	$stmt->bindValue(1, (int) $_GET["categoryid"], PDO::PARAM_INT);
	$stmt->execute();
	
	header("Location: " . getFullPath("categories.php?message=Category+deleted."));
	exit;
}
// --- Handle Edit Category Action (Fetch Data) ---
else if ($action == "edit") {
	$stmt = $smarty->dbh()->prepare("SELECT category FROM {$opt["table_prefix"]}categories WHERE categoryid = ?");
	$stmt->bindValue(1, (int) $_GET["categoryid"], PDO::PARAM_INT);
	$stmt->execute();
	// Fetch category name for editing
	if ($row = $stmt->fetch()) {
		$category = $row["category"];
	}
}
else if ($action == "") {
	$category = "";
}
// --- Handle Insert Category Action ---
else if ($action == "insert") {
	if (!$haserror) {
		$stmt = $smarty->dbh()->prepare("INSERT INTO {$opt["table_prefix"]}categories(categoryid,category) VALUES(NULL, ?)");
		$stmt->bindParam(1, $category, PDO::PARAM_STR);
		$stmt->execute();
		
		header("Location: " . getFullPath("categories.php?message=Category+added."));
		exit;
		// Note: Execution continues after exit, should be unreachable.
	}
}
// --- Handle Update Category Action ---
else if ($action == "update") {
	if (!$haserror) {
		$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}categories " .
					"SET category = ? " .
					"WHERE categoryid = ?");
		$stmt->bindParam(1, $category, PDO::PARAM_STR);
		$stmt->bindValue(2, (int) $_GET["categoryid"], PDO::PARAM_INT);
		$stmt->execute();
		
		header("Location: " . getFullPath("categories.php?message=Category+updated."));
		exit;		
		// Note: Execution continues after exit, should be unreachable.
	}
}
// --- Handle Unknown Action ---
else {
	die("Unknown verb.");
	// Note: Execution continues after die, should ideally exit.
}

$stmt = $smarty->dbh()->prepare("SELECT c.categoryid, c.category, COUNT(itemid) AS itemsin " .
			"FROM {$opt["table_prefix"]}categories c " .
			"LEFT OUTER JOIN {$opt["table_prefix"]}items i ON i.category = c.categoryid " .
			"GROUP BY c.categoryid, category " .
			"ORDER BY category");
// Fetch all categories and count items in each
$stmt->execute();
$categories = array();
while ($row = $stmt->fetch()) {
	$categories[] = $row;
}

// Assign data to Smarty template
if (isset($action)) {
	$smarty->assign('action', $action);
}
$smarty->assign('categories', $categories);
if (isset($_GET["categoryid"])) {
	$smarty->assign('categoryid', (int) $_GET["categoryid"]);
}
if (isset($message)) {
	$smarty->assign('message', $message);
}
$smarty->assign('category', $category);
if (isset($category_error)) {
	$smarty->assign('category_error', $category_error);
}
$smarty->assign('haserror', isset($haserror) ? $haserror : false);
$smarty->display('categories.tpl'); // Display the categories template
?>
