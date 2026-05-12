{*
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.
*}

<!DOCTYPE html>
<html lang="en">
<head>
	<title>Gift Registry - Settings</title>
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
				<h2>Administrator Settings</h2>
				<p>
					Database connection settings are loaded from <code>.env</code> and are not editable here.
					OIDC authentication settings can be changed on this page or overridden by defining the corresponding environment variables in <code>.env</code>.
				</p>
				{if isset($error) && $error}
					<div class="alert alert-danger">{$error|escape:'htmlall'}</div>
				{/if}
				{if isset($success) && $success}
					<div class="alert alert-success">{$success|escape:'htmlall'}</div>
				{/if}
				<form name="settingsform" id="settingsform" method="post" action="settings.php" class="well form-horizontal">
					<input type="hidden" name="action" value="save_settings">
					<fieldset>
						<legend>Runtime Configuration</legend>
						{foreach from=$schema key=key item=meta}
							<div class="control-group">
								<label class="control-label" for="{$key}">{$meta.label}</label>
								<div class="controls">
									{if $meta.type == 'checkbox'}
										<label class="checkbox">
											<input type="checkbox" id="{$key}" name="{$key}" value="1"{if $settings.$key} checked{/if}> {$meta.description}
										</label>
									{elseif $meta.type == 'number'}
										<input type="number" id="{$key}" name="{$key}" class="input-medium" value="{$settings.$key|escape:'htmlall'}" min="{$meta.min}" max="{$meta.max}">
										<p class="help-block">{$meta.description}</p>
									{else}
										<input type="text" id="{$key}" name="{$key}" class="input-xlarge" value="{$settings.$key|escape:'htmlall'}">
										<p class="help-block">{$meta.description}</p>
									{/if}
								</div>
							</div>
						{/foreach}
						<div class="form-actions">
							<button type="submit" class="btn btn-primary">Save Settings</button>
							<button type="button" class="btn" onclick="window.location='index.php';">Cancel</button>
						</div>
					</fieldset>
				</form>
			</div>
		</div>
	</div>
</body>
</html>
