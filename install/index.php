<?php
@session_start();
header('Content-Encoding: none;');
//print_r($_SESSION);
if (isset($_SESSION['error'])) {
	echo 'Error : '.$_SESSION['error'].' - Resetting install... You need to fix the problem and run install again.';
	unset($_SESSION['error']);
	unset($_SESSION['install']);
}
require_once(dirname(__FILE__).'/class.create_db.php');
require_once(dirname(__FILE__).'/class.update_schema.php');
require_once(dirname(__FILE__).'/class.settings.php');
$title="Install";
require(dirname(__FILE__).'/../require/settings.php');
require(dirname(__FILE__).'/header.php');

if ($globalInstalled && !isset($_SESSION['install'])) {
	print '<div class="info column"><p>You need to change $globalInstalled in settings.php to FALSE if you want to access setup again.</p></div>';
	require('../footer.php');
	exit;
}

$writable = false;
if (!is_writable('../require/settings.php')) {
	print '<div class="info column"><p><strong>The file <i>require/settings</i> must be writable.</strong></p></div>';
	require('../footer.php');
	exit;
}
if (!is_writable('tmp')) {
	print '<div class="info column"><p><strong>The directory <i>install/tmp</i> must be writable.</strong></p></div>';
	require('../footer.php');
	exit;
}
if (!is_writable('../images/airlines')) {
	print '<div class="info column"><p><strong>The directory <i>images/airlines</i> must be writable for IVAO.</strong></p></div>';
}
if (!set_time_limit(0)) {
	print '<div class="info column"><p><strong>You may need to update the maximum execution time.</strong></p></div>';
}
/*
if (!function_exists('pcntl_fork')) {
	print '<div class="info column"><p><strong>pcntl_fork is not available. Schedules will not be fetched.</strong></p></div>';
}
*/
$error = array();
if (!extension_loaded('SimpleXML')) {
	$error[] = "SimpleXML is not loaded.";
}
if (!extension_loaded('dom')) {
	$error[] = "Dom is not loaded. Needed for aircraft schedule";
}
if (!extension_loaded('PDO')) {
	$error[] = "PDO is not loaded.";
}
if (!extension_loaded('pdo_sqlite')) {
	$error[] = "PDO SQLite is not loaded. Needed to populate database for SBS.";
}
if (!extension_loaded('zip')) {
	$error[] = "ZIP is not loaded. Needed to populate database for SBS.";
}
if (!extension_loaded('json')) {
	$error[] = "Json is not loaded. Needed for aircraft schedule and bitly.";
}
if (!extension_loaded('sockets')) {
	$error[] = "Sockets is not loaded. Needed to populate DB from spotter_daemon.php script.";
}
if (!extension_loaded('curl')) {
	$error[] = "Curl is not loaded.";
}
if(function_exists('apache_get_modules') ){
	if(!in_array('mod_rewrite',apache_get_modules())) {
		$error[] = "mod_rewrite is not available.";
	}
/*
	if (!isset($_SERVER['HTTP_FAMHTACCESS'])) {
		$error[] = "htaccess is not interpreted. Check your Apache configuration";
	}
*/
}

