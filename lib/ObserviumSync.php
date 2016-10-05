<?php

/**
 * lib/ObserviumSync.php.
 *
 * This class syncronizes Observium devices with a network management solution.
 * 
 *
 * PHP version 5
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3.0 of the License, or (at your option) any later version.
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category  default
 *
 * @author    Andrew Jones
 * @copyright 2016 @authors
 * @license   http://www.gnu.org/copyleft/lesser.html The GNU LESSER GENERAL PUBLIC LICENSE, Version 3.0
 */
namespace ohtarr;

class ObserviumSync
{

	//public $NM_DEVICES = json_decode();
	public $NM_DEVICES;
	public $OBS_DEVICES;
	public $OBS_GROUPS;
	public $SNOW_LOCS;			//array of locations from SNOW
	public $OBSBASEURL = "https://netmon.kiewitplaza.com/api/";
	public $logmsg = "";

    public function __construct()
	{
		global $DB;
		$this->NM_DEVICES = $this->Netman_get_cisco_devices();		//populate array of switches from Network Management Platform
		$this->SNOW_LOCS = $this->Snow_get_valid_locations();	//populate a list of locations from SNOW
		$this->OBS_DEVICES = $this->obs_get_devices();	//populate a list of Observium devices
		$this->OBS_GROUPS = $this->obs_get_groups();
/*
		if (empty($this->NM_DEVICES)		||
			empty($this->SNOW_LOCS)			||
			empty($this->OBS_DEVICES)
			)
		{
			$DB->log("ObserviumSync failed: 1 or more data sources are empty!");
			die();
		}
/**/
	}

	public function __destruct()
	{
/*
		global $DB;
		if ($this->logmsg){
			$DB->log($this->logmsg);
		}
/**/
	}

	/*
	Hit a reporting API to log all automation tasks.
	$params format:
							[	"origin_hostname"	=>	"test",
								"processname"		=>	"tester",
								"category"			=>	"Network",
								"timesaved"			=>	"2",
								"datestarted"		=>	"2016-05-26 09:34:00.000",
								"datefinished"		=>	"2016-05-26 09:35:00.000",
								"success"			=>	"1",
					//			"target_hostname"	=>	"1",	//optional
					//			"triggeredby"		=>	test,	//optional
					//			"description"		=>	test,	//optional
					//			"target_ip"			=>	test,	//optional
					//			"notes"				=>	test,	//optional
					];
	/**/

/*
 	public function automation_report($params)
	{
		if(!$params){
			$params = [];
		}
		$baseparams = [	"origin_hostname"	=>	"netman",
						"processname"		=>	"E911_EGWSYNC",
						"category"			=>	"Network",
						"timesaved"			=>	"5",
						"datestarted"		=>	date('Y/m/d H:i:s'),
						"datefinished"		=>	date('Y/m/d H:i:s'),
						"success"			=>	"1",
						"target_hostname"	=>	"E911_EGW",											//optional
						"triggeredby"		=>	"netman",											//optional
						"description"		=>	"Netman E911_EGWSYNC function completed",			//optional
						"target_ip"			=>	"10.123.123.91",									//optional
						"notes"				=>	"A generic E911_EGW function as been completed",	//optional
		];
		$newparams = array_merge($baseparams, $params);
		$URI = API_REPORTING_URL;											//api to hit e911 raw DB
		$response = \Httpful\Request::post($URI)								//Build a GET request...
								->authenticateWith(LDAP_USER, LDAP_PASS)		//basic authentication
								->body($newparams)									//parameters to send in body
								->sendsType(\Httpful\Mime::FORM)				//we are sending basic forms
								->send()										//execute the request
								->body;											//only give us the body back
		return $response;
	}
*/

