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
// Purpose: Handles user login and logout using traditional username/password.

require_once(dirname(__FILE__) . "/includes/funcLib.php");
require_once(dirname(__FILE__) . "/includes/MySmarty.class.php");
$smarty = new MySmarty();
$opt = $smarty->opt(); // Get application options from Smarty instance

if (isset($_GET["action"]) && $_GET["action"] == "logout") {
    session_start();
    session_destroy();
    header("Location: " . getFullPath("login.php")); //Redirect to login page after logout.
    exit;
}

// --- Handle Login Attempt (POST) ---
if (!empty($_POST["username"])) {
	$username = $_POST["username"];
	// Note: Password is read directly from $_POST, which is okay before hashing, but handle with care.
	$password = $_POST["password"];
	try {
		// Query to find user by username and password hash, and check if approved
		$stmt = $smarty->dbh()->prepare("SELECT userid, fullname, admin, password FROM {$opt["table_prefix"]}users WHERE username = ? AND approved = 1");
		$stmt->bindParam(1, $username, PDO::PARAM_STR); // Bind username

		$stmt->execute();
		if ($row = $stmt->fetch()) {
			if (password_verify($password,$row["password"])) {
			$lifetime = 86400; // 24 hours
			session_set_cookie_params($lifetime);
			session_start();
			// Regenerate session ID to prevent session fixation attacks
			session_regenerate_id();
			$_SESSION["userid"] = $row["userid"];
			$_SESSION["fullname"] = $row["fullname"];
			$_SESSION["admin"] = $row["admin"];
		
			header("Location: " . getFullPath("index.php"));
			exit;
			// Note: Execution continues after exit, should be unreachable.
                        }
		}
	}
	catch (PDOException $e) {
		die("sql exception: " . $e->getMessage());
		// Handle database errors during login
	}

	// If login failed, re-display the login form with the entered username
	$smarty->assign('username', $username);
	$smarty->display('login.tpl');
}
else {
	$smarty->display('login.tpl'); // Display the empty login form initially
}
?>
