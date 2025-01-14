<?php
require_once(dirname(__FILE__).'/class.Connection.php');
require_once(dirname(__FILE__).'/class.Spotter.php');
require_once(dirname(__FILE__).'/class.SpotterLive.php');
require_once(dirname(__FILE__).'/class.SpotterArchive.php');
require_once(dirname(__FILE__).'/class.Scheduler.php');
require_once(dirname(__FILE__).'/class.Translation.php');
require_once(dirname(__FILE__).'/class.Stats.php');
require_once(dirname(__FILE__).'/class.Source.php');

class SpotterImport {
    private $all_flights = array();
    private $last_delete_hourly = 0;
    private $last_delete = 0;
    private $stats = array();
    private $tmd = 0;
    private $source_location = array();
    public $db = null;
    public $nb = 0;

    public function __construct($dbc = null) {
	global $globalBeta;
	$Connection = new Connection($dbc);
	$this->db = $Connection->db();
	date_default_timezone_set('UTC');

	// Get previous source stats
	$Stats = new Stats($dbc);
	$currentdate = date('Y-m-d');
	$sourcestat = $Stats->getStatsSource($currentdate);
	if (!empty($sourcestat)) {
	    foreach($sourcestat as $srcst) {
	    	$type = $srcst['stats_type'];
		if ($type == 'polar' || $type == 'hist') {
		    $source = $srcst['source_name'];
		    $data = $srcst['source_data'];
		    $this->stats[$currentdate][$source][$type] = json_decode($data,true);
	        }
	    }
	}
    }

    public function get_Schedule($id,$ident) {
	global $globalDebug, $globalFork, $globalSchedulesFetch;
	// Get schedule here, so it's done only one time
	
	/*
	if ($globalFork) {
		$Connection = new Connection();
		$dbc = $Connection->db;
	} else $dbc = $this->db;
	*/
	$dbc = $this->db;
	$this->all_flights[$id]['schedule_check'] = true;
	if ($globalSchedulesFetch) {
	if ($globalDebug) echo 'Getting schedule info...'."\n";
	$Spotter = new Spotter($dbc);
	$Schedule = new Schedule($dbc);
	$Translation = new Translation($dbc);
	$operator = $Spotter->getOperator($ident);
	$scheduleexist = false;
	if ($Schedule->checkSchedule($operator) == 0) {
	    $operator = $Translation->checkTranslation($ident);
	    if ($Schedule->checkSchedule($operator) == 0) {
		$schedule = $Schedule->fetchSchedule($operator);
		if (count($schedule) > 0 && isset($schedule['DepartureTime']) && isset($schedule['ArrivalTime'])) {
		    if ($globalDebug) echo "-> Schedule info for ".$operator." (".$ident.")\n";
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport_time' => $schedule['DepartureTime']));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('arrival_airport_time' => $schedule['ArrivalTime']));
		    // Should also check if route schedule = route from DB
		    if ($schedule['DepartureAirportIATA'] != '') {
			if ($this->all_flights[$id]['departure_airport'] != $Spotter->getAirportIcao($schedule['DepartureAirportIATA'])) {
			    $airport_icao = $Spotter->getAirportIcao($schedule['DepartureAirportIATA']);
			    if (trim($airport_icao) != '') {
				$this->all_flights[$id]['departure_airport'] = $airport_icao;
				if ($globalDebug) echo "-> Change departure airport to ".$airport_icao." for ".$ident."\n";
			    }
			}
		    }
		    if ($schedule['ArrivalAirportIATA'] != '') {
			if ($this->all_flights[$id]['arrival_airport'] != $Spotter->getAirportIcao($schedule['ArrivalAirportIATA'])) {
			    $airport_icao = $Spotter->getAirportIcao($schedule['ArrivalAirportIATA']);
			    if (trim($airport_icao) != '') {
				$this->all_flights[$id]['arrival_airport'] = $airport_icao;
				if ($globalDebug) echo "-> Change arrival airport to ".$airport_icao." for ".$ident."\n";
			    }
			}
		    }
		    $Schedule->addSchedule($operator,$this->all_flights[$id]['departure_airport'],$this->all_flights[$id]['departure_airport_time'],$this->all_flights[$id]['arrival_airport'],$this->all_flights[$id]['arrival_airport_time'],$schedule['Source']);
		}
	    } else $scheduleexist = true;
	} else $scheduleexist = true;
	// close connection, at least one way will work ?
       if ($scheduleexist) {
		if ($globalDebug) echo "-> get arrival/departure airport info for ".$ident."\n";
    		$sch = $Schedule->getSchedule($operator);
		$this->all_flights[$id] = array_merge($this->all_flights[$id],array('arrival_airport' => $sch['arrival_airport_icao'],'departure_airport' => $sch['departure_airport_icao'],'departure_airport_time' => $sch['departure_airport_time'],'arrival_airport_time' => $sch['arrival_airport_time']));
       }
	$Spotter->db = null;
	$Schedule->db = null;
	$Translation->db = null;
	unset($Spotter->db);
	unset($Schedule->db);
	unset($Translation->db);

	/*
	if ($globalFork) {
	    $Connection->db = null;
	    unset($Connection->db);
	}
	  */
	}
    }

    public function checkAll() {
	global $globalDebug;
	if ($globalDebug) echo "Update last seen flights data...\n";
	foreach ($this->all_flights as $key => $flight) {
	    if (isset($this->all_flights[$key]['id'])) {
		//echo $this->all_flights[$key]['id'].' - '.$this->all_flights[$key]['latitude'].'  '.$this->all_flights[$key]['longitude']."\n";
    		$Spotter = new Spotter($this->db);
        	$real_arrival = $this->arrival($key);
        	$Spotter->updateLatestSpotterData($this->all_flights[$key]['id'],$this->all_flights[$key]['ident'],$this->all_flights[$key]['latitude'],$this->all_flights[$key]['longitude'],$this->all_flights[$key]['altitude'],$this->all_flights[$key]['ground'],$this->all_flights[$key]['speed'],$this->all_flights[$key]['datetime'],$real_arrival['airport_icao'],$real_arrival['airport_time']);
            }
	}
    }

    public function arrival($key) {
	global $globalClosestMinDist, $globalDebug;
	if ($globalDebug) echo 'Update arrival...'."\n";
	$Spotter = new Spotter($this->db);
        $airport_icao = '';
        $airport_time = '';
        if (!isset($globalClosestMinDist) || $globalClosestMinDist == '') $globalClosestMinDist = 50;
	if ($this->all_flights[$key]['latitude'] != '' && $this->all_flights[$key]['longitude'] != '') {
	    $closestAirports = $Spotter->closestAirports($this->all_flights[$key]['latitude'],$this->all_flights[$key]['longitude'],$globalClosestMinDist);
    	    if (isset($closestAirports[0])) {
        	if (isset($this->all_flights[$key]['arrival_airport']) && $this->all_flights[$key]['arrival_airport'] == $closestAirports[0]['icao']) {
        	    $airport_icao = $closestAirports[0]['icao'];
        	    $airport_time = $this->all_flights[$key]['datetime'];
        	    if ($globalDebug) echo "---++ Find arrival airport. airport_icao : ".$airport_icao."\n";
        	} elseif (count($closestAirports > 1) && isset($this->all_flights[$key]['arrival_airport']) && $this->all_flights[$key]['arrival_airport'] != '') {
        	    foreach ($closestAirports as $airport) {
        		if ($this->all_flights[$key]['arrival_airport'] == $airport['icao']) {
        		    $airport_icao = $airport['icao'];
        		    $airport_time = $this->all_flights[$key]['datetime'];
        		    if ($globalDebug) echo "---++ Find arrival airport. airport_icao : ".$airport_icao."\n";
        		    break;
        		}
        	    }
        	} elseif ($this->all_flights[$key]['altitude'] == 0 || ($this->all_flights[$key]['altitude_real'] != '' && ($closestAirports[0]['altitude'] < $this->all_flights[$key]['altitude_real'] && $this->all_flights[$key]['altitude_real'] < $closestAirports[0]['altitude']+5000))) {
        		$airport_icao = $closestAirports[0]['icao'];
        		$airport_time = $this->all_flights[$key]['datetime'];
        	} else {
        		if ($globalDebug) echo "----- Can't find arrival airport. Airport altitude : ".$closestAirports[0]['altitude'].' - flight altitude : '.$this->all_flights[$key]['altitude_real']."\n";
        	}
    	    } else {
    		    if ($globalDebug) echo "----- No Airport near last coord. Latitude : ".$this->all_flights[$key]['latitude'].' - Longitude : '.$this->all_flights[$key]['longitude'].' - MinDist : '.$globalClosestMinDist."\n";
    	    }

        } else {
        	if ($globalDebug) echo "---- No latitude or longitude. Ident : ".$this->all_flights[$key]['ident']."\n";
        }
        return array('airport_icao' => $airport_icao,'airport_time' => $airport_time);
    }