	/*
    [WCDBCVAN] => Array
        (
            [zip] => V5C 0G5
            [u_street_2] =>
            [street] => 123 Fast Creek Drive
            [name] => XXXXXXXX
            [state] => BC
            [sys_id] => 11ccf5b16ffb020034cb07321c3ee4b1
            [country] => CA
            [city] => Burnaby
        )
	/**/
	public function Snow_get_valid_locations(){

		$SNOW = new \ohtarr\ServiceNowRestClient;		//new snow rest api instance

		$PARAMS = array(								//parameters needed for SNOW API call
							"u_active"                	=>	"true",
							"sysparm_fields"        	=>	"sys_id,name,street,u_street_2,city,state,zip,country",
		);

		$RESPONSE = $SNOW->SnowTableApiGet("cmn_location", $PARAMS);	//get all locations from snow api
		foreach($RESPONSE as $loc){										//loop through all locations returned from snow
			$snowlocs[$loc[name]] = $loc;								//build new array with sitecode as the key
		}
		ksort($snowlocs);												//sort by key

/*
		$fp = fopen('/opt/ohtarr/SNOWDATA.json', 'w');
		fwrite($fp, json_encode($snowlocs));
		fclose($fp);
/**/

		return $snowlocs;												//return new array
		//$str = file_get_contents('/opt/ohtarr/SNOWDATA.json');
		//return json_decode($str, true);
	}

	public function Netman_get_cisco_devices(){

		$CERTFILE   = "/opt/networkautomation/archive/netman.ldapint.pem";
		$CERTPASS   = "";

		$OPTIONS = [
			//Generic client stuff
			CURLOPT_COOKIEJAR       => '/opt/networkautomation/cookiejar',
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_FOLLOWLOCATION  => true,
			CURLOPT_USERAGENT       => "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)",
			//CA / Server certs
			CURLOPT_SSL_VERIFYPEER  => true,        // Validate the server cert is valid & signed by a trusted CA
			CURLOPT_SSL_VERIFYHOST  => 1,           // Only trust directly-issued certs, no intermediates
			//User certs
			CURLOPT_SSLCERTTYPE     => "PEM",       // Our user cert and key file format is base64 encoded PEM
			CURLOPT_SSLCERT         => $CERTFILE,   // Our user cert filename
			//CURLOPT_SSLCERTPASSWD => $CERTPASS,   // And private key password
			//Debugging
			//CURLOPT_CERTINFO      => true,
			//CURLOPT_VERBOSE       => true,
				];

		$postparams = [	"category"	=>	"Management",
				"type"		=>	"Device_Network_Cisco"
		];

		$URL = BASEURL . 'information/api/search/';

		$request = \Httpful\Request::post($URL);
		foreach( $OPTIONS as $key => $val) {
				$request->addOnCurlOption($key, $val);
		}
		$DEVICEIDS = $request->body(json_encode($postparams))				//parameters to send in body
							->send()										//execute the request
							->body;											//only give us the body back

		$DEVICEIDS = get_object_vars($DEVICEIDS);
		//sort($DEVICEIDS);

		$DEVICEIDS = $DEVICEIDS[results];

		foreach($DEVICEIDS as $deviceid){
			$URL = BASEURL . 'information/api/retrieve/?id=' . $deviceid;

			$request = \Httpful\Request::get($URL);
			foreach( $OPTIONS as $key => $val) {
					$request->addOnCurlOption($key, $val);
			}
			$response = $request-> send();
			//\metaclassing\Utility::dumper($response->body);
			$device = \metaclassing\Utility::objectToArray($response->body->object);

			$newarray[$device['data']['id']]['name'] = 	$device['data']['name'];
			$newarray[$device['data']['id']]['id'] = 		$device['data']['id'];
			$newarray[$device['data']['id']]['ip'] = 		$device['data']['ip'];
			$newarray[$device['data']['id']]['model'] = 	$device['data']['model'];
			//print_r($newarray);
			//exit;
		}
		ksort($newarray);
		return $newarray;
/**/
/*
		$fp = fopen('/opt/ohtarr/NMDATA.json', 'w');
		fwrite($fp, json_encode($newarray));
		fclose($fp);
/**/
/*
		$str = file_get_contents('/opt/ohtarr/NMDATA.json');
		return json_decode($str, true);
/**/
	}

