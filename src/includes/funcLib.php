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

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Safely require the Composer autoloader
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    die("Composer autoloader not found. Please run 'composer install' in the project root.");
}

function getFullPath($url) {
	$fp = $_SERVER["SERVER_PORT"] == "443" ? "https://" : "http://";
	$fp .= $_SERVER["HTTP_HOST"];
	$dir = dirname($_SERVER["PHP_SELF"]);
	if ($dir != "/")
		$fp .= $dir;
	$fp .= "/" . $url;
	return $fp;
}

function jsEscape($s) {
	return str_replace("\"","\\u0022",str_replace("'","\\'",str_replace("\r\n","\\r\\n",$s)));
}

function adjustAllocQuantity($itemid, $userid, $bought, $adjust, $dbh, $opt) {
	$howmany = getExistingQuantity($itemid, $userid, $bought, $dbh, $opt);
	if ($howmany == 0) {
		if ($adjust < 0) {
			// can't subtract anything from 0.
			return 0;
		}
		else {
			$stmt = $dbh->prepare("INSERT INTO allocs(itemid,userid,bought,quantity) VALUES(?, ?, ?, ?)");
			$stmt->bindParam(1, $itemid, PDO::PARAM_INT);
			$stmt->bindParam(2, $userid, PDO::PARAM_INT);
			$stmt->bindParam(3, $bought, PDO::PARAM_BOOL);
			$stmt->bindParam(4, $adjust, PDO::PARAM_INT);
			$stmt->execute();
			return $howmany;
		}
	}
	else {
		/* figure out the real amount to adjust by, in case someone claims to have
			received 3 of something from a buyer when they only bought 2. */
		if ($adjust < 0) {
			if (abs($adjust) > $howmany)
				$actual = -$howmany;
			else
				$actual = $adjust;
		}
		else {
			$actual = $adjust;
		}
		
		if ($howmany + $actual == 0) {
			$stmt = $dbh->prepare("DELETE FROM allocs WHERE itemid = ? AND userid = ? AND bought = ?");
			$stmt->bindParam(1, $itemid, PDO::PARAM_INT);
			$stmt->bindParam(2, $userid, PDO::PARAM_INT);
			$stmt->bindParam(3, $bought, PDO::PARAM_BOOL);
			$stmt->execute();
		}
		else {
			$stmt = $dbh->prepare("UPDATE allocs " .
					"SET quantity = quantity + ? " .	// because "quantity + -5" is okay.
					"WHERE itemid = ? AND userid = ? AND bought = ?");
			$stmt->bindParam(1, $actual, PDO::PARAM_INT);
			$stmt->bindParam(2, $itemid, PDO::PARAM_INT);
			$stmt->bindParam(3, $userid, PDO::PARAM_INT);
			$stmt->bindParam(4, $bought, PDO::PARAM_BOOL);
			$stmt->execute();
		}
		return $actual;
	}
}

function getExistingQuantity($itemid, $userid, $bought, $dbh, $opt) {
	$stmt = $dbh->prepare("SELECT quantity FROM allocs WHERE bought = ? AND userid = ? AND itemid = ?");
	$stmt->bindParam(1, $bought, PDO::PARAM_BOOL);
	$stmt->bindParam(2, $userid, PDO::PARAM_INT);
	$stmt->bindParam(3, $itemid, PDO::PARAM_INT);
	$stmt->execute();
	if ($row = $stmt->fetch()) {
		return $row["quantity"];
	}
	else {
		return 0;
	}
}

