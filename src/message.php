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
// Purpose: Allows a logged-in user to send messages to other users they are
//          approved to shop for.

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

$action = empty($_GET["action"]) ? "" : $_GET["action"];

// --- Handle Send Message Action ---
if ($action == "send") {
	// Note: This action uses GET for message content and recipients.
	// This is insecure and has limitations on message length. Should use POST.
	$msg = $_GET["msg"];

	// Loop through selected recipients and send the message
	for ($i = 0; $i < count($_GET["recipients"]); $i++)
		sendMessage($userid, (int) $_GET["recipients"][$i], $msg, $smarty->dbh(), $smarty->opt()); // Call helper function to send message
		
	header("Location: " . getFullPath("index.php?message=Your+message+has+been+sent+to+" . count($_GET["recipients"]) . "+recipient(s)."));
	exit;
}

try {
	$stmt = $smarty->dbh()->prepare("SELECT u.userid, u.fullname " .
			"FROM {$opt["table_prefix"]}shoppers s " .
			"INNER JOIN {$opt["table_prefix"]}users u ON u.userid = s.mayshopfor " .
			"WHERE s.shopper = ? " .
				"AND pending = 0 " .
			"ORDER BY u.fullname"); // Order by recipient's full name
	$stmt->bindParam(1, $userid, PDO::PARAM_INT); // Bind the current user's ID (the shopper)
	$stmt->execute();
	$recipients = array();
	// Fetch all potential recipients into an array
	$rcount = 0;
	while ($row = $stmt->fetch()) {
		$recipients[] = $row;
		++$rcount;
	}

	$smarty->assign('recipients', $recipients);
	$smarty->assign('rcount', $rcount); // Number of recipients
	$smarty->assign('userid', $userid); // Current user's ID
	$smarty->display('message.tpl'); // Display the message template
}
catch (PDOException $e) {
	die("sql exception: " . $e->getMessage());
	// Handle database errors during fetch
}
?>