	public function obs_get_devices(){

		$OPTIONS = [
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_FOLLOWLOCATION  => true,
			CURLOPT_USERAGENT       => "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)",
				];

		$URL = $this->OBSBASEURL . '?type=device';

		$request = \Httpful\Request::get($URL);
		foreach( $OPTIONS as $key => $val) {
				$request->addOnCurlOption($key, $val);
		}
		$response = $request->send();										//execute the request

		$devices = \metaclassing\Utility::objectToArray($response->body->data);

		return $devices;
/**/
/*
		$fp = fopen('/opt/ohtarr/OBSDATA.json', 'w');
		fwrite($fp, json_encode($devices));
		fclose($fp);
/**/
/*
		$str = file_get_contents('/opt/ohtarr/OBSDATA.json');
		return json_decode($str, true);
/**/
	}

	public function obs_get_groups(){

		$OPTIONS = [
			CURLOPT_RETURNTRANSFER  => true,
			CURLOPT_FOLLOWLOCATION  => true,
			CURLOPT_USERAGENT       => "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)",
				];

		$URL = $this->OBSBASEURL . '?type=group';

		$request = \Httpful\Request::get($URL);
		foreach( $OPTIONS as $key => $val) {
				$request->addOnCurlOption($key, $val);
		}
		$response = $request->send();										//execute the request

		$groups = \metaclassing\Utility::objectToArray($response->body->data);

		return $groups;
	}

	public function obs_devices_to_add(){
		//build array of netman devices
		$newnmarray = array();
		foreach($this->NM_DEVICES as $nmid => $nmdevice){
			if (!empty($nmdevice['name'])){
				$newnmarray[] = strtolower($nmdevice['name']);
			}
		}
		sort($newnmarray);
		//print_r($newnmarray);

		//build array of observium devices
		$newobsarray = array();
		foreach($this->OBS_DEVICES as $obsid => $obsdevice){


			$newobsarray[] = chop($obsdevice['hostname'],".net.kiewitplaza.com");
		}
		sort($newobsarray);
		//print_r($newobsarray);
/*
		foreach($newobsarray as $key => $value){
			if (empty($value)) {
				unset($newobsarray[$key]);
			}
		}


		$newobsarray = array_values($newobsarray);
/**/
		return array_values(array_diff($newnmarray, $newobsarray));
	}

	public function obs_devices_to_remove(){

		$newnmarray = array();
		foreach($this->NM_DEVICES as $nmid => $nmdevice){
			$newnmarray[] = strtolower($nmdevice['name']);
		}
		sort($newnmarray);
		//print_r($newnmarray);

		//build array of observium devices
		$newobsarray = array();
		foreach($this->OBS_DEVICES as $obsid => $obsdevice){

//			$newobsarray[] = chop($obsdevice['hostname'],".net.kiewitplaza.com");
			$newobsarray[] = $obsdevice['hostname'];
		}
		sort($newobsarray);
		//print_r($newobsarray);

		return array_values(array_diff($newobsarray, $newnmarray));
	}

	public function obs_add_device($hostname){

		$URL = $this->OBSBASEURL;

		$postparams = [	"action"	=>	"add_device",
						"hostname"	=>	$hostname];

		$OPTIONS = [
		CURLOPT_RETURNTRANSFER  => true,
		CURLOPT_FOLLOWLOCATION  => true,
		CURLOPT_USERAGENT       => "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)",
			];

		$request = \Httpful\Request::post($URL);

		foreach( $OPTIONS as $key => $val) {
				$request->addOnCurlOption($key, $val);
		}

		$DEVICE = $request->body(json_encode($postparams))				//parameters to send in body
							->send()										//execute the request
							->body;											//only give us the body back

		//$DEVICE = get_object_vars($DEVICE);
		$DEVICE = \metaclassing\Utility::objectToArray($DEVICE);

		//\metaclassing\Utility::dumper($DEVICE);
		//If device is an ACCESS SWITCH, disable PORTS module.

		if($DEVICE['success'] == true){
			$reg = "/^\D{5}\S{3}.*(sw[api]|SW[API])[0-9]{2,4}.*$/";                   //regex to match ACCESS switches only
			if (preg_match($reg,$hostname, $hits)){
				$postparams2 = [	"action"	=>	"modify_device",
									"id"		=>	$DEVICE['data']['device_id'],
									"option"	=>	"disable_port_discovery",
									//"debug"		=>	1,
				];
				$request2 = \Httpful\Request::post($URL);
				$response2 = $request2->body(json_encode($postparams2))
								->send()
								->body;

				$response2 = \metaclassing\Utility::objectToArray($response2);

				$postparams3 = [	"action"	=>	"modify_device",
									"id"		=>	$DEVICE['data']['device_id'],
									"option"	=>	"disable_port_polling",
				];

				$request3 = \Httpful\Request::post($URL);
				$response3 = $request3->body(json_encode($postparams3))
								->send()
								->body;

				$response3 = \metaclassing\Utility::objectToArray($response3);

				//\metaclassing\Utility::dumper($response2);
				//\metaclassing\Utility::dumper($response3);


			}
		}
		return $DEVICE;
	}

