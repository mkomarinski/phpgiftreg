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
		"smtp_host" => "localhost",
		"smtp_port" => 587,
		"smtp_username" => "",
		"smtp_password" => "",
		"smtp_encryption" => "tls",
		"smtp_auth" => 0,
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