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

require_once(dirname(__FILE__) . "/../vendor/autoload.php");
require_once(dirname(__FILE__) . "/config.php");

class MySmarty extends Smarty\Smarty {
	public function __construct() {
		parent::__construct();

		date_default_timezone_set("GMT+0");
	}

	public function dbh() {
		$opt = $this->opt();
		return new PDO(
			$opt["pdo_connection_string"],
			$opt["pdo_username"],
			$opt["pdo_password"]);
	}

	public function opt($refresh = false) {
		static $opt;
		if (!isset($opt) || $refresh) {
			$opt = getGlobalOptions($refresh);
		}
		return $opt;
	}

	public function display($template = NULL, $cache_id = NULL, $compile_id = NULL, $parent = NULL) {
		parent::assign('isadmin', isset($_SESSION['admin']) ? $_SESSION['admin'] : false);
		parent::assign('opt', $this->opt());
		parent::display($template, $cache_id, $compile_id);
	}
}
?>