    public function del() {
	global $globalDebug;
	// Delete old infos
	if ($globalDebug) echo 'Delete old values and update latest data...'."\n";
	foreach ($this->all_flights as $key => $flight) {
    	    if (isset($flight['lastupdate'])) {
        	if ($flight['lastupdate'] < (time()-3000)) {
            	    if (isset($this->all_flights[$key]['id'])) {
            		if ($globalDebug) echo "--- Delete old values with id ".$this->all_flights[$key]['id']."\n";
			/*
			$SpotterLive = new SpotterLive();
            		$SpotterLive->deleteLiveSpotterDataById($this->all_flights[$key]['id']);
			$SpotterLive->db = null;
			*/
            		$real_arrival = $this->arrival($key);
            		$Spotter = new Spotter($this->db);
            		if ($this->all_flights[$key]['latitude'] != '' && $this->all_flights[$key]['longitude'] != '') {
				$result = $Spotter->updateLatestSpotterData($this->all_flights[$key]['id'],$this->all_flights[$key]['ident'],$this->all_flights[$key]['latitude'],$this->all_flights[$key]['longitude'],$this->all_flights[$key]['altitude'],$this->all_flights[$key]['ground'],$this->all_flights[$key]['speed'],$this->all_flights[$key]['datetime'],$real_arrival['airport_icao'],$real_arrival['airport_time']);
				if ($globalDebug && $result != 'success') echo '!!! ERROR : '.$result."\n";
			}
			// Put in archive
//			$Spotter->db = null;
            	    }
            	    unset($this->all_flights[$key]);
    	        }
	    }
        }
    }

    public function add($line) {
	global $globalPilotIdAccept, $globalAirportAccept, $globalAirlineAccept, $globalAirlineIgnore, $globalAirportIgnore, $globalFork, $globalDistanceIgnore, $globalDaemon, $globalSBS1update, $globalDebug, $globalIVAO, $globalVATSIM, $globalphpVMS, $globalCoordMinChange, $globalDebugTimeElapsed, $globalCenterLatitude, $globalCenterLongitude, $globalBeta, $globalSourcesupdate, $globalAirlinesSource, $globalVAM, $globalAllFlights;
	//if (!isset($globalDebugTimeElapsed) || $globalDebugTimeElapsed == '') $globalDebugTimeElapsed = FALSE;
	if (!isset($globalCoordMinChange) || $globalCoordMinChange == '') $globalCoordMinChange = '0.02';
/*
	$Spotter = new Spotter();
	$dbc = $Spotter->db;
	$SpotterLive = new SpotterLive($dbc);
	$Common = new Common();
	$Schedule = new Schedule($dbc);
*/
	date_default_timezone_set('UTC');
	// signal handler - playing nice with sockets and dump1090
	// pcntl_signal_dispatch();

	// get the time (so we can figure the timeout)
	//$time = time();

	//pcntl_signal_dispatch();
	$dataFound = false;
	//$putinarchive = false;
	$send = false;
	
	// SBS format is CSV format
	if(is_array($line) && isset($line['hex'])) {
	    //print_r($line);
  	    if ($line['hex'] != '' && $line['hex'] != '00000' && $line['hex'] != '000000' && $line['hex'] != '111111' && ctype_xdigit($line['hex']) && strlen($line['hex']) === 6) {

		// Increment message number
		if (isset($line['sourcestats']) && $line['sourcestats'] == TRUE) {
		    $current_date = date('Y-m-d');
		    $source = $line['source_name'];
		    if ($source == '' || $line['format_source'] == 'aprs') $source = $line['format_source'];
		    if (!isset($this->stats[$current_date][$source]['msg'])) {
		    	$this->stats[$current_date][$source]['msg']['date'] = time();
		    	$this->stats[$current_date][$source]['msg']['nb'] = 1;
		    } else $this->stats[$current_date][$source]['msg']['nb'] += 1;
		}
		
		/*
		$dbc = $this->db;
		$Connection = new Connection($dbc);
		$Connection->connectionExists();
		$dbc = $Connection->db;
		*/
		//$Spotter = new Spotter($dbc);
		//$SpotterLive = new SpotterLive($dbc);
		$Common = new Common();
//		echo $this->nb++."\n";
		//$this->db = $dbc;

		$hex = trim($line['hex']);
	        if (!isset($line['id'])) $id = trim($line['hex']);
	        else $id = trim($line['id']);
		
		//print_r($this->all_flights);
		if (!isset($this->all_flights[$id]['hex']) && ctype_xdigit($hex)) {
		    $this->all_flights[$id] = array('hex' => $hex);
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('addedSpotter' => 0));
		    //if (isset($line['datetime']) && preg_match('/^(\d{4}(?:\-\d{2}){2} \d{2}(?:\:\d{2}){2})$/',$line['datetime'])) {
			//$this->all_flights[$id] = array_merge($this->all_flights[$id],array('datetime' => $line['datetime']));
		    //} else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('datetime' => date('Y-m-d H:i:s')));
		    if (!isset($line['aircraft_name']) && (!isset($line['aircraft_icao']) || $line['aircraft_icao'] == '????')) {

			$timeelapsed = microtime(true);
			$Spotter = new Spotter($this->db);
			$aircraft_icao = $Spotter->getAllAircraftType($hex);
			$Spotter->db = null;
			if ($globalDebugTimeElapsed) echo 'Time elapsed for update getallaircrattype : '.round(microtime(true)-$timeelapsed,2).'s'."\n";

			if ($aircraft_icao == '' && isset($line['aircraft_type'])) {
			    if ($line['aircraft_type'] == 'PARA_GLIDER') $aircraft_icao = 'GLID';
			    elseif ($line['aircraft_type'] == 'HELICOPTER_ROTORCRAFT') $aircraft_icao = 'UHEL';
			    elseif ($line['aircraft_type'] == 'TOW_PLANE') $aircraft_icao = 'TOWPLANE';
			    elseif ($line['aircraft_type'] == 'POWERED_AIRCRAFT') $aircraft_icao = 'POWAIRC';
			}
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('aircraft_icao' => $aircraft_icao));
		    } else if (isset($line['aircraft_name'])) {
			// Get aircraft ICAO from aircraft name
			$Spotter = new Spotter($this->db);
			$aircraft_icao = $Spotter->getAircraftIcao($line['aircraft_name']);
			$Spotter->db = null;
			if ($aircraft_icao != '') $this->all_flights[$id] = array_merge($this->all_flights[$id],array('aircraft_icao' => $aircraft_icao));
			else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('aircraft_icao' => 'NA'));
		    } else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('aircraft_icao' => $line['aircraft_icao']));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('ident' => '','departure_airport' => '', 'arrival_airport' => '','latitude' => '', 'longitude' => '', 'speed' => '', 'altitude' => '','altitude_real' => '', 'heading' => '','departure_airport_time' => '','arrival_airport_time' => '','squawk' => '','route_stop' => '','registration' => '','pilot_id' => '','pilot_name' => '','waypoints' => '','ground' => '0', 'format_source' => '','source_name' => '','over_country' => '','verticalrate' => '','noarchive' => false,'putinarchive' => true));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('lastupdate' => time()));
		    if (!isset($line['id'])) {
			if (!isset($globalDaemon)) $globalDaemon = TRUE;
//			if (isset($line['format_source']) && ($line['format_source'] == 'sbs' || $line['format_source'] == 'tsv' || $line['format_source'] == 'raw') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident'].'-'.date('YmdGi')));
//			if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw' || $line['format_source'] === 'deltadbtxt' || $line['format_source'] === 'planeupdatefaa' || $line['format_source'] === 'aprs') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.date('YmdHi')));
			if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw' || $line['format_source'] === 'deltadbtxt' || $line['format_source'] === 'planeupdatefaa' || $line['format_source'] === 'aprs' || $line['format_source'] === 'aircraftlistjson' || $line['format_source'] === 'radarvirtueljson')) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.date('YmdHi')));
		        //else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident']));
		     } else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $line['id']));

		    if ($globalDebug) echo "*********** New aircraft hex : ".$hex." ***********\n";
		    if ($globalAllFlights !== FALSE) $dataFound = true;
		}
		
		//if (isset($line['datetime']) && preg_match('/^(\d{4}(?:\-\d{2}){2} \d{2}(?:\:\d{2}){2})$/',$line['datetime'])) {
		if (isset($line['datetime']) && strtotime($line['datetime']) > time()-20*60) {
		    if (!isset($this->all_flights[$id]['datetime']) || strtotime($line['datetime']) >= strtotime($this->all_flights[$id]['datetime'])) {
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('datetime' => $line['datetime']));
		    } else {
				if (strtotime($line['datetime']) == strtotime($this->all_flights[$id]['datetime']) && $globalDebug) echo "!!! Date is the same as previous data for ".$this->all_flights[$id]['hex']." - format : ".$line['format_source']."\n";
				elseif (strtotime($line['datetime']) > strtotime($this->all_flights[$id]['datetime']) && $globalDebug) echo "!!! Date previous latest data (".$line['datetime']." > ".$this->all_flights[$id]['datetime'].") !!! for ".$this->all_flights[$id]['hex']." - format : ".$line['format_source']."\n";
				/*
				echo strtotime($line['datetime']).' > '.strtotime($this->all_flights[$id]['datetime']);
				print_r($this->all_flights[$id]);
				print_r($line);
				*/
				return '';
		    }
		} else {
			date_default_timezone_set('UTC');
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('datetime' => date('Y-m-d H:i:s')));
		}

		if (isset($line['registration']) && $line['registration'] != '' && $line['registration'] != 'z.NO-REG') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('registration' => $line['registration']));
		}
		if (isset($line['waypoints']) && $line['waypoints'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('waypoints' => $line['waypoints']));
		}
		if (isset($line['pilot_id']) && $line['pilot_id'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('pilot_id' => $line['pilot_id']));
		}
		if (isset($line['pilot_name']) && $line['pilot_name'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('pilot_name' => $line['pilot_name']));
		}
 
		if (isset($line['ident']) && $line['ident'] != '' && $line['ident'] != '????????' && $line['ident'] != '00000000' && ($this->all_flights[$id]['ident'] != trim($line['ident'])) && preg_match('/^[a-zA-Z0-9]+$/', $line['ident'])) {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('ident' => trim($line['ident'])));
		    if ($this->all_flights[$id]['addedSpotter'] == 1) {
			$timeelapsed = microtime(true);
            		$Spotter = new Spotter($this->db);
            		$fromsource = NULL;
            		if (isset($globalAirlinesSource) && $globalAirlinesSource != '') $fromsource = $globalAirlinesSource;
            		elseif (isset($line['format_source']) && $line['format_source'] == 'vatsimtxt') $fromsource = 'vatsim';
			elseif (isset($line['format_source']) && $line['format_source'] == 'whazzup') $fromsource = 'ivao';
			elseif (isset($globalVATSIM) && $globalVATSIM) $fromsource = 'vatsim';
			elseif (isset($globalIVAO) && $globalIVAO) $fromsource = 'ivao';
            		$result = $Spotter->updateIdentSpotterData($this->all_flights[$id]['id'],$this->all_flights[$id]['ident'],$fromsource);
			if ($globalDebug && $result != 'success') echo '!!! ERROR : '.$result."\n";
			$Spotter->db = null;
			if ($globalDebugTimeElapsed) echo 'Time elapsed for update identspotterdata : '.round(microtime(true)-$timeelapsed,2).'s'."\n";
		    }

