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

require_once(dirname(__FILE__) . "/includes/funcLib.php");
require_once(dirname(__FILE__) . "/includes/MySmarty.class.php");
$smarty = new MySmarty();
$opt = $smarty->opt();

if (isset($_GET["action"]) && $_GET["action"] == "logout") {
    session_start();
    session_destroy();
    header("Location: " . getFullPath("login.php")); //Redirect to login page after logout.
    exit;
}

if (!empty($_POST["username"])) {
	$username = $_POST["username"];
	$password = $_POST["password"];
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
		case "":
			$hash = $password;
			break;
	}

	try {
		$stmt = $smarty->dbh()->prepare("SELECT userid, fullname, admin FROM {$opt["table_prefix"]}users WHERE username = ? AND password = ? AND approved = 1");
		$stmt->bindParam(1, $username, PDO::PARAM_STR);
		$stmt->bindParam(2, $hash, PDO::PARAM_STR);

		$stmt->execute();
		if ($row = $stmt->fetch()) {
			$lifetime = 86400; // 24 hours
			session_set_cookie_params($lifetime);
			session_start();
			session_regenerate_id();
			$_SESSION["userid"] = $row["userid"];
			$_SESSION["fullname"] = $row["fullname"];
			$_SESSION["admin"] = $row["admin"];
		
			header("Location: " . getFullPath("index.php"));
			exit;
		}
	}
	catch (PDOException $e) {
		die("sql exception: " . $e->getMessage());
	}

	$smarty->assign('username', $username);
	$smarty->display('login.tpl');
}
else {
	$smarty->display('login.tpl');
}
?>
