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
// Purpose: Handles administrative actions related to user approval/rejection.
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
else {
	$userid = $_SESSION["userid"]; // Get the logged-in admin's ID
}

$action = $_GET["action"]; // Get the requested action from GET data

// Note: Using GET for actions that modify data (approve, reject) is insecure.
// These actions should ideally use POST requests.

// --- Handle Approve User Action ---
if ($action == "approve") {
	[$pwd, $hash] = generatePassword($opt); // Generate a temporary password for the user
	if ($_GET["familyid"] != "") {
		$stmt = $smarty->dbh()->prepare("INSERT INTO {$opt["table_prefix"]}memberships(userid,familyid) VALUES(?, ?)"); // Add user to the initial family
		$stmt->bindValue(1, (int) $_GET["userid"], PDO::PARAM_INT);
		$stmt->bindValue(2, (int) $_GET["familyid"], PDO::PARAM_INT);
		$stmt->execute();
	}
	$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}users SET approved = 1, password = ? WHERE userid = ?");
	$stmt->bindParam(1, $hash, PDO::PARAM_SRT);
	$stmt->bindValue(2, (int) $_GET["userid"], PDO::PARAM_INT);
	$stmt->execute();
	
	// send the e-mails
	$stmt = $smarty->dbh()->prepare("SELECT username, email FROM {$opt["table_prefix"]}users WHERE userid = ?"); // Fetch user details for email
	$stmt->bindValue(1, (int) $_GET["userid"], PDO::PARAM_INT);
	$stmt->execute();
	if ($row = $stmt->fetch()) {
		mail(
			$row["email"],
			"Gift Registry application approved",
			"Your Gift Registry application was approved by " . $_SESSION["fullname"] . ".\r\n" . 
				"Your username is " . $row["username"] . " and your password is $pwd.",
			"From: {$opt["email_from"]}\r\nReply-To: {$opt["email_reply_to"]}\r\nX-Mailer: {$opt["email_xmailer"]}\r\n"
		) or die("Mail not accepted for " . $row["email"]);	
	}
	header("Location: " . getFullPath("index.php"));
	exit; // Redirect after action
}
// --- Handle Reject User Action ---
else if ($action == "reject") {
	// send the e-mails to the rejected user
	$stmt = $smarty->dbh()->prepare("SELECT email FROM {$opt["table_prefix"]}users WHERE userid = ?");
	$stmt->bindValue(1, (int) $_GET["userid"], PDO::PARAM_INT);
	$stmt->execute();
	if ($row = $stmt->fetch()) {
		mail(
			$row["email"],
			"Gift Registry application denied",
			"Your Gift Registry application was denied by " . $_SESSION["fullname"] . ".",
			"From: {$opt["email_from"]}\r\nReply-To: {$opt["email_reply_to"]}\r\nX-Mailer: {$opt["email_xmailer"]}\r\n"
		) or die("Mail not accepted for " . $row["email"]);	
	}

	$stmt = $smarty->dbh()->prepare("DELETE FROM {$opt["table_prefix"]}users WHERE userid = ?"); // Delete the user record
	$stmt->bindValue(1, (int) $_GET["userid"], PDO::PARAM_INT);
	$stmt->execute();
	
	header("Location: " . getFullPath("index.php"));
	exit; // Redirect after action
}
?>
