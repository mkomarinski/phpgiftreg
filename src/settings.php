<?php
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.

// Purpose: Admin settings page for runtime configuration values.

require_once(dirname(__FILE__) . "/includes/funcLib.php");
require_once(dirname(__FILE__) . "/includes/MySmarty.class.php");

session_start();
if (!isset($_SESSION["userid"])) {
	header("Location: " . getFullPath("login.php"));
	exit;
}
if ($_SESSION["admin"] != 1) {
	echo "You don't have admin privileges.";
	exit;
}

$smarty = new MySmarty();
$opt = $smarty->opt();

$schema = array(
	"event_threshold" => array("label" => "Event threshold (days)", "type" => "number", "min" => 1, "max" => 3650, "description" => "How many days before an event a reminder will appear."),
	"shop_requires_approval" => array("label" => "Require approval for purchase requests", "type" => "checkbox", "description" => "If set, shoppers must be approved before buying items."),
	"newuser_requires_approval" => array("label" => "Require approval for new users", "type" => "checkbox", "description" => "If set, new accounts must be approved by an administrator."),
	"anonymous_purchasing" => array("label" => "Hide purchaser identity", "type" => "checkbox", "description" => "If set, buyers are hidden from other users."),
	"items_per_page" => array("label" => "Items per page", "type" => "number", "min" => 1, "max" => 100, "description" => "How many items to display per page."),
	"email_from" => array("label" => "E-mail From address", "type" => "text", "required" => true, "description" => "The SMTP From address used for outgoing messages."),
	"email_reply_to" => array("label" => "E-mail Reply-To", "type" => "text", "required" => true, "description" => "Reply-To header for outgoing messages."),
	"email_xmailer" => array("label" => "E-mail X-Mailer header", "type" => "text", "description" => "The X-Mailer header sent with outgoing e-mail."),
	"oidc_enabled" => array("label" => "Enable OIDC login", "type" => "checkbox", "description" => "Allow users to sign in with OpenID Connect single sign-on."),
	"oidc_issuer" => array("label" => "OIDC issuer URL", "type" => "text", "description" => "The issuer URL from your OpenID Connect provider."),
	"oidc_client_id" => array("label" => "OIDC client ID", "type" => "text", "description" => "The client ID registered with your OIDC provider."),
	"oidc_client_secret" => array("label" => "OIDC client secret", "type" => "text", "description" => "The client secret for OIDC authentication."),
	"oidc_scopes" => array("label" => "OIDC scopes", "type" => "text", "description" => "Scopes to request from the OIDC provider, such as 'openid email profile'."),
	"oidc_auto_provision" => array("label" => "Auto-provision users", "type" => "checkbox", "description" => "Automatically create new local users when OIDC login succeeds."),
	"oidc_auto_approve" => array("label" => "Auto-approve new users", "type" => "checkbox", "description" => "Automatically approve accounts created via OIDC."),
	"oidc_prompt" => array("label" => "OIDC prompt", "type" => "text", "description" => "Optional prompt parameter for authentication requests, such as 'login'."),
	"show_helptext" => array("label" => "Show help text", "type" => "checkbox", "description" => "Display short help blurbs throughout the app."),
	"confirm_item_deletes" => array("label" => "Confirm item deletes", "type" => "checkbox", "description" => "Require JavaScript confirmation before deleting items."),
	"allow_multiples" => array("label" => "Allow multiple item quantities", "type" => "checkbox", "description" => "Permit setting quantities greater than one for items."),
	"currency_symbol" => array("label" => "Currency symbol", "type" => "text", "description" => "Prefix for monetary values."),
	"date_format" => array("label" => "Date format", "type" => "text", "description" => "PHP date() format string for all dates."),
	"show_own_events" => array("label" => "Show own events", "type" => "checkbox", "description" => "Display your own events on the home page."),
	"password_length" => array("label" => "Password length", "type" => "number", "min" => 8, "max" => 32, "description" => "Length of randomly generated passwords."),
	"hide_zero_price" => array("label" => "Hide zero prices", "type" => "checkbox", "description" => "Do not show prices when they are zero."),
	"allow_images" => array("label" => "Enable image uploads", "type" => "checkbox", "description" => "Allow item image uploads if the image directory is writable."),
	"image_subdir" => array("label" => "Image subdirectory", "type" => "text", "description" => "Relative directory for uploaded item images."),
	"notify_threshold_minutes" => array("label" => "Notification throttling (minutes)", "type" => "number", "min" => 1, "max" => 1440, "description" => "Minimum minutes between subscription notifications."),
);

$success = null;
$error = null;

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "save_settings") {
	$newValues = array();
	foreach ($schema as $key => $meta) {
		if ($meta["type"] === "checkbox") {
			$newValues[$key] = isset($_POST[$key]) ? 1 : 0;
		}
		else if ($meta["type"] === "number") {
			$value = isset($_POST[$key]) ? trim($_POST[$key]) : "";
			if ($value === "") {
				$error = "The value for {$meta['label']} is required.";
				break;
			}
			$value = filter_var($value, FILTER_VALIDATE_INT);
			if ($value === false) {
				$error = "{$meta['label']} must be an integer.";
				break;
			}
			if (isset($meta["min"]) && $value < $meta["min"]) {
				$error = "{$meta['label']} must be at least {$meta['min']} ";
				break;
			}
			if (isset($meta["max"]) && $value > $meta["max"]) {
				$error = "{$meta['label']} must be at most {$meta['max']} ";
				break;
			}
			$newValues[$key] = $value;
		}
		else {
			$value = isset($_POST[$key]) ? trim($_POST[$key]) : "";
			if (!empty($value) || empty($meta["required"])) {
				$newValues[$key] = $value;
			} else {
				$error = "{$meta['label']} is required.";
				break;
			}
		}
	}

	if (!$error) {
		saveConfigValues($newValues);
		$success = "Settings were saved successfully.";
		$opt = $smarty->opt(true);
	}
}

$currentValues = array();
foreach ($schema as $key => $meta) {
	$currentValues[$key] = isset($opt[$key]) ? $opt[$key] : null;
}

$smarty->assign('settings', $currentValues);
$smarty->assign('schema', $schema);
$smarty->assign('success', $success);
$smarty->assign('error', $error);
$smarty->assign('action', 'settings');
$smarty->display('settings.tpl');
