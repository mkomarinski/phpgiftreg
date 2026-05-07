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

function loadEnvFile($path) {
	if (!file_exists($path) || !is_readable($path)) {
		return;
	}

	$lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		if (strpos($line, '=') === false) {
			continue;
		}

		list($name, $value) = explode('=', $line, 2);
		$name = trim($name);
		$value = trim($value);
		$value = trim($value, " \t\n\r\0\x0B\"");
		if ($name === '') {
			continue;
		}

		if (getenv($name) === false) {
			putenv("$name=$value");
		}
		$_ENV[$name] = $value;
		$_SERVER[$name] = $value;
	}
}

if (!function_exists('getEnvOrDefault')) {
	function getEnvOrDefault($name, $default = null) {
		$value = getenv($name);
		return $value !== false ? $value : $default;
	}
}

function getConfigValues() {
	static $configValues;
	if (!isset($configValues)) {
		$file = dirname(__FILE__) . "/config_settings.php";
		if (file_exists($file)) {
			$loaded = include $file;
			$configValues = is_array($loaded) ? $loaded : array();
		} else {
			$configValues = array();
		}
	}
	return $configValues;
}

function saveConfigValues($values) {
	$file = dirname(__FILE__) . "/config_settings.php";
	$allowed = array_keys(getDefaultConfigOptions());
	$filtered = array();
	foreach ($allowed as $key) {
		if (array_key_exists($key, $values)) {
			$filtered[$key] = $values[$key];
		}
	}

	$export = "<?php\nreturn " . var_export($filtered, true) . ";\n";
	file_put_contents($file, $export, LOCK_EX);
}

function getDefaultConfigOptions() {
	return array(
		"event_threshold" => 60,
		"shop_requires_approval" => 1,
		"newuser_requires_approval" => 1,
		"anonymous_purchasing" => 0,
		"items_per_page" => 10,
		"email_from" => "webmaster@" . ($_SERVER['SERVER_NAME'] ?? 'localhost'),
		"email_reply_to" => "mkomarinski@wayga.org",
		"email_xmailer" => "PHP/" . phpversion(),
		"show_helptext" => 0,
		"confirm_item_deletes" => 0,
		"allow_multiples" => 1,
		"currency_symbol" => "$",
		"date_format" => "m/d/Y",
		"table_prefix" => "",
		"show_own_events" => 1,
		"password_length" => 8,
		"hide_zero_price" => 1,
		"allow_images" => 1,
		"image_subdir" => "item_images",
		"notify_threshold_minutes" => 60
	);
}

loadEnvFile(dirname(__FILE__) . "/../.env");

function getGlobalOptions($refresh = false) {
	static $opt;
	if (!isset($opt) || $refresh) {
		$defaults = getDefaultConfigOptions();
		$saved = getConfigValues();
		$opt = array_merge($defaults, $saved);

		$db_host = getEnvOrDefault('DB_HOST', 'localhost');
		$db_name = getEnvOrDefault('DB_NAME', 'giftreg');
		$db_user = getEnvOrDefault('DB_USER', 'giftreg');
		$db_password = getEnvOrDefault('DB_PASSWORD', 'cn3Malk');
		$db_port = getEnvOrDefault('DB_PORT', '3306');

		$opt["pdo_connection_string"] = "mysql:host={$db_host};port={$db_port};dbname={$db_name}";
		$opt["pdo_username"] = $db_user;
		$opt["pdo_password"] = $db_password;
	}
	return $opt;
}

	$db_host = getEnvOrDefault('DB_HOST', 'localhost');
	$db_name = getEnvOrDefault('DB_NAME', 'giftreg');
	$db_user = getEnvOrDefault('DB_USER', 'giftreg');
	$db_password = getEnvOrDefault('DB_PASSWORD', 'cn3Malk');
	$db_port = getEnvOrDefault('DB_PORT', '3306');
	
	return array(
		/* The PDO connection string.
			http://www.php.net/manual/en/pdo.connections.php
		*/
		"pdo_connection_string" => "mysql:host={$db_host};port={$db_port};dbname={$db_name}",

		/* The database username and password. */
		"pdo_username" => $db_user,
		"pdo_password" => $db_password,

		/* The maximum number of days before an event which produces a notification. */
		"event_threshold" => "60",

		/* Whether or not requesting to shop for someone is immediately approved. 
			0 = auto-approve,
			1 = require approval
		*/
		"shop_requires_approval" => 1,

		/* Whether or not requesting a new account is immediately approved.
			0 = auto-approve,
			1 = require administrator approval
		*/
		"newuser_requires_approval" => 1,

		/* Whether or not whom an item is reserved/bought by is hidden. */
		"anonymous_purchasing" => 0,

		/* The number of your items that show on each page. */
		"items_per_page" => 10,

		/* The e-mail From: header. */
		"email_from" => "webmaster@" . $_SERVER['SERVER_NAME'],

		/* The e-mail Reply-To: header. */
		"email_reply_to" => "mkomarinski@wayga.org",

		/* The e-mail X-Mailer header. */
		"email_xmailer" => "PHP/" . phpversion(),

		/* Whether or not to show brief blurbs in certain spots which describe how 
			features work.
			0 = don't help text,
			1 = show help text
		*/
		"show_helptext" => 0,

		/* Whether or not clicking the Delete Item link requires a JavaScript-based
			confirmation.
			0 = don't show confirmation,
			1 = show confirmation
		*/
		"confirm_item_deletes" => 0,

		/* Whether or not to allow multiple quantities of an item. */
		"allow_multiples" => 1,

		/* This is prefixed to all currency values, set it as appropriate for your currency. */
		"currency_symbol" => "$",	// US or other dollars      
		//"currency_symbol" => "&#163;",	// Pound (�) symbol
		//"currency_symbol" => "&#165;",	// Yen
		//"currency_symbol" => "&#8364;",	// Euro
		//"currency_symbol" => "&euro;",	// Euro alternative

		/* The date format used in DateTime::format()
			http://php.net/manual/en/function.date.php */
		"date_format" => "m/d/Y",

		/* If this is set to something other than "" then phpgiftreg will expect that
			string to prefix all tables in this installation.  Useful for running
			multiple phpgiftreg installations in the same MySQL database.
		*/
		"table_prefix" => "",
		//"table_prefix" => "gift_",		// all tables must be prefixed by `gift_'

		/* Whether or not your own events show up on the home page.
			0 = don't show my own events,
			1 = show my own events
		*/
		"show_own_events" => 1,

		/* The length of random generated passwords. */
		"password_length" => 8,

		/* Whether or not to hide the price when it's $0.00.
			0 = don't hide it,
			1 = hide it
		*/
		"hide_zero_price" => 1,

		/* Whether or not to allow image uploads.  If on, the next option must point to
			a valid subdirectory that is writeable by the web server.  The setup.php
			script will confirm this.
			0 = don't allow images,
			1 = allow images
		*/
		"allow_images" => 1,

		/* The *sub*-directory we we can store item images.  If you don't want to
			allow images to be attached to items, leave this variable empty ("").
			Trailing / is optional.
		*/
		"image_subdir" => "item_images",
		
		/* The number of minutes in between subscription notifications so the subscribers
			don't get flooded with updates.
		*/
		"notify_threshold_minutes" => 60
	);
}