function processSubscriptions($publisher, $action, $itemdesc, $dbh, $opt) {
	// join the users table as a cheap way to get the guy's name without having to pass it in.
	$stmt = $dbh->prepare("SELECT subscriber, fullname FROM subscriptions sub INNER JOIN users u ON u.userid = sub.publisher WHERE publisher = ? AND (last_notified IS NULL OR DATE_ADD(last_notified, INTERVAL {$opt["notify_threshold_minutes"]} MINUTE) < NOW())");
	$stmt->bindParam(1, $publisher, PDO::PARAM_INT);
	$stmt->execute();

	$msg = "";
	while ($row = $stmt->fetch()) {
		if ($msg == "") {
			// same message for each user but we need the fullname from the first row before we can assemble it.
			if ($action == "insert") {
				$msg = $row["fullname"] . " has added the item \"$itemdesc\" to their list.";
			}
			else if ($action == "update") {
				$msg = $row["fullname"] . " has updated the item \"$itemdesc\" on their list.";
			}
			else if ($action == "delete") {
				$msg = $row["fullname"] . " has deleted the item \"$itemdesc\" from their list.";
			}
			$msg .= "\r\n\r\nYou are receiving this message because you are subscribed to their updates.  You will not receive another message for their updates for the next " . $opt["notify_threshold_minutes"] . " minutes.";
		}
		sendMessage($publisher, $row["subscriber"], $msg, $dbh, $opt);

		// stamp the subscription.
		$stmt2 = $dbh->prepare("UPDATE subscriptions SET last_notified = NOW() WHERE publisher = ? AND subscriber = ?");
		$stmt2->bindParam(1, $publisher, PDO::PARAM_INT);
		$stmt2->bindParam(2, $row["subscriber"], PDO::PARAM_INT);
		$stmt2->execute();
	}
}

function sendMessage($sender, $recipient, $message, $dbh, $opt) {
	$stmt = $dbh->prepare("INSERT INTO messages(sender,recipient,message,created) VALUES(?, ?, ?, ?)");
	$stmt->bindParam(1, $sender, PDO::PARAM_INT);
	$stmt->bindParam(2, $recipient, PDO::PARAM_INT);
	$stmt->bindParam(3, $message, PDO::PARAM_STR);
	$stmt->bindValue(4, strftime("%Y-%m-%d"), PDO::PARAM_STR);
	$stmt->execute();
	
	// determine if e-mail must be sent.
	$stmt = $dbh->prepare("SELECT ur.email_msgs, ur.email AS remail, us.fullname, us.email AS semail FROM users ur " .
			"INNER JOIN users us ON us.userid = ? " .
			"WHERE ur.userid = ?");
	$stmt->bindParam(1, $sender, PDO::PARAM_INT);
	$stmt->bindParam(2, $recipient, PDO::PARAM_INT);
	$stmt->execute();
	if ($row = $stmt->fetch()) {
		if ($row["email_msgs"] == 1) {
			$subject = "Gift Registry message from " . $row["fullname"];
			$body = $row["fullname"] . " <" . $row["semail"] . "> sends:\r\n" . $message;
			if (!sendEmail($row["remail"], $subject, $body, $opt, $row["semail"])) {
				error_log("Failed to send email to " . $row["remail"]);
			}
		}
	}
	else {
		die("recipient doesn't exist");
	}
}

function generatePassword($opt) {
	//* borrowed from hitech-password.php - a PHP Message board script
	//* (c) Hitech Scripts 2003
	//* For more information, visit http://www.hitech-scripts.com
	//* modified for phpgiftreg by Chris Clonch
	if ($opt["password_length"] > 8) {
		$length = $opt["password_length"];
	} else {
		$length = 8;
	}
	$bytes = random_bytes($length);
	$newstring = bin2hex($bytes); // Or base64_encode($bytes);
	$hash = password_hash($newstring, PASSWORD_BCRYPT);
	return [$newstring, $hash];
}

function formatPrice($price, $opt) {
	if ($price == 0.0 && $opt["hide_zero_price"])
		return "&nbsp;";
	else
		return $opt["currency_symbol"] . number_format($price,2,".",",");
}

function stampUser($userid, $dbh, $opt) {
	$stmt = $dbh->prepare("UPDATE users SET list_stamp = NOW() WHERE userid = ?");
	$stmt->bindParam(1, $userid, PDO::PARAM_INT);
	$stmt->execute();
}

