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
// Purpose: Admin page for managing item rankings/priorities.
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
// Note: Using GET for actions that modify data (delete, promote, demote, insert, update) is insecure.
// These actions should ideally use POST requests.

// --- Data Validation for Insert/Update Actions ---
if ($action == "insert" || $action == "update") {
	/* validate the data. */
	$title = trim($_GET["title"]); // Get rank title from GET
	$rendered = trim($_GET["rendered"]); // Get rendered HTML from GET
		
	$haserror = false;
	if ($title == "") {
		$haserror = true;
		$title_error = "A title is required.";
	}
	if ($rendered == "") {
		$haserror = true;
		$rendered_error = "HTML is required.";
	}
}

// --- Handle Delete Rank Action ---
if ($action == "delete") {
	/* first, NULL all ranking FKs for items that use this rank. */
	$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}items SET ranking = NULL WHERE ranking = ?");
	// Unlink items from the rank being deleted
	$stmt->bindValue(1, (int) $_GET["ranking"], PDO::PARAM_INT);
	$stmt->execute();

	$stmt = $smarty->dbh()->prepare("DELETE FROM {$opt["table_prefix"]}ranks WHERE ranking = ?");
	$stmt->bindValue(1, (int) $_GET["ranking"], PDO::PARAM_INT);
	$stmt->execute();
	
	header("Location: " . getFullPath("ranks.php?message=Rank+deleted."));
	exit;
}
// --- Handle Promote Rank Action ---
else if ($action == "promote") {
	// Increment rankorder of the rank *below* the target rank
	$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}ranks SET rankorder = rankorder + 1 WHERE rankorder = ? - 1");
	$stmt->bindValue(1, (int) $_GET["rankorder"], PDO::PARAM_INT);
	$stmt->execute();
	// Decrement rankorder of the target rank
	$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}ranks SET rankorder = rankorder - 1 WHERE ranking = ?");
	$stmt->bindValue(1, (int) $_GET["ranking"], PDO::PARAM_INT);
	$stmt->execute();

	header("Location: " . getFullPath("ranks.php?message=Rank+promoted."));
	exit;
}
// --- Handle Demote Rank Action ---
else if ($action == "demote") {
	// Decrement rankorder of the rank *above* the target rank
	$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}ranks SET rankorder = rankorder - 1 WHERE rankorder = ? + 1");
	$stmt->bindValue(1, (int) $_GET["rankorder"], PDO::PARAM_INT);
	$stmt->execute();
    // Increment rankorder of the target rank
    $stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}ranks SET rankorder = rankorder + 1 WHERE ranking = ?");
	$stmt->bindValue(1, (int) $_GET["ranking"], PDO::PARAM_INT);
	$stmt->execute();
    
	header("Location: " . getFullPath("ranks.php?message=Rank+demoted."));
    exit;
}
// --- Handle Edit Rank Action (Fetch Data) ---
else if ($action == "edit") {
	$stmt = $smarty->dbh()->prepare("SELECT title, rendered FROM {$opt["table_prefix"]}ranks WHERE ranking = ?");
	$stmt->bindValue(1, (int) $_GET["ranking"], PDO::PARAM_INT);
	$stmt->execute();
	// Fetch rank details for editing
	if ($row = $stmt->fetch()) {
		$title = $row["title"];
		$rendered = $row["rendered"];
	}
}
else if ($action == "") {
	$title = "";
	$rendered = "";
}
// --- Handle Insert Rank Action ---
else if ($action == "insert") {
	if (!$haserror) {
		/* we can't assume the DB has a sequence on this so determine the highest rankorder and add one. */
		$stmt = $smarty->dbh()->prepare("SELECT MAX(rankorder) as maxrankorder FROM {$opt["table_prefix"]}ranks");
		$stmt->execute();
		if ($row = $stmt->fetch()) {
			$rankorder = $row["maxrankorder"] + 1;
			$stmt = $smarty->dbh()->prepare("INSERT INTO {$opt["table_prefix"]}ranks(title,rendered,rankorder) VALUES(?, ?, ?)");
			$stmt->bindParam(1, $title, PDO::PARAM_STR);
			$stmt->bindParam(2, $rendered, PDO::PARAM_STR);
			$stmt->bindParam(3, $rankorder, PDO::PARAM_INT);
			$stmt->execute();
			
			header("Location: " . getFullPath("ranks.php?message=Rank+added."));
			exit;
			// Note: Execution continues after exit, should be unreachable.
		}
	}
	// Note: Execution continues if $haserror is true, displaying the form with errors.
}
else if ($action == "update") {
	if (!$haserror) {
		$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}ranks " .
					"SET title = ?, rendered = ? " .
					"WHERE ranking = ?");
		$stmt->bindParam(1, $title, PDO::PARAM_STR);
		$stmt->bindParam(2, $rendered, PDO::PARAM_STR);
		$stmt->bindValue(3, (int) $_GET["ranking"], PDO::PARAM_INT);
		$stmt->execute();
		
		header("Location: " . getFullPath("ranks.php?message=Rank+updated."));
		exit;		
		// Note: Execution continues after exit, should be unreachable.
	}
}
// --- Handle Unknown Action ---
else {
	die("Unknown verb.");
	// Note: Execution continues after die, should ideally exit.
}

$stmt = $smarty->dbh()->prepare("SELECT ranking, title, rendered, rankorder " .
			"FROM {$opt["table_prefix"]}ranks " .
			"ORDER BY rankorder");
$stmt->execute();
$ranks = array();
// Fetch all ranks for display
while ($row = $stmt->fetch()) {
	$ranks[] = $row;
}

$smarty->assign('action', $action);
// Assign data to Smarty template
$smarty->assign('ranks', $ranks);
if (isset($message)) {
	$smarty->assign('message', $message);
}
$smarty->assign('title', $title);
if (isset($title_error)) {
	$smarty->assign('title_error', $title_error);
}
$smarty->assign('rendered', $rendered);
if (isset($rendered_error)) {
	$smarty->assign('rendered_error', $rendered_error);
}
$smarty->assign('ranking', isset($_GET["ranking"]) ? (int) $_GET["ranking"] : "");
$smarty->assign('haserror', isset($haserror) ? $haserror : false); // Assign error flag
$smarty->display('ranks.tpl'); // Display the ranks template
?>