	public function obs_add_devices(){
                $this->logmsg .= "***ADD_DEVICES*** ";
		$counter = 0;
		$adddevices = $this->obs_devices_to_add();
		//print_r($adddevices);

//		while($counter < 75){
//                        \metaclassing\Utility::dumper($this->obs_add_device($adddevices[$counter]));
//			$counter++;
//		}


		foreach ($adddevices as $adddevice){
			//print $adddevice . "\n";
			//print "\n";
			$this->logmsg .= $adddevice . ", ";
			\metaclassing\Utility::dumper($this->obs_add_device($adddevice));
			//print "\n";
			//return $this->obs_add_device($adddevice);
			//break;
		}

	}

	public function obs_remove_device($params){

		$URL = $this->OBSBASEURL;

		$postparams['action'] = "delete_device";
		if ($params['id']){
			$postparams['id'] = $params['id'];
		} elseif ($params['hostname']){
			$postparams['hostname'] = $params['hostname'];
		} else {
			return 'Missing parameter "id" or "hostname" !!!';
		}

		$OPTIONS = [
		CURLOPT_RETURNTRANSFER  => true,
		CURLOPT_FOLLOWLOCATION  => true,
		CURLOPT_USERAGENT       => "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)",
			];

		$request = \Httpful\Request::post($URL);

		foreach( $OPTIONS as $key => $val) {
				$request->addOnCurlOption($key, $val);
		}
		
		$RESPONSE = $request->body(json_encode($postparams))				//parameters to send in body
							->send()										//execute the request
							->body;											//only give us the body back

		$RESPONSE = \metaclassing\Utility::objectToArray($RESPONSE);
	
		return $RESPONSE;
	}

	public function obs_remove_devices(){
                $this->logmsg .= "***REMOVE_DEVICES*** ";
		$deldevices = $this->obs_devices_to_remove();
		foreach($deldevices as $hostname){
			$this->logmsg .= $hostname . ", ";
			$result = $this->obs_remove_device(array("hostname"=>$hostname));
		}
	}

	public function obs_site_groups_to_add(){	
		$snowsites = $this->SNOW_LOCS;
		
		foreach($snowsites as $sitename => $site){
			$snowsitenames[] = $sitename;
		}
		sort($snowsitenames);
		//print_r($snowsitenames);

		$obsgroups = $this->OBS_GROUPS;

		$regex = "/SITE_/";
		$obsgroupnames = array();
		foreach($obsgroups as $groupid => $group){
			if(preg_match($regex, $group['group_name'], $hits)){
				$obsgroupnames[] = substr($group['group_name'], 5);
			}
		}
		sort($obsgroupnames);
		//print_r($obsgroupnames);
		
		return array_values(array_diff($snowsitenames, $obsgroupnames));
	}

	public function obs_site_groups_to_remove(){

		$snowsites = $this->SNOW_LOCS;
		
		foreach($snowsites as $sitename => $site){
			$snowsitenames[] = "SITE_" . $sitename;
		}
		sort($snowsitenames);
		//print_r($snowsitenames);

		$obsgroups = $this->OBS_GROUPS;

		$obsgroupnames = array();
		$regex = "/SITE_/";
		foreach($obsgroups as $groupid => $group){
			if(preg_match($regex, $group['group_name'], $hits)){
				$obsgroupnames[] = $group['group_name'];
			}
		}
		sort($obsgroupnames);
		//print_r($obsgroupnames);

		return array_values(array_diff($obsgroupnames, $snowsitenames));
	}