function isGuardianOf($guardian_userid, $child_userid, $dbh) {
	$stmt = $dbh->prepare("SELECT 1 FROM guardianships WHERE guardian_userid = ? AND child_userid = ?");
	$stmt->bindParam(1, $guardian_userid, PDO::PARAM_INT);
	$stmt->bindParam(2, $child_userid, PDO::PARAM_INT);
	$stmt->execute();
	return $stmt->fetch() ? true : false;
}

function oidcFetchJson($url, $postFields = null, $headers = array()) {
	if (function_exists('curl_version')) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(array('Accept: application/json'), $headers));
		if ($postFields !== null) {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
		}
		$result = curl_exec($ch);
		if ($result === false) {
			throw new Exception('OIDC HTTP request failed: ' . curl_error($ch));
		}
		$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($status < 200 || $status >= 300) {
			throw new Exception('OIDC HTTP request returned status ' . $status . ': ' . $result);
		}
	}
	else {
		$options = array('http' => array(
			'method' => $postFields === null ? 'GET' : 'POST',
			'header' => implode("\r\n", array_merge(array('Accept: application/json'), $headers)),
			'content' => $postFields === null ? null : http_build_query($postFields),
			'ignore_errors' => true
		));
		$context = stream_context_create($options);
		$result = file_get_contents($url, false, $context);
		if ($result === false) {
			throw new Exception('OIDC HTTP request failed: ' . $url);
		}
		$status = null;
		if (isset($http_response_header)) {
			preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $http_response_header[0], $matches);
			$status = isset($matches[1]) ? (int)$matches[1] : null;
		}
		if ($status !== null && ($status < 200 || $status >= 300)) {
			throw new Exception('OIDC HTTP request returned status ' . $status . ': ' . $result);
		}
	}

	$decoded = json_decode($result, true);
	if (!is_array($decoded)) {
		throw new Exception('OIDC response was not valid JSON.');
	}
	return $decoded;
}

function oidcGetConfiguration($issuer) {
	$issuer = rtrim($issuer, '/');
	return oidcFetchJson($issuer . '/.well-known/openid-configuration');
}

function oidcBase64UrlDecode($input) {
	$remainder = strlen($input) % 4;
	if ($remainder) {
		$input .= str_repeat('=', 4 - $remainder);
	}
	$input = strtr($input, '-_', '+/');
	return base64_decode($input);
}

function oidcDecodeJwt($jwt) {
	$parts = explode('.', $jwt);
	if (count($parts) !== 3) {
		throw new Exception('Invalid JWT format.');
	}
	$header = json_decode(oidcBase64UrlDecode($parts[0]), true);
	$payload = json_decode(oidcBase64UrlDecode($parts[1]), true);
	$signature = oidcBase64UrlDecode($parts[2]);
	if (!is_array($header) || !is_array($payload) || $signature === false) {
		throw new Exception('Invalid JWT content.');
	}
	return array($header, $payload, $signature, $parts[0] . '.' . $parts[1]);
}

function oidcEncodeLength($length) {
	if ($length < 128) {
		return chr($length);
	}
	$hexLength = dechex($length);
	if (strlen($hexLength) % 2) {
		$hexLength = '0' . $hexLength;
	}
	$lengthBytes = hex2bin($hexLength);
	return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
}

