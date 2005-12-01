<?php
/*
Plugin Name: LiveSync
Plugin URI: http://www.kapowaz.net/livesync/
Description: Allows synchronisation between live and local installations of WordPress via the WordPress Administration Interface
Author: Ben Darlow
Version: 1.0
Author URI: http://www.kapowaz.net/
*/ 

$kpwz_livesync = array();

// perform option updating function
if ($_POST['livesync_updateoptions'] == 'true')
{
	if (strlen($_POST['livesync_hostname'])>0)
	{
		// first make sure the user entered a destination hostname
		
		// update temporary directory location
		if (strlen($_POST['livesync_tempdir'])>0)
		{
			update_option('kpwz_livesync_tempdir', $_POST['livesync_tempdir']);
		}
		else
		{
			update_option('kpwz_livesync_tempdir', '/tmp');
		}
		
		if ($_POST['livesync_usedefaults'] == 'true')
		{
			// user specified to use the default (i.e. the same) database/username/password credentials
			update_option('kpwz_livesync_hostname', $_POST['livesync_hostname']);
			update_option('kpwz_livesync_usedefaults', 1);
			update_option('kpwz_livesync_database', DB_NAME);
			update_option('kpwz_livesync_username', DB_USER);
			update_option('kpwz_livesync_password', DB_PASSWORD);
			
			$kpwz_livesync['updateoptions_message'] = 'success';
		}
		elseif ($_POST['livesync_database'] && $_POST['livesync_username'] && $_POST['livesync_password'] && $_POST['livesync_usedefaults'] != 'true')
		{
			// user specified a different database, username and password
	
			update_option('kpwz_livesync_hostname', $_POST['livesync_hostname']);
			update_option('kpwz_livesync_usedefaults', 0);
			update_option('kpwz_livesync_database', $_POST['livesync_database']);
			update_option('kpwz_livesync_username', $_POST['livesync_username']);
			update_option('kpwz_livesync_password', $_POST['livesync_password']);
			
			$kpwz_livesync['updateoptions_message'] = 'success';
		}
		else
		{
			// the user didn't say to use defaults, but forgot to enter either a username or a password.
			$kpwz_livesync['updateoptions_message'] = 'You must enter a database name, username and password if you are not using the default connection credentials';
		}
	}
	else
	{
		$kpwz_livesync['updateoptions_message'] = 'You must enter a remote hostname for the target WordPress installation';
	}
}