/*
		    if (!isset($line['id'])) {
			if (!isset($globalDaemon)) $globalDaemon = TRUE;
//			if (isset($line['format_source']) && ($line['format_source'] == 'sbs' || $line['format_source'] == 'tsv' || $line['format_source'] == 'raw') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident'].'-'.date('YmdGi')));
			if (isset($line['format_source']) && ($line['format_source'] == 'sbs' || $line['format_source'] == 'tsv' || $line['format_source'] == 'raw') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.date('YmdGi')));
		        else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident']));
		     } else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $line['id']));
  */
		    if (!isset($this->all_flights[$id]['id'])) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident']));

		    //$putinarchive = true;
		    if (isset($line['departure_airport_time']) && $line['departure_airport_time'] != 0) {
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport_time' => $line['departure_airport_time']));
		    }
		    if (isset($line['arrival_airport_time']) && $line['arrival_airport_time'] != 0) {
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('arrival_airport_time' => $line['arrival_airport_time']));
		    }
		    if (isset($line['departure_airport_icao']) && isset($line['arrival_airport_icao'])) {
		    		$this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport' => $line['departure_airport_icao'],'arrival_airport' => $line['arrival_airport_icao'],'route_stop' => ''));
		    } elseif (isset($line['departure_airport_iata']) && isset($line['arrival_airport_iata'])) {
				$timeelapsed = microtime(true);
				$Spotter = new Spotter($this->db);
				$line['departure_airport_icao'] = $Spotter->getAirportIcao($line['departure_airport_iata']);
				$line['arrival_airport_icao'] = $Spotter->getAirportIcao($line['arrival_airport_iata']);
		    		$this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport' => $line['departure_airport_icao'],'arrival_airport' => $line['arrival_airport_icao'],'route_stop' => ''));
				if ($globalDebugTimeElapsed) echo 'Time elapsed for update getAirportICAO : '.round(microtime(true)-$timeelapsed,2).'s'."\n";

		    } elseif (!isset($line['format_source']) || $line['format_source'] != 'aprs') {
			$timeelapsed = microtime(true);
			$Spotter = new Spotter($this->db);
			$route = $Spotter->getRouteInfo(trim($line['ident']));
			if (!isset($route['fromairport_icao']) && !isset($route['toairport_icao'])) {
				$Translation = new Translation($this->db);
				$ident = $Translation->checkTranslation(trim($line['ident']));
				$route = $Spotter->getRouteInfo($ident);
				$Translation->db = null;
			}
			$Spotter->db = null;
			if ($globalDebugTimeElapsed) echo 'Time elapsed for update getrouteinfo : '.round(microtime(true)-$timeelapsed,2).'s'."\n";

			if (isset($route['fromairport_icao']) && isset($route['toairport_icao'])) {
			    //if ($route['FromAirport_ICAO'] != $route['ToAirport_ICAO']) {
			    if ($route['fromairport_icao'] != $route['toairport_icao']) {
				//    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport' => $route['FromAirport_ICAO'],'arrival_airport' => $route['ToAirport_ICAO'],'route_stop' => $route['RouteStop']));
		    		$this->all_flights[$id] = array_merge($this->all_flights[$id],array('departure_airport' => $route['fromairport_icao'],'arrival_airport' => $route['toairport_icao'],'route_stop' => $route['routestop']));
		    	    }
			}
			if (!isset($globalFork)) $globalFork = TRUE;
			if (!$globalIVAO && !$globalVATSIM && !$globalphpVMS && !$globalVAM && (!isset($line['format_source']) || $line['format_source'] != 'aprs')) {
				if (!isset($this->all_flights[$id]['schedule_check']) || $this->all_flights[$id]['schedule_check'] === false) $this->get_Schedule($id,trim($line['ident']));
			}
		    }
		}

		if (isset($line['speed']) && $line['speed'] != '') {
		//    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('speed' => $line[12]));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('speed' => round($line['speed'])));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('speed_fromsrc' => true));
		    //$dataFound = true;
		} else if (!isset($this->all_flights[$id]['speed_fromsrc']) && isset($this->all_flights[$id]['time_last_coord']) && $this->all_flights[$id]['time_last_coord'] != time() && isset($line['latitude']) && isset($line['longitude'])) {
		    $distance = $Common->distance($line['latitude'],$line['longitude'],$this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],'m');
		    if ($distance > 1000 && $distance < 10000) {
		    // use datetime
			$speed = $distance/(time() - $this->all_flights[$id]['time_last_coord']);
			$speed = $speed*3.6;
			if ($speed < 1000) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('speed' => round($speed)));
  			if ($globalDebug) echo "ø Calculated Speed for ".$this->all_flights[$id]['hex']." : ".$speed." - distance : ".$distance."\n";
		    }
		}



	        if (isset($line['latitude']) && isset($line['longitude']) && $line['latitude'] != '' && $line['longitude'] != '' && is_numeric($line['latitude']) && is_numeric($line['longitude'])) {
	    	    if (isset($this->all_flights[$id]['time_last_coord'])) $timediff = round(time()-$this->all_flights[$id]['time_last_coord']);
	    	    else unset($timediff);
	    	    if ($this->tmd > 5 || (isset($globalIVAO) && $globalIVAO) || (isset($globalVATSIM) && $globalVATSIM) || (isset($globalphpVMS) && $globalphpVMS) || (isset($globalVAM) && $globalVAM) || !isset($timediff) || $timediff > 800 || ($timediff > 10 && isset($this->all_flights[$id]['latitude']) && isset($this->all_flights[$id]['longitude']) && $Common->withinThreshold($timediff,$Common->distance($line['latitude'],$line['longitude'],$this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],'m')))) {
			if (isset($this->all_flights[$id]['archive_latitude']) && isset($this->all_flights[$id]['archive_longitude']) && isset($this->all_flights[$id]['livedb_latitude']) && isset($this->all_flights[$id]['livedb_longitude'])) {
			    if (!$Common->checkLine($this->all_flights[$id]['archive_latitude'],$this->all_flights[$id]['archive_longitude'],$this->all_flights[$id]['livedb_latitude'],$this->all_flights[$id]['livedb_longitude'],$line['latitude'],$line['longitude'])) {
				$this->all_flights[$id]['archive_latitude'] = $line['latitude'];
				$this->all_flights[$id]['archive_longitude'] = $line['longitude'];
				$this->all_flights[$id]['putinarchive'] = true;
				
				if ($globalDebug) echo "\n".' ------- Check Country for '.$this->all_flights[$id]['ident'].' with latitude : '.$line['latitude'].' and longitude : '.$line['longitude'].'.... ';
				$timeelapsed = microtime(true);
				$Spotter = new Spotter($this->db);
				$all_country = $Spotter->getCountryFromLatitudeLongitude($line['latitude'],$line['longitude']);
				if (!empty($all_country)) $this->all_flights[$id]['over_country'] = $all_country['iso2'];
				$Spotter->db = null;
				if ($globalDebugTimeElapsed) echo 'Time elapsed for update getCountryFromlatitudeLongitude : '.round(microtime(true)-$timeelapsed,2).'s'."\n";
				$this->tmd = 0;
				if ($globalDebug) echo 'FOUND : '.$this->all_flights[$id]['over_country'].' ---------------'."\n";
			    }
			}

			if (isset($line['latitude']) && $line['latitude'] != '' && $line['latitude'] != 0 && $line['latitude'] < 91 && $line['latitude'] > -90) {
			    //if (!isset($this->all_flights[$id]['latitude']) || $this->all_flights[$id]['latitude'] == '' || abs($this->all_flights[$id]['latitude']-$line['latitude']) < 3 || $line['format_source'] != 'sbs' || time() - $this->all_flights[$id]['lastupdate'] > 30) {
				if (!isset($this->all_flights[$id]['archive_latitude'])) $this->all_flights[$id]['archive_latitude'] = $line['latitude'];
				if (!isset($this->all_flights[$id]['livedb_latitude']) || abs($this->all_flights[$id]['livedb_latitude']-$line['latitude']) > $globalCoordMinChange || $this->all_flights[$id]['format_source'] == 'aprs') {
				    $this->all_flights[$id]['livedb_latitude'] = $line['latitude'];
				    $dataFound = true;
				    $this->all_flights[$id]['time_last_coord'] = time();
				}
				// elseif ($globalDebug) echo '!*!*! Ignore data, too close to previous one'."\n";
				$this->all_flights[$id] = array_merge($this->all_flights[$id],array('latitude' => $line['latitude']));
				/*
				if (abs($this->all_flights[$id]['archive_latitude']-$this->all_flights[$id]['latitude']) > 0.3) {
				    $this->all_flights[$id]['archive_latitude'] = $line['latitude'];
				    $this->all_flights[$id]['putinarchive'] = true;
				    //$putinarchive = true;
				}
				*/
			    /*
			    } elseif (isset($this->all_flights[$id]['latitude'])) {
				if ($globalDebug) echo '!!! Strange latitude value - diff : '.abs($this->all_flights[$id]['latitude']-$line['latitude']).'- previous lat : '.$this->all_flights[$id]['latitude'].'- new lat : '.$line['latitude']."\n";
			    }
			    */
			}
			if (isset($line['longitude']) && $line['longitude'] != '' && $line['longitude'] != 0 && $line['longitude'] < 360 && $line['longitude'] > -180) {
			    if ($line['longitude'] > 180) $line['longitude'] = $line['longitude'] - 360;
			    //if (!isset($this->all_flights[$id]['longitude']) || $this->all_flights[$id]['longitude'] == ''  || abs($this->all_flights[$id]['longitude']-$line['longitude']) < 2 || $line['format_source'] != 'sbs' || time() - $this->all_flights[$id]['lastupdate'] > 30) {
				if (!isset($this->all_flights[$id]['archive_longitude'])) $this->all_flights[$id]['archive_longitude'] = $line['longitude'];
				if (!isset($this->all_flights[$id]['livedb_longitude']) || abs($this->all_flights[$id]['livedb_longitude']-$line['longitude']) > $globalCoordMinChange || $this->all_flights[$id]['format_source'] == 'aprs') {
				    $this->all_flights[$id]['livedb_longitude'] = $line['longitude'];
				    $dataFound = true;
				    $this->all_flights[$id]['time_last_coord'] = time();
				}
				// elseif ($globalDebug) echo '!*!*! Ignore data, too close to previous one'."\n";
				$this->all_flights[$id] = array_merge($this->all_flights[$id],array('longitude' => $line['longitude']));
				/*
				if (abs($this->all_flights[$id]['archive_longitude']-$this->all_flights[$id]['longitude']) > 0.3) {
				    $this->all_flights[$id]['archive_longitude'] = $line['longitude'];
				    $this->all_flights[$id]['putinarchive'] = true;
				    //$putinarchive = true;
				}
				*/
			/*
			    } elseif (isset($this->all_flights[$id]['longitude'])) {
				if ($globalDebug) echo '!!! Strange longitude value - diff : '.abs($this->all_flights[$id]['longitude']-$line['longitude']).'- previous lat : '.$this->all_flights[$id]['longitude'].'- new lat : '.$line['longitude']."\n";
			    }
			    */
			}

		    } else if ($globalDebug && $timediff > 20) {
			$this->tmd = $this->tmd + 1;
			echo '!!! Too much distance in short time... for '.$this->all_flights[$id]['ident']."\n";
			echo 'Time : '.$timediff.'s - Distance : '.$Common->distance($line['latitude'],$line['longitude'],$this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],'m')."m -";
			echo 'Speed : '.(($Common->distance($line['latitude'],$line['longitude'],$this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],'m')/$timediff)*3.6)." km/h - ";
			echo 'Lat : '.$line['latitude'].' - long : '.$line['longitude'].' - prev lat : '.$this->all_flights[$id]['latitude'].' - prev long : '.$this->all_flights[$id]['longitude']." \n";
		    }
		}
		if (isset($line['last_update']) && $line['last_update'] != '') {
		    if (isset($this->all_flights[$id]['last_update']) && $this->all_flights[$id]['last_update'] != $line['last_update']) $dataFound = true;
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('last_update' => $line['last_update']));
		}
		if (isset($line['verticalrate']) && $line['verticalrate'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('verticalrate' => $line['verticalrate']));
		    //$dataFound = true;
		}
		if (isset($line['format_source']) && $line['format_source'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('format_source' => $line['format_source']));
		}
		if (isset($line['source_name']) && $line['source_name'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('source_name' => $line['source_name']));
		}
		if (isset($line['emergency']) && $line['emergency'] != '') {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('emergency' => $line['emergency']));
		    //$dataFound = true;
		}
		if (isset($line['ground']) && $line['ground'] != '') {
		    if (isset($this->all_flights[$id]['ground']) && $this->all_flights[$id]['ground'] == 1 && $line['ground'] == 0) {
			// Here we force archive of flight because after ground it's a new one (or should be)
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('addedSpotter' => 0));
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('forcenew' => 1));
			if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw') && $globalDaemon) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.date('YmdGi')));
		        elseif (isset($line['id'])) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $line['id']));
			elseif (isset($this->all_flights[$id]['ident'])) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.$this->all_flights[$id]['ident']));
		    }
		    if ($line['ground'] != 1) $line['ground'] = 0;
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('ground' => $line['ground']));
		    //$dataFound = true;
		}
		if (isset($line['squawk']) && $line['squawk'] != '') {
		    if (isset($this->all_flights[$id]['squawk']) && $this->all_flights[$id]['squawk'] != '7500' && $this->all_flights[$id]['squawk'] != '7600' && $this->all_flights[$id]['squawk'] != '7700' && isset($this->all_flights[$id]['id'])) {
			    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('squawk' => $line['squawk']));
			    $highlight = '';
			    if ($this->all_flights[$id]['squawk'] == '7500') $highlight = 'Squawk 7500 : Hijack at '.date('Y-m-d G:i').' UTC';
			    if ($this->all_flights[$id]['squawk'] == '7600') $highlight = 'Squawk 7600 : Lost Comm (radio failure) at '.date('Y-m-d G:i').' UTC';
			    if ($this->all_flights[$id]['squawk'] == '7700') $highlight = 'Squawk 7700 : Emergency at '.date('Y-m-d G:i').' UTC';
			    if ($highlight != '') {
				$timeelapsed = microtime(true);
				$Spotter = new Spotter($this->db);
				$Spotter->setHighlightFlight($this->all_flights[$id]['id'],$highlight);
				$Spotter->db = null;
				if ($globalDebugTimeElapsed) echo 'Time elapsed for update sethighlightflight : '.round(microtime(true)-$timeelapsed,2).'s'."\n";

				$this->all_flights[$id]['putinarchive'] = true;
				//$putinarchive = true;
				//$highlight = '';
			    }
			    
		    } else $this->all_flights[$id] = array_merge($this->all_flights[$id],array('squawk' => $line['squawk']));
		    //$dataFound = true;
		}

		if (isset($line['altitude']) && $line['altitude'] != '') {
		    //if (!isset($this->all_flights[$id]['altitude']) || $this->all_flights[$id]['altitude'] == '' || ($this->all_flights[$id]['altitude'] > 0 && $line['altitude'] != 0)) {
			if (is_int($this->all_flights[$id]['altitude']) && abs(round($line['altitude']/100)-$this->all_flights[$id]['altitude']) > 2) $this->all_flights[$id]['putinarchive'] = true;
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('altitude' => round($line['altitude']/100)));
			$this->all_flights[$id] = array_merge($this->all_flights[$id],array('altitude_real' => $line['altitude']));
			//$dataFound = true;
		    //} elseif ($globalDebug) echo "!!! Strange altitude data... not added.\n";
  		}

		if (isset($line['noarchive']) && $line['noarchive'] === true) {
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('noarchive' => true));
		}
		
		if (isset($line['heading']) && $line['heading'] != '') {
		    if (is_int($this->all_flights[$id]['heading']) && abs($this->all_flights[$id]['heading']-round($line['heading'])) > 2) $this->all_flights[$id]['putinarchive'] = true;
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('heading' => round($line['heading'])));
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('heading_fromsrc' => true));
		    //$dataFound = true;
  		} elseif (!isset($this->all_flights[$id]['heading_fromsrc']) && isset($this->all_flights[$id]['archive_latitude']) && $this->all_flights[$id]['archive_latitude'] != $this->all_flights[$id]['latitude'] && isset($this->all_flights[$id]['archive_longitude']) && $this->all_flights[$id]['archive_longitude'] != $this->all_flights[$id]['longitude']) {
  		    $heading = $Common->getHeading($this->all_flights[$id]['archive_latitude'],$this->all_flights[$id]['archive_longitude'],$this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude']);
		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('heading' => round($heading)));
		    if (abs($this->all_flights[$id]['heading']-round($heading)) > 2) $this->all_flights[$id]['putinarchive'] = true;
  		    if ($globalDebug) echo "ø Calculated Heading for ".$this->all_flights[$id]['hex']." : ".$heading."\n";
  		} elseif (isset($this->all_flights[$id]['format_source']) && $this->all_flights[$id]['format_source'] == 'ACARS') {
  		    // If not enough messages and ACARS set heading to 0
  		    $this->all_flights[$id] = array_merge($this->all_flights[$id],array('heading' => 0));
  		}
		if (isset($globalSourcesupdate) && $globalSourcesupdate != '' && isset($this->all_flights[$id]['lastupdate']) && time()-$this->all_flights[$id]['lastupdate'] < $globalSourcesupdate) $dataFound = false;
		elseif (isset($globalSBS1update) && $globalSBS1update != '' && isset($this->all_flights[$id]['lastupdate']) && time()-$this->all_flights[$id]['lastupdate'] < $globalSBS1update) $dataFound = false;

//		print_r($this->all_flights[$id]);
		//gets the callsign from the last hour
		//if (time()-$this->all_flights[$id]['lastupdate'] > 30 && $dataFound == true && $this->all_flights[$id]['ident'] != '' && $this->all_flights[$id]['latitude'] != '' && $this->all_flights[$id]['longitude'] != '') {
		//if ($dataFound == true && isset($this->all_flights[$id]['hex']) && $this->all_flights[$id]['ident'] != '' && $this->all_flights[$id]['latitude'] != '' && $this->all_flights[$id]['longitude'] != '') {
		//if ($dataFound === true && isset($this->all_flights[$id]['hex']) && $this->all_flights[$id]['heading'] != '' && $this->all_flights[$id]['latitude'] != '' && $this->all_flights[$id]['longitude'] != '') {
		if ($dataFound === true && isset($this->all_flights[$id]['hex'])) {
		    $this->all_flights[$id]['lastupdate'] = time();
		    if ($this->all_flights[$id]['addedSpotter'] == 0) {
		        if (!isset($globalDistanceIgnore['latitude']) || $this->all_flights[$id]['longitude'] == ''  || $this->all_flights[$id]['latitude'] == '' || (isset($globalDistanceIgnore['latitude']) && $Common->distance($this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],$globalDistanceIgnore['latitude'],$globalDistanceIgnore['longitude']) < $globalDistanceIgnore['distance'])) {
			    //print_r($this->all_flights);
			    //echo $this->all_flights[$id]['id'].' - '.$this->all_flights[$id]['addedSpotter']."\n";
			    //$last_hour_ident = Spotter->getIdentFromLastHour($this->all_flights[$id]['ident']);
			    if (!isset($this->all_flights[$id]['forcenew']) || $this->all_flights[$id]['forcenew'] == 0) {
				if ($globalDebug) echo "Check if aircraft is already in DB...";
				$timeelapsed = microtime(true);
				$SpotterLive = new SpotterLive($this->db);
				if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw' || $line['format_source'] === 'deltadbtxt' || $line['format_source'] === 'planeupdatefaa' || $line['format_source'] === 'aprs' || $line['format_source'] === 'aircraftlistjson' || $line['format_source'] === 'radarvirtueljson')) {
				    $recent_ident = $SpotterLive->checkModeSRecent($this->all_flights[$id]['hex']);
				    if ($globalDebugTimeElapsed) echo 'Time elapsed for update checkModeSRecent : '.round(microtime(true)-$timeelapsed,2).'s'."\n";
				} elseif (isset($line['id'])) {
				    $recent_ident = $SpotterLive->checkIdRecent($line['id']);
				    if ($globalDebugTimeElapsed) echo 'Time elapsed for update checkIdRecent : '.round(microtime(true)-$timeelapsed,2).'s'."\n";
				} elseif (isset($this->all_flights[$id]['ident']) && $this->all_flights[$id]['ident'] != '') {
				    $recent_ident = $SpotterLive->checkIdentRecent($this->all_flights[$id]['ident']);
				    if ($globalDebugTimeElapsed) echo 'Time elapsed for update checkIdentRecent : '.round(microtime(true)-$timeelapsed,2).'s'."\n";
				} else $recent_ident = '';
				$SpotterLive->db=null;

				if ($globalDebug && $recent_ident == '') echo " Not in DB.\n";
				elseif ($globalDebug && $recent_ident != '') echo " Already in DB.\n";
			    } else {
				$recent_ident = '';
				$this->all_flights[$id] = array_merge($this->all_flights[$id],array('forcenew' => 0));
			    }
			    //if there was no aircraft with the same callsign within the last hour and go post it into the archive
			    if($recent_ident == "")
			    {
				if ($globalDebug) echo "\o/ Add ".$this->all_flights[$id]['ident']." in archive DB : ";
				if ($this->all_flights[$id]['departure_airport'] == "") { $this->all_flights[$id]['departure_airport'] = "NA"; }
				if ($this->all_flights[$id]['arrival_airport'] == "") { $this->all_flights[$id]['arrival_airport'] = "NA"; }
				//adds the spotter data for the archive
				$ignoreImport = false;
				foreach($globalAirportIgnore as $airportIgnore) {
				    if (($this->all_flights[$id]['departure_airport'] == $airportIgnore) || ($this->all_flights[$id]['arrival_airport'] == $airportIgnore)) {
					$ignoreImport = true;
				    }
				}
				if (count($globalAirportAccept) > 0) {
				    $ignoreImport = true;
				    foreach($globalAirportIgnore as $airportIgnore) {
					if (($this->all_flights[$id]['departure_airport'] == $airportIgnore) || ($this->all_flights[$id]['arrival_airport'] == $airportIgnore)) {
					    $ignoreImport = false;
					}
				    }
				}
				if (isset($globalAirlineIgnore) && is_array($globalAirlineIgnore)) {
				    foreach($globalAirlineIgnore as $airlineIgnore) {
					if ((is_numeric(substr(substr($this->all_flights[$id]['ident'],0,4),-1,1)) && substr($this->all_flights[$id]['ident'],0,3) == $airlineIgnore) || (is_numeric(substr(substr($this->all_flights[$id]['ident'],0,3),-1,1)) && substr($this->all_flights[$id]['ident'],0,2) == $airlineIgnore)) {
					    $ignoreImport = true;
					}
				    }
				}
				if (isset($globalAirlineAccept) && count($globalAirlineAccept) > 0) {
				    $ignoreImport = true;
				    foreach($globalAirlineAccept as $airlineAccept) {
					if ((is_numeric(substr(substr($this->all_flights[$id]['ident'],0,4),-1,1)) && substr($this->all_flights[$id]['ident'],0,3) == $airlineAccept) || (is_numeric(substr(substr($this->all_flights[$id]['ident'],0,3),-1,1)) && substr($this->all_flights[$id]['ident'],0,2) == $airlineAccept)) {
					    $ignoreImport = false;
					}
				    }
				}
				if (isset($globalPilotIdAccept) && count($globalPilotIdAccept) > 0) {
				    $ignoreImport = true;
				    foreach($globalPilotIdAccept as $pilotIdAccept) {
					if ($this->all_flights[$id]['pilot_id'] == $pilotIdAccept) {
					    $ignoreImport = false;
					}
				    }
				}
				
				if (!$ignoreImport) {
				    $highlight = '';
				    if ($this->all_flights[$id]['squawk'] == '7500') $highlight = 'Squawk 7500 : Hijack';
				    if ($this->all_flights[$id]['squawk'] == '7600') $highlight = 'Squawk 7600 : Lost Comm (radio failure)';
				    if ($this->all_flights[$id]['squawk'] == '7700') $highlight = 'Squawk 7700 : Emergency';
				    if (!isset($this->all_flights[$id]['id'])) $this->all_flights[$id] = array_merge($this->all_flights[$id],array('id' => $this->all_flights[$id]['hex'].'-'.date('YmdHi')));
				    $timeelapsed = microtime(true);
				    $Spotter = new Spotter($this->db);
				    $result = $Spotter->addSpotterData($this->all_flights[$id]['id'], $this->all_flights[$id]['ident'], $this->all_flights[$id]['aircraft_icao'], $this->all_flights[$id]['departure_airport'], $this->all_flights[$id]['arrival_airport'], $this->all_flights[$id]['latitude'], $this->all_flights[$id]['longitude'], $this->all_flights[$id]['waypoints'], $this->all_flights[$id]['altitude'], $this->all_flights[$id]['heading'], $this->all_flights[$id]['speed'], $this->all_flights[$id]['datetime'], $this->all_flights[$id]['departure_airport_time'], $this->all_flights[$id]['arrival_airport_time'],$this->all_flights[$id]['squawk'],$this->all_flights[$id]['route_stop'],$highlight,$this->all_flights[$id]['hex'],$this->all_flights[$id]['registration'],$this->all_flights[$id]['pilot_id'],$this->all_flights[$id]['pilot_name'],$this->all_flights[$id]['verticalrate'],$this->all_flights[$id]['ground'],$this->all_flights[$id]['format_source'],$this->all_flights[$id]['source_name']);
				    $Spotter->db = null;
				    if ($globalDebug && isset($result)) echo $result."\n";
				    if ($globalDebugTimeElapsed) echo 'Time elapsed for update addspotterdata : '.round(microtime(true)-$timeelapsed,2).'s'."\n";
				    
				    // Add source stat in DB
				    $Stats = new Stats($this->db);
				    if (!empty($this->stats)) {
					if ($globalDebug) echo 'Add source stats : ';
				        foreach($this->stats as $date => $data) {
					    foreach($data as $source => $sourced) {
					        //print_r($sourced);
				    	        if (isset($sourced['polar'])) echo $Stats->addStatSource(json_encode($sourced['polar']),$source,'polar',$date);
				    	        if (isset($sourced['hist'])) echo $Stats->addStatSource(json_encode($sourced['hist']),$source,'hist',$date);
				    		if (isset($sourced['msg'])) {
				    		    if (time() - $sourced['msg']['date'] > 10) {
				    		        $nbmsg = round($sourced['msg']['nb']/(time() - $sourced['msg']['date']));
				    		        echo $Stats->addStatSource($nbmsg,$source,'msg',$date);
			    			        unset($this->stats[$date][$source]['msg']);
			    			    }
			    			}
			    		    }
			    		    if ($date != date('Y-m-d')) {
			    			unset($this->stats[$date]);
			    		    }
				    	}
				    	if ($globalDebug) echo 'Done'."\n";

				    }
				    $Stats->db = null;
				    
				    $this->del();
				} elseif ($globalDebug) echo 'Ignore data'."\n";
				//$ignoreImport = false;
				$this->all_flights[$id]['addedSpotter'] = 1;
				//print_r($this->all_flights[$id]);
			/*
			if (isset($globalArchive) && $globalArchive) {
			    $archives_ident = SpotterLive->getAllLiveSpotterDataByIdent($this->all_flights[$id]['ident']);
			    foreach ($archives_ident as $archive) {
				SpotterArchive->addSpotterArchiveData($archive['flightaware_id'], $archive['ident'], $archive['registration'],$archive['airline_name'],$archive['airline_icao'],$archive['airline_country'],$archive['airline_type'],$archive['aircraft_icao'],$archive['aircraft_shadow'],$archive['aircraft_name'],$archive['aircraft_manufacturer'], $archive['departure_airport_icao'],$archive['departure_airport_name'],$archive['departure_airport_city'],$archive['departure_airport_country'],$archive['departure_airport_time'],
				$archive['arrival_airport_icao'],$archive['arrival_airport_name'],$archive['arrival_airport_city'],$archive['arrival_airport_country'],$archive['arrival_airport_time'],
				$archive['route_stop'],$archive['date'],$archive['latitude'], $archive['longitude'], $archive['waypoints'], $archive['altitude'], $archive['heading'], $archive['ground_speed'],
				$archive['squawk'],$archive['ModeS']);
			    }
			}
			*/
			//SpotterLive->deleteLiveSpotterDataByIdent($this->all_flights[$id]['ident']);
				if ($this->last_delete == 0 || time() - $this->last_delete > 1800) {
				    if ($globalDebug) echo "---- Deleting Live Spotter data older than 9 hours...";
				    //SpotterLive->deleteLiveSpotterDataNotUpdated();
				    $SpotterLive = new SpotterLive($this->db);
				    $SpotterLive->deleteLiveSpotterData();
				    $SpotterLive->db=null;
				    if ($globalDebug) echo " Done\n";
				    $this->last_delete = time();
				}
			    } else {
				if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw' || $line['format_source'] === 'deltadbtxt'|| $line['format_source'] === 'planeupdatefaa'  || $line['format_source'] === 'aprs' || $line['format_source'] === 'aircraftlistjson')) {
				    $this->all_flights[$id]['id'] = $recent_ident;
				    $this->all_flights[$id]['addedSpotter'] = 1;
				}
				if (isset($globalDaemon) && !$globalDaemon) {
					$Spotter = new Spotter($this->db);
					$Spotter->updateLatestSpotterData($this->all_flights[$id]['id'],$this->all_flights[$id]['ident'],$this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],$this->all_flights[$id]['altitude'],$this->all_flights[$id]['ground'],$this->all_flights[$id]['speed'],$this->all_flights[$id]['datetime'],$this->all_flights[$id]['arrival_airport'],$this->all_flights[$id]['arrival_airport_time']);
					$Spotter->db = null;
				}
				
			    }
			}
		    }
		    //adds the spotter LIVE data
		    //SpotterLive->addLiveSpotterData($flightaware_id, $ident, $aircraft_type, $departure_airport, $arrival_airport, $latitude, $longitude, $waypoints, $altitude, $heading, $groundspeed);
		    //echo "\nAdd in Live !! \n";
		    //echo "{$line[8]} {$line[7]} - MODES:{$line[4]}  CALLSIGN:{$line[10]}   ALT:{$line[11]}   VEL:{$line[12]}   HDG:{$line[13]}   LAT:{$line[14]}   LON:{$line[15]}   VR:{$line[16]}   SQUAWK:{$line[17]}\n";
		    if ($globalDebug) {
			if ((isset($globalIVAO) && $globalIVAO) || (isset($globalVATSIM) && $globalVATSIM) || (isset($globalphpVMS) && $globalphpVMS) || (isset($globalVAM) && $globalVAM)) {
				if (isset($this->all_flights[$id]['source_name'])) echo 'DATA : hex : '.$this->all_flights[$id]['hex'].' - ident : '.$this->all_flights[$id]['ident'].' - ICAO : '.$this->all_flights[$id]['aircraft_icao'].' - Departure Airport : '.$this->all_flights[$id]['departure_airport'].' - Arrival Airport : '.$this->all_flights[$id]['arrival_airport'].' - Latitude : '.$this->all_flights[$id]['latitude'].' - Longitude : '.$this->all_flights[$id]['longitude'].' - waypoints : '.$this->all_flights[$id]['waypoints'].' - Altitude : '.$this->all_flights[$id]['altitude'].' - Heading : '.$this->all_flights[$id]['heading'].' - Speed : '.$this->all_flights[$id]['speed'].' - Departure Airport Time : '.$this->all_flights[$id]['departure_airport_time'].' - Arrival Airport time : '.$this->all_flights[$id]['arrival_airport_time'].' - Pilot : '.$this->all_flights[$id]['pilot_name'].' - Source name : '.$this->all_flights[$id]['source_name']."\n";
				else echo 'DATA : hex : '.$this->all_flights[$id]['hex'].' - ident : '.$this->all_flights[$id]['ident'].' - ICAO : '.$this->all_flights[$id]['aircraft_icao'].' - Departure Airport : '.$this->all_flights[$id]['departure_airport'].' - Arrival Airport : '.$this->all_flights[$id]['arrival_airport'].' - Latitude : '.$this->all_flights[$id]['latitude'].' - Longitude : '.$this->all_flights[$id]['longitude'].' - waypoints : '.$this->all_flights[$id]['waypoints'].' - Altitude : '.$this->all_flights[$id]['altitude'].' - Heading : '.$this->all_flights[$id]['heading'].' - Speed : '.$this->all_flights[$id]['speed'].' - Departure Airport Time : '.$this->all_flights[$id]['departure_airport_time'].' - Arrival Airport time : '.$this->all_flights[$id]['arrival_airport_time'].' - Pilot : '.$this->all_flights[$id]['pilot_name']."\n";
			} else {
				if (isset($this->all_flights[$id]['source_name'])) echo 'DATA : hex : '.$this->all_flights[$id]['hex'].' - ident : '.$this->all_flights[$id]['ident'].' - ICAO : '.$this->all_flights[$id]['aircraft_icao'].' - Departure Airport : '.$this->all_flights[$id]['departure_airport'].' - Arrival Airport : '.$this->all_flights[$id]['arrival_airport'].' - Latitude : '.$this->all_flights[$id]['latitude'].' - Longitude : '.$this->all_flights[$id]['longitude'].' - waypoints : '.$this->all_flights[$id]['waypoints'].' - Altitude : '.$this->all_flights[$id]['altitude'].' - Heading : '.$this->all_flights[$id]['heading'].' - Speed : '.$this->all_flights[$id]['speed'].' - Departure Airport Time : '.$this->all_flights[$id]['departure_airport_time'].' - Arrival Airport time : '.$this->all_flights[$id]['arrival_airport_time'].' - Source Name : '.$this->all_flights[$id]['source_name']."\n";
				else echo 'DATA : hex : '.$this->all_flights[$id]['hex'].' - ident : '.$this->all_flights[$id]['ident'].' - ICAO : '.$this->all_flights[$id]['aircraft_icao'].' - Departure Airport : '.$this->all_flights[$id]['departure_airport'].' - Arrival Airport : '.$this->all_flights[$id]['arrival_airport'].' - Latitude : '.$this->all_flights[$id]['latitude'].' - Longitude : '.$this->all_flights[$id]['longitude'].' - waypoints : '.$this->all_flights[$id]['waypoints'].' - Altitude : '.$this->all_flights[$id]['altitude'].' - Heading : '.$this->all_flights[$id]['heading'].' - Speed : '.$this->all_flights[$id]['speed'].' - Departure Airport Time : '.$this->all_flights[$id]['departure_airport_time'].' - Arrival Airport time : '.$this->all_flights[$id]['arrival_airport_time']."\n";
			}
		    }
		    $ignoreImport = false;
		    if ($this->all_flights[$id]['departure_airport'] == "") { $this->all_flights[$id]['departure_airport'] = "NA"; }
		    if ($this->all_flights[$id]['arrival_airport'] == "") { $this->all_flights[$id]['arrival_airport'] = "NA"; }

		    foreach($globalAirportIgnore as $airportIgnore) {
		        if (($this->all_flights[$id]['departure_airport'] == $airportIgnore) || ($this->all_flights[$id]['arrival_airport'] == $airportIgnore)) {
			    $ignoreImport = true;
			}
		    }
		    if (count($globalAirportAccept) > 0) {
		        $ignoreImport = true;
		        foreach($globalAirportIgnore as $airportIgnore) {
			    if (($this->all_flights[$id]['departure_airport'] == $airportIgnore) || ($this->all_flights[$id]['arrival_airport'] == $airportIgnore)) {
				$ignoreImport = false;
			    }
			}
		    }
		    if (isset($globalAirlineIgnore) && is_array($globalAirlineIgnore)) {
			foreach($globalAirlineIgnore as $airlineIgnore) {
			    if ((is_numeric(substr(substr($this->all_flights[$id]['ident'],0,4),-1,1)) && substr($this->all_flights[$id]['ident'],0,3) == $airlineIgnore) || (is_numeric(substr(substr($this->all_flights[$id]['ident'],0,3),-1,1)) && substr($this->all_flights[$id]['ident'],0,2) == $airlineIgnore)) {
				$ignoreImport = true;
			    }
			}
		    }
		    if (isset($globalAirlineAccept) && count($globalAirlineAccept) > 0) {
			$ignoreImport = true;
			foreach($globalAirlineAccept as $airlineAccept) {
			    if ((is_numeric(substr(substr($this->all_flights[$id]['ident'],0,4),-1,1)) && substr($this->all_flights[$id]['ident'],0,3) == $airlineAccept) || (is_numeric(substr(substr($this->all_flights[$id]['ident'],0,3),-1,1)) && substr($this->all_flights[$id]['ident'],0,2) == $airlineAccept)) {
				$ignoreImport = false;
			    }
			}
		    }
		    if (isset($globalPilotIdAccept) && count($globalPilotIdAccept) > 0) {
			$ignoreImport = true;
			foreach($globalPilotIdAccept as $pilotIdAccept) {
			    if ($this->all_flights[$id]['pilot_id'] == $pilotIdAccept) {
			        $ignoreImport = false;
			    }
			}
		    }

		    if (!$ignoreImport) {
			if (!isset($globalDistanceIgnore['latitude']) || (isset($globalDistanceIgnore['latitude']) && $Common->distance($this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],$globalDistanceIgnore['latitude'],$globalDistanceIgnore['longitude']) < $globalDistanceIgnore['distance'])) {
				if ($globalDebug) echo "\o/ Add ".$this->all_flights[$id]['ident']." from ".$this->all_flights[$id]['format_source']." in Live DB : ";
				$timeelapsed = microtime(true);
				$SpotterLive = new SpotterLive($this->db);
				$result = $SpotterLive->addLiveSpotterData($this->all_flights[$id]['id'], $this->all_flights[$id]['ident'], $this->all_flights[$id]['aircraft_icao'], $this->all_flights[$id]['departure_airport'], $this->all_flights[$id]['arrival_airport'], $this->all_flights[$id]['latitude'], $this->all_flights[$id]['longitude'], $this->all_flights[$id]['waypoints'], $this->all_flights[$id]['altitude'], $this->all_flights[$id]['heading'], $this->all_flights[$id]['speed'],$this->all_flights[$id]['datetime'], $this->all_flights[$id]['departure_airport_time'], $this->all_flights[$id]['arrival_airport_time'], $this->all_flights[$id]['squawk'],$this->all_flights[$id]['route_stop'],$this->all_flights[$id]['hex'],$this->all_flights[$id]['putinarchive'],$this->all_flights[$id]['registration'],$this->all_flights[$id]['pilot_id'],$this->all_flights[$id]['pilot_name'], $this->all_flights[$id]['verticalrate'], $this->all_flights[$id]['noarchive'], $this->all_flights[$id]['ground'],$this->all_flights[$id]['format_source'],$this->all_flights[$id]['source_name'],$this->all_flights[$id]['over_country']);
				$SpotterLive->db = null;
				if ($globalDebugTimeElapsed) echo 'Time elapsed for update addlivespotterdata : '.round(microtime(true)-$timeelapsed,2).'s'."\n";

				// Put statistics in $this->stats variable
				//if ($line['format_source'] != 'aprs') {
				//if (isset($line['format_source']) && ($line['format_source'] === 'sbs' || $line['format_source'] === 'tsv' || $line['format_source'] === 'raw' || $line['format_source'] === 'deltadbtxt')) {
				if (isset($line['sourcestats']) && $line['sourcestats'] == TRUE && $line['format_source'] != 'aprs' && $this->all_flights[$id]['latitude'] != '' && $this->all_flights[$id]['longitude'] != '') {
					$source = $this->all_flights[$id]['source_name'];
					if ($source == '') $source = $this->all_flights[$id]['format_source'];
					if (!isset($this->source_location[$source])) {
						$Location = new Source();
						$coord = $Location->getLocationInfobySourceName($source);
						if (count($coord) > 0) {
							$latitude = $coord[0]['latitude'];
							$longitude = $coord[0]['longitude'];
						} else {
							$latitude = $globalCenterLatitude;
							$longitude = $globalCenterLongitude;
						}
						$this->source_location[$source] = array('latitude' => $latitude,'longitude' => $longitude);
					} else {
						$latitude = $this->source_location[$source]['latitude'];
						$longitude = $this->source_location[$source]['longitude'];
					}
					$stats_heading = $Common->getHeading($latitude,$longitude,$this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude']);
					//$stats_heading = $stats_heading%22.5;
					$stats_heading = round($stats_heading/22.5);
					$stats_distance = $Common->distance($latitude,$longitude,$this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude']);
					$current_date = date('Y-m-d');
					if ($stats_heading == 16) $stats_heading = 0;
					if (!isset($this->stats[$current_date][$source]['polar'][1])) {
						for ($i=0;$i<=15;$i++) {
						    $this->stats[$current_date][$source]['polar'][$i] = 0;
						}
						$this->stats[$current_date][$source]['polar'][$stats_heading] = $stats_distance;
					} else {
						if ($this->stats[$current_date][$source]['polar'][$stats_heading] < $stats_distance) {
							$this->stats[$current_date][$source]['polar'][$stats_heading] = $stats_distance;
						}
					}
					$distance = (round($stats_distance/10)*10);
					//echo '$$$$$$$$$$ DISTANCE : '.$distance.' - '.$source."\n";
					//var_dump($this->stats);
					if (!isset($this->stats[$current_date][$source]['hist'][$distance])) {
						if (isset($this->stats[$current_date][$source]['hist'][0])) {
						    end($this->stats[$current_date][$source]['hist']);
						    $mini = key($this->stats[$current_date][$source]['hist'])+10;
						} else $mini = 0;
						for ($i=$mini;$i<=$distance;$i+=10) {
						    $this->stats[$current_date][$source]['hist'][$i] = 0;
						}
						$this->stats[$current_date][$source]['hist'][$distance] = 1;
					} else {
						$this->stats[$current_date][$source]['hist'][$distance] += 1;
					}
				}

				$this->all_flights[$id]['lastupdate'] = time();
				if ($this->all_flights[$id]['putinarchive']) $send = true;
				//if ($globalDebug) echo "Distance : ".Common->distance($this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],$globalDistanceIgnore['latitude'],$globalDistanceIgnore['longitude'])."\n";
				if ($globalDebug) echo $result."\n";
			} elseif (isset($this->all_flights[$id]['latitude']) && isset($globalDistanceIgnore['latitude']) && $globalDebug) echo "!! Too far -> Distance : ".$Common->distance($this->all_flights[$id]['latitude'],$this->all_flights[$id]['longitude'],$globalDistanceIgnore['latitude'],$globalDistanceIgnore['longitude'])."\n";
			//$this->del();
			
			
			if ($this->last_delete_hourly == 0 || time() - $this->last_delete_hourly > 900) {
			    if ($globalDebug) echo "---- Deleting Live Spotter data Not updated since 2 hour...";
			    $SpotterLive = new SpotterLive($this->db);
			    $SpotterLive->deleteLiveSpotterDataNotUpdated();
			    $SpotterLive->db = null;
			    //SpotterLive->deleteLiveSpotterData();
			    if ($globalDebug) echo " Done\n";
			    $this->last_delete_hourly = time();
			}
			
		    }
		    //$ignoreImport = false;
		}
		//if (function_exists('pcntl_fork') && $globalFork) pcntl_signal(SIGCHLD, SIG_IGN);
		if ($send) return $this->all_flights[$id];
	    }
	}
    }
}
?>
