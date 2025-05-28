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
// Purpose: Allows a user to mark items on their *own* list as received.
//          This removes the item and any associated allocations.

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
	$userid = $_SESSION["userid"]; // Get the logged-in user's ID
}

$action = (!empty($_GET["action"]) ? $_GET["action"] : "");
$itemid = (int) $_GET["itemid"];

// get details. is it our item? is this a single-quantity item?
// --- Check Item Ownership and Fetch Quantity ---
try {
	$stmt = $smarty->dbh()->prepare("SELECT userid, quantity FROM {$opt["table_prefix"]}items WHERE itemid = ?"); // Fetch item details
	$stmt->bindParam(1, $itemid, PDO::PARAM_INT);
	$stmt->execute();
	if ($row = $stmt->fetch()) {
		if ($row["userid"] != $userid)
			die("That's not your item!");

		$quantity = $row["quantity"];
	}
	else {
		die("Item does not exist.");
	}

	stampUser($userid, $smarty->dbh(), $smarty->opt()); // Update the user's list timestamp

	// --- Handle Single Quantity Item ---
	if ($quantity == 1) {
		/* just delete the alloc and the item and get out.
			yes, it's possible the item was RESERVED, not PURCHASED. */
		deleteImageForItem($itemid, $smarty->dbh(), $smarty->opt()); // Delete associated image file

		// Delete any allocations for this item
		$stmt = $smarty->dbh()->prepare("DELETE FROM {$opt["table_prefix"]}allocs WHERE itemid = ?");
		$stmt->bindParam(1, $itemid, PDO::PARAM_INT);
		$stmt->execute();

		$stmt = $smarty->dbh()->prepare("DELETE FROM {$opt["table_prefix"]}items WHERE itemid = ?");
		$stmt->bindParam(1, $itemid, PDO::PARAM_INT);
		$stmt->execute();

		header("Location: " . getFullPath("index.php?message=Item+marked+as+received."));
		exit;
		// Note: Execution continues after exit, should be unreachable.
	}
	// --- Handle Multi-Quantity Item (Receive Specific Quantity) ---
	else if ($action == "receive") {
		// $actual will be a negative number, so let's flip it.
		// Adjust allocated quantity (bought first)
		$actual = -adjustAllocQuantity($itemid, (int) $_GET["buyer"], 1, -1 * (int) $_GET["quantity"], $smarty->dbh(), $smarty->opt());
	
		if ($actual < (int) $_GET["quantity"]) {
			// If not enough were bought, adjust reserved quantity
			$actual += -adjustAllocQuantity($itemid,(int) $_GET["buyer"],0,-1 * ((int) $_GET["quantity"] - $actual), $smarty->dbh(), $smarty->opt());
		}
	
		if ($actual == $quantity) {
			// now they're all gone.
			deleteImageForItem($itemid, $smarty->dbh(), $smarty->opt());
			$stmt = $smarty->dbh()->prepare("DELETE FROM {$opt["table_prefix"]}items WHERE itemid = ?");
			$stmt->bindParam(1, $itemid, PDO::PARAM_INT);
			$stmt->execute();
		}
		else { // If some quantity remains
			// decrement the item's desired quantity.
			$stmt = $smarty->dbh()->prepare("UPDATE {$opt["table_prefix"]}items SET quantity = quantity - ? WHERE itemid = ?");
			$stmt->bindParam(1, $actual, PDO::PARAM_INT);
			$stmt->bindParam(2, $itemid, PDO::PARAM_INT);
			$stmt->execute();
		}
	
		header("Location: " . getFullPath("index.php?message=Item+marked+as+received."));
		exit;
		// Note: Execution continues after exit, should be unreachable.
	}

	// --- Fetch Potential Buyers for Multi-Quantity Items Display ---
	$stmt = $smarty->dbh()->prepare("SELECT u.userid, u.fullname " .
			"FROM {$opt["table_prefix"]}shoppers s " . // Find users who can shop for the current user
			"INNER JOIN {$opt["table_prefix"]}users u ON u.userid = s.shopper " .
			"WHERE s.mayshopfor = ? " .
				"AND pending = 0 " .
			"ORDER BY u.fullname");
	$stmt->bindParam(1, $userid, PDO::PARAM_INT);
	$stmt->execute();
	$buyers = array();
	while ($row = $stmt->fetch()) {
		$buyers[] = $row;
	}

	// Assign data to Smarty template
	$smarty->assign('buyers', $buyers);
	$smarty->assign('quantity', $quantity);
	$smarty->assign('itemid', $itemid);
	$smarty->assign('userid', $userid);
	$smarty->display('receive.tpl');
}
catch (PDOException $e) { // Handle database errors
	die("sql exception: " . $e->getMessage());
	// Note: Execution continues after die, should ideally exit.
}
?>