// perform live update
if ($_POST['livesync_go'] == 'true')
{
	if (
		get_option('kpwz_livesync_hostname') != '' &&
		get_option('kpwz_livesync_database') != '' &&
		get_option('kpwz_livesync_username') != '' &&
		get_option('kpwz_livesync_password') != ''
	)
	{
		if (is_writable(get_option('kpwz_livesync_tempdir')))
		{
			// all prerequisites passed
			$kpwz_livesync['sql_script_path'] = get_option('kpwz_livesync_tempdir').'/livesync-'.DB_NAME.'-'.md5(time()).'.sql';
			$kpwz_livesync['database_tables'] = 'wp_categories wp_comments wp_linkcategories wp_links wp_options wp_post2cat wp_postmeta wp_posts wp_users';
			$kpwz_livesync['database_credentials'] = "-u ".DB_USER." --password=".DB_PASSWORD;
			$script_export_command = "`which mysqldump` {$kpwz_livesync['database_credentials']} --add-drop-table --complete-insert ".DB_NAME." {$kpwz_livesync['database_tables']} > {$kpwz_livesync['sql_script_path']} 2>&1";
			
			// run the export script
			$script_export_result = `$script_export_command`;
			
			// check that the file was successfully created
			if (file_exists($kpwz_livesync['sql_script_path']))
			{
				// 1. modify the file, replacing all instances of the local sitename with live sitename
				
				$fread = fopen($kpwz_livesync['sql_script_path'], 'r');
				$script_contents = fread($fread, filesize($kpwz_livesync['sql_script_path']));
				fclose($fread);
				$script_contents = str_replace(get_option('siteurl'), "http://".get_option('kpwz_livesync_hostname')."/", $script_contents);
				$fwrite = fopen($kpwz_livesync['sql_script_path'], 'w');
				$bool_rewritten = fwrite($fwrite, $script_contents);
				fclose($fwrite);
				
				// 2. run the modified SQL script
				
				if ($bool_rewritten)
				{
					// run the upload script
					$script_update = "`which mysql` -h ".get_option('kpwz_livesync_hostname')." -u ".get_option('kpwz_livesync_username')." --password=".get_option('kpwz_livesync_password')." --database=".get_option('kpwz_livesync_database')." < {$kpwz_livesync['sql_script_path']} 2>&1";
					$script_update_result = `$script_update`;
					if (!$script_update_result) $kpwz_livesync['go_message'] = 'success';
				}
				else
				{
					$kpwz_livesync['go_message'] = "The script was unable to create the updated SQL query. Check the permissions on your temporary directory, or contact your WordPress administrator.";
				}
				
				// 3. delete the SQL script
				
				$remove_file_result = `rm -f {$kpwz_livesync['sql_script_path']}`;
			}
			else
			{
				$kpwz_livesync['go_message'] = "The script was unable to create the live synchronisation SQL query. Check the permissions on your temporary directory, or contact your WordPress administrator.";
			}
		}
		else
		{
			// the temp dir specified isn't writeable
			$kpwz_livesync['go_message'] = "The temporary directory '".get_option('kpwz_livesync_tempdir')."' specified for storing the update script is not writeable by the webserver. Please check that the directory permissions are correctly set, or that your temporary directory is correct. You can change this on the <a href=\"/wp-admin/options-general.php?page=livesync/livesync.php\">options page</a>, or if you lack sufficient permissions contact your WordPress administrator.";
		}
	}
	else
	{
		// some/all of the options weren't filled in
		$kpwz_livesync['go_message'] = "All necessary update credentials have been filled in. Please fill these in <a href=\"/wp-admin/options-general.php?page=livesync/livesync.php\">here</a>, or if you lack sufficient permissions contact your WordPress administrator.";
	}
}

// Add menu options to the WP Admin console
function kpwz_livesync_init()
{
	// actual livesync 'action' page
	add_management_page('Synchronise with Live', 'LiveSync', '4', __FILE__, 'kpwz_livesync_display');
	
	// options for livesync
	add_options_page('LiveSync Options', 'LiveSync', 8, __FILE__, 'kpwz_livesync_options');
}

