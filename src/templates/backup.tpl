{*
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
*}

<!DOCTYPE html>
<html lang="en">
<head>
	<title>Gift Registry - Backup</title>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link href="bootstrap/css/bootstrap.min.css" rel="stylesheet">
	<link href="bootstrap/css/bootstrap-responsive.css" rel="stylesheet">
	<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
	<script src="bootstrap/js/bootstrap.min.js"></script>
</head>
<body>
	<div class="container" style="padding-top: 30px;">
		<div class="row">
			<div class="span8 offset2">
				<h2>Backup Application Data</h2>
				<p>Download a ZIP archive containing the database export, current runtime settings, and the item images folder.</p>
				{if isset($error) && $error}
					<div class="alert alert-danger">{$error|escape:'htmlall'}</div>
				{/if}
				<form name="backupform" id="backupform" method="post" action="backup.php" class="well form-horizontal">
					<input type="hidden" name="action" value="download_backup">
					<fieldset>
						<legend>Backup contents</legend>
						<div class="control-group">
							<label class="control-label">Settings file</label>
							<div class="controls">
								<p class="help-block">{$config_file}</p>
							</div>
						</div>
						<div class="control-group">
							<label class="control-label">Item image directory</label>
							<div class="controls">
								<p class="help-block">{$image_dir}</p>
							</div>
						</div>
						<div class="form-actions">
							<button type="submit" class="btn btn-primary">Download Backup ZIP</button>
							<button type="button" class="btn" onclick="window.location='settings.php';">Cancel</button>
						</div>
					</fieldset>
				</form>
			</div>
		</div>
	</div>
</body>
</html>