function oidcJwkToPem($jwk) {
	if (empty($jwk['kty']) || $jwk['kty'] !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
		throw new Exception('Unsupported JWK key type.');
	}
	$modulus = oidcBase64UrlDecode($jwk['n']);
	$exponent = oidcBase64UrlDecode($jwk['e']);
	$modulus = ltrim($modulus, "\x00");
	$modulusEnc = "\x02" . oidcEncodeLength(strlen($modulus)) . $modulus;
	$exponentEnc = "\x02" . oidcEncodeLength(strlen($exponent)) . $exponent;
	$sequence = "\x30" . oidcEncodeLength(strlen($modulusEnc . $exponentEnc)) . $modulusEnc . $exponentEnc;
	$rsaOID = hex2bin('300d06092a864886f70d0101010500');
	$bitstring = "\x00" . $sequence;
	$publicKey = "\x30" . oidcEncodeLength(strlen($rsaOID . "\x03" . oidcEncodeLength(strlen($bitstring)) . $bitstring)) . $rsaOID . "\x03" . oidcEncodeLength(strlen($bitstring)) . $bitstring;
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicKey), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function oidcVerifyJwtSignature($jwt, $jwks) {
	list($header, $payload, $signature, $signedPart) = oidcDecodeJwt($jwt);
	if (empty($header['alg']) || strpos($header['alg'], 'RS') !== 0) {
		throw new Exception('Unsupported JWT signing algorithm.');
	}
	$kid = isset($header['kid']) ? $header['kid'] : null;
	foreach ($jwks['keys'] as $key) {
		if ($kid !== null && isset($key['kid']) && $key['kid'] !== $kid) {
			continue;
		}
		$publicKey = oidcJwkToPem($key);
		$ok = openssl_verify($signedPart, $signature, $publicKey, OPENSSL_ALGO_SHA256);
		if ($ok === 1) {
			return true;
		}
	}
	return false;
}

function oidcValidateIdToken($idToken, $opt, $nonce = null, $config = null) {
	list($header, $claims,,) = oidcDecodeJwt($idToken);
	if ($config === null) {
		$config = oidcGetConfiguration(rtrim($opt['oidc_issuer'], '/'));
	}
	$jwks = oidcFetchJson($config['jwks_uri']);
	if (!oidcVerifyJwtSignature($idToken, $jwks)) {
		throw new Exception('OIDC ID token signature validation failed.');
	}
	if (empty($claims['iss']) || rtrim($claims['iss'], '/') !== rtrim($opt['oidc_issuer'], '/')) {
		throw new Exception('OIDC issuer mismatch.');
	}
	if (empty($claims['aud'])) {
		throw new Exception('OIDC audience is missing.');
	}
	$audience = is_array($claims['aud']) ? $claims['aud'] : array($claims['aud']);
	if (!in_array($opt['oidc_client_id'], $audience, true)) {
		throw new Exception('OIDC audience mismatch.');
	}
	$now = time();
	if (isset($claims['exp']) && $now > $claims['exp']) {
		throw new Exception('OIDC ID token has expired.');
	}
	if (isset($claims['nbf']) && $now < $claims['nbf']) {
		throw new Exception('OIDC ID token is not yet valid.');
	}
	if ($nonce !== null && isset($claims['nonce']) && $claims['nonce'] !== $nonce) {
		throw new Exception('OIDC nonce validation failed.');
	}
	return $claims;
}