if (!function_exists("gettext")) {
	print '<div class="info column"><p><strong>gettext doesn\'t exist. Site translation not available.</strong></p></div>';
}
print '<div class="info column"><p><strong>If you use MySQL or MariaDB, check that <i>max_allowed_packet</i> >= 8M, else import of some table can fail.</strong></p></div>';
if (isset($_SERVER['REQUEST_SCHEME']) && isset($_SERVER['SERVER_NAME']) && isset($_SERVER['SERVER_PORT']) && isset($_SERVER['REQUEST_URI'])) {
	if (function_exists('get_headers')) {
		$check_header = @get_headers($_SERVER['REQUEST_SCHEME'].'://'.$_SERVER["SERVER_NAME"].':'.$_SERVER["SERVER_PORT"].str_replace('install/','search',str_replace('index.php',$_SERVER["REQUEST_URI"])));
		if (isset($check_header[0]) && !stripos($check_header[0],"200 OK")) {
			print '<div class="info column"><p><strong>Check your configuration, rewrite don\'t seems to work.</strong></p></div>';
		}
	}
}
if (count($error) > 0) {
	print '<div class="info column"><ul>';
	foreach ($error as $err) {
		print '<li>'.$err.'</li>';
	}
	print '</ul>You <strong>must</strong> add these modules/fix errors.</div>';
//	require('../footer.php');
//	exit;
}
//if (isset($_SESSION['install'])) echo 'My session';
if (!isset($_SESSION['install']) && !isset($_POST['dbtype']) && (count($error) == 0)) {
	?>
	<div class="info column install">
	<form method="post" class="form-horizontal">
		<fieldset id="database">
			<legend>Database configuration</legend>
			<p>
				<label for="dbtype">Database type</label>
				<select name="dbtype" id="dbtype">
					<option value="mysql" <?php if (isset($globalDBdriver) && $globalDBdriver == 'mysql') { ?>selected="selected" <?php } ?>>MySQL</option>
					<option value="pgsql" <?php if (isset($globalDBdriver) && $globalDBdriver == 'pgsql') { ?>selected="selected" <?php } ?>>PostgreSQL</option>
				</select>
			</p>
			<p>
				<label for="createdb">Create database</label>
				<input type="checkbox" name="createdb" id="createdb" value="createdb" onClick="create_database_js()" />
			</p>
			<div id="createdb_data">
				<p>
					<label for="dbroot">Database admin user</label>
					<input type="text" name="dbroot" id="dbroot" />
				</p>
				<p>
					<label for="dbrootpass">Database admin password</label>
					<input type="password" name="dbrootpass" id="dbrootpass" />
				</p>
			</div>
			<p>
				<label for="dbhost">Database hostname</label>
				<input type="text" name="dbhost" id="dbhost" value="<?php if (isset($globalDBhost)) print $globalDBhost; ?>" />
			</p>
			<p>
				<label for="dbport">Database port</label>
				<input type="text" name="dbport" id="dbport" value="<?php if (isset($globalDBport)) print $globalDBport; ?>" />
				<p class="help-block">Default is 3306 for MariaDB/MySQL, 5432 for PostgreSQL</p>
			</p>
			<p>
				<label for="dbname">Database name</label>
				<input type="text" name="dbname" id="dbname" value="<?php if (isset($globalDBname)) print $globalDBname; ?>" />
			</p>
			<p>
				<label for="dbuser">Database user</label>
				<input type="text" name="dbuser" id="dbuser" value="<?php if (isset($globalDBuser)) print $globalDBuser; ?>" />
			</p>
			<p>
				<label for="dbuserpass">Database user password</label>
				<input type="password" name="dbuserpass" id="dbuserpass" value="<?php if (isset($globalDBpass)) print $globalDBpass; ?>" />
			</p>
		</fieldset>
		<fieldset id="site">
			<legend>Site configuration</legend>
			<p>
				<label for="sitename">Site name</label>
				<input type="text" name="sitename" id="sitename" value="<?php if (isset($globalName)) print $globalName; ?>" />
			</p>
			<p>
				<label for="siteurl">Site directory</label>
				<input type="text" name="siteurl" id="siteurl" value="<?php if (isset($globalURL)) print $globalURL; ?>" />
				<p class="help-block">Can be null. ex : <i>flightairmap</i> if complete URL is <i>http://toto.com/flightairmap</i></p>
			</p>
			<p>
				<label for="timezone">Timezone</label>
				<input type="text" name="timezone" id="timezone" value="<?php if (isset($globalTimezone)) print $globalTimezone; ?>" />
				<p class="help-block">ex : UTC, Europe/Paris,...</p>
			</p>
			<p>
				<label for="language">Language</label>
				<input type="text" name="language" id="language" value="<?php if (isset($globalLanguage)) print $globalLanguage; ?>" />
				<p class="help-block">Used only when link to wikipedia for now. Can be EN,DE,FR,...</p>
			</p>
		</fieldset>
		<fieldset id="mapprov">
			<legend>Map provider</legend>
			<p>
				<label for="mapprovider">Default map Provider</label>
				<select name="mapprovider" id="mapprovider">
					<option value="OpenStreetMap" <?php if (isset($globalMapProvider) && $globalMapProvider == 'OpenStreetMap') { ?>selected="selected" <?php } ?>>OpenStreetMap</option>
					<option value="Mapbox" <?php if (isset($globalMapProvider) && $globalMapProvider == 'Mapbox') { ?>selected="selected" <?php } ?>>Mapbox</option>
					<option value="MapQuest-OSM" <?php if (isset($globalMapProvider) && $globalMapProvider == 'MapQuest-OSM') { ?>selected="selected" <?php } ?>>MapQuest-OSM</option>
					<option value="MapQuest-Aerial" <?php if (isset($globalMapProvider) && $globalMapProvider == 'MapQuest-Aerial') { ?>selected="selected" <?php } ?>>MapQuest-Aerial</option>
					<option value="Google-Hybrid" <?php if (isset($globalMapProvider) && $globalMapProvider == 'Google-Hybrid') { ?>selected="selected" <?php } ?>>Google Hybrid</option>
					<option value="Bing-Hybrid" <?php if (isset($globalMapProvider) && $globalMapProvider == 'Bing-Hybrid') { ?>selected="selected" <?php } ?>>Bing Hybrid</option>
					<option value="Yandex" <?php if (isset($globalMapProvider) && $globalMapProvider == 'Yandex') { ?>selected="selected" <?php } ?>>Yandex</option>
				</select>
			</p>
			<div id="mapbox_data">
				<p>
					<label for="mapboxid">Mapbox id</label>
					<input type="text" name="mapboxid" id="mapboxid" value="<?php if (isset($globalMapboxId)) print $globalMapboxId; ?>" />
				</p>
				<p>
					<label for="mapboxtoken">Mapbox token</label>
					<input type="text" name="mapboxtoken" id="mapboxtoken" value="<?php if (isset($globalMapboxToken)) print $globalMapboxToken; ?>" />
				</p>
				<p class="help-block">Get a key <a href="https://www.mapbox.com/developers/">here</a></p>
			</div>
			<br />
			<div id="google_data">
				<p>
					<label for="googlekey">Google API key</label>
					<input type="text" name="googlekey" id="googlekey" value="<?php if (isset($globalGoogleAPIKey)) print $globalGoogleAPIKey; ?>" />
					<p class="help-block">Get a key <a href="https://developers.google.com/maps/documentation/javascript/get-api-key#get-an-api-key">here</a></p>
				</p>
			</div>
			<br />
			<div id="bing_data">
				<p>
					<label for="bingkey">Bing Map key</label>
					<input type="text" name="bingkey" id="bingkey" value="<?php if (isset($globalBingMapKey)) print $globalBingMapKey; ?>" />
					<p class="help-block">Get a key <a href="https://www.bingmapsportal.com/">here</a></p>
				</p>
			</div>
			<br />
			<div id="mapquest_data">
				<p>
					<label for="mapquestkey">MapQuest key</label>
					<input type="text" name="mapquestkey" id="mapquestkey" value="<?php if (isset($globalMapQuestKey)) print $globalMapQuestKey; ?>" />
					<p class="help-block">Get a key <a href="https://developer.mapquest.com/user/me/apps">here</a></p>
				</p>
			</div>
			<br />
			<div id="here_data">
				<p>
					<label for="hereappid">Here App_Id</label>
					<input type="text" name="hereappid" id="hereappid" value="<?php if (isset($globalHereappId)) print $globalHereappId; ?>" />
				</p>
				<p>
					<label for="hereappcode">Here App_Code</label>
					<input type="text" name="hereappcode" id="hereappcode" value="<?php if (isset($globalHereappCode)) print $globalHereappCode; ?>" />
				</p>
				<p class="help-block">Get a key <a href="https://developer.here.com/rest-apis/documentation/enterprise-map-tile/topics/quick-start.html">here</a></p>
			</div>
		</fieldset>
		<fieldset id="coverage">
			<legend>Coverage area</legend>
			<p>
				<label for="latitudemax">The maximum latitude (north)</label>
				<input type="text" name="latitudemax" id="latitudemax" value="<?php if (isset($globalLatitudeMax)) print $globalLatitudeMax; ?>" />
			</p>
			<p>
				<label for="latitudemin">The minimum latitude (south)</label>
				<input type="text" name="latitudemin" id="latitudemin" value="<?php if (isset($globalLatitudeMin)) print $globalLatitudeMin; ?>" />
			</p>
			<p>
				<label for="longitudemax">The maximum longitude (west)</label>
				<input type="text" name="longitudemax" id="longitudemax" value="<?php if (isset($globalLongitudeMax)) print $globalLongitudeMax; ?>" />
			</p>
			<p>
				<label for="longitudemin">The minimum longitude (east)</label>
				<input type="text" name="longitudemin" id="longitudemin" value="<?php if (isset($globalLongitudeMin)) print $globalLongitudeMin; ?>" />
			</p>
			<p>
				<label for="latitudecenter">The latitude center</label>
				<input type="text" name="latitudecenter" id="latitudecenter" value="<?php if (isset($globalCenterLatitude)) print $globalCenterLatitude; ?>" />
			</p>
			<p>
				<label for="longitudecenter">The longitude center</label>
				<input type="text" name="longitudecenter" id="longitudecenter" value="<?php if (isset($globalCenterLongitude)) print $globalCenterLongitude; ?>" />
			</p>
			<p>
				<label for="livezoom">Default Zoom on live map</label>
				<input type="number" name="livezoom" id="livezoom" value="<?php if (isset($globalLiveZoom)) print $globalLiveZoom; else print '9'; ?>" />
			</p>
			<p>
				<label for="squawk_country">Country for squawk usage</label>
				<select name="squawk_country" id="squawk_country">
					<option value="UK"<?php if (isset($globalSquawkCountry) && $globalSquawkCountry == 'UK') print ' selected '; ?>>UK</option>
					<option value="NZ"<?php if (isset($globalSquawkCountry) && $globalSquawkCountry == 'NZ') print ' selected '; ?>>NZ</option>
					<option value="US"<?php if (isset($globalSquawkCountry) && $globalSquawkCountry == 'US') print ' selected '; ?>>US</option>
					<option value="AU"<?php if (isset($globalSquawkCountry) && $globalSquawkCountry == 'AU') print ' selected '; ?>>AU</option>
					<option value="NL"<?php if (isset($globalSquawkCountry) && $globalSquawkCountry == 'NL') print ' selected '; ?>>NL</option>
					<option value="FR"<?php if (isset($globalSquawkCountry) && $globalSquawkCountry == 'FR') print ' selected '; ?>>FR</option>
					<option value="TR"<?php if (isset($globalSquawkCountry) && $globalSquawkCountry == 'TR') print ' selected '; ?>>TR</option>
				</select>
			</p>
		</fieldset>
		<fieldset id="zone">
			<legend>Zone of interest</legend>
			<p><i>Only put in DB flights that are inside a circle</i></p>
			<p>
				<label for="latitude">Center latitude</label>
				<input type="text" name="zoilatitude" id="latitude" value="<?php if (isset($globalDistanceIgnore['latitude'])) echo $globalDistanceIgnore['latitude']; ?>" />
			</p>
			<p>
				<label for="longitude">Center longitude</label>
				<input type="text" name="zoilongitude" id="longitude" value="<?php if (isset($globalDistanceIgnore['longitude'])) echo $globalDistanceIgnore['longitude']; ?>" />
			</p>
			<p>
				<label for="Distance">Distance (in km)</label>
				<input type="text" name="zoidistance" id="distance" value="<?php if (isset($globalDistanceIgnore['distance'])) echo $globalDistanceIgnore['distance']; ?>" />
			</p>
		</fieldset>
		<fieldset id="sourceloc">
			<legend>Sources location</legend>
			<table class="sources">
				<tr>
					<th>Name</th>
					<th>Latitude</th>
					<th>Longitude</th>
					<th>Altitude</th>
					<th>City</th>
					<th>Country</th>
					<th>Source name</th>
				</tr>
				<!--
		<?php
		    require_once(dirname(__FILE__).'/../require/class.Connection.php');
		    $Connection = new Connection();
		?>
				-->
		<?php
		    if ($Connection->db != NULL) {
			if ($Connection->tableExists('source_location')) {
			    require_once(dirname(__FILE__).'/../require/class.Source.php');
			    $Source = new Source();
			    $alllocations = $Source->getAllLocationInfo();
			    foreach ($alllocations as $location) {
		?>
				<tr>
	    				<input type="hidden" name="source_id[]" value="<?php print $location['id']; ?>" />
					<td><input type="text" name="source_name[]" value="<?php print $location['name']; ?>" /></td>
					<td><input type="text" name="source_latitude[]" value="<?php print $location['latitude']; ?>" /></td>
					<td><input type="text" name="source_longitude[]" value="<?php print $location['longitude']; ?>" /></td>
					<td><input type="text" name="source_altitude[]" value="<?php print $location['altitude']; ?>" /></td>
					<td><input type="text" name="source_city[]" value="<?php print $location['city']; ?>" /></td>
					<td><input type="text" name="source_country[]" value="<?php print $location['country']; ?>" /></td>
					<td><input type="text" name="source_ref[]" value="<?php print $location['source']; ?>" /></td>
				</tr>
		
		<?php
			    }
			}
		    }
		?>

				<tr>
					<td><input type="text" name="source_name[]" value="" /></td>
					<td><input type="text" name="source_latitude[]" value="" /></td>
					<td><input type="text" name="source_longitude[]" value="" /></td>
					<td><input type="text" name="source_altitude[]" value="" /></td>
					<td><input type="text" name="source_city[]" value="" /></td>
					<td><input type="text" name="source_country[]" value="" /></td>
					<td><input type="text" name="source_ref[]" value="" /></td>
				</tr>
			</table>
			<center>
				<input type="button" value="Add a row" class="add-row-source" />
				<input type="button" value="Remove last row" class="del-row-source" />
			</center>
		</fieldset>
		<fieldset id="datasource">
			<legend>Data source</legend>
			<p>
				<b>Virtual flights</b>
				<p>
				<p><i>If you choose IVAO, airlines names and logos will come from ivao.aero (you have to run install/populate_ivao.php to populate table with IVAO data)</i></p>
				<input type="checkbox" name="globalivao" id="ivao" value="ivao" onClick="datasource_js()" <?php if (isset($globalIVAO) && $globalIVAO) { ?>checked="checked" <?php } ?>/>
				<label for="ivao">IVAO</label>
				<input type="checkbox" name="globalvatsim" id="vatsim" value="vatsim" onClick="datasource_js()" <?php if (isset($globalVATSIM) && $globalVATSIM) { ?>checked="checked" <?php } ?>/>
				<label for="vatsim">VATSIM</label>
				<input type="checkbox" name="globalphpvms" id="phpvms" value="phpvms" onClick="datasource_js()" <?php if (isset($globalphpVMS) && $globalphpVMS) { ?>checked="checked" <?php } ?>/>
				<label for="phpvms">phpVMS</label>
				<input type="checkbox" name="globalvam" id="vam" value="vam" onClick="datasource_js()" <?php if (isset($globalVAM) && $globalVAM) { ?>checked="checked" <?php } ?>/>
				<label for="vam">Virtual Airline Manager</label>
				</p>
			</p><p>
				<b>Real flights</b>
				<p>
<!--
				<input type="radio" name="datasource" id="flightaware" value="flightaware" onClick="datasource_js()" <?php if (isset($globalFlightAware) && $globalFlightAware) { ?>checked="checked" <?php } ?>/>
				<label for="flightaware">FlightAware (not tested, no more supported no data feed available for test)</label>
-->
				<input type="checkbox" name="globalsbs" id="sbs" value="sbs" onClick="datasource_js()" <?php if (isset($globalSBS1) && $globalSBS1) { ?>checked="checked" <?php } ?> />
				<label for="sbs">ADS-B, SBS-1 format (dump1090 or SBS-1 compatible format)</label>
				<input type="checkbox" name="globalaprs" id="aprs" value="aprs" onClick="datasource_js()" <?php if (isset($globalAPRS) && $globalAPRS) { ?>checked="checked" <?php } ?> />
				<label for="sbs">APRS from glidernet</label>
				<input type="checkbox" name="acars" id="acars" value="acars" onClick="datasource_js()" <?php if (isset($globalACARS) && $globalACARS) { ?>checked="checked" <?php } ?> />
				<label for="acars">ACARS</label>
				</p>
			</p>
<!--
			<div id="flightaware_data">
				<p>
					<label for="flightawareusername">FlightAware username</label>
					<input type="text" name="flightawareusername" id="flightawareusername" value="<?php if (isset($globalFlightAwareUsername)) print $globalFlightAwareUsername; ?>" />
				</p>
				<p>
					<label for="flightawarepassword">FlightAware password/API key</label>
					<input type="text" name="flightawarepassword" id="flightawarepassword" value="<?php if (isset($globalFlightAwarePassword)) print $globalFlightAwarePassword; ?>" />
				</p>
			</div>
-->
<!--			<div id="sbs_data">
-->
				<fieldset id="sources">
					<legend>Sources</legend>
					<table id="SourceTable">
						<thead>
							<tr>
								<th>Host/URL</th>
								<th>Port</th>
								<th>Format</th>
								<th>Name</th>
								<th>Source Stats</th>
								<th>Action</th>
							</tr>
						</thead>
						<tbody>
<?php

		if (!isset($globalSources) && isset($globalSBS1Hosts)) {
			if (!is_array($globalSBS1Hosts)) {
				$globalSources[] = array('host' => $globalSBS1Hosts);
			} else {
				foreach ($globalSBS1Hosts as $host) {
					$globalSources[] = array('host' => $host);
				}
			}
		}
		$i = 0;
		if (isset($globalSources)) {
			foreach ($globalSources as $source) {
?>
							<tr>
								<?php
								    if (filter_var($source['host'],FILTER_VALIDATE_URL)) {
								?>
								<td><input type="text" name="host[]" id="host" value="<?php print $source['host']; ?>" /></td>
								<td><input type="number" name="port[]" id="port" value="<?php print $source['port']; ?>" /></td>
								<?php
								    } else {
									$hostport = explode(':',$source['host']);
									if (isset($hostport[1])) {
										$host = $hostport[0];
										$port = $hostport[1];
									} else {
										$host = $source['host'];
										$port = $source['port'];
									}
								?>
								<td><input type="text" name="host[]" id="host" value="<?php print $host; ?>" /></td>
								<td><input type="number" name="port[]" id="port" value="<?php print $port; ?>" /></td>
								<?php
								    }
								?>
								<td>
									<select name="format[]" id="format">
										<option value="auto" <?php if (!isset($source['format'])) print 'selected'; ?>>Auto</option>
										<option value="sbs" <?php if (isset($source['format']) && $source['format'] == 'sbs') print 'selected'; ?>>SBS</option>
										<option value="tsv" <?php if (isset($source['format']) && $source['format'] == 'tsv') print 'selected'; ?>>TSV</option>
										<option value="raw" <?php if (isset($source['format']) && $source['format'] == 'raw') print 'selected'; ?>>Raw</option>
										<option value="aprs" <?php if (isset($source['format']) && $source['format'] == 'aprs') print 'selected'; ?>>APRS</option>
										<option value="deltadbtxt" <?php if (isset($source['format']) && $source['format'] == 'deltadbtxt') print 'selected'; ?>>Radarcape deltadb.txt</option>
										<option value="vatsimtxt" <?php if (isset($source['format']) && $source['format'] == 'vatsimtxt') print 'selected'; ?>>Vatsim</option>
										<option value="aircraftlistjson" <?php if (isset($source['format']) && $source['format'] == 'aircraftlistjson') print 'selected'; ?>>Virtual Radar Server</option>
										<option value="phpvmacars" <?php if (isset($source['format']) && $source['format'] == 'phpvmacars') print 'selected'; ?>>phpVMS</option>
										<option value="vam" <?php if (isset($source['format']) && $source['format'] == 'vam') print 'selected'; ?>>Virtual Airlines Manager</option>
										<option value="whazzup" <?php if (isset($source['format']) && $source['format'] == 'whazzup') print 'selected'; ?>>IVAO</option>
										<option value="flightgearmp" <?php if (isset($source['format']) && $source['format'] == 'flightgearmp') print 'selected'; ?>>FlightGear Multiplayer</option>
										<option value="flightgearsp" <?php if (isset($source['format']) && $source['format'] == 'flightgearsp') print 'selected'; ?>>FlightGear Singleplayer</option>
										<option value="acars" <?php if (isset($source['format']) && $source['format'] == 'acars') print 'selected'; ?>>ACARS from acarsdec/acarsdeco2 over UDP</option>
										<option value="acarssbs3" <?php if (isset($source['format']) && $source['format'] == 'acarssbs3') print 'selected'; ?>>ACARS over SBS-3 TCP</option>
									</select>
								</td>
								<td><input type="text" name="name[]" id="name" value="<?php if (isset($source['name'])) print $source['name']; ?>" /></td>
								<td><input type="checkbox" name="sourcestats[]" id="sourcestats" title="Create statistics for the source like number of messages, distance,..." value="1" <?php if (isset($source['sourcestats']) && $source['sourcestats']) print 'checked'; ?> /></td>
								<td><input type="button" id="delhost" value="Delete" onclick="deleteRow(this)" /> <input type="button" id="addhost" value="Add" onclick="insRow()" /></td>
							</tr>
<?php
			}
		}
?>
							<tr>
								<td><input type="text" id="host" name="host[]" value="" /></td>
								<td><input type="number" id="port" name="port[]" value="" /></td>
								<td>
									<select name="format[]" id="format">
										<option value="auto">Auto</option>
										<option value="sbs">SBS</option>
										<option value="tsv">TSV</option>
										<option value="raw">Raw</option>
										<option value="aprs">APRS</option>
										<option value="deltadbtxt">Radarcape deltadb.txt</option>
										<option value="vatsimtxt">Vatsim</option>
										<option value="aircraftlistjson">Virtual Radar Server</option>
										<option value="phpvmacars">phpVMS</option>
										<option value="vam">Virtual Airlines Manager</option>
										<option value="whazzup">IVAO</option>
										<option value="flightgearmp">FlightGear Multiplayer</option>
										<option value="flightgearsp">FlightGear Singleplayer</option>
										<option value="acars">ACARS from acarsdec/acarsdeco2 over UDP</option>
										<option value="acarssbs3">ACARS SBS-3 over TCP</option>
									</select>
								</td>
								<td><input type="text" name="name[]" value="" id="name" /></td>
								<td><input type="checkbox" name="sourcestats[]" id="sourcestats" title="Create statistics for the source like number of messages, distance,..." value="1" /></td>
								<td><input type="button" id="addhost" value="Delete" onclick="deleteRow(this)" /> <input type="button" id="addhost" value="Add" onclick="insRow()" /></td>
							</tr>
						</tbody>
					</table>
				<p class="help-block">For working source statistics, the name of the source <b>MUST</b> be the same as the source name of a source location, else center coverage latitude and longitude is used as source position. This is not available/usable with virtual airlines.</p>
				<p class="help-block">FlightGear Singleplayer open an UDP server, the host should be <i>0.0.0.0</i>.</p>
				<p class="help-block">Virtual Airlines Manager need to use the file <i>install/vAM/VAM-json.php</i> and the url <i>http://yourvaminstall/VAM-json.php</i>.</p>
				</fieldset>
			</fieldset>
			<div id="acars_data">
				<fieldset>
					<legend>Source ACARS</legend>
					<p>Listen UDP server for acarsdec/acarsdeco2/... with <i>daemon-acars.php</i> script</p>
					<p>
						<label for="acarshost">ACARS UDP host</label>
						<input type="text" name="acarshost" id="acarshost" value="<?php if (isset($globalACARSHost)) print $globalACARSHost; ?>" />
					</p>
					<p>
						<label for="acarsport">ACARS UDP port</label>
						<input type="number" name="acarsport" id="acarsport" value="<?php if (isset($globalACARSPort)) print $globalACARSPort; ?>" />
					</p>
				</fieldset>
			</div>
		</fieldset>
		
		<fieldset id="optional">
			<legend>Optional configuration</legend>
			<p>
				<label for="crash">Add accident/incident support for real flights</label>
				<input type="checkbox" name="crash" id="crash" value="crash"<?php if ((isset($globalAccidents) && $globalAccidents) || !isset($globalAccidents)) { ?> checked="checked"<?php } ?> />
			</p>
			<br />
			<p>
				<label for="map3d">Enable map in 3D</label>
				<input type="checkbox" name="map3d" id="map3d" value="map3d"<?php if ((isset($globalMap3D) && $globalMap3D) || !isset($globalMap3D)) { ?> checked="checked"<?php } ?> />
				<p class="help-block">Bing map key is needed.</p>
			</p>
			<p>
				<label for="map3ddefault">Default to map in 3D</label>
				<input type="checkbox" name="map3ddefault" id="map3ddefault" value="map3ddefault"<?php if (isset($globalMap3Ddefault) && $globalMap3Ddefault) { ?> checked="checked"<?php } ?> />
			</p>
			<p>
				<label for="mapsatellites">Enable satellites in 3D map</label>
				<input type="checkbox" name="mapsatellites" id="mapsatellites" value="mapsatellites"<?php if ((isset($globalMapSatellites) && $globalMapSatellites) || !isset($globalMapSatellites)) { ?> checked="checked"<?php } ?> />
				<p class="help-block">Bing map key is needed.</p>
			</p>
			<br />
			<p>
				<label for="translate">Allow site translation</label>
				<input type="checkbox" name="translate" id="translate" value="translate"<?php if (isset($globalTranslate) && $globalTranslate) { ?> checked="checked"<?php } ?> />
				<p class="help-block">Display language available, else the site is only available in english.</p>
			</p>
			<br />
			<p>
				<label for="realairlines">Always use real airlines</label>
				<input type="checkbox" name="realairlines" id="realairlines" value="realairlines"<?php if (isset($globalUseRealAirlines) && $globalUseRealAirlines) { ?> checked="checked"<?php } ?> />
				<p class="help-block">Use real airlines even for IVAO or VATSIM.</p>
			</p>
			<br />
			<p>
				<label for="estimation">Planes animate between updates</label>
				<input type="checkbox" name="estimation" id="estimation" value="estimation"<?php if (isset($globalMapEstimation) && $globalMapEstimation) { ?> checked="checked"<?php } ?> />
				<p class="help-block">Estimate plane track between flights refresh.</p>
			</p>
			<br />
			<p>
				<label for="unitdistance">Unit for distance</label>
				<select name="unitdistance" id="unitdistance">
					<option value="km" <?php if (isset($globalUnitDistance) && $globalUnitDistance == 'km') { ?>selected="selected" <?php } ?>>Kilometres</option>
					<option value="nm" <?php if (isset($globalUnitDistance) && $globalUnitDistance == 'nm') { ?>selected="selected" <?php } ?>>Nautical Miles</option>
					<option value="mi" <?php if (isset($globalUnitDistance) && $globalUnitDistance == 'mi') { ?>selected="selected" <?php } ?>>Statute Miles</option>
				</select>
			</p>
			<p>
				<label for="unitaltitude">Unit for altitude</label>
				<select name="unitaltitude" id="unitaltitude">
					<option value="m" <?php if (isset($globalUnitAltitude) && $globalUnitAltitude == 'm') { ?>selected="selected" <?php } ?>>Metres</option>
					<option value="feet" <?php if (isset($globalUnitAltitude) && $globalUnitAltitude == 'feet') { ?>selected="selected" <?php } ?>>Feet</option>
				</select>
			</p>
			<p>
				<label for="unitspeed">Unit for speed</label>
				<select name="unitspeed" id="unitspeed">
					<option value="kmh" <?php if (isset($globalUnitSpeed) && $globalUnitSpeed == 'kmh') { ?>selected="selected" <?php } ?>>Kilometres/Hour</option>
					<option value="mph" <?php if (isset($globalUnitSpeed) && $globalUnitSpeed == 'mph') { ?>selected="selected" <?php } ?>>Miles/Hour</option>
					<option value="knots" <?php if (isset($globalUnitSpeed) && $globalUnitSpeed == 'knots') { ?>selected="selected" <?php } ?>>Knots</option>
				</select>
			</p>
			<br />
			<div id="optional_sbs">
			<p>
				<label for="schedules">Retrieve schedules from external websites</label>
				<input type="checkbox" name="schedules" id="schedules" value="schedules"<?php if (isset($globalSchedulesFetch) && $globalSchedulesFetch || !isset($globalSchedulesFetch)) { ?> checked="checked"<?php } ?> onClick="schedule_js()" />
				<p class="help-block">Not available for IVAO</p>
			</p>
			<br />
			<div id="schedules_options">
				<p>
					<label for="britishairways">British Airways API Key</label>
					<input type="text" name="britishairways" id="britishairways" value="<?php if (isset($globalBritishAirwaysKey)) print $globalBritishAirwaysKey; ?>" />
					<p class="help-block">Register an account on <a href="https://developer.ba.com/">https://developer.ba.com/</a></p>
				</p>
				<!--
				<p>
					<label for="transavia">Transavia Test API Consumer Key</label>
					<input type="text" name="transavia" id="transavia" value="<?php if (isset($globalTransaviaKey)) print $globalTransaviaKey; ?>" />
					<p class="help-block">Register an account on <a href="https://developer.transavia.com">https://developer.transavia.com</a></p>
				</p>
				-->
				<p>
					<div class="form-group">
						<b>Lufthansa API Key</b>
						<p>
							<label for="lufthansakey">Key</label>
							<input type="text" name="lufthansakey" id="lufthansakey" value="<?php if (isset($globalLufthansaKey['key'])) print $globalLufthansaKey['key']; ?>" />
						</p><p>
							<label for="lufthansasecret">Secret</label>
							<input type="text" name="lufthansasecret" id="lufthansasecret" value="<?php if (isset($globalLufthansaKey['secret'])) print $globalLufthansaKey['secret']; ?>" />
						</p>
					</div>
					<p class="help-block">Register an account on <a href="https://developer.lufthansa.com/page">https://developer.lufthansa.com/page</a></p>
				</p>
			</div>
			<br />
			<p>
				<label for="owner">Add private owners of aircrafts</label>
				<input type="checkbox" name="owner" id="owner" value="owner"<?php if (isset($globalOwner) && $globalOwner) { ?> checked="checked"<?php } ?> />
				<p class="help-block">Display also private owners of aircrafts, else only commercial owners are available</p>
			</p>
			</div>
			<br />
			<p>
				<label for="notam">Activate NOTAM support</label>
				<input type="checkbox" name="notam" id="notam" value="notam"<?php if (isset($globalNOTAM) && $globalNOTAM) { ?> checked="checked"<?php } ?> />
			</p>
			<p>
				<label for="notamsource">URL of your feed from notaminfo.com</label>
				<input type="text" name="notamsource" id="notamsource" value="<?php if (isset($globalNOTAMSource)) print $globalNOTAMSource; ?>" />
				<p class="help-block">If you want to use world NOTAM from FlightAirMap website, leave it blank</p>
			</p>
			<br />
			<p>
				<label for="metar">Activate METAR support</label>
				<input type="checkbox" name="metar" id="metar" value="metar"<?php if (isset($globalMETAR) && $globalMETAR) { ?> checked="checked"<?php } ?> />
			</p>
			<p>
				<label for="metarcycle">Activate METAR cycle support</label>
				<input type="checkbox" name="metarcycle" id="metarcycle" onClick="metarcycle_js()" value="metarcycle"<?php if (isset($globalMETARcycle) && $globalMETARcycle) { ?> checked="checked"<?php } ?> />
				<p class="help-block">Download feed from NOAA every hour. Need <i>scripts/update_db.php</i> in cron</p>
			</p>
			<div id="metarsrc">
				<p>
					<label for="metarsource">URL of your METAR source</label>
					<input type="text" name="metarsource" id="metarsource" value="<?php if (isset($globalMETARurl)) print $globalMETARurl; ?>" />
					<p class="help-block">Use {icao} to specify where we replace by airport icao. ex : http://metar.vatsim.net/metar.php?id={icao}</p>
				</p>
			</div>
			<br />
			<p>
				<label for="bitly">Bit.ly access token api (used in search page)</label>
				<input type="text" name="bitly" id="bitly" value="<?php if (isset($globalBitlyAccessToken)) print $globalBitlyAccessToken; ?>" />
			</p>
			<br />
			<p>
				<label for="waypoints">Add Waypoints, Airspace and countries data (about 45Mio in DB) <i>Need PostGIS if you use PostgreSQL</i></label>
				<input type="checkbox" name="waypoints" id="waypoints" value="waypoints"<?php if (!isset($globalWaypoints) || (isset($globalWaypoints) && $globalWaypoints)) { ?> checked="checked"<?php } ?> />
			</p>
			<br />
			<p>
				<label for="resetyearstats">Reset stats every years</label>
				<input type="checkbox" name="resetyearstats" id="resetyearsats" value="1"<?php if ((isset($globalDeleteLastYearStats) && $globalDeleteLastYearStats) || !isset($globalDeleteLastYearStats)) { ?> checked="checked"<?php } ?> />
				<p class="help-block">Reset count of aircraft types, airlines, registrations, callsigns, owners, pilots, departure and arrival airports</p>
			</p>
			<br />
			<p>
				<label for="archive">Archive all flights data</label>
				<input type="checkbox" name="archive" id="archive" value="archive"<?php if ((isset($globalArchive) && $globalArchive) || !isset($globalArchive)) { ?> checked="checked"<?php } ?> />
			</p>
			<p>
				<label for="archivemonths">Generate statistics, delete or put in archive flights older than xx months</label>
				<input type="number" name="archivemonths" id="archivemonths" value="<?php if (isset($globalArchiveMonths)) print $globalArchiveMonths; else echo '0'; ?>" />
				<p class="help-block">0 to disable, delete old flight if <i>Archive all flights data</i> is disabled</p>
			</p>
			<p>
				<label for="archiveyear">Generate statistics, delete or put in archive flights from previous year</label>
				<input type="checkbox" name="archiveyear" id="archiveyear" value="archiveyear"<?php if (isset($globalArchiveYear) && $globalArchiveYear) { ?> checked="checked"<?php } ?> />
				<p class="help-block">delete old flight if <i>Archive all flights data</i> is disabled</p>
			</p>
			<p>
				<label for="archivekeepmonths">Keep flights data for xx months in archive</label>
				<input type="number" name="archivekeepmonths" id="archivekeepmonths" value="<?php if (isset($globalArchiveKeepMonths)) print $globalArchiveKeepMonths; else echo '0'; ?>" />
				<p class="help-block">0 to disable</p>
			</p>
			<p>
				<label for="archivekeeptrackmonths">Keep flights track data for xx months in archive</label>
				<input type="number" name="archivekeeptrackmonths" id="archivekeeptrackmonths" value="<?php if (isset($globalArchiveKeepTrackMonths)) print $globalArchiveKeepTrackMonths; else echo '0'; ?>" />
				<p class="help-block">0 to disable, should be less or egal to <i>Keep flights data</i> value</p>
			</p>
			<br />
			<p>
				<label for="daemon">Use daemon-spotter.php as daemon</label>
				<input type="checkbox" name="daemon" id="daemon" value="daemon"<?php if ((isset($globalDaemon) && $globalDaemon) || !isset($globalDaemon)) { ?> checked="checked"<?php } ?> onClick="daemon_js()" />
				<p class="help-block">Uncheck if the script is running as cron job</p>
				<div id="cronends"> 
					<label for="cronend">Run script for xx seconds</label>
					<input type="number" name="cronend" id="cronend" value="<?php if (isset($globalCronEnd)) print $globalCronEnd; else print '0'; ?>" />
					<p class="help-block">Set to 0 to disable. Should be disabled if source is URL.</p>
				</div>
			</p>
			<br />
<!--
			<p>
				<label for="fork">Allow processes fork</label>
				<input type="checkbox" name="fork" id="fork" value="fork"<?php if ((isset($globalFork) && $globalFork) || !isset($globalFork)) { ?> checked="checked"<?php } ?> />
				<p class="help-block">Used for schedule</p>
			</p>
			<br />
-->
			<p>
				<label for="colormap">Show altitudes on map with several colors</label>
				<input type="checkbox" name="colormap" id="colormap" value="colormap"<?php if ((isset($globalMapAltitudeColor) && $globalMapAltitudeColor) || !isset($globalMapAltitudeColor)) { ?> checked="checked"<?php } ?> />
			</p>
<!--
			<p>
				<label for="mappopup">Show flights info in popup</label>
				<input type="checkbox" name="mappopup" id="mappopup" value="mappopup"<?php if ((isset($globalMapPopup) && $globalMapPopup)) { ?> checked="checked"<?php } ?> />
			</p>
			<p>
				<label for="airportpopup">Show airport info in popup</label>
				<input type="checkbox" name="airportpopup" id="airportpopup" value="airportpopup"<?php if ((isset($globalAirportPopup) && $globalAirportPopup)) { ?> checked="checked"<?php } ?> />
			</p>
-->
			<br />
			<p>
				<label for="maphistory">Always show path of flights (else only when flight is selected)</label>
				<input type="checkbox" name="maphistory" id="maphistory" value="maphistory"<?php if ((isset($globalMapHistory) && $globalMapHistory) || !isset($globalMapHistory)) { ?> checked="checked"<?php } ?> />
			</p>
			<br />
			<p>
				<label for="flightroute">Show route of flights when selected</label>
				<input type="checkbox" name="flightroute" id="flightroute" value="flightroute"<?php if ((isset($globalMapRoute) && $globalMapRoute) || !isset($globalMapRoute)) { ?> checked="checked"<?php } ?> />
			</p>
			<br />
			<p>
				<label for="allflights">Put all flights in DB even without coordinates</label>
				<input type="checkbox" name="allflights" id="allflights" value="allflights"<?php if ((isset($globalAllFlights) && $globalAllFlights) || !isset($globalAllFlights)) { ?> checked="checked"<?php } ?> />
			</p>
			<br />
			<p>
				<label for="refresh">Show flights detected since xxx seconds</label>
				<input type="number" name="refresh" id="refresh" value="<?php if (isset($globalLiveInterval)) echo $globalLiveInterval; else echo '200'; ?>" />
			</p>
			<p>
				<label for="maprefresh">Live map refresh (in seconds)</label>
				<input type="number" name="maprefresh" id="maprefresh" value="<?php if (isset($globalMapRefresh)) echo $globalMapRefresh; else echo '30'; ?>" />
			</p>
			<p>
				<label for="mapidle">Map idle timeout (in minutes)</label>
				<input type="number" name="mapidle" id="mapidle" value="<?php if (isset($globalMapIdleTimeout)) echo $globalMapIdleTimeout; else echo '30'; ?>" />
				<p class="help-block">0 to disable</p>
			</p>
			<br />
			<p>
				<label for="closestmindist">Distance to airport set as arrival (in km)</label>
				<input type="number" name="closestmindist" id="closestmindist" value="<?php if (isset($globalClosestMinDist)) echo $globalClosestMinDist; else echo '50'; ?>" />
			</p>
			<br />
			<p>
				<label for="aircraftsize">Size of aircraft icon on map (default to 30px if zoom > 7 else 15px), empty to default</label>
				<input type="number" name="aircraftsize" id="aircraftsize" value="<?php if (isset($globalAircraftSize)) echo $globalAircraftSize;?>" />
			</p>
			<br />
			<p>
			<?php 
			    if (extension_loaded('gd') && function_exists('gd_info')) {
			?>
				<label for="aircrafticoncolor">Color of aircraft icon on map</label>
				<input type="color" name="aircrafticoncolor" id="aircrafticoncolor" value="#<?php if (isset($globalAircraftIconColor)) echo $globalAircraftIconColor; else echo '1a3151'; ?>" />
			<?php
				if (!is_writable('../cache')) {
			?>
				<b>The directory cache is not writable, aircraft icon will not be cached</b>
			<?php
				}
			    } else {
			?>
				<b>PHP GD is not installed, you can t change color of aircraft icon on map</b>
			<?php
			    }
			?>
			</p>
			<br />
			<p>
				<label for="airportzoom">Zoom level minimum to see airports icons</label>
				<div class="range">
					<input type="range" name="airportzoom" id="airportzoom" value="<?php if (isset($globalAirportZoom)) echo $globalAirportZoom; else echo '7'; ?>" />
					<output id="range"><?php if (isset($globalAirportZoom)) echo $globalAirportZoom; else echo '7'; ?></output>
				</div>
			</p>
		</fieldset>
		<input type="submit" name="submit" value="Create/Update database & write setup" />
	</form>
	<p>
	    If it fails to populate tables, you can run inside console <i>install/install_db.php</i> or <i>install/install_db.sh</i>.
	</p>
<?php
	require('../footer.php');
        exit;
}
	