	public function obs_add_site_group($sitename){
		$URL = $this->OBSBASEURL;

		$postparams = [	"action"				=>	"add_group",
						"group_type"			=>	"device",
						"name"					=>	"SITE_".$sitename,
						"description"			=>	"Default site group for " . $sitename,
						"device_association"	=>	"hostname match " . $sitename . "*",
						"entity_association"	=>	"*",
						];

		$OPTIONS = [
		CURLOPT_RETURNTRANSFER  => true,
		CURLOPT_FOLLOWLOCATION  => true,
		CURLOPT_USERAGENT       => "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)",
			];

		$request = \Httpful\Request::post($URL);

		foreach( $OPTIONS as $key => $val) {
				$request->addOnCurlOption($key, $val);
		}
		
		$response = $request->body(json_encode($postparams))				//parameters to send in body
							->send()										//execute the request
							->body;											//only give us the body back
		
		//$DEVICE = get_object_vars($DEVICE);
		$status = \metaclassing\Utility::objectToArray($response);
		return $status;
	}

	public function obs_add_site_groups(){
                $this->logmsg .= "***ADD_SITE-GROUPS*** ";
		$addsites = $this->obs_site_groups_to_add();

		foreach ($addsites as $site){
			$this->logmsg .= $site . ", ";
			$this->obs_add_site_group($site);
		}
	}

	public function obs_remove_site_group($sitename){
		$URL = $this->OBSBASEURL;

		$postparams = [	"action"				=>	"delete_group",
						"name"					=>	$sitename,
						];

		$OPTIONS = [
		CURLOPT_RETURNTRANSFER  => true,
		CURLOPT_FOLLOWLOCATION  => true,
		CURLOPT_USERAGENT       => "Mozilla/4.0 (compatible; MSIE 5.01; Windows NT 5.0)",
			];

		$request = \Httpful\Request::post($URL);

		foreach( $OPTIONS as $key => $val) {
				$request->addOnCurlOption($key, $val);
		}
		
		$response = $request->body(json_encode($postparams))				//parameters to send in body
							->send()										//execute the request
							->body;											//only give us the body back
		
		//$DEVICE = get_object_vars($DEVICE);
		$status = \metaclassing\Utility::objectToArray($response);
		return $status;
	}

	public function obs_remove_site_groups(){
                $this->logmsg .= "***REMOVE_SITE-GROUPS*** ";
		$delsites = $this->obs_site_groups_to_remove();

		foreach ($delsites as $sitename){
			$this->logmsg .= $sitename . ", ";
			$this->obs_remove_site_group($sitename);
		}

	}

	public function obs_remove_all_site_groups(){
		foreach($this->OBS_GROUPS as $group){
			$this->obs_remove_site_group($group['group_name']);
		}
	}

	public function obs_remove_all_devices(){
		foreach($this->OBS_DEVICES as $id => $device){
			print $device['hostname'] . "\n";
			$result = $this->obs_remove_device(array("id"=>$id));
			print $result['success'] . "\n";
		}
	}

	public function obs_remove_dup_devices(){
		$devices = $this->OBS_DEVICES;
		//return array_diff_key( $devices , array_unique( $devices ) );
		//return array_count_values($devices);
		//return count($devices) !== count(array_unique($devices));

        foreach($this->OBS_DEVICES as $obsid => $obsdevice){
            $newobsarray[] = chop($obsdevice['hostname'],".net.kiewitplaza.com");
        }
        sort($newobsarray);
		/*
		print "Obs Devices : \n";
        print_r($newobsarray);
		print "Obs Duplicates : \n:";
		print_r(array_values(array_diff_key($newobsarray , array_unique($newobsarray))));
		/**/
		$dups = array_values(array_diff_key($newobsarray , array_unique($newobsarray)));

		foreach($dups as $dup){
			$result = $this->obs_remove_device(array("hostname"=>$dup));
		}

	}
/**/
}