// display the options page
function kpwz_livesync_options()
{
	global $kpwz_livesync;
	?>
<div class="wrap">

<h2>LiveSync Options</h2>

	<p>These options are required if using the LiveSync plugin to synchronise local and live WordPress installations.</p>

<?php

if ($_POST['livesync_updateoptions'] == 'true')
{
	if ($kpwz_livesync['updateoptions_message'] == 'success')
	{
		echo "\n\n<p>Connection details successfully updated:</p>
		\n\n<p><strong>Server address:</strong> ".get_option('kpwz_livesync_hostname')."<br />
		<strong>Temporary directory:</strong> ".get_option('kpwz_livesync_tempdir')."<br />";
		
		if (get_option('kpwz_livesync_usedefaults') == 1)
		{
			echo "<strong>Using default connection credentials</strong></p>\n";
		}
		else
		{
			echo "<strong>Database name:</strong> ".get_option('kpwz_livesync_database')."<br />
		<strong>Database username:</strong> ".get_option('kpwz_livesync_username')."<br />
		<strong>Database password:</strong> ********</p>\n";
		}
	}
	else
	{
		echo "\n\n<p><em>{$kpwz_livesync['updateoptions_message']}</em></p>\n";
	}
}
?>
	
	<form name="livesync_options" method="post" action="<?php echo $_SERVER['SCRIPT_NAME'].'?page=livesync/livesync.php' ?>">
		<input type="hidden" name="livesync_updateoptions" value="true" />
		<fieldset class="options"> 
			<legend>Connection Details</legend> 
			<table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
				<tr> 
					<th width="33%" scope="row">Live hostname:</th> 
					<td><input name="livesync_hostname" type="text" id="livesynchostname" value="<?php echo get_option('kpwz_livesync_hostname'); ?>" size="40" /><br />
					This should be the address only, <em>not</em> starting <em>http://...</em> etcetera.</td> 
				</tr>
				<tr> 
					<th scope="row">Temporary directory:</th> 
					<td><input name="livesync_tempdir" type="text" id="livesync_tempdir" value="<?php echo get_option('kpwz_livesync_tempdir') == '' ? '/tmp' : get_option('kpwz_livesync_tempdir') ; ?>" size="40" /><br />
					This should be a directory your webserver process can write to. The default should be okay for Unix users.</td> 
				</tr>
				<tr>
					<th>Use default connection credentials:</th>
					<td><input name="livesync_usedefaults" type="checkbox" id="livesync_usedefaults" value="true" checked="checked" onclick="if (this.checked) { document.getElementById('livesync_database').disabled = true; document.getElementById('livesync_username').disabled = true; document.getElementById('livesync_password').disabled = true; } else { document.getElementById('livesync_database').disabled = false; document.getElementById('livesync_username').disabled = false; document.getElementById('livesync_password').disabled = false; }" /> If your database, username and password are the same for both servers, leave this checked.</td>
				</tr>
				<tr> 
					<th scope="row">Live server database name:</th> 
					<td><input name="livesync_database" type="text" id="livesync_database" value="<?php echo get_option('kpwz_livesync_database'); ?>" size="40" disabled="disabled" /><br />
					This is your MySQL database name for your live WordPress database.</td> 
				</tr> 
				<tr> 
					<th scope="row">Live server username:</th> 
					<td><input name="livesync_username" type="text" id="livesync_username" value="<?php echo get_option('kpwz_livesync_username'); ?>" size="40" disabled="disabled" /><br />
					This is your MySQL database username for your live WordPress database.</td> 
				</tr> 
				<tr> 
					<th scope="row">Live server password:</th> 
					<td><input name="livesync_password" type="password" id="livesync_password" value="<?php echo get_option('kpwz_livesync_password'); ?>" size="40" disabled="disabled" /><br />
					This is your MySQL database password for your live WordPress database.</td> 
				</tr>
			</table>
		</fieldset>
		<p class="submit"><input type="submit" name="Submit" value="Update Options &raquo;" /></p>
	</form>
</div>

<?php
}

// show the actual content
function kpwz_livesync_display()
{
	global $kpwz_livesync;
	?>
<div class="wrap">

<h2>LiveSync</h2>

<p>Use this page to synchronise the local WordPress database with your live installation.<br />
<strong>N.B.</strong> this cannot be undone.</p>

<?php

if ($kpwz_livesync['go_message'] == 'success')
{
	echo "\n\n<p>Successfully synchronised with live server.</p>\n";
}
else
{
	echo "\n\n<p><em>{$kpwz_livesync['go_message']}</em></p>\n";
}

?>

<form name="livesync_sync" method="post" action="<?php echo $_SERVER['SCRIPT_NAME'].'?page=livesync/livesync.php' ?>" onsubmit="return confirm('Are you sure you want to synchronise the live server with your local database?\nNote that this cannot be undone.');">
<input type="hidden" name="livesync_go" value="true" />
<p class="submit"><input type="submit" name="Submit" value="Synchronise Live &raquo;" /></p>
</form>

</div>

<?php
}

add_action('admin_menu', 'kpwz_livesync_init');

?>