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

$lifetime = 86400; // 24 hours
session_set_cookie_params($lifetime);
session_start();
$action = empty($_GET["action"]) ? "" : $_GET["action"];

if ($action == "logout") {
	session_destroy();
	header("Location: " . getFullPath("login.php"));
	exit;
}

if ($action == "oidc_login") {
	if (empty($opt["oidc_enabled"])) {
		die("OIDC login is not enabled.");
	}
	$issuer = rtrim($opt["oidc_issuer"], '/');
	$config = oidcGetConfiguration($issuer);
	if (empty($config["authorization_endpoint"])) {
		die("OIDC provider did not provide an authorization endpoint.");
	}
	$state = bin2hex(random_bytes(16));
	$nonce = bin2hex(random_bytes(16));
	$_SESSION["oidc_state"] = $state;
	$_SESSION["oidc_nonce"] = $nonce;
	$redirectUri = getFullPath("login.php?action=oidc_callback");
	$params = array(
		"client_id" => $opt["oidc_client_id"],
		"response_type" => "code",
		"scope" => $opt["oidc_scopes"],
		"redirect_uri" => $redirectUri,
		"state" => $state,
		"nonce" => $nonce
	);
	if (!empty($opt["oidc_prompt"])) {
		$params["prompt"] = $opt["oidc_prompt"];
	}
	header("Location: " . $config["authorization_endpoint"] . "?" . http_build_query($params));
	exit;
}

if ($action == "oidc_callback") {
	if (empty($opt["oidc_enabled"])) {
		die("OIDC login is not enabled.");
	}
	$error = null;
	if (empty($_GET["state"]) || $_GET["state"] !== ($_SESSION["oidc_state"] ?? '')) {
		$error = "Invalid OIDC state.";
	}
	elseif (!empty($_GET["error"])) {
		$error = "OIDC login error: " . htmlspecialchars($_GET["error_description"] ?? $_GET["error"]);
	}
	elseif (empty($_GET["code"])) {
		$error = "Missing authorization code from OIDC provider.";
	}
	else {
		$issuer = rtrim($opt["oidc_issuer"], '/');
		$config = oidcGetConfiguration($issuer);
		$redirectUri = getFullPath("login.php?action=oidc_callback");
		$postFields = array(
			"grant_type" => "authorization_code",
			"code" => $_GET["code"],
			"redirect_uri" => $redirectUri,
			"client_id" => $opt["oidc_client_id"],
		);
		$headers = array();
		if (!empty($opt["oidc_client_secret"])) {
			$headers[] = "Authorization: Basic " . base64_encode($opt["oidc_client_id"] . ":" . $opt["oidc_client_secret"]);
			$postFields = array(
				"grant_type" => "authorization_code",
				"code" => $_GET["code"],
				"redirect_uri" => $redirectUri,
				"client_id" => $opt["oidc_client_id"],
			);
		}
		try {
			$tokenResponse = oidcFetchJson($config["token_endpoint"], $postFields, $headers);
			if (empty($tokenResponse["id_token"])) {
				$error = "OIDC provider did not return an ID token.";
			}
			else {
				$claims = oidcValidateIdToken($tokenResponse["id_token"], $opt, $_SESSION["oidc_nonce"] ?? null, $config);
				$user = oidcFindOrProvisionUser($claims, $smarty->dbh(), $opt);
				if (!$user) {
					$error = "No matching user account was found for your SSO identity.";
				}
				elseif ($user["approved"] != 1) {
					$error = "Your account is not approved yet.";
				}
				else {
					session_regenerate_id();
					$_SESSION["userid"] = $user["userid"];
					$_SESSION["fullname"] = $user["fullname"];
					$_SESSION["admin"] = $user["admin"];
					header("Location: " . getFullPath("index.php"));
					exit;
				}
			}
		}
		catch (Exception $e) {
			$error = "OIDC login failed: " . $e->getMessage();
		}
	}
	$smarty->assign('login_error', $error);
	$smarty->display('login.tpl');
	exit;
}

// --- Handle Login Attempt (POST) ---
if (!empty($_POST["username"])) {
	$username = $_POST["username"];
	// Note: Password is read directly from $_POST, which is okay before hashing, but handle with care.
	$password = $_POST["password"];
	try {
		// Query to find user by username and password hash, and check if approved
		$stmt = $smarty->dbh()->prepare("SELECT userid, fullname, admin, password FROM users WHERE username = ? AND approved = 1");
		$stmt->bindParam(1, $username, PDO::PARAM_STR); // Bind username

		$stmt->execute();
		if ($row = $stmt->fetch()) {
			if (password_verify($password,$row["password"])) {
				if ($row["admin"] != 1) {
					$stmt2 = $smarty->dbh()->prepare("SELECT COUNT(*) FROM users WHERE admin = 1");
					$stmt2->execute();
					if ($stmt2->fetchColumn() == 0) {
						$stmt3 = $smarty->dbh()->prepare("UPDATE users SET admin = 1 WHERE userid = ?");
						$stmt3->bindParam(1, $row["userid"], PDO::PARAM_INT);
						$stmt3->execute();
						$row["admin"] = 1;
					}
				}

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
	$smarty->assign('login_error', 'Bad login.');
	$smarty->display('login.tpl');
}
else {
	$smarty->display('login.tpl'); // Display the empty login form initially
}
?>
