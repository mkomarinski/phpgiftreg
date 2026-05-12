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

function loadEnvFile($file) {
	if (!file_exists($file) || !is_readable($file)) {
		return;
	}
	$lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '' || strpos($line, '#') === 0) {
			continue;
		}
		if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/', $line, $matches)) {
			continue;
		}
		$key = $matches[1];
		$value = $matches[2];
		if ((strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') || (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'")) {
			$value = substr($value, 1, -1);
		}
		if (getenv($key) === false) {
			putenv("$key=$value");
			$_ENV[$key] = $value;
			$_SERVER[$key] = $value;
		}
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
		"show_own_events" => 1,
		"password_length" => 8,
		"hide_zero_price" => 1,
		"allow_images" => 1,
		"image_subdir" => "item_images",
		"notify_threshold_minutes" => 60,
	"oidc_enabled" => 0,
	"oidc_issuer" => "",
	"oidc_client_id" => "",
	"oidc_client_secret" => "",
	"oidc_scopes" => "openid email profile",
	"oidc_auto_provision" => 0,
	"oidc_auto_approve" => 0,
	"oidc_prompt" => "",
	);
}

function getGlobalOptions($refresh = false) {
	static $opt;
	if (!isset($opt) || $refresh) {
		$defaults = getDefaultConfigOptions();
		$saved = getConfigValues();
		$opt = array_merge($defaults, $saved);

		$envFile = dirname(__DIR__) . "/.env";
		loadEnvFile($envFile);

		$db_host = getEnvOrDefault('DB_HOST', 'localhost');
		$db_name = getEnvOrDefault('DB_NAME', 'giftreg');
		$db_user = getEnvOrDefault('DB_USER', 'giftreg');
		$db_password = getEnvOrDefault('DB_PASSWORD', 'cn3Malk');
		$db_port = getEnvOrDefault('DB_PORT', '3306');

		$opt["pdo_connection_string"] = "mysql:host={$db_host};port={$db_port};dbname={$db_name}";
		$opt["pdo_username"] = $db_user;
		$opt["pdo_password"] = $db_password;

		$opt["oidc_enabled"] = (int) getEnvOrDefault('OIDC_ENABLED', $opt["oidc_enabled"]);
		$opt["oidc_issuer"] = getEnvOrDefault('OIDC_ISSUER', $opt["oidc_issuer"]);
		$opt["oidc_client_id"] = getEnvOrDefault('OIDC_CLIENT_ID', $opt["oidc_client_id"]);
		$opt["oidc_client_secret"] = getEnvOrDefault('OIDC_CLIENT_SECRET', $opt["oidc_client_secret"]);
		$opt["oidc_scopes"] = getEnvOrDefault('OIDC_SCOPES', $opt["oidc_scopes"]);
		$opt["oidc_auto_provision"] = (int) getEnvOrDefault('OIDC_AUTO_PROVISION', $opt["oidc_auto_provision"]);
		$opt["oidc_auto_approve"] = (int) getEnvOrDefault('OIDC_AUTO_APPROVE', $opt["oidc_auto_approve"]);
		$opt["oidc_prompt"] = getEnvOrDefault('OIDC_PROMPT', $opt["oidc_prompt"]);
	}
	return $opt;
}