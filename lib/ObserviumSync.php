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

use GuzzleHttp\Client as GuzzleHttpClient;
use GuzzleHttp\Cookie\FileCookieJar as FileCookieJar;
use Dotenv\Dotenv as Dotenv;

class ObserviumSync
{

	//public $NM_DEVICES = json_decode();
	public $NM_DEVICES;			//array of Netman cisco devices
	public $OBS_DEVICES;		//array of Observium devices
	public $OBS_GROUPS;			//array of Observium Groups
	public $SNOW_LOCS;			//array of locations from SNOW
	public $logmsg = "";
	public $NetmonClient;		//Netmon GUZZLE REQUEST
	public $NetmanClient;		//Netman GUZZLE REQUEST
	public $SnowClient;			//SNOW GUZZLE REQUEST

    public function __construct()
	{
		$dotenv = new Dotenv(__DIR__."/../");
		$dotenv->load();
		global $DB;
		$this->NetmonClient = new GuzzleHttpClient([
			'base_uri' => getenv('OBSERVIUM_BASE_URI'),
		]);

		$this->NetmanClient = new GuzzleHttpClient([
			'base_uri' => getenv('NETMAN_BASE_URI'),
			//'cert' => getenv('NETMAN_CERT'),
		]);

		$this->SnowClient = new GuzzleHttpClient([
			'base_uri' => getenv('SNOW_BASE_URI'),
		]);

		$this->DcoapiClient = new GuzzleHttpClient([
			'base_uri' => getenv('DCOAPI_BASE_URI'),
		]);
		
		//$this->NM_DEVICES = $this->Netman_get_cisco_devices();		//populate array of switches from Network Management Platform
		//$this->SNOW_LOCS = $this->Snow_get_valid_locations();	//populate a list of locations from SNOW
		//$this->OBS_DEVICES = $this->obs_get_devices();	//populate a list of Observium devices
		//$this->OBS_GROUPS = $this->obs_get_groups();
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

	}

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
	public function Snow_get_valid_locations()
	{
		if($this->SNOW_LOCS)
		{
			return $this->SNOW_LOCS;
		} else {
			//Build a Guzzle GET request, get all SNOW locs, active and innactive.
			$apiRequest = $this->SnowClient->request('GET', getenv('SNOW_API_URI'), [
				'query' => [
					//'u_active' => "true", 
					'sysparm_fields' => "sys_id,u_active,name,street,u_street_2,city,state,zip,country,latitude,longitude"
				],
				'auth' => [
					getenv('SNOW_USERNAME'), 
					getenv('SNOW_PASSWORD')
				],
			]);
			$response = json_decode($apiRequest->getBody()->getContents(), true); //EXECUTE GUZZLE REQUEST

			foreach($response['result'] as $loc){							//loop through all locations returned from snow
				$snowlocs[$loc[name]] = $loc;								//build new array with sitecode as the key
			}
			ksort($snowlocs);												//sort by key

			$this->SNOW_LOCS = $snowlocs;									//return new array
			return $this->SNOW_LOCS;
		}
	}

	/*
	public function Netman_get_cisco_devices()
	{

		$postparams = [
				"category"  =>  "Management",
				"type"      =>  "Device_Network_Cisco"
		];

		$apiRequest = $this->NetmanClient->request('POST', getenv('NETMAN_SEARCH_API_URI'), [
				'json' => $postparams,
		]);
		$DEVICEIDS = json_decode($apiRequest->getBody()->getContents(), true);
		$DEVICEIDS = $DEVICEIDS['results'];
		
		foreach($DEVICEIDS as $deviceid){

			$apiRequest = $this->NetmanClient->request('GET', getenv('NETMAN_RETRIEVE_API_URI'), [
				'query' => ['id' => $deviceid],
			]);
			$device = json_decode($apiRequest->getBody()->getContents(), true);

			$newarray[$device['object']['data']['id']]['name'] = 	$device['object']['data']['name'];
			$newarray[$device['object']['data']['id']]['id'] = 		$device['object']['data']['id'];
			$newarray[$device['object']['data']['id']]['ip'] = 		$device['object']['data']['ip'];
			$newarray[$device['object']['data']['id']]['model'] = 	$device['object']['data']['model'];
			
			foreach (preg_split('/\r\n|\r|\n/', $device['object']['data']['run']) as $LINE) {
				if (preg_match("/snmp-server location (.+)/", $LINE, $MATCH)) {
					$SNMPLOCATION = $MATCH[1];
					break;
				}
			}
			$SNMPARRAY = json_decode($SNMPLOCATION,true);			
			if (is_array($SNMPARRAY)) {
				$newarray[$device['object']['data']['id']]['snmp'] = $SNMPARRAY;
			}
		
		}
		ksort($newarray);
		return $newarray;

	}
	/**/

	public function Netman_get_cisco_devices()
	{
		if($this->NM_DEVICES)
		{
			return $this->NM_DEVICES;
		} else {
			//Build a Guzzle GET request
			$apiRequest = $this->NetmanClient->request('GET', "reports/device-monitoring-netmon.php");
			//decode the JSON into an array
			$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true); //EXECUTE GUZZLE REQUEST
			//print_r($RESPONSE);
			
			$this->NM_DEVICES = $RESPONSE['devices']; //return the devices in the response.
			return $this->NM_DEVICES;
		}
	}

	public function obs_get_devices()
	{
		if($this->OBS_DEVICES)
		{
			return $this->OBS_DEVICES;
		} else {
			//Build a Guzzle GET request
			$apiRequest = $this->NetmonClient->request('GET', 'api/', [
				'query' => ['type' => 'device'],
				'auth' => [
					getenv('OBS_USERNAME'), 
					getenv('OBS_PASSWORD')
				],
			]);

			$devices = json_decode($apiRequest->getBody()->getContents(), true); //EXECUTE GUZZLE REQUEST

			$this->OBS_DEVICES = $devices['data'];
			return $this->OBS_DEVICES;
		}
	}

	public function obs_get_groups()
	{
		if($this->OBS_GROUPS)
		{
			return $this->OBS_GROUPS;
		} else {
			//Build a Guzzle GET request
			$apiRequest = $this->NetmonClient->request('GET', 'api/', [
				'query' => ['type' => 'group'],
				'auth' => [
					getenv('OBS_USERNAME'), 
					getenv('OBS_PASSWORD')
				],
			]);

			$groups = json_decode($apiRequest->getBody()->getContents(), true); //EXECUTE GUZZLE REQUEST

			$this->OBS_GROUPS = $groups['data'];
			return $this->OBS_GROUPS;
		}
	}

	public function obs_get_ports($obsdeviceid)
	{
		//NETMON API PARAMETERS
		$postparams = [
			"action"	=>	"dbquery",
			"table"		=>	"ports",
			"key"		=>	"device_id",
			"id"		=>	$obsdeviceid,									
		];
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
				'json' => $postparams,
				'auth' => [
					getenv('OBS_USERNAME'), 
					getenv('OBS_PASSWORD')
				],
		]);

		if($ports = json_decode($apiRequest->getBody()->getContents(), true)) //EXECUTE GUZZLE REQUEST
		{
			return $ports['data'];
		} else {
			return null;
		}
	}

	public function obs_get_port($ports, $intname)
	{
		//Cycle through provided list of ports.
		foreach($ports as $port)
		{
			//Locate port that matches the provided name.
			if(strtolower($port['ifDescr']) == strtolower($intname) || strtolower($port['ifName']) == strtolower($intname))
			{
				return $port; //Return said port.
			}
		}
	}

	public function nm_get_device($hostname)
	{
		//Loop through all NM_DEVICES
		foreach($this->Netman_get_cisco_devices() as $devicename => $device)
		{
			//Locate device with name that matches provided name
			if (strtoupper($hostname) == strtoupper($devicename))
			{
				//If we find a match, return it!
				return $device;
			}
		}
		return null; //otherwise return null
	}
	
	public function obs_get_device($hostname)
	{
		//Cycle through all OBS_DEVICES
		foreach($this->obs_get_devices() as $device)
		{
			//Find a device that matches provided name
			if (strtoupper($hostname) == strtoupper($device['hostname']))
			{
				return $device; //If we find a match, RETURN IT!
			}
		}
		return null; //otherwise return null
	}

	public function get_snow_location($locname)
	{
		//Loop through all SNOW_LOCS
		foreach($this->Snow_get_valid_locations() as $sitename => $site)
		{
			//Fine a loc that matches provided locname
			if (strtoupper($locname) == strtoupper($sitename))
			{
				return $site; //If we find a match, RETURN IT!
			}		
		}
		return null; //otherwise return null
	}

	public function obs_devices_to_add()
	{
		//build array of netman devices
		$newnmarray = array();
		//Loop through NM_DEVICES
		foreach($this->Netman_get_cisco_devices() as $devicename => $nmdevice)
		{
			//If the device has MON=1 configured in SNMP LOC and the name of device isn't empty
			if($nmdevice['snmploc']['mon'] === 1 && !empty($nmdevice['name']))
			{
				$newnmarray[] = $nmdevice['name']; //Add to our array
			}
		}
		sort($newnmarray); //sort the array
		//print_r($newnmarray);
		//build array of observium devices
		$newobsarray = array();
		//loop through all OBS_DEVICES
		foreach($this->obs_get_devices() as $obsid => $obsdevice)
		{
			$newobsarray[] = $obsdevice['hostname']; //Build an array of obs device names
		}
		sort($newobsarray);  //sort the array
		//print_r($newobsarray);

		//Compare the 2 arrays and make a new array of the differences (Devices to add)
		$newarray = array_values(array_diff($newnmarray, $newobsarray));

		return $newarray; //return devices to add
	}

	public function obs_devices_to_remove()
	{
		//loop through OBS_DEVICES
		foreach($this->obs_get_devices() as $obsid => $obsdevice)
		{
			//print "OBS DEVICE: " . $obsdevice['hostname'] . "\n";
			$exists = 0;
			$mon = 1;
			//If the device exists in Netman
			if($nmdevice = $this->nm_get_device($obsdevice['hostname']))
			{
				//print "Device " . $obsdevice['hostname'] . " Exists in NETMAN!\n";
				$exists = 1;
				//If the device is configured for MON=0
				if($nmdevice['snmploc']['mon'] === 0)
				{
					//print "Mon = 0\n";
					$mon = 0;
				}
			}
			//If the device does NOT exist in netman, or MON=0
			if($exists === 0 || $mon === 0)
			{
				//print "Adding to array to remove!!\n";
				//add to array of devices to remove.
				$removedevices[] = $obsdevice['hostname'];
			}
		}
		//If there are any items in the array
		if (is_array($removedevices))
		{
			sort($removedevices); //sort the array
			return $removedevices; //return it
		} else {
			return null; //otherwise return null
		}
	}

	public function obs_add_device($hostname)
	{
		//parameters for our Guzzle request
		$postparams = [	"action"	=>	"add_device",
						"hostname"	=>	$hostname];

		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
				'json' => $postparams,
				'auth' => [
					getenv('OBS_USERNAME'), 
					getenv('OBS_PASSWORD')
				],
		]);
		$DEVICE = json_decode($apiRequest->getBody()->getContents(), true);  //execute the request

		/*
		if($DEVICE['success'] == true){
			//If device is an ACCESS SWITCH, disable PORTS module.
			$reg = "/^\D{5}\S{3}.*(sw[api]|SW[API])[0-9]{2,4}.*$/";                   //regex to match ACCESS switches only
			if (preg_match($reg,$hostname, $hits)){
				$postparams2 = [	"type"		=>	"device",
									"id"		=>	$DEVICE['data']['device_id'],
									"option"	=>	"discover_ports",
									"value"		=>	"0",
									//"debug"		=>	1,
				];
				//Build a Guzzle POST request
				$apiRequest = $this->NetmonClient->request('POST', 'api/', [
					'json' => $postparams2,
					'auth' => [
						getenv('OBS_USERNAME'), 
						getenv('OBS_PASSWORD')
					],						
				]);
				$response2 = json_decode($apiRequest->getBody()->getContents(), true);

				$postparams3 = [	"type"		=>	"device",
									"id"		=>	$DEVICE['data']['device_id'],
									"option"	=>	"poll_ports",
									"value"		=>	"0",
									//"debug"		=>	1,
				];
				//Build a Guzzle POST request
				$apiRequest = $this->NetmonClient->request('POST', 'api/', [
					'json' => $postparams3,
					'auth' => [
						getenv('OBS_USERNAME'), 
						getenv('OBS_PASSWORD')
					],
				]);
				$response3 = json_decode($apiRequest->getBody()->getContents(), true);
			}
		}
		/**/
		return $DEVICE;
	}

	public function obs_add_devices()
	{
		print "***ADDING DEVICES***\n";

		//Get list of devices that need to be added
		$adddevices = $this->obs_devices_to_add();

		//loop through each device and attempt to add them
		foreach ($adddevices as $adddevice){
			print "Adding device " . $adddevice . ".....";
			$RESPONSE = $this->obs_add_device($adddevice);
			if($RESPONSE['success'] == 1)
			{
				print "SUCCESS!\n";
			} else {
				print "FAILED! " . $RESPONSE['message'] . "\n";
			}
		}
	}

	public function obs_remove_device($params)
	{
		//parameters for netmon api
		$postparams['action'] = "delete_device";
		//If ID is provided, include it
		if ($params['id']){
			$postparams['id'] = $params['id'];
		//otherwise use hostname if it's provided
		} elseif ($params['hostname']){
			$postparams['hostname'] = $params['hostname'];
		//otherwise just error out.
		} else {
			return 'Missing parameter "id" or "hostname" !!!';
		}
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
			'json' => $postparams,
			'auth' => [
				getenv('OBS_USERNAME'), 
				getenv('OBS_PASSWORD')
			],
		]);
		//execute the request
		$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);
	
		return $RESPONSE;
	}

	public function obs_remove_devices()
	{
		print "***REMOVING_DEVICES***\n";
		//retrieve list of devices that need to be removed
		if($deldevices = $this->obs_devices_to_remove())
		{
			//loop through the devices
			foreach($deldevices as $hostname){
				print "Removing device " . $hostname . ".....";
				//attempt removal of device
				$result = $this->obs_remove_device(array("hostname"=>$hostname));
				if ($result)
				{
					print "SUCCESS!\n";
				} else {
					print "FAILED!\n";
				}
			}
		}
	}

	public function obs_site_groups_to_add()
	{	
		//loop through each SNOW location
		foreach($this->Snow_get_valid_locations() as $sitename => $site){
			//If the site is ACTIVE in SNOW, add it to our list.
			if($site['u_active'] == "true")
			{
				$snowsitenames[] = $sitename;
			}
		}
		sort($snowsitenames);
		
		$regex = "/SITE_/";
		//Check if any OBS GROUPS exist
		if(!empty($this->obs_get_groups()))
		{
			//Loop through OBS Groups
			foreach($this->obs_get_groups() as $groupid => $group){
				//locate any GROUPS that prefix with SITE_ and add them to our list
				if(preg_match($regex, $group['group_name'], $hits)){
					$obsgroupnames[] = substr($group['group_name'], 5);
				}
			}
			sort($obsgroupnames);
			//compare SNOW SITES list to OBS GROUPS to find any that are missing from OBS
			return array_values(array_diff($snowsitenames, $obsgroupnames));
		} else {
			//If there are NO OBS GROUPS, then we need to add ALL snow sites
			return $snowsitenames;
		}
	}

	public function obs_site_groups_to_remove()
	{
		//Loop through all SNOW LOCS
		foreach($this->Snow_get_valid_locations() as $sitename => $site){
			//Find any ACTIVE sites and add them to our list.  PREPEND SITE_ in front of them.
			if($site['u_active'] == true)
			{
				$snowsitenames[] = "SITE_" . $sitename;
			}
		}
		sort($snowsitenames);

		$regex = "/SITE_/";
		//Make sure list from OBS is not empty.
		if(!empty($this->obs_get_groups()))
		{
			//Loop through OBS GROUPS and find any that begin with SITE_, add them to our list
			foreach($this->obs_get_groups() as $groupid => $group){
				if(preg_match($regex, $group['group_name'], $hits)){
					$obsgroupnames[] = $group['group_name'];
				}
			}
			sort($obsgroupnames);
			//compare the 2 lists and return any group names that need to be removed from SNOW.
			return array_values(array_diff($obsgroupnames, $snowsitenames));
		} else {
			//If there are NO groups in OBS, then we don't need to remove anything.
			return null;
		}
	}

	public function obs_add_site_group($sitename)
	{
		$postparams = [	"action"				=>	"add_group",
						"group_type"			=>	"device",
						"name"					=>	"SITE_".$sitename,
						"description"			=>	"Default site group for " . $sitename,
						"device_association"	=>	"hostname match " . $sitename . "*",
						"entity_association"	=>	"*",
						];
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
				'json' => $postparams,
				'auth' => [
					getenv('OBS_USERNAME'), 
					getenv('OBS_PASSWORD')
				],
		]);

		$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);

		return $RESPONSE;
	}

	public function obs_add_site_groups()
	{
		print "***ADDING_SITE_GROUPS***\n";
		//get a list of SITE GROUPS that need to be added
		$addsites = $this->obs_site_groups_to_add();
		//Loop through list of site groups and add each.
		foreach ($addsites as $site){
			print "ADDING Site Group SITE_" . $site . "......";
			$RESPONSE = $this->obs_add_site_group($site);
			if ($RESPONSE['success'] == 1)
			{
				print "SUCCESS!\n";
			} else {
				print "FAILED! {$RESPONSE['message']}\n";
			}
		}
	}

	public function obs_remove_site_group($sitename)
	{
		$postparams = [	"action"				=>	"delete_group",
						"name"					=>	$sitename,
						];
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
				'json' => $postparams,
				'auth' => [
					getenv('OBS_USERNAME'), 
					getenv('OBS_PASSWORD')
				],
		]);

		$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);
		
		return $RESPONSE;
	}

	public function obs_remove_site_groups()
	{
		print "***REMOVING_SITE_GROUPS***\n";
		$delsites = $this->obs_site_groups_to_remove();
		if(!empty($delsites))
		{
			foreach ($delsites as $sitename)
			{
				print "REMOVING Site Group SITE_" . $sitename . "......";
				$this->obs_remove_site_group($sitename);
				if ($RESPONSE['success'] == 1)
				{
					print "SUCCESS!\n";
				} else {
					print "FAILED! {$RESPONSE['message']}\n";
				}
			}
		} else {
			print "NO SITES GROUPS TO REMOVE!\n";
		}
	}

	public function obs_remove_all_site_groups()
	{
		foreach($this->obs_get_groups() as $group){
			$this->obs_remove_site_group($group['group_name']);
		}
	}

	public function obs_remove_all_devices()
	{
		foreach($this->obs_get_devices() as $id => $device){
			print $device['hostname'] . "\n";
			$result = $this->obs_remove_device(array("id"=>$id));
			print $result['success'] . "\n";
		}
	}

	public function obs_remove_dup_devices()
	{
        foreach($this->obs_get_devices() as $obsid => $obsdevice){
            $newobsarray[] = chop($obsdevice['hostname'],".net.kiewitplaza.com");
        }
        sort($newobsarray);

		$dups = array_values(array_diff_key($newobsarray , array_unique($newobsarray)));

		foreach($dups as $dup){
			$result = $this->obs_remove_device(array("hostname"=>$dup));
		}
	}

	public function obs_set_location_string($deviceid, $addrstring)
	{
		//print $addrstring . "\n";
		$postparams = [
			"action"	=>	"set_entity_attrib",
			"type"		=>	"device",
			"id"		=>	$deviceid,
			"option"	=>	"override_sysLocation_bool",
			"value"		=>	"1",
		];
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
				'json' => $postparams,
				'auth' => [
					getenv('OBS_USERNAME'), 
					getenv('OBS_PASSWORD')
				],
		]);
		$DEVICE = json_decode($apiRequest->getBody()->getContents(), true);					
		//print_r($DEVICE);
		//print "\n";
		$postparams2 = [
			"action"	=>	"set_entity_attrib",
			"type"		=>	"device",
			"id"		=>	$deviceid,
			"option"	=>	"override_sysLocation_string",
			"value"		=>	$addrstring,
		];
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
				'json' => $postparams2,
				'auth' => [
					getenv('OBS_USERNAME'), 
					getenv('OBS_PASSWORD')
				],
		]);
		$DEVICE2 = json_decode($apiRequest->getBody()->getContents(), true);					
		//print_r($DEVICE2);
		//print "\n";
	
		if ($DEVICE['success'] == 1 && $DEVICE2['success'] == 1)
		{
			return true;
		} else {
			return false;
		}
	}
	
	public function obs_set_location_coords($deviceid,$lat,$lon)
	{
		if($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180 )
		{
			$postparams = [
				"action"	=>	"dbquery",
				"table"		=>	"devices_locations",
				"key"		=>	"device_id",
				"id"		=>	$deviceid,
			];
			//Build a Guzzle POST request
			$apiRequest = $this->NetmonClient->request('POST', 'api/', [
					'json' => $postparams,
					'auth' => [
						getenv('OBS_USERNAME'), 
						getenv('OBS_PASSWORD')
					],
			]);
			$DEVICE = json_decode($apiRequest->getBody()->getContents(), true);					
			if ($DEVICE['success'])
			{
				$obslocation = $DEVICE['data'][0];
				
				$postparams2 = [
					"action"	=>	"dbupdate",
					"params"	=>	[
						"location_lat"		=>	$lat,
						"location_lon"		=>	$lon,
						"location_manual"	=>	"1",				
					],
					"table"		=>	"devices_locations",
					"key"		=>	"location_id",
					"id"		=>	$obslocation['location_id'],
				];
				//Build a Guzzle POST request
				$apiRequest2 = $this->NetmonClient->request('POST', 'api/', [
						'json' => $postparams2,
						'auth' => [
							getenv('OBS_USERNAME'), 
							getenv('OBS_PASSWORD')
						],
				]);
				$DEVICE2 = json_decode($apiRequest2->getBody()->getContents(), true);	
			}
		}
		if($DEVICE2['success'])
		{
			return true;
		} else {
			return false;
		}
	}

	public function obs_set_locations()
	{
		print "***LOCATION SETTINGS***\n";
		foreach($this->obs_get_devices() as $deviceid => $device)
		{
			if($nmdevice = $this->nm_get_device($device['hostname']))
			{
				print "*** DEVICE ID: {$deviceid}, DEVICE NAME: {$device['hostname']} ***\n";				
				if($locname = $nmdevice['snmploc']['site'])
				{
					if($site = $this->get_snow_location($locname))
					{
						$addrstring = $site['name'] . "," . $site['street'] . "," . $site['city'] . "," . $site['state'] . "," . $site['zip'] . "," . $site['country'];
						print "	SET LOCATION STRING " . $addrstring . " ........";
						if($this->obs_set_location_string($deviceid, $addrstring))
						{
							print "SUCCESS!\n";
						} else {
							print "FAILED!\n";
						}
						if (strlen($site['latitude']) > 0 && strlen($site['longitude']) > 0 && $site['latitude'] >= -90 && $site['latitude'] <= 90 && $site['longitude'] >= -180 && $site['longitude'] <= 180)
						{
							print "	SET COORDS " . $site[latitude] . "," . $site[longitude] . " .........";
							if($this->obs_set_location_coords($deviceid,$site['latitude'],$site['longitude']))
							{
								print "SUCCESS!\n";
							} else {
								print "FAILED!\n";
							}
						} else {
							print "COORDS INVALID OR MISSING!!! SET DEFAULT COORDS : \n";
							print $this->obs_set_location_coords($deviceid,37.7463058,-45.0000000) . "\n";							
						}
					} else {
						print "No SITE found in SNOW! \n";
						continue;
					}
				//break; //debugging
				} else {
					print "No LOCATION found for device in Network Manager! \n";
					continue;
				}
			} else {
				print "No device found in Network Manager! \n";
				continue;
			}
			unset($nmdevice);
			unset($locname);
		}
	}
	
	public function obs_unset_coords()
	{
		foreach($this->obs_get_devices() as $deviceid => $device){

			$postparams = [
				"action"	=>	"dbquery",
				"table"		=>	"devices_locations",
				"key"		=>	"device_id",
				"id"		=>	$deviceid,
			];
			//Build a Guzzle POST request
			$apiRequest = $this->NetmonClient->request('POST', 'api/', [
					'json' => $postparams,
					'auth' => [
						getenv('OBS_USERNAME'), 
						getenv('OBS_PASSWORD')
					],
			]);
			$DEVICE = json_decode($apiRequest->getBody()->getContents(), true);					
			
			$obslocation = $DEVICE['data'][0];
			
			$postparams2 = [
				"action"	=>	"dbupdate",
				"params"	=>	[
					//"location_lat"		=>	$lat,
					//"location_lon"		=>	$lon,
					"location_manual"	=>	"0",				
				],
				"table"		=>	"devices_locations",
				"key"		=>	"location_id",
				"id"		=>	$obslocation['location_id'],
			];
			//Build a Guzzle POST request
			$apiRequest2 = $this->NetmonClient->request('POST', 'api/', [
					'json' => $postparams2,
					'auth' => [
						getenv('OBS_USERNAME'), 
						getenv('OBS_PASSWORD')
					],
			]);
			$DEVICE2 = json_decode($apiRequest2->getBody()->getContents(), true);					
		}
	}

    public function obs_add_50_devices()
    {
        $this->logmsg .= "***ADD_DEVICES*** ";
        $counter = 0;
        $adddevices = $this->obs_devices_to_add();
        //print_r($adddevices);

        foreach ($adddevices as $adddevice){
			if($counter < 50)
			{
	            $this->logmsg .= $adddevice . ", ";
	            print_r($this->obs_add_device($adddevice));
				$counter++;
			} else {
				break;
			}
        }

    }
	
	public function obs_device_toggle_ignore($hostname, $ignore)
	{
		if($hostname)
		{
			if($ignore === 0 || $ignore === 1)
			{
				$postparams = [
					'action'	=>	'dbupdate',
					'table'		=>	'devices',
					'key'		=>	'hostname',
					'id'		=>	$hostname,
					'params'	=>	[
						'ignore'	=>	$ignore
					]
				];
				//Build a Guzzle POST request
				$apiRequest = $this->NetmonClient->request('POST', 'api/', [
					'json' => $postparams,
					'auth' => [
						getenv('OBS_USERNAME'), 
						getenv('OBS_PASSWORD')
					],
				]);

				$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);
			
				return $RESPONSE['success'];
			} else {
				print "Invalid parameter provided!";
				return 0;
			}
		} else {
			print "No hostname found!";
			return 0;
		}
	}

	public function obs_devices_to_ignore()
	{
		foreach($this->obs_get_devices() as $obsdevice)
		{
			if($obsdevice['ignore'] == 0)
			{
				if($nmdevice = $this->nm_get_device($obsdevice['hostname']))
				{
					if($nmdevice['snmploc']['alert'] === 0)
					{
						$ignoredevices[] = $obsdevice['hostname'];
					}
				}
			}
		}
		if(isset($ignoredevices))
		{
			sort($ignoredevices);
			return $ignoredevices;
		} else {
			return null;
		}

	}	

	public function obs_devices_to_unignore()
	{
		foreach($this->obs_get_devices() as $obsdevice)
		{
			if($obsdevice['ignore'] == 1)
			{
				if($nmdevice = $this->nm_get_device($obsdevice['hostname']))
				{
					if($nmdevice['snmploc']['alert'] === 1)
					{
						$unignoredevices[] = $obsdevice['hostname'];
					}
				}
			}
		}
		if(isset($unignoredevices))
		{
			sort($unignoredevices);
			return $unignoredevices;
		} else {
			return null;
		}

	}	

	public function obs_ignore_devices()
	{
		print "***** IGNORING DEVICES ******\n";
		$ignoredevices = $this->obs_devices_to_ignore();
		if($ignoredevices)
		{
			foreach($ignoredevices as $ignoredevice)
			{
				print "IGNORE DEVICE : " . $ignoredevice . "...........";
				if($this->obs_device_toggle_ignore($ignoredevice, 1))
				{
					print "SUCCESS!\n";
				} else {
					print "FAILED!\n";
				}
			}
		}
	}

	public function obs_unignore_devices()
	{
		print "***** UNIGNORING DEVICES ******\n";
		$unignoredevices = $this->obs_devices_to_unignore();
		if($unignoredevices)
		{
			foreach($unignoredevices as $unignoredevice)
			{
				print "UNIGNORE DEVICE : " . $unignoredevice . "...........";
				if($this->obs_device_toggle_ignore($unignoredevice, 0))
				{
					print "SUCCESS!\n";
				} else {
					print "FAILED!\n";
				}
			}
		}
	}
	
	public function obs_port_toggle_ignore($portid, $ignore)
	{
		if(is_int($portid))
		{
			if($ignore === 0 || $ignore === 1)
			{
				$postparams = [
					'action'	=>	'dbupdate',
					'table'		=>	'ports',
					'key'		=>	'port_id',
					'id'		=>	$portid,
					'params'	=>	[
						'ignore'	=>	$ignore
					]
				];
				//Build a Guzzle POST request
				$apiRequest = $this->NetmonClient->request('POST', 'api/', [
					'json' => $postparams,
					'auth' => [
						getenv('OBS_USERNAME'), 
						getenv('OBS_PASSWORD')
					],
				]);

				$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);
				return $RESPONSE['success'];
			} else {
				print "Invalid parameter provided!\n";
				return 0;
			}
		} else {
			print "INVALID PORT ID!\n";
			return 0;
		}
	}
	
	public function obs_ports_to_ignore()
	{
		foreach($this->Netman_get_cisco_devices() as $nmdevice)
		{
			if($nmdevice['interfaces'])
			{
				//print "INTERFACES EXIST on " . $nmdevice['name'] . "!!\n";
				if($obsdevice = $this->obs_get_device($nmdevice['name']))
				{
					//print "OBS DEVICE EXISTS! \n";
					if($obsports = $this->obs_get_ports($obsdevice['device_id']))
					{
						//print "OBTAINED OBS DEVICE PORTS! \n";
						foreach($nmdevice['interfaces'] as $iname => $iconfig)
						{
							//print_r($iconfig);
							if($iconfig['description']['ALERT'] === 0)
							{
								//print $nmdevice['name'] . " interface " . $iname . " is set to ALERT=1! \n";
								if($obsport = $this->obs_get_port($obsports,$iname))
								{
									//print "RETRIEVED OBS PORT!\n";
									if($obsport['ignore'] == 0)
									{
										//print "OBS PORT IS CURRENTLY NOT IGNORED!! ADD TO ARRAY!\n";
										$ignoreports[] = [
											"device_name"		=>	$nmdevice['name'],
											"port_name"			=>	$obsport['IfDescr'],
											"obs_device_id"		=>	intval($obsdevice['device_id']),
											"obs_port_id"		=>	intval($obsport['port_id']),
										];
									}
								}
							}
						}
					}
				}						
			}
		}
		if(isset($ignoreports))
		{
			return $ignoreports;
		} else {
			return null;
		}
	}	

	public function obs_ports_to_unignore()
	{
		foreach($this->Netman_get_cisco_devices() as $nmdevice)
		{
			if($nmdevice['interfaces'])
			{
				//print "INTERFACES EXIST on " . $nmdevice['name'] . "!!\n";
				if($obsdevice = $this->obs_get_device($nmdevice['name']))
				{
					//print "OBS DEVICE EXISTS! \n";
					if($obsports = $this->obs_get_ports($obsdevice['device_id']))
					{
						//print "OBTAINED OBS DEVICE PORTS! \n";
						foreach($nmdevice['interfaces'] as $iname => $iconfig)
						{
							//print_r($iconfig);
							if($iconfig['description']['ALERT'] === 1)
							{
								//print $nmdevice['name'] . " interface " . $iname . " is set to ALERT=1! \n";
								if($obsport = $this->obs_get_port($obsports,$iname))
								{
									//print "RETRIEVED OBS PORT!\n";
									if($obsport['ignore'] == 1)
									{
										//print "OBS PORT IS CURRENTLY IGNORED!! ADD TO ARRAY!\n";
										$unignoreports[] = [
											"device_name"		=>	$nmdevice['name'],
											"port_name"			=>	$obsport['IfDescr'],
											"obs_device_id"		=>	intval($obsdevice['device_id']),
											"obs_port_id"		=>	intval($obsport['port_id']),
										];
									}
								}
							}
						}
					}
				}						
			}
		}
		if(isset($unignoreports))
		{
			return $unignoreports;
		} else {
			return null;
		}
	}
	
	public function obs_ignore_ports()
	{
		print "***** IGNORING PORTS ******\n";
		if($ignoreports = $this->obs_ports_to_ignore())
		{		
			foreach($ignoreports as $ignoreport)
			{
				print "IGNORING PORT ID " . $ignoreport['obs_port_id'] . ".............";
				if($this->obs_port_toggle_ignore($ignoreport['obs_port_id'], 1))
				{
					print "SUCCESS!\n";
				} else {
					print "FAILED!\n";
				}
			}
		} else {
			print "--NO PORTS TO IGNORE!\n";
		}
	}
	
	public function obs_unignore_ports()
	{
		print "***** UNIGNORING PORTS ******\n";
		if($unignoreports = $this->obs_ports_to_unignore())
		{
			foreach($unignoreports as $unignoreport)
			{
				print "UNIGNORING PORT ID " . $unignoreport['obs_port_id'] . ".............";
				if($this->obs_port_toggle_ignore($unignoreport['obs_port_id'], 0))
				{
					print "SUCCESS!\n";
				} else {
					print "FAILED!\n";
				}
			}
		} else {
            print "--NO PORTS TO UNIGNORE!\n";
		}
	}
	
	public function obs_port_toggle_disabled($portid, $disabled)
	{
		if($portid)
		{
			if($disabled === 1 || $disabled === 0)
			{
				$postparams = [
					'action'	=>	'dbupdate',
					'table'		=>	'ports',
					'key'		=>	'port_id',
					'id'		=>	$portid,
					'params'	=>	[
						'disabled'		=>	$disabled,
					]
				];	
				if($disabled === 1)
				{
					$postparams['params']['ifAdminStatus'] = "down";
					$postparams['params']['ignore'] = 1;
				}
				//Build a Guzzle POST request
				$apiRequest = $this->NetmonClient->request('POST', 'api/', [
					'json' => $postparams,
					'auth' => [
						getenv('OBS_USERNAME'), 
						getenv('OBS_PASSWORD')
					],
				]);

				$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);
				$this->obs_alert_disable($portid);
//				if ($RESPONSE['success'] == 1 && $this->obs_alert_disable($portid) == 1)
				if ($RESPONSE['success'] == 1)
				{
					return 1;
				} else {
					return 0;
				}
			} else {
			print "Invalid parameter provided!";
			return 0;
			}
				//return $RESPONSE;
		} else {
			print "No PORT ID found!";
			return 0;
		}
	}
	
	public function obs_ports_to_disable()
	{
		foreach($this->Netman_get_cisco_devices() as $nmdevice)
		{
			if($nmdevice['interfaces'])
			{
				//print "INTERFACES EXIST on " . $nmdevice['name'] . "!!\n";
				if($obsdevice = $this->obs_get_device($nmdevice['name']))
				{
					//print_r($obsdevice);
					//print "OBS DEVICE EXISTS! \n";
					if($obsports = $this->obs_get_ports($obsdevice['device_id']))
					{
						//print "OBTAINED OBS DEVICE PORTS! \n";
						foreach($nmdevice['interfaces'] as $iname => $iconfig)
						{
							//print_r($iconfig);
							if($iconfig['description']['MON'] === 0)
							{
								//print $nmdevice['name'] . " interface " . $iname . " is set to MON=0! \n";
								if($obsport = $this->obs_get_port($obsports,$iname))
								{
									//print "RETRIEVED OBS PORT!\n";
									if($obsport['disabled'] == 0)
									{
										//print "OBS PORT IS CURRENTLY IGNORED!! ADD TO ARRAY!\n";
										$disableports[] = [
											"device_name"		=>	$nmdevice['name'],
											"port_name"			=>	$obsport['IfDescr'],
											"obs_device_id"		=>	$obsdevice['device_id'],
											"obs_port_id"		=>	$obsport['port_id'],
										];
									}
								}
							}
						}
					}
				}						
			}
		}
		if(isset($disableports))
		{
			return $disableports;
		} else {
			return null;
		}
	}
	
	public function obs_ports_to_enable()
	{
		foreach($this->Netman_get_cisco_devices() as $nmdevice)
		{
			if($nmdevice['interfaces'])
			{
				//print "INTERFACES EXIST on " . $nmdevice['name'] . "!!\n";
				if($obsdevice = $this->obs_get_device($nmdevice['name']))
				{
					//print "OBS DEVICE EXISTS! \n";
					if($obsports = $this->obs_get_ports($obsdevice['device_id']))
					{
						//print "OBTAINED OBS DEVICE PORTS! \n";
						//print_r($obsports);
						foreach($nmdevice['interfaces'] as $iname => $iconfig)
						{
							//print_r($iconfig);
							if($iconfig['description']['MON'] === 1)
							{
								//print $nmdevice['name'] . " interface " . $iname . " is set to MON=1! \n";
								if($obsport = $this->obs_get_port($obsports,$iname))
								{
									//print "RETRIEVED OBS PORT!\n";
									//print_r($obsport);
									if($obsport['disabled'] == 1)
									{
										//print "OBS PORT IS CURRENTLY IGNORED!! ADD TO ARRAY!\n";
										$enableports[] = [
											"device_name"		=>	$nmdevice['name'],
											"port_name"			=>	$obsport['IfDescr'],
											"obs_device_id"		=>	$obsdevice['device_id'],
											"obs_port_id"		=>	$obsport['port_id'],
										];
									}
								}
							}
						}
					}
				}						
			}
		}
		if(isset($enableports))
		{
			return $enableports;
		} else {
			return null;
		}
	}

	public function obs_disable_ports()
	{
        print "***** DISABLING PORTS ******\n";
		if($disableports = $this->obs_ports_to_disable())
		{		
			foreach($disableports as $disableport)
			{
                print "DISABLING PORT ID " . $disableport['obs_port_id'] . ", DEVICE NAME: " . $disableport['device_name'] . ", PORT NAME: " . $disableport['port_name'] . ".............";
                if($this->obs_port_toggle_disabled($disableport['obs_port_id'], 1))
                {
                    print "SUCCESS!\n";
                } else {
                    print "FAILED!\n";
                }
			}
		} else {
            print "--NO PORTS TO DISABLE!\n";
		}
	}
	
	public function obs_enable_ports()
	{
        print "***** ENABLING PORTS ******\n"; 
		if($enableports = $this->obs_ports_to_enable())
		{		
			foreach($enableports as $enableport)
			{
                print "ENABLING PORT ID " . $enableport['obs_port_id'] . ", DEVICE NAME: " . $enableport['device_name'] . ", PORT NAME: " . $enableport['port_name'] . ".............";
                if($this->obs_port_toggle_disabled($enableport['obs_port_id'], 0))
                {
                    print "SUCCESS!\n";
                } else {
                    print "FAILED!\n";
                }
			}
		} else {
            print "--NO PORTS TO ENABLE!\n";
		}
	}
	
	public function obs_get_alert_id($portid)
	{
		$postparams = [
			'action'	=>	'dbquery',
			'table'		=>	'ports',
			'key'		=>	'port_id',
			'id'		=>	$portid,
		];
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
			'json' => $postparams,
			'auth' => [
				getenv('OBS_USERNAME'), 
				getenv('OBS_PASSWORD')
			],
		]);

		$PORT = json_decode($apiRequest->getBody()->getContents(), true);
		//print_r($PORT);
		
		$postparams = [
			'action'	=>	'dbquery',
			'table'		=>	'alert_table',
			'key'		=>	'device_id',
			'id'		=>	$PORT['data'][0]['device_id'],
		];
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
			'json' => $postparams,
			'auth' => [
				getenv('OBS_USERNAME'), 
				getenv('OBS_PASSWORD')
			],
		]);

		$DEVICE = json_decode($apiRequest->getBody()->getContents(), true);
		//print_r($DEVICE);

		foreach($DEVICE['data'] as $alert)
		{
			if($alert['entity_type'] == "port" && $alert['entity_id'] == $portid)
			{
				$alertid = $alert['alert_table_id'];
				break;
			}
		}
		if($alertid)
		{
			return $alertid;
		} else {
			return null;
		}
	}
	public function obs_alert_disable($portid)
	{
		$alertid = $this->obs_get_alert_id($portid);

		$postparams = [
			'action'	=>	'dbupdate',
			'table'		=>	'alert_table',
			'key'		=>	'alert_table_id',
			'id'		=>	$alertid,
			'params'	=>	[
				'alert_status'	=>	1,
				'last_message'	=>	"Checks OK",
			]
		];
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
			'json' => $postparams,
			'auth' => [
				getenv('OBS_USERNAME'), 
				getenv('OBS_PASSWORD')
			],
		]);

		$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);

		return $RESPONSE['success'];
	}
	
	public function obs_update_networkservices_oncall()
	{
		$contact_name = "Network Services OnCall";
		$emailsuffix = "@vtext.com";

		$postparams = [
			'username'	=>	getenv('DCOAPI_USERNAME'),
			'password'	=>	getenv('DCOAPI_PASSWORD'),
		];
		//Build a Guzzle POST request
		$apiRequest = $this->DcoapiClient->request('POST', 'telephony/api/authenticate', [
			'json' => $postparams,
		]);

		$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);
		
		$dcoapi_token = $RESPONSE['token'];

		//Build a Guzzle POST request
		$apiRequest = $this->DcoapiClient->request('GET', 'telephony/api/cucm/line/Global-All-Lines/4029435001', [
			'query' => [
				'token' => $dcoapi_token
			],
		]);

		$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);	
		
		$oncallnum = ltrim($RESPONSE['response']['callForwardAll']['destination'],"1+");

		$postparams = [
			'action'	=>	'dbquery',
			'table'		=>	'alert_contacts',
			'key'		=>	'contact_descr',
			'id'		=>	$contact_name
		];
		//Build a Guzzle POST request
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
			'json' => $postparams,
			'auth' => [
				getenv('OBS_USERNAME'), 
				getenv('OBS_PASSWORD')
			],
		]);

		$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);

		if($RESPONSE['success'])
		{
			$contact_id = $RESPONSE['data'][0]['contact_id'];
		} else {
			return false;
		}

		$postparams = [
			'action'	=>	'dbupdate',
			'table'		=>	'alert_contacts',
			'key'		=>	'contact_id',
			'id'		=>	$contact_id,
			'params'	=>	[
				'contact_endpoint'	=>	"{\"email\":\"{$oncallnum}{$emailsuffix}\"}"
			]
		];
		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
			'json' => $postparams,
			'auth' => [
				getenv('OBS_USERNAME'), 
				getenv('OBS_PASSWORD')
			],
		]);

		$RESPONSE = json_decode($apiRequest->getBody()->getContents(), true);

		return $RESPONSE['success'];
	}
	/**/
/**/
}
