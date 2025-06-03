<?php
// This program is free software; you can redistribute it and/or modify
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
// Purpose: Allows logged-in users to view and update their profile information
//          (full name, email, comment, email preferences) and change their password.

require_once(dirname(__FILE__) . "/includes/funcLib.php");
require_once(dirname(__FILE__) . "/includes/MySmarty.class.php");
$smarty = new MySmarty();
$opt = $smarty->opt(); // Get application options from Smarty instance

session_start();
if (!isset($_SESSION["userid"])) {
	header("Location: " . getFullPath("login.php"));
	exit;
}
else {
	$userid = $_SESSION["userid"]; // Get the logged-in user's ID from the session
}

$action = "";
if (!empty($_POST["action"])) {
	$action = $_POST["action"]; // Get the requested action from POST data

	// --- Handle Change Password Action ---
	if ($action == "changepwd") {
		$password = $_POST["newpwd"];
		switch ($opt["password_hasher"]) {
			case "MD5":
				$hash = md5($password);
				break;
			case "SHA1":
				$hash = sha1($password);
				break;
			case "BCRYPT":
				$hash = password_hash($password, PASSWORD_BCRYPT);
				break;
			case "": // Plain text (highly insecure!)
				$hash = $password;
				break;
		}

		try {
			$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}users SET password = ? WHERE userid = ?");
			// Bind the generated hash and the user ID to the prepared statement
			$stmt->bindParam(1, $hash, PDO::PARAM_STR);
			$stmt->bindParam(2, $userid, PDO::PARAM_INT);

			$stmt->execute();

			header("Location: " . getFullPath("index.php?message=Password+changed."));
			exit;
		}
		catch (PDOException $e) {
			// Handle database errors
			die("sql exception: " . $e->getMessage());
		}
	}
	// --- Handle Save Profile Action ---
	else if ($action == "save") {
		// Get profile data from POST
		$fullname = $_POST["fullname"];
		$email = $_POST["email"];
		$comment = $_POST["comment"];
		$email_msgs = (isset($_POST["email_msgs"]) && $_POST["email_msgs"] == "on" ? 1 : 0); // Checkbox value handling

		try {
			$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}users SET fullname = ?, email = ?, email_msgs = ?, comment = ? WHERE userid = ?");
			// Bind the updated profile data and user ID
			$stmt->bindParam(1, $fullname, PDO::PARAM_STR);
			$stmt->bindParam(2, $email, PDO::PARAM_STR);
			$stmt->bindParam(3, $email_msgs, PDO::PARAM_BOOL);
			$stmt->bindParam(4, $comment, PDO::PARAM_STR);
			$stmt->bindParam(5, $userid, PDO::PARAM_INT);
		
			$stmt->execute();

			// Update the session variable for the user's full name
			$_SESSION["fullname"] = $fullname;

			header("Location: " . getFullPath("index.php?message=Profile+updated."));
			exit;
		}
		catch (PDOException $e) {
			die("sql exception: " . $e->getMessage());
			// Handle database errors
		}
	}
	else {
		// Handle unknown actions
		die("Unknown verb.");
	}
}

// --- Fetch User Data for Display ---
try {
	$stmt = $smarty->dbh()->prepare("SELECT fullname, email, email_msgs, comment FROM {$opt["table_prefix"]}users WHERE userid = ?");
	$stmt->bindParam(1, $userid, PDO::PARAM_INT); // Bind the logged-in user's ID

	$stmt->execute();
	if ($row = $stmt->fetch()) {
		$smarty->assign('fullname', $row["fullname"]);
		$smarty->assign('email', $row["email"]);
		$smarty->assign('email_msgs', $row["email_msgs"]);
		$smarty->assign('comment', $row["comment"]);
		$smarty->display('profile.tpl');
	}
	else { // Should not happen if session check passed, but good practice
		die("You don't exist.");
	}
}
catch (PDOException $e) {
	die("sql exception: " . $e->getMessage());
	// Handle database errors during fetch
}
?>
