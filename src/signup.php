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
// Purpose: Handles new user registration/signup.
//          Includes checks for username uniqueness and handles admin approval flow.

require_once(dirname(__FILE__) . "/includes/funcLib.php");
require_once(dirname(__FILE__) . "/includes/MySmarty.class.php");
$smarty = new MySmarty();
$opt = $smarty->opt(); // Get application options from Smarty instance

// Initialize variables to prevent undefined variable warnings
$username = '';
$fullname = '';
$email = '';
$familyid = NULL;
$error = NULL;
$success = NULL;

if (isset($_POST["action"]) && $_POST["action"] == "signup") {
	$username = $_POST["username"];
	$fullname = $_POST["fullname"];
	$email = $_POST["email"];
	$familyid = $_POST["familyid"];
		
	// If this is the first user ever, grant them admin rights and auto-approve them.
	$stmt = $smarty->dbh()->prepare("SELECT COUNT(*) AS usercount FROM users");
	$stmt->execute();
	$firstUser = ($stmt->fetchColumn() == 0);

	// make sure that username isn't taken.
	// --- Check for Username Uniqueness ---
	$stmt = $smarty->dbh()->prepare("SELECT userid FROM users WHERE username = ?");
	$stmt->bindParam(1, $username, PDO::PARAM_STR);
	$stmt->execute();
	if ($stmt->fetch()) { // If a row is returned, username exists
		$error = "The username '" . $username . "' is already taken.  Please choose another.";
	}
	else {
		// generate a password and insert the row.
		// NOTE: if approval is required, this password will be replaced
		// when the account is approved.
		[$pwd, $hash] = generatePassword($opt);
		// Generate a temporary password and its hash

		$approved = $firstUser || !$opt["newuser_requires_approval"];
		$admin = $firstUser ? 1 : 0;

		$stmt = $smarty->dbh()->prepare("INSERT INTO users(username,fullname,password,email,approved,admin,initialfamilyid) VALUES(?, ?, ?, ?, ?, ?, ?)");
		$stmt->bindParam(1, $username, PDO::PARAM_STR);
		$stmt->bindParam(2, $fullname, PDO::PARAM_STR);
		$stmt->bindParam(3, $hash, PDO::PARAM_STR);
		$stmt->bindParam(4, $email, PDO::PARAM_STR);
		$stmt->bindValue(5, $approved, PDO::PARAM_BOOL);
		$stmt->bindValue(6, $admin, PDO::PARAM_INT);
		$stmt->bindParam(7, $familyid, PDO::PARAM_INT);
		$stmt->execute();
			
		// --- Handle Approval Flow ---
		if ($firstUser) {
			// For the first user, show password on screen and redirect to login
			$success = "Welcome! Your account has been created with full administrative privileges.\n\n" .
					   "Username: $username\n" .
					   "Password: $pwd\n\n" .
					   "You can now log in. For security, please change your password after logging in.";
			$smarty->assign('success', $success);
			// Redirect to login after 5 seconds
			header("refresh:5;url=" . getFullPath("login.php"));
		}
		else if ($opt["newuser_requires_approval"]) {
			// send the e-mails to the administrators.
			$stmt = $smarty->dbh()->prepare("SELECT fullname, email FROM users WHERE admin = 1 AND email IS NOT NULL"); // Fetch admin emails
			$stmt->execute();
			while ($row = $stmt->fetch()) {
				$subject = "Gift Registry approval request for " . $fullname;
				$body = $fullname . " <" . $email . "> would like you to approve him/her for access to the Gift Registry.";
				if (!sendEmail($row["email"], $subject, $body, $opt)) {
					error_log("Failed to send approval request email to " . $row["email"]);
				}
			}
			// Note: Execution continues after die, should ideally exit.
		}
		else {
			// we don't require approval, 
			// so immediately send them their initial password.
			// also, join them up to their initial family (if requested).
			// --- Auto-Approve and Send Password ---
			if ($familyid != NULL) {
				$stmt = $smarty->dbh()->prepare("SELECT userid FROM users WHERE username = ?");
				$stmt->bindParam(1, $username, PDO::PARAM_STR);
				$stmt->execute();
				if ($row = $stmt->fetch()) {
					$userid = $row["userid"];
			
					$stmt = $smarty->dbh()->prepare("INSERT INTO memberships(userid,familyid) VALUES(?, ?)");
					$stmt->bindParam(1, $userid, PDO::PARAM_INT);
					$stmt->bindParam(2, $familyid, PDO::PARAM_INT);
					$stmt->execute();
				}

				mail(
					$email,
					"Gift Registry account created",
					"Your Gift Registry account was created.\r\n" . 
						"Your username is $username and your password is $pwd.",
					"From: {$opt["email_from"]}\r\nReply-To: {$opt["email_reply_to"]}\r\nX-Mailer: {$opt["email_xmailer"]}\r\n"
				) or die("Mail not accepted for $email");	
			}
			// Note: Execution continues after mail or die, should ideally exit.
		}
	}
}

// --- Fetch Families for Signup Form ---
$stmt = $smarty->dbh()->prepare("SELECT familyid, familyname FROM families ORDER BY familyname");
$stmt->execute();
$families = array();
while ($row = $stmt->fetch()) {
	$families[] = $row;
}

if (count($families) == 1) {
	// If only one family exists, pre-select it
	// default the family to the single family we have.
	$familyid = $families[0]["familyid"];
}
$smarty->assign('families', $families);
$smarty->assign('username', $username);
$smarty->assign('fullname', $fullname);
$smarty->assign('email', $email);
$smarty->assign('familyid', $familyid);
$smarty->assign('familycount', count($families));
if (isset($_POST["action"])) {
	$smarty->assign('action', $_POST["action"]);
}
// Assign data and potential error to Smarty template
if (isset($error)) {
	$smarty->assign('error', $error);
}
$smarty->display('signup.tpl'); // Display the signup template
?>