function oidcFindOrProvisionUser($claims, $dbh, $opt) {
	$email = !empty($claims['email']) ? $claims['email'] : null;
	$usernameClaim = !empty($claims['preferred_username']) ? $claims['preferred_username'] : null;
	$fullname = !empty($claims['name']) ? $claims['name'] : ($usernameClaim ?: $email ?: 'OIDC User');

	$user = null;
	if ($email) {
		$stmt = $dbh->prepare("SELECT * FROM users WHERE email = ?");
		$stmt->bindParam(1, $email, PDO::PARAM_STR);
		$stmt->execute();
		$user = $stmt->fetch();
	}
	if (!$user && $usernameClaim) {
		$stmt = $dbh->prepare("SELECT * FROM users WHERE username = ?");
		$stmt->bindParam(1, $usernameClaim, PDO::PARAM_STR);
		$stmt->execute();
		$user = $stmt->fetch();
	}

	if ($user) {
		return $user;
	}

	if (!empty($opt['oidc_auto_provision'])) {
		$username = $usernameClaim ?: ($email ? preg_replace('/[^a-zA-Z0-9._-]/', '', strstr($email, '@', true)) : 'oidcuser');
		$username = preg_replace('/[^a-zA-Z0-9._-]/', '', $username);
		if ($username === '') {
			$username = 'oidcuser';
		}
		$base = $username;
		$index = 1;
		while (true) {
			$stmt = $dbh->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
			$stmt->bindParam(1, $username, PDO::PARAM_STR);
			$stmt->execute();
			if ($stmt->fetchColumn() == 0) {
				break;
			}
			$username = $base . $index;
			$index++;
		}
		$password = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
		$approved = !empty($opt['oidc_auto_approve']) ? 1 : 0;
		$stmt = $dbh->prepare("INSERT INTO users(username, password, fullname, email, approved, admin, comment, email_msgs, list_stamp, initialfamilyid) VALUES(?, ?, ?, ?, ?, 0, ?, 0, NULL, NULL)");
		$comment = 'OIDC provisioned user.';
		$stmt->bindParam(1, $username, PDO::PARAM_STR);
		$stmt->bindParam(2, $password, PDO::PARAM_STR);
		$stmt->bindParam(3, $fullname, PDO::PARAM_STR);
		$stmt->bindParam(4, $email, PDO::PARAM_STR);
		$stmt->bindParam(5, $approved, PDO::PARAM_INT);
		$stmt->bindParam(6, $comment, PDO::PARAM_STR);
		$stmt->execute();
		$userId = $dbh->lastInsertId();
		$stmt = $dbh->prepare("SELECT * FROM users WHERE userid = ?");
		$stmt->bindParam(1, $userId, PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetch();
	}

	return null;
}

function deleteImageForItem($itemid, $dbh, $opt) {
	$stmt = $dbh->prepare("SELECT image_filename FROM items WHERE itemid = ?");
	$stmt->bindParam(1, $itemid, PDO::PARAM_INT);
	$stmt->execute();
	if ($row = $stmt->fetch()) {
		if ($row["image_filename"] != "") {
			unlink($opt["image_subdir"] . "/" . $row["image_filename"]);
		}

		$stmt = $dbh->prepare("UPDATE items SET image_filename = NULL WHERE itemid = ?");
		$stmt->bindParam(1, $itemid, PDO::PARAM_INT);
		$stmt->execute();
	}
}

function fixForJavaScript($s) {
	$s = htmlentities($s);
	$s = str_replace("'","\\'",$s);
	$s = str_replace("\r\n","<br />",$s);
	$s = str_replace("\n","<br />",$s);
	return $s;
}

function sendEmail($to, $subject, $body, $opt, $replyTo = null) {
	$mail = new PHPMailer(true);

	try {
		// Server settings
		$mail->isSMTP();
		$mail->Host = $opt['smtp_host'];
		$mail->Port = $opt['smtp_port'];
		if ($opt['smtp_auth']) {
			$mail->SMTPAuth = true;
			$mail->Username = $opt['smtp_username'];
			$mail->Password = $opt['smtp_password'];
		} else {
			$mail->SMTPAuth = false;
		}
		if ($opt['smtp_encryption'] === 'tls') {
			$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
		} elseif ($opt['smtp_encryption'] === 'ssl') {
			$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
		} else {
			$mail->SMTPSecure = '';
		}

		// Recipients
		$mail->setFrom($opt['email_from']);
		$mail->addAddress($to);

		if ($replyTo) {
			$mail->addReplyTo($replyTo);
		} elseif ($opt['email_reply_to']) {
			$mail->addReplyTo($opt['email_reply_to']);
		}

		// Content
		$mail->isHTML(false);
		$mail->Subject = $subject;
		$mail->Body = $body;
		$mail->XMailer = $opt['email_xmailer'];

		$mail->send();
		return true;
	} catch (Exception $e) {
		error_log("Email send failed: " . $mail->ErrorInfo);
		return false;
	}
}
?>