$settings = array();
$settings_comment = array();
$error = '';

if (isset($_POST['dbtype'])) {
	$dbtype = filter_input(INPUT_POST,'dbtype',FILTER_SANITIZE_STRING);
	$dbroot = filter_input(INPUT_POST,'dbroot',FILTER_SANITIZE_STRING);
	$dbrootpass = filter_input(INPUT_POST,'dbrootpass',FILTER_SANITIZE_STRING);
	$dbname = filter_input(INPUT_POST,'dbname',FILTER_SANITIZE_STRING);
	$dbuser = filter_input(INPUT_POST,'dbuser',FILTER_SANITIZE_STRING);
	$dbuserpass = filter_input(INPUT_POST,'dbuserpass',FILTER_SANITIZE_STRING);
	$dbhost = filter_input(INPUT_POST,'dbhost',FILTER_SANITIZE_STRING);
	$dbport = filter_input(INPUT_POST,'dbport',FILTER_SANITIZE_STRING);

	if ($dbtype == 'mysql' && !extension_loaded('pdo_mysql')) $error .= 'Mysql driver for PDO must be loaded';
	if ($dbtype == 'pgsql' && !extension_loaded('pdo_pgsql')) $error .= 'PosgreSQL driver for PDO must be loaded';
	
	$_SESSION['database_root'] = $dbroot;
	$_SESSION['database_rootpass'] = $dbrootpass;
	/*
	if ($error == '' && isset($_POST['createdb']) && $dbname != '' && $dbuser != '' && $dbuserpass != '') {
		if ($dbroot != '' && $dbrootpass != '') {
			$result = create_db::create_database($dbroot,$dbrootpass,$dbuser,$dbuserpass,$dbname,$dbtype,$dbhost);
			if ($result != '') $error .= $result;
		}
		if ($error == '') {
			//$error .= create_db::import_all_db('../db/');
			$settings = array_merge($settings,array('globalDBdriver' => $dbtype,'globalDBhost' => $dbhost,'globalDBport' => $dbport,'globalDBuser' => $dbuser,'globalDBpass' => $dbuserpass,'globalDBname' => $dbname));
		}
	} else $settings = array_merge($settings,array('globalDBdriver' => $dbtype,'globalDBhost' => $dbhost,'globalDBuser' => $dbuser,'globalDBport' => $dbport,'globalDBpass' => $dbuserpass,'globalDBname' => $dbname));
	*/
	
	$settings = array_merge($settings,array('globalDBdriver' => $dbtype,'globalDBhost' => $dbhost,'globalDBuser' => $dbuser,'globalDBport' => $dbport,'globalDBpass' => $dbuserpass,'globalDBname' => $dbname));

	$sitename = filter_input(INPUT_POST,'sitename',FILTER_SANITIZE_STRING);
	$siteurl = filter_input(INPUT_POST,'siteurl',FILTER_SANITIZE_STRING);
	$timezone = filter_input(INPUT_POST,'timezone',FILTER_SANITIZE_STRING);
	$language = filter_input(INPUT_POST,'language',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalName' => $sitename,'globalURL' => $siteurl, 'globalTimezone' => $timezone,'globalLanguage' => $language));

	$mapprovider = filter_input(INPUT_POST,'mapprovider',FILTER_SANITIZE_STRING);
	$mapboxid = filter_input(INPUT_POST,'mapboxid',FILTER_SANITIZE_STRING);
	$mapboxtoken = filter_input(INPUT_POST,'mapboxtoken',FILTER_SANITIZE_STRING);
	$googlekey = filter_input(INPUT_POST,'googlekey',FILTER_SANITIZE_STRING);
	$bingkey = filter_input(INPUT_POST,'bingkey',FILTER_SANITIZE_STRING);
	$mapquestkey = filter_input(INPUT_POST,'mapquestkey',FILTER_SANITIZE_STRING);
	$hereappid = filter_input(INPUT_POST,'hereappid',FILTER_SANITIZE_STRING);
	$hereappcode = filter_input(INPUT_POST,'hereappcode',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalMapProvider' => $mapprovider,'globalMapboxId' => $mapboxid,'globalMapboxToken' => $mapboxtoken,'globalGoogleAPIKey' => $googlekey,'globalBingMapKey' => $bingkey,'globalHereappID' => $hereappid,'globalHereappCode' => $hereappcode,'globalMapQuestKey' => $mapquestkey));
	
	$latitudemax = filter_input(INPUT_POST,'latitudemax',FILTER_SANITIZE_STRING);
	$latitudemin = filter_input(INPUT_POST,'latitudemin',FILTER_SANITIZE_STRING);
	$longitudemax = filter_input(INPUT_POST,'longitudemax',FILTER_SANITIZE_STRING);
	$longitudemin = filter_input(INPUT_POST,'longitudemin',FILTER_SANITIZE_STRING);
	$livezoom = filter_input(INPUT_POST,'livezoom',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalLatitudeMax' => $latitudemax,'globalLatitudeMin' => $latitudemin,'globalLongitudeMax' => $longitudemax,'globalLongitudeMin' => $longitudemin,'globalLiveZoom' => $livezoom));

	$squawk_country = filter_input(INPUT_POST,'squawk_country',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalSquawkCountry' => $squawk_country));

	$latitudecenter = filter_input(INPUT_POST,'latitudecenter',FILTER_SANITIZE_STRING);
	$longitudecenter = filter_input(INPUT_POST,'longitudecenter',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalCenterLatitude' => $latitudecenter,'globalCenterLongitude' => $longitudecenter));

	$acars = filter_input(INPUT_POST,'acars',FILTER_SANITIZE_STRING);
	if ($acars == 'acars') {
		$settings = array_merge($settings,array('globalACARS' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalACARS' => 'FALSE'));
	}

	$flightawareusername = filter_input(INPUT_POST,'flightawareusername',FILTER_SANITIZE_STRING);
	$flightawarepassword = filter_input(INPUT_POST,'flightawarepassword',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalFlightAwareUsername' => $flightawareusername,'globalFlightAwarePassword' => $flightawarepassword));
	
	$source_name = $_POST['source_name'];
	$source_latitude = $_POST['source_latitude'];
	$source_longitude = $_POST['source_longitude'];
	$source_altitude = $_POST['source_altitude'];
	$source_city = $_POST['source_city'];
	$source_country = $_POST['source_country'];
	$source_ref = $_POST['source_ref'];
	if (isset($source_id)) $source_id = $_POST['source_id'];
	else $source_id = array();
	
	$sources = array();
	foreach ($source_name as $keys => $name) {
	    if (isset($source_id[$keys])) $sources[] = array('name' => $name,'latitude' => $source_latitude[$keys],'longitude' => $source_longitude[$keys],'altitude' => $source_altitude[$keys],'city' => $source_city[$keys],'country' => $source_country[$keys],'id' => $source_id[$keys],'source' => $source_ref[$keys]);
	    else $sources[] = array('name' => $name,'latitude' => $source_latitude[$keys],'longitude' => $source_longitude[$keys],'altitude' => $source_altitude[$keys],'city' => $source_city[$keys],'country' => $source_country[$keys],'source' => $source_ref[$keys]);
	}
	if (count($sources) > 0) $_SESSION['sources'] = $sources;

	//$sbshost = filter_input(INPUT_POST,'sbshost',FILTER_SANITIZE_STRING);
	//$sbsport = filter_input(INPUT_POST,'sbsport',FILTER_SANITIZE_NUMBER_INT);
	//$sbsurl = filter_input(INPUT_POST,'sbsurl',FILTER_SANITIZE_URL);
	/*
	$sbshost = $_POST['sbshost'];
	$sbsport = $_POST['sbsport'];
	$sbsurl = $_POST['sbsurl'];
	*/

	$globalvatsim = filter_input(INPUT_POST,'globalvatsim',FILTER_SANITIZE_STRING);
	$globalivao = filter_input(INPUT_POST,'globalivao',FILTER_SANITIZE_STRING);
	$globalphpvms = filter_input(INPUT_POST,'globalphpvms',FILTER_SANITIZE_STRING);
	$globalvam = filter_input(INPUT_POST,'globalvam',FILTER_SANITIZE_STRING);
	$globalsbs = filter_input(INPUT_POST,'globalsbs',FILTER_SANITIZE_STRING);
	$globalaprs = filter_input(INPUT_POST,'globalaprs',FILTER_SANITIZE_STRING);
	$datasource = filter_input(INPUT_POST,'datasource',FILTER_SANITIZE_STRING);

/*	
	$globalSBS1Hosts = array();
//	if ($datasource != 'ivao' && $datasource != 'vatsim') {
	if ($globalsbs == 'sbs') {
	    foreach ($sbshost as $key => $host) {
		if ($host != '') $globalSBS1Hosts[] = $host.':'.$sbsport[$key];
	    }
	}
	if (count($sbsurl) > 0 && $sbsurl[0] != '') {
	    $sbsurl = array_filter($sbsurl);
	    $globalSBS1Hosts = array_merge($globalSBS1Hosts,$sbsurl);
	}
	$settings = array_merge($settings,array('globalSBS1Hosts' => $globalSBS1Hosts));
*/
	$settings_comment = array_merge($settings_comment,array('globalSBS1Hosts'));
	$host = $_POST['host'];
	$port = $_POST['port'];
	$name = $_POST['name'];
	$format = $_POST['format'];
	if (isset($_POST['sourcestats'])) $sourcestats = $_POST['sourcestats'];
	else $sourcestats = array();
	$gSources = array();
	foreach ($host as $key => $h) {
		if (isset($sourcestats[$key]) && $sourcestats[$key] == 1) $cov = 'TRUE';
		else $cov = 'FALSE';
		if ($h != '') $gSources[] = array('host' => $h, 'port' => $port[$key],'name' => $name[$key],'format' => $format[$key],'sourcestats' => $cov);
	}
	$settings = array_merge($settings,array('globalSources' => $gSources));

/*
	$sbstimeout = filter_input(INPUT_POST,'sbstimeout',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalSourcesTimeOut' => $sbstimeout));
*/
	$acarshost = filter_input(INPUT_POST,'acarshost',FILTER_SANITIZE_STRING);
	$acarsport = filter_input(INPUT_POST,'acarsport',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalACARSHost' => $acarshost,'globalACARSPort' => $acarsport));

	$bitly = filter_input(INPUT_POST,'bitly',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalBitlyAccessToken' => $bitly));

	$notamsource = filter_input(INPUT_POST,'notamsource',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalNOTAMSource' => $notamsource));
	$metarsource = filter_input(INPUT_POST,'metarsource',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalMETARurl' => $metarsource));

	$zoilatitude = filter_input(INPUT_POST,'zoilatitude',FILTER_SANITIZE_STRING);
	$zoilongitude = filter_input(INPUT_POST,'zoilongitude',FILTER_SANITIZE_STRING);
	$zoidistance = filter_input(INPUT_POST,'zoidistance',FILTER_SANITIZE_NUMBER_INT);
	if ($zoilatitude != '' && $zoilongitude != '' && $zoidistance != '') {
		$settings = array_merge($settings,array('globalDistanceIgnore' => array('latitude' => $zoilatitude,'longitude' => $zoilongitude,'distance' => $zoidistance)));
	} else $settings = array_merge($settings,array('globalDistanceIgnore' => array()));

	$refresh = filter_input(INPUT_POST,'refresh',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalLiveInterval' => $refresh));
	$maprefresh = filter_input(INPUT_POST,'maprefresh',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalMapRefresh' => $maprefresh));
	$mapidle = filter_input(INPUT_POST,'mapidle',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalMapIdleTimeout' => $mapidle));
	$closestmindist = filter_input(INPUT_POST,'closestmindist',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalClosestMinDist' => $closestmindist));

	$aircraftsize = filter_input(INPUT_POST,'aircraftsize',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalAircraftSize' => $aircraftsize));

	$archivemonths = filter_input(INPUT_POST,'archivemonths',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalArchiveMonths' => $archivemonths));
	
	$archiveyear = filter_input(INPUT_POST,'archiveyear',FILTER_SANITIZE_STRING);
	if ($archiveyear == "archiveyear") {
		$settings = array_merge($settings,array('globalArchiveYear' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalArchiveYear' => 'FALSE'));
	}
	$archivekeepmonths = filter_input(INPUT_POST,'archivekeepmonths',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalArchiveKeepMonths' => $archivekeepmonths));
	$archivekeeptrackmonths = filter_input(INPUT_POST,'archivekeeptrackmonths',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalArchiveKeepTrackMonths' => $archivekeeptrackmonths));

	$britishairways = filter_input(INPUT_POST,'britishairways',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalBritishAirwaysKey' => $britishairways));
	$transavia = filter_input(INPUT_POST,'transavia',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalTransaviaKey' => $transavia));

	$lufthansakey = filter_input(INPUT_POST,'lufthansakey',FILTER_SANITIZE_STRING);
	$lufthansasecret = filter_input(INPUT_POST,'lufthansasecret',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalLufthansaKey' => array('key' => $lufthansakey,'secret' => $lufthansasecret)));

	// Create in settings.php keys not yet configurable if not already here
	//if (!isset($globalImageBingKey)) $settings = array_merge($settings,array('globalImageBingKey' => ''));
	if (!isset($globalDebug)) $settings = array_merge($settings,array('globalDebug' => 'TRUE'));

	$resetyearstats = filter_input(INPUT_POST,'resetyearstats',FILTER_SANITIZE_STRING);
	if ($resetyearstats == 'resetyearstats') {
		$settings = array_merge($settings,array('globalDeleteLastYearStats' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalDeleteLastYearStats' => 'FALSE'));
	}

	$archive = filter_input(INPUT_POST,'archive',FILTER_SANITIZE_STRING);
	if ($archive == 'archive') {
		$settings = array_merge($settings,array('globalArchive' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalArchive' => 'FALSE'));
	}
	$daemon = filter_input(INPUT_POST,'daemon',FILTER_SANITIZE_STRING);
	if ($daemon == 'daemon') {
		$settings = array_merge($settings,array('globalDaemon' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalDaemon' => 'FALSE'));
	}
	$schedules = filter_input(INPUT_POST,'schedules',FILTER_SANITIZE_STRING);
	if ($schedules == 'schedules') {
		$settings = array_merge($settings,array('globalSchedulesFetch' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalSchedulesFetch' => 'FALSE'));
	}

/*
	$datasource = filter_input(INPUT_POST,'datasource',FILTER_SANITIZE_STRING);
	if ($datasource == 'flightaware') {
		$settings = array_merge($settings,array('globalFlightAware' => 'TRUE','globalSBS1' => 'FALSE'));
	} else {
		$settings = array_merge($settings,array('globalFlightAware' => 'FALSE','globalSBS1' => 'TRUE'));
	}
*/
	$settings = array_merge($settings,array('globalFlightAware' => 'FALSE'));
	if ($globalsbs == 'sbs') $settings = array_merge($settings,array('globalSBS1' => 'TRUE'));
	else $settings = array_merge($settings,array('globalSBS1' => 'FALSE'));
	if ($globalaprs == 'aprs') $settings = array_merge($settings,array('globalAPRS' => 'TRUE'));
	else $settings = array_merge($settings,array('globalAPRS' => 'FALSE'));
	if ($globalivao == 'ivao') {
		//$settings = array_merge($settings,array('globalIVAO' => 'TRUE','globalVATSIM' => 'FALSE'));
		$settings = array_merge($settings,array('globalIVAO' => 'TRUE'));
	} else $settings = array_merge($settings,array('globalIVAO' => 'FALSE'));
	if ($globalvatsim == 'vatsim') {
		//$settings = array_merge($settings,array('globalVATSIM' => 'TRUE','globalIVAO' => 'FALSE'));
		$settings = array_merge($settings,array('globalVATSIM' => 'TRUE'));
	} else $settings = array_merge($settings,array('globalVATSIM' => 'FALSE'));
	if ($globalphpvms == 'phpvms') {
		$settings = array_merge($settings,array('globalphpVMS' => 'TRUE'));
	} else $settings = array_merge($settings,array('globalphpVMS' => 'FALSE'));
	if ($globalvam == 'vam') {
		$settings = array_merge($settings,array('globalVAM' => 'TRUE'));
	} else $settings = array_merge($settings,array('globalVAM' => 'FALSE'));
	if ($globalvatsim == 'vatsim' || $globalivao == 'ivao' || $globalphpvms == 'phpvms') {
		$settings = array_merge($settings,array('globalSchedulesFetch' => 'FALSE','globalTranslationFetch' => 'FALSE'));
	} else $settings = array_merge($settings,array('globalSchedulesFetch' => 'TRUE','globalTranslationFetch' => 'TRUE'));
	


	$notam = filter_input(INPUT_POST,'notam',FILTER_SANITIZE_STRING);
	if ($notam == 'notam') {
		$settings = array_merge($settings,array('globalNOTAM' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalNOTAM' => 'FALSE'));
	}
	$owner = filter_input(INPUT_POST,'owner',FILTER_SANITIZE_STRING);
	if ($owner == 'owner') {
		$settings = array_merge($settings,array('globalOwner' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalOwner' => 'FALSE'));
	}
	$map3d = filter_input(INPUT_POST,'map3d',FILTER_SANITIZE_STRING);
	if ($map3d == 'map3d') {
		$settings = array_merge($settings,array('globalMap3D' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMap3D' => 'FALSE'));
	}
	$crash = filter_input(INPUT_POST,'crash',FILTER_SANITIZE_STRING);
	if ($crash == 'crash') {
		$settings = array_merge($settings,array('globalAccidents' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalAccidents' => 'FALSE'));
	}
	$mapsatellites = filter_input(INPUT_POST,'mapsatellites',FILTER_SANITIZE_STRING);
	if ($mapsatellites == 'mapsatellites') {
		$settings = array_merge($settings,array('globalMapSatellites' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMapSatellites' => 'FALSE'));
	}
	$map3ddefault = filter_input(INPUT_POST,'map3ddefault',FILTER_SANITIZE_STRING);
	if ($map3ddefault == 'map3ddefault') {
		$settings = array_merge($settings,array('globalMap3Ddefault' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMap3Ddefault' => 'FALSE'));
	}
	$translate = filter_input(INPUT_POST,'translate',FILTER_SANITIZE_STRING);
	if ($translate == 'translate') {
		$settings = array_merge($settings,array('globalTranslate' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalTranslate' => 'FALSE'));
	}
	$realairlines = filter_input(INPUT_POST,'realairlines',FILTER_SANITIZE_STRING);
	if ($realairlines == 'realairlines') {
		$settings = array_merge($settings,array('globalUseRealAirlines' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalUseRealAirlines' => 'FALSE'));
	}
	$estimation = filter_input(INPUT_POST,'estimation',FILTER_SANITIZE_STRING);
	if ($estimation == 'estimation') {
		$settings = array_merge($settings,array('globalMapEstimation' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMapEstimation' => 'FALSE'));
	}
	$metar = filter_input(INPUT_POST,'metar',FILTER_SANITIZE_STRING);
	if ($metar == 'metar') {
		$settings = array_merge($settings,array('globalMETAR' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMETAR' => 'FALSE'));
	}
	$metarcycle = filter_input(INPUT_POST,'metarcycle',FILTER_SANITIZE_STRING);
	if ($metarcycle == 'metarcycle') {
		$settings = array_merge($settings,array('globalMETARcycle' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMETARcycle' => 'FALSE'));
	}
	$fork = filter_input(INPUT_POST,'fork',FILTER_SANITIZE_STRING);
	if ($fork == 'fork') {
		$settings = array_merge($settings,array('globalFork' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalFork' => 'FALSE'));
	}

	$colormap = filter_input(INPUT_POST,'colormap',FILTER_SANITIZE_STRING);
	if ($colormap == 'colormap') {
		$settings = array_merge($settings,array('globalMapAltitudeColor' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMapAltitudeColor' => 'FALSE'));
	}
	
	if (isset($_POST['aircrafticoncolor'])) {
		$aircrafticoncolor = filter_input(INPUT_POST,'aircrafticoncolor',FILTER_SANITIZE_STRING);
		$settings = array_merge($settings,array('globalAircraftIconColor' => substr($aircrafticoncolor,1)));
	}

	$airportzoom = filter_input(INPUT_POST,'airportzoom',FILTER_SANITIZE_NUMBER_INT);
	$settings = array_merge($settings,array('globalAirportZoom' => $airportzoom));

	$unitdistance = filter_input(INPUT_POST,'unitdistance',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalUnitDistance' => $unitdistance));
	$unitaltitude = filter_input(INPUT_POST,'unitaltitude',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalUnitAltitude' => $unitaltitude));
	$unitspeed = filter_input(INPUT_POST,'unitspeed',FILTER_SANITIZE_STRING);
	$settings = array_merge($settings,array('globalUnitSpeed' => $unitspeed));

	$mappopup = filter_input(INPUT_POST,'mappopup',FILTER_SANITIZE_STRING);
	if ($mappopup == 'mappopup') {
		$settings = array_merge($settings,array('globalMapPopup' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMapPopup' => 'FALSE'));
	}
	$airportpopup = filter_input(INPUT_POST,'airportpopup',FILTER_SANITIZE_STRING);
	if ($airportpopup == 'airportpopup') {
		$settings = array_merge($settings,array('globalAirportPopup' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalAirportPopup' => 'FALSE'));
	}
	$maphistory = filter_input(INPUT_POST,'maphistory',FILTER_SANITIZE_STRING);
	if ($maphistory == 'maphistory') {
		$settings = array_merge($settings,array('globalMapHistory' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMapHistory' => 'FALSE'));
	}
	$flightroute = filter_input(INPUT_POST,'flightroute',FILTER_SANITIZE_STRING);
	if ($flightroute == 'flightroute') {
		$settings = array_merge($settings,array('globalMapRoute' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalMapRoute' => 'FALSE'));
	}
	$allflights = filter_input(INPUT_POST,'allflights',FILTER_SANITIZE_STRING);
	if ($allflights == 'allflights') {
		$settings = array_merge($settings,array('globalAllFlights' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalAllFlights' => 'FALSE'));
	}
	$waypoints = filter_input(INPUT_POST,'waypoints',FILTER_SANITIZE_STRING);
	if ($waypoints == 'waypoints') {
		$settings = array_merge($settings,array('globalWaypoints' => 'TRUE'));
	} else {
		$settings = array_merge($settings,array('globalWaypoints' => 'FALSE'));
	}

	if (!isset($globalTransaction)) $settings = array_merge($settings,array('globalTransaction' => 'TRUE'));

	// Set some defaults values...
	if (!isset($globalAircraftImageSources)) {
	    $globalAircraftImageSources = array('ivaomtl','wikimedia','airportdata','deviantart','flickr','bing','jetphotos','planepictures','planespotters');
	    $settings = array_merge($settings,array('globalAircraftImageSources' => $globalAircraftImageSources));
	}

	if (!isset($globalSchedulesSources)) {
	    $globalSchedulesSources = array('flightmapper','costtotravel','flightradar24','flightaware');
    	    $settings = array_merge($settings,array('globalSchedulesSources' => $globalSchedulesSources));
    	}

	$settings = array_merge($settings,array('globalInstalled' => 'TRUE'));

	if ($error == '') settings::modify_settings($settings);
	if ($error == '') settings::comment_settings($settings_comment);
	if ($error != '') {
		print '<div class="info column">'.$error.'</div>';
		require('../footer.php');
		exit;
	} else {
		if (isset($_POST['waypoints']) && $_POST['waypoints'] == 'waypoints') $_SESSION['waypoints'] = 1;
		if (isset($_POST['owner']) && $_POST['owner'] == 'owner') $_SESSION['owner'] = 1;
		if (isset($_POST['createdb'])) {
			$_SESSION['install'] = 'database_create';
		} else {
			require_once(dirname(__FILE__).'/../require/class.Connection.php');
			$Connection = new Connection();
			if ($Connection->latest() && isset($_POST['waypoints']) && $_POST['waypoints'] == 'waypoints') {
				if ($Connection->tableExists('airspace') === false) {
					$_SESSION['install'] = 'waypoints';
				} else {
					$_SESSION['install'] = 'database_import';
				}
			} else {
				$_SESSION['install'] = 'database_import';
			}
		}
		//require('../footer.php');
		print '<div class="info column"><ul>';
		 /*
		if (isset($_POST['createdb'])) {
			$_SESSION['done'] = array('Create database','Write configuration');
			print '<li>Create database....<strong>SUCCESS</strong></li>';
		} else $_SESSION['done'] = array('Write configuration');
		*/
		$_SESSION['done'] = array('Write configuration');
		print '<li>Write configuration....<img src="../images/loading.gif" /></li></ul></div>';
#		flush();
#		@ob_flush();
#		sleep(10);
		print "<script>console.log('Configuration writed...');setTimeout(window.location = 'index.php?".rand()."&next=".$_SESSION['install']."',10000);</script>";
//		header("Location: index.php?".rand());
//		require('../footer.php');
	}
} else if (isset($_SESSION['install']) && $_SESSION['install'] != 'finish') {
	print '<div class="info column">';
	print '<ul><div id="step">';
	$pop = false;
	$popi = false;
	$popw = false;
	foreach ($_SESSION['done'] as $done) {
	    print '<li>'.$done.'....<strong>SUCCESS</strong></li>';
	    if ($done == 'Create database') $pop = true;
	    if ($_SESSION['install'] == 'database_create') $pop = true;
	    if ($_SESSION['install'] == 'database_import') $popi = true;
	    if ($_SESSION['install'] == 'waypoints') $popw = true;
	}
	if ($pop) {
	    sleep(5);
	    print '<li>Create database....<img src="../images/loading.gif" /></li>';
	} else if ($popi) {
	    sleep(5);
	    print '<li>Create and import tables....<img src="../images/loading.gif" /></li>';
	} else if ($popw) {
	    sleep(5);
	    print '<li>Populate waypoints database....<img src="../images/loading.gif" /></li>';
	} else print '<li>Update schema if needed....<img src="../images/loading.gif" /></li>';
	print '</div></ul>';
	print '<div id="error"></div>';
/*	foreach ($_SESSION['done'] as $done) {
	    print '<li>'.$done.'....<strong>SUCCESS</strong></li>';
	}
	print '<li>'.$SESSION['next'].'....<img src="../images/loading.gif" /></li>';

	if ($error != '') {
		print '<div class="info column"><span class="error"><strong>Error</strong>'.$error.'</span></div>';
		require('../footer.php');
                exit;
	}
*/
?>
    <script language="JavaScript">
		function installaction() {
		    $.ajax({
			url:'install-action.php',
			dataType: 'json',
			async: true,
			success: function(result) {
			    console.log(result);
			    $('#step').html('');
			    result['done'].forEach(function(done) {
				$('#step').append('<li>'+ done +'....<strong>SUCCESS</strong></li>');
			    });
			    if (result['error'] != '') {
				setTimeout(function(){
				    console.log('error !');
				    $('#error').html('<p><b>Error : </b> ' + result['error'] + '</p>');
				}, 1000);
				loop = false;
			    } else if (result['next'] != 'finish') {
				$('#step').append('<li>'+ result['next'] +'....<img src="../images/loading.gif" /></li>');
				installaction();
			    } else if (result['install'] == 'finish') {
				console.log('finish !!!');
				$('#step').append('<li>Reloading page to check all is now ok....<img src="../images/loading.gif" /></li>');
				$(location).attr('href','index.php?next=finish');
				loop = false;
			    }
			},
			error: function(XMLHttpRequest, textStatus, errorThrown) { 
				console.log('error !');
				console.log(XMLHttpRequest);
				$('#error').html('<p><b>Error : </b> ' + textStatus + ' - ' + errorThrown + '</p><p><i>If the error is a time-out, you have to increase PHP script execution time-out</i></p>');
			}
		    });
		}


	$(document).ready(function() {
		installaction();
	});
    </script>
<?php
} else if (isset($_SESSION['install']) && $_SESSION['install'] == 'finish') {
	unset($_SESSION['install']);
	unset($_COOKIE['install']);
	print '<div class="info column"><ul>';
	foreach ($_SESSION['done'] as $done) {
	    print '<li>'.$done.'....<strong>SUCCESS</strong></li>';
	}
	print '<li>Reloading page to check all is now ok....<strong>SUCCESS</strong></li>';
	print '</ul></div>';
	print '<br /><p>All is now installed ! Thanks</p>';
	if ($globalSBS1) {
		print '<p>You need to run <b>scripts/daemon-spotter.php</b> as a daemon. You can use init script in the install/init directory.</p>';
	}
	if ($globalACARS) {
		print '<p>You need to run <b>scripts/daemon-acars.php</b> as a daemon. You can use init script in the install/init directory.</p>';
	}
	if ($globalFlightAware && ($globalFlightAwareUsername == '' || $globalFlightAwarePassword == '')) {
		print '<p>You <strong>must</strong> have a FlightAware API account to use FlightAware source</p>';
	}
	if (isset($globalVATSIM) && $globalVATSIM) {
		print '<p>Airline table is populated with VATSIM data</p>';
	}
	if (isset($globalIVAO) && $globalIVAO) {
		print '<p>You need to run install/populate_ivao.php if you want to have IVAO airlines</p>';
	}
	if (isset($globalMap3D) && $globalMap3D) {
		print '<p>You need to run <b>scripts/update_db.php</b> first time manually, this will update all and download 3D models.</p>';
	}
	if (isset($globalVAM) && $globalVAM) {
		print '<p>You need to copy <b>install/VAM/VAM-json.php</b> to your Virtual Airline Manager directory and use this URL as source.</p>';
	}
	print '<p>If you want to keep external data updated, you have to add <b>scripts/update_db.php</b> in cron (every hour or 30 minutes if computer is fast enough).</p>';
	print '<p>If <b>scripts/daemon-spotter.php</b> is already running, you have to restart it.</p>';
	print '</div>';
} else {
	unset($_SESSION['install']);
//	header("Location: index.php");
}
require('../footer.php');
?>