<?php
require_once(dirname(__FILE__).'/libs/simple_html_dom.php');
require_once(dirname(__FILE__).'/libs/uagent/uagent.php');

class Common {
	//protected $cookies = array();
	
	/**
	* Get data from form result
	* @param String $url form URL
	* @param String $type type of submit form method (get or post)
	* @param String|Array $data values form post method
	* @param Array $headers header to submit with the form
	* @return String the result
	*/
	public function getData($url, $type = 'get', $data = '', $headers = '',$cookie = '',$referer = '',$timeout = '',$useragent = '') {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLINFO_HEADER_OUT, true); 
		curl_setopt($ch,CURLOPT_ENCODING , "gzip");
		//curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.1.2) Gecko/20090729 Firefox/3.5.2 GTB5');
//		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (X11; Linux x86_64; rv:42.0) Gecko/20100101 Firefox/42.0');
		if ($useragent == '') {
			curl_setopt($ch, CURLOPT_USERAGENT, UAgent::random());
		} else {
			curl_setopt($ch, CURLOPT_USERAGENT, $useragent);
		}
		if ($timeout == '') curl_setopt($ch, CURLOPT_TIMEOUT, 10); 
		else curl_setopt($ch, CURLOPT_TIMEOUT, $timeout); 
		curl_setopt($ch, CURLOPT_HEADERFUNCTION, array('Common',"curlResponseHeaderCallback"));
		if ($type == 'post') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
			if (is_array($data)) {
				curl_setopt($ch, CURLOPT_POST, count($data));
				$data_string = '';
				foreach($data as $key=>$value) { $data_string .= $key.'='.$value.'&'; }
				rtrim($data_string, '&');
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
			} else {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
			}
		}
		if ($headers != '') {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		if ($cookie != '') {
			if (is_array($cookie)) {
				curl_setopt($ch, CURLOPT_COOKIE, implode($cookie,';'));
			} else {
				curl_setopt($ch, CURLOPT_COOKIE, $cookie);
			}
		}
		if ($referer != '') {
			curl_setopt($ch, CURLOPT_REFERER, $referer);
		}
		$result = curl_exec($ch);
		$info = curl_getinfo($ch);
		curl_close($ch);
		if ($info['http_code'] == '503' && strstr($result,'DDoS protection by CloudFlare')) {
			echo "Cloudflare Detected\n";
			require_once(dirname(__FILE__).'/libs/cloudflare-bypass/libraries/cloudflareClass.php');
			$useragent = UAgent::random();
			cloudflare::useUserAgent($useragent);
			if ($clearanceCookie = cloudflare::bypass($url)) {
				return $this->getData($url,'get',$data,$headers,$clearanceCookie,$referer,$timeout,$useragent);
			}
		} else {
		    return $result;
		}
	}
	
	private function curlResponseHeaderCallback($ch, $headerLine) {
		//global $cookies;
		$cookies = array();
		if (preg_match('/^Set-Cookie:\s*([^;]*)/mi', $headerLine, $cookie) == 1)
			$cookies[] = $cookie;
		return strlen($headerLine); // Needed by curl
	}

	public static function download($url, $file, $referer = '') {
		global $globalDebug;
		$fp = fopen($file, 'w');
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		if ($referer != '') curl_setopt($ch, CURLOPT_REFERER, $referer);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US; rv:1.9.1.2) Gecko/20090729 Firefox/3.5.2 GTB5');
		curl_setopt($ch, CURLOPT_FILE, $fp);
		curl_exec($ch);
		if (curl_errno($ch) && $globalDebug) echo 'Download error: '.curl_error($ch);
		curl_close($ch);
		fclose($fp);
	}
	
	/**
	* Convert a HTML table to an array
	* @param String $data HTML page
	* @return Array array of the tables in HTML page
	*/
	public function table2array($data) {
		if (!is_string($data)) return array();
		if ($data == '') return array();
		$html = str_get_html($data);
		if ($html === false) return array();
		$tabledata=array();
		foreach($html->find('tr') as $element)
		{
			$td = array();
			foreach( $element->find('th') as $row)
			{
				$td [] = trim($row->plaintext);
			}
			$td=array_filter($td);
			$tabledata[] = $td;

			$td = array();
			$tdi = array();
			foreach( $element->find('td') as $row)
			{
				$td [] = trim($row->plaintext);
				$tdi [] = trim($row->innertext);
			}
			$td=array_filter($td);
			$tdi=array_filter($tdi);
			$tabledata[]=array_merge($td,$tdi);
		}
		$html->clear();
		unset($html);
		return(array_filter($tabledata));
	}
	
	/**
	* Convert <p> part of a HTML page to an array
	* @param String $data HTML page
	* @return Array array of the <p> in HTML page
	*/
	public function text2array($data) {
		$html = str_get_html($data);
		if ($html === false) return array();
		$tabledata=array();
		foreach($html->find('p') as $element)
		{
			$tabledata [] = trim($element->plaintext);
		}
		$html->clear();
		unset($html);
		return(array_filter($tabledata));
	}

	/**
	* Give distance between 2 coordonnates
	* @param Float $lat latitude of first point
	* @param Float $lon longitude of first point
	* @param Float $latc latitude of second point
	* @param Float $lonc longitude of second point
	* @param String $unit km else no unit used
	* @return Float Distance in $unit
	*/
	public function distance($lat, $lon, $latc, $lonc, $unit = 'km') {
		if ($lat == $latc && $lon == $lonc) return 0;
		$dist = rad2deg(acos(sin(deg2rad(floatval($lat)))*sin(deg2rad(floatval($latc)))+ cos(deg2rad(floatval($lat)))*cos(deg2rad(floatval($latc)))*cos(deg2rad(floatval($lon)-floatval($lonc)))))*60*1.1515;
		if ($unit == "km") {
			return round($dist * 1.609344);
		} elseif ($unit == "m") {
			return round($dist * 1.609344 * 1000);
		} elseif ($unit == "mile" || $unit == "mi") {
			return round($dist);
		} elseif ($unit == "nm") {
			return round($dist*0.868976);
		} else {
			return round($dist);
		}
	}

	/**
	* Check is distance realistic
	* @param int $timeDifference the time between the reception of both messages
	* @param float $distance distance covered
	* @return whether distance is realistic
	*/
	public function withinThreshold ($timeDifference, $distance) {
		$x = abs($timeDifference);
		$d = abs($distance);
		if ($x == 0 || $d == 0) return true;
		// may be due to Internet jitter; distance is realistic
		if ($x < 0.7 && $d < 2000) return true;
		else return $d/$x < 1500*0.27778; // 1500 km/h max
	}


	// Check if an array is assoc
	public function isAssoc($array)
	{
		return ($array !== array_values($array));
	}

	public function isInteger($input){
	    return(ctype_digit(strval($input)));
	}


	public function convertDec($dms,$latlong) {
		if ($latlong == 'latitude') {
			$deg = substr($dms, 0, 2);
			$min = substr($dms, 2, 4);
		} else {
			$deg = substr($dms, 0, 3);
			$min = substr($dms, 3, 5);
		}
		return $deg+(($min*60)/3600);
	}
	
	/**
	* Copy folder contents
	* @param       string   $source    Source path
	* @param       string   $dest      Destination path
	* @return      bool     Returns true on success, false on failure
	*/
	public function xcopy($source, $dest)
	{
		$files = glob($source.'*.*');
		foreach($files as $file){
			$file_to_go = str_replace($source,$dest,$file);
			copy($file, $file_to_go);
		}
		return true;
	}
	
	/**
	* Check if an url exist
	* @param	String $url url to check
	* @return	bool Return true on succes false on failure
	*/
	public function urlexist($url){
		$headers=get_headers($url);
		return stripos($headers[0],"200 OK")?true:false;
	}
	
	/**
	* Convert hexa to string
	* @param	String $hex data in hexa
	* @return	String Return result
	*/
	public function hex2str($hex) {
		$str = '';
		$hexln = strlen($hex);
		for($i=0;$i<$hexln;$i+=2) $str .= chr(hexdec(substr($hex,$i,2)));
		return $str;
	}
	
	
	public function getHeading($lat1, $lon1, $lat2, $lon2) {
		//difference in longitudinal coordinates
		$dLon = deg2rad($lon2) - deg2rad($lon1);
		//difference in the phi of latitudinal coordinates
		$dPhi = log(tan(deg2rad($lat2) / 2 + pi() / 4) / tan(deg2rad($lat1) / 2 + pi() / 4));
		//we need to recalculate $dLon if it is greater than pi
		if(abs($dLon) > pi()) {
			if($dLon > 0) {
				$dLon = (2 * pi() - $dLon) * -1;
			} else {
				$dLon = 2 * pi() + $dLon;
			}
		}
		//return the angle, normalized
		return (rad2deg(atan2($dLon, $dPhi)) + 360) % 360;
	}
	
	public function checkLine($lat1,$lon1,$lat2,$lon2,$lat3,$lon3,$approx = 0.1) {
		//$a = ($lon2-$lon1)*$lat3+($lat2-$lat1)*$lon3+($lat1*$lon2+$lat2*$lon1);
		$a = -($lon2-$lon1);
		$b = $lat2 - $lat1;
		$c = -($a*$lat1+$b*$lon1);
		$d = $a*$lat3+$b*$lon3+$c;
		if ($d > -$approx && $d < $approx) return true;
		else return false;
	}
	
	public function array_merge_noappend() {
		$output = array();
		foreach(func_get_args() as $array) {
			foreach($array as $key => $value) {
				$output[$key] = isset($output[$key]) ?
				array_merge($output[$key], $value) : $value;
			}
		}
		return $output;
	}
	

	public function arr_diff($arraya, $arrayb) {
		foreach ($arraya as $keya => $valuea) {
			if (in_array($valuea, $arrayb)) {
				unset($arraya[$keya]);
			}
		}
		return $arraya;
	}

	/*
	* Check if a key exist in an array
	* Come from http://stackoverflow.com/a/19420866
	* @param Array array to check
	* @param String key to check
	* @return Bool true if exist, else false
	*/
	public function multiKeyExists(array $arr, $key) {
		// is in base array?
		if (array_key_exists($key, $arr)) {
			return true;
		}
		// check arrays contained in this array
		foreach ($arr as $element) {
			if (is_array($element)) {
				if ($this->multiKeyExists($element, $key)) {
					return true;
				}
			}
		}
		return false;
	}
	
	/**
	* Returns list of available locales
	*
	* @return array
	 */
	public function listLocaleDir()
	{
		$result = array('en');
		if (!is_dir('./locale')) {
			return $result;
		}
		$handle = @opendir('./locale');
		if ($handle === false) return $result;
		while (false !== ($file = readdir($handle))) {
			$path = './locale'.'/'.$file.'/LC_MESSAGES/fam.mo';
			if ($file != "." && $file != ".." && @file_exists($path)) {
				$result[] = $file;
			}
		}
		closedir($handle);
		return $result;
	}

	public function nextcoord($latitude, $longitude, $speed, $heading, $archivespeed = 1){
		global $globalMapRefresh;
		$distance = ($speed*0.514444*$globalMapRefresh*$archivespeed)/1000;
		$r = 6378;
		$latitude = deg2rad($latitude);
		$longitude = deg2rad($longitude);
		$bearing = deg2rad($heading); 
		$latitude2 =  asin( (sin($latitude) * cos($distance/$r)) + (cos($latitude) * sin($distance/$r) * cos($bearing)) );
		$longitude2 = $longitude + atan2( sin($bearing)*sin($distance/$r)*cos($latitude), cos($distance/$r)-(sin($latitude)*sin($latitude2)) );
		return array('latitude' => number_format(rad2deg($latitude2),5,'.',''),'longitude' => number_format(rad2deg($longitude2),5,'.',''));
	}
	
	public function getCoordfromDistanceBearing($latitude,$longitude,$bearing,$distance) {
		// distance in meter
		$R = 6378.14;
		$latitude1 = $latitude * (M_PI/180);
		$longitude1 = $longitude * (M_PI/180);
		$brng = $bearing * (M_PI/180);
		$d = $distance;

		$latitude2 = asin(sin($latitude1)*cos($d/$R) + cos($latitude1)*sin($d/$R)*cos($brng));
		$longitude2 = $longitude1 + atan2(sin($brng)*sin($d/$R)*cos($latitude1),cos($d/$R)-sin($latitude1)*sin($latitude2));

		$latitude2 = $latitude2 * (180/M_PI);
		$longitude2 = $longitude2 * (180/M_PI);

		$flat = round ($latitude2,6);
		$flong = round ($longitude2,6);
/*
		$dx = $distance*cos($bearing);
		$dy = $distance*sin($bearing);
		$dlong = $dx/(111320*cos($latitude));
		$dlat = $dy/110540;
		$flong = $longitude + $dlong;
		$flat = $latitude + $dlat;
*/
		return array('latitude' => $flat,'longitude' => $flong);
	}

	/**
	 * GZIPs a file on disk (appending .gz to the name)
	 *
	 * From http://stackoverflow.com/questions/6073397/how-do-you-create-a-gz-file-using-php
	 * Based on function by Kioob at:
	 * http://www.php.net/manual/en/function.gzwrite.php#34955
	 * 
	 * @param string $source Path to file that should be compressed
	 * @param integer $level GZIP compression level (default: 9)
	 * @return string New filename (with .gz appended) if success, or false if operation fails
	 */
	public function gzCompressFile($source, $level = 9){ 
		$dest = $source . '.gz'; 
		$mode = 'wb' . $level; 
		$error = false; 
		if ($fp_out = gzopen($dest, $mode)) { 
			if ($fp_in = fopen($source,'rb')) { 
				while (!feof($fp_in)) 
					gzwrite($fp_out, fread($fp_in, 1024 * 512)); 
				fclose($fp_in); 
			} else {
				$error = true; 
			}
			gzclose($fp_out); 
		} else {
			$error = true; 
		}
		if ($error)
			return false; 
		else
			return $dest; 
	} 
	
	public function remove_accents($string) {
		if ( !preg_match('/[\x80-\xff]/', $string) ) return $string;
		$chars = array(
		    // Decompositions for Latin-1 Supplement
		    chr(195).chr(128) => 'A', chr(195).chr(129) => 'A',
		    chr(195).chr(130) => 'A', chr(195).chr(131) => 'A',
		    chr(195).chr(132) => 'A', chr(195).chr(133) => 'A',
		    chr(195).chr(135) => 'C', chr(195).chr(136) => 'E',
		    chr(195).chr(137) => 'E', chr(195).chr(138) => 'E',
		    chr(195).chr(139) => 'E', chr(195).chr(140) => 'I',
		    chr(195).chr(141) => 'I', chr(195).chr(142) => 'I',
		    chr(195).chr(143) => 'I', chr(195).chr(145) => 'N',
		    chr(195).chr(146) => 'O', chr(195).chr(147) => 'O',
		    chr(195).chr(148) => 'O', chr(195).chr(149) => 'O',
		    chr(195).chr(150) => 'O', chr(195).chr(153) => 'U',
		    chr(195).chr(154) => 'U', chr(195).chr(155) => 'U',
		    chr(195).chr(156) => 'U', chr(195).chr(157) => 'Y',
		    chr(195).chr(159) => 's', chr(195).chr(160) => 'a',
		    chr(195).chr(161) => 'a', chr(195).chr(162) => 'a',
		    chr(195).chr(163) => 'a', chr(195).chr(164) => 'a',
		    chr(195).chr(165) => 'a', chr(195).chr(167) => 'c',
		    chr(195).chr(168) => 'e', chr(195).chr(169) => 'e',
		    chr(195).chr(170) => 'e', chr(195).chr(171) => 'e',
		    chr(195).chr(172) => 'i', chr(195).chr(173) => 'i',
		    chr(195).chr(174) => 'i', chr(195).chr(175) => 'i',
		    chr(195).chr(177) => 'n', chr(195).chr(178) => 'o',
		    chr(195).chr(179) => 'o', chr(195).chr(180) => 'o',
		    chr(195).chr(181) => 'o', chr(195).chr(182) => 'o',
		    chr(195).chr(182) => 'o', chr(195).chr(185) => 'u',
		    chr(195).chr(186) => 'u', chr(195).chr(187) => 'u',
		    chr(195).chr(188) => 'u', chr(195).chr(189) => 'y',
		    chr(195).chr(191) => 'y',
		    // Decompositions for Latin Extended-A
		    chr(196).chr(128) => 'A', chr(196).chr(129) => 'a',
		    chr(196).chr(130) => 'A', chr(196).chr(131) => 'a',
		    chr(196).chr(132) => 'A', chr(196).chr(133) => 'a',
		    chr(196).chr(134) => 'C', chr(196).chr(135) => 'c',
		    chr(196).chr(136) => 'C', chr(196).chr(137) => 'c',
		    chr(196).chr(138) => 'C', chr(196).chr(139) => 'c',
		    chr(196).chr(140) => 'C', chr(196).chr(141) => 'c',
		    chr(196).chr(142) => 'D', chr(196).chr(143) => 'd',
		    chr(196).chr(144) => 'D', chr(196).chr(145) => 'd',
		    chr(196).chr(146) => 'E', chr(196).chr(147) => 'e',
		    chr(196).chr(148) => 'E', chr(196).chr(149) => 'e',
		    chr(196).chr(150) => 'E', chr(196).chr(151) => 'e',
		    chr(196).chr(152) => 'E', chr(196).chr(153) => 'e',
		    chr(196).chr(154) => 'E', chr(196).chr(155) => 'e',
		    chr(196).chr(156) => 'G', chr(196).chr(157) => 'g',
		    chr(196).chr(158) => 'G', chr(196).chr(159) => 'g',
		    chr(196).chr(160) => 'G', chr(196).chr(161) => 'g',
		    chr(196).chr(162) => 'G', chr(196).chr(163) => 'g',
		    chr(196).chr(164) => 'H', chr(196).chr(165) => 'h',
		    chr(196).chr(166) => 'H', chr(196).chr(167) => 'h',
		    chr(196).chr(168) => 'I', chr(196).chr(169) => 'i',
		    chr(196).chr(170) => 'I', chr(196).chr(171) => 'i',
		    chr(196).chr(172) => 'I', chr(196).chr(173) => 'i',
		    chr(196).chr(174) => 'I', chr(196).chr(175) => 'i',
		    chr(196).chr(176) => 'I', chr(196).chr(177) => 'i',
		    chr(196).chr(178) => 'IJ',chr(196).chr(179) => 'ij',
		    chr(196).chr(180) => 'J', chr(196).chr(181) => 'j',
		    chr(196).chr(182) => 'K', chr(196).chr(183) => 'k',
		    chr(196).chr(184) => 'k', chr(196).chr(185) => 'L',
		    chr(196).chr(186) => 'l', chr(196).chr(187) => 'L',
		    chr(196).chr(188) => 'l', chr(196).chr(189) => 'L',
		    chr(196).chr(190) => 'l', chr(196).chr(191) => 'L',
		    chr(197).chr(128) => 'l', chr(197).chr(129) => 'L',
		    chr(197).chr(130) => 'l', chr(197).chr(131) => 'N',
		    chr(197).chr(132) => 'n', chr(197).chr(133) => 'N',
		    chr(197).chr(134) => 'n', chr(197).chr(135) => 'N',
		    chr(197).chr(136) => 'n', chr(197).chr(137) => 'N',
		    chr(197).chr(138) => 'n', chr(197).chr(139) => 'N',
		    chr(197).chr(140) => 'O', chr(197).chr(141) => 'o',
		    chr(197).chr(142) => 'O', chr(197).chr(143) => 'o',
		    chr(197).chr(144) => 'O', chr(197).chr(145) => 'o',
		    chr(197).chr(146) => 'OE',chr(197).chr(147) => 'oe',
		    chr(197).chr(148) => 'R',chr(197).chr(149) => 'r',
		    chr(197).chr(150) => 'R',chr(197).chr(151) => 'r',
		    chr(197).chr(152) => 'R',chr(197).chr(153) => 'r',
		    chr(197).chr(154) => 'S',chr(197).chr(155) => 's',
		    chr(197).chr(156) => 'S',chr(197).chr(157) => 's',
		    chr(197).chr(158) => 'S',chr(197).chr(159) => 's',
		    chr(197).chr(160) => 'S', chr(197).chr(161) => 's',
		    chr(197).chr(162) => 'T', chr(197).chr(163) => 't',
		    chr(197).chr(164) => 'T', chr(197).chr(165) => 't',
		    chr(197).chr(166) => 'T', chr(197).chr(167) => 't',
		    chr(197).chr(168) => 'U', chr(197).chr(169) => 'u',
		    chr(197).chr(170) => 'U', chr(197).chr(171) => 'u',
		    chr(197).chr(172) => 'U', chr(197).chr(173) => 'u',
		    chr(197).chr(174) => 'U', chr(197).chr(175) => 'u',
		    chr(197).chr(176) => 'U', chr(197).chr(177) => 'u',
		    chr(197).chr(178) => 'U', chr(197).chr(179) => 'u',
		    chr(197).chr(180) => 'W', chr(197).chr(181) => 'w',
		    chr(197).chr(182) => 'Y', chr(197).chr(183) => 'y',
		    chr(197).chr(184) => 'Y', chr(197).chr(185) => 'Z',
		    chr(197).chr(186) => 'z', chr(197).chr(187) => 'Z',
		    chr(197).chr(188) => 'z', chr(197).chr(189) => 'Z',
		    chr(197).chr(190) => 'z', chr(197).chr(191) => 's'
		);
		$string = strtr($string, $chars);
		return $string;
	}
}
?>