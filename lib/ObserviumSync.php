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
	public $NM_DEVICES;
	public $OBS_DEVICES;
	public $OBS_GROUPS;
	public $SNOW_LOCS;			//array of locations from SNOW
	public $logmsg = "";
	public $NetmonClient;
	public $NetmanCookieJar;		
	public $NetmanClient;
	public $SnowClient;

    public function __construct()
	{
		$dotenv = new Dotenv(__DIR__."/../");
		$dotenv->load();
		global $DB;
		$this->NetmonClient = new GuzzleHttpClient([
			'base_uri' => getenv('OBSERVIUM_BASE_URI'),
		]);
		
		$this->NetmanCookieJar = new FileCookieJar('ObserviumSyncCookieJar', true);		
		$this->NetmanClient = new GuzzleHttpClient([
			'base_uri' => getenv('NETMAN_BASE_URI'),
			'cookies' => $this->NetmanCookieJar,
			'cert' => getenv('NETMAN_CERT'),
		]);
		
		$this->SnowClient = new GuzzleHttpClient([
			'base_uri' => getenv('SNOW_BASE_URI'),
		]);		
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
	public function Snow_get_valid_locations(){

		$apiRequest = $this->SnowClient->request('GET', getenv('SNOW_API_URI'), [
			'query' => [
				'u_active' => "true", 
				'sysparm_fields' => "sys_id,name,street,u_street_2,city,state,zip,country,latitude,longitude"
			],
			'auth' => [
				getenv('SNOW_USERNAME'), 
				getenv('SNOW_PASSWORD')
			],
		]);
		$response = json_decode($apiRequest->getBody()->getContents(), true);

		foreach($response['result'] as $loc){							//loop through all locations returned from snow
			$snowlocs[$loc[name]] = $loc;								//build new array with sitecode as the key
		}
		ksort($snowlocs);												//sort by key

		return $snowlocs;												//return new array
	}

	public function Netman_get_cisco_devices(){

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
		}
		ksort($newarray);
		return $newarray;

	}

	public function obs_get_devices(){

		
		$apiRequest = $this->NetmonClient->request('GET', 'api/', [
			'query' => ['type' => 'device'],
			'auth' => [
				getenv('OBS_USERNAME'), 
				getenv('OBS_PASSWORD')
			],
		]);

		$devices = json_decode($apiRequest->getBody()->getContents(), true);

		return $devices['data'];

	}

	public function obs_get_groups(){
		$apiRequest = $this->NetmonClient->request('GET', 'api/', [
			'query' => ['type' => 'group'],
			'auth' => [
				getenv('OBS_USERNAME'), 
				getenv('OBS_PASSWORD')
			],
		]);

		$groups = json_decode($apiRequest->getBody()->getContents(), true);

		return $groups['data'];
	}

	public function obs_devices_to_add(){
		//build array of netman devices
		$newnmarray = array();
		foreach($this->NM_DEVICES as $nmid => $nmdevice){
			if (!empty($nmdevice['name'])){
				$newnmarray[] = str_replace('/', '-', strtolower($nmdevice['name']));
			}
		}
		sort($newnmarray);

		//build array of observium devices
		$newobsarray = array();
		foreach($this->OBS_DEVICES as $obsid => $obsdevice){
			$newobsarray[] = str_replace('/', '-', $obsdevice['hostname']);

			//$newobsarray[] = chop($obsdevice['hostname'],".net.kiewitplaza.com");
		}
		sort($newobsarray);

/*
		foreach($newobsarray as $key => $value){
			if (empty($value)) {
				unset($newobsarray[$key]);
			}
		}


		$newobsarray = array_values($newobsarray);
/**/

		$newarray = array_values(array_diff($newnmarray, $newobsarray));

		return $newarray;
	}

	public function obs_devices_to_remove(){

		$newnmarray = array();
		foreach($this->NM_DEVICES as $nmid => $nmdevice){
			$newnmarray[] = str_replace('/', '-', strtolower($nmdevice['name']));
		}
		sort($newnmarray);
		//print_r($newnmarray);

		//build array of observium devices
		$newobsarray = array();
		foreach($this->OBS_DEVICES as $obsid => $obsdevice){

//			$newobsarray[] = chop($obsdevice['hostname'],".net.kiewitplaza.com");
			$newobsarray[] = str_replace('/', '-', $obsdevice['hostname']);
		}
		sort($newobsarray);
		//print_r($newobsarray);

		$newarray = array_values(array_diff($newobsarray, $newnmarray));

		return $newarray;
	}

	public function obs_add_device($hostname){
		$hostname = str_replace('/', '-', $hostname);

		$postparams = [	"action"	=>	"add_device",
						"hostname"	=>	$hostname];

		$apiRequest = $this->NetmonClient->request('POST', 'api/', [
				'json' => $postparams,
				'auth' => [
					getenv('OBS_USERNAME'), 
					getenv('OBS_PASSWORD')
				],
		]);
		$DEVICE = json_decode($apiRequest->getBody()->getContents(), true);

		//If device is an ACCESS SWITCH, disable PORTS module.
		if($DEVICE['success'] == true){
			$reg = "/^\D{5}\S{3}.*(sw[api]|SW[API])[0-9]{2,4}.*$/";                   //regex to match ACCESS switches only
			if (preg_match($reg,$hostname, $hits)){
				$postparams2 = [	"type"		=>	"device",
									"id"		=>	$DEVICE['data']['device_id'],
									"option"	=>	"discover_ports",
									"value"		=>	"0",
									//"debug"		=>	1,
				];				
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
		return $DEVICE;
	}

	public function obs_add_devices(){
		$this->logmsg .= "***ADD_DEVICES*** ";
		$counter = 0;
		$adddevices = $this->obs_devices_to_add();
		//print_r($adddevices);

		foreach ($adddevices as $adddevice){
			//print $adddevice . "\n";
			//print "\n";
			$this->logmsg .= $adddevice . ", ";
			print_r($this->obs_add_device($adddevice));
			//print "\n";
			//return $this->obs_add_device($adddevice);
			//break;
		}

	}

	public function obs_remove_device($params){

		$postparams['action'] = "delete_device";
		if ($params['id']){
			$postparams['id'] = $params['id'];
		} elseif ($params['hostname']){
			$postparams['hostname'] = str_replace('/', '-', $params['hostname']);
		} else {
			return 'Missing parameter "id" or "hostname" !!!';
		}

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
		//print "SNOW SITE NAMES:\n";
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
		//print "OBS GROUP NAMES:\n";
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
		$postparams = [	"action"				=>	"add_group",
						"group_type"			=>	"device",
						"name"					=>	"SITE_".$sitename,
						"description"			=>	"Default site group for " . $sitename,
						"device_association"	=>	"hostname match " . $sitename . "*",
						"entity_association"	=>	"*",
						];

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

	public function obs_add_site_groups(){
                $this->logmsg .= "***ADD_SITE-GROUPS*** ";
		$addsites = $this->obs_site_groups_to_add();

		foreach ($addsites as $site){
			$this->logmsg .= $site . ", ";
			$this->obs_add_site_group($site);
		}
	}

	public function obs_remove_site_group($sitename){

		$postparams = [	"action"				=>	"delete_group",
						"name"					=>	$sitename,
						];

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

	public function obs_set_location(){
		//get devices
		foreach($this->OBS_DEVICES as $deviceid => $device){
			print $device['hostname'] . "\n";
			$devicesite = strtolower(substr($device['hostname'],0,8));
			foreach($this->SNOW_LOCS as $sitename => $site){
				
				if ($devicesite == strtolower($sitename)){
					$addrstring = $sitename . "," . $site['street'] . "," . $site['city'] . "," . $site['state'] . "," . $site['zip'] . "," . $site['country'];
					print $addrstring . "\n";
					$postparams = [
						"action"	=>	"set_entity_attrib",
						"type"		=>	"device",
						"id"		=>	$deviceid,
						"option"	=>	"override_sysLocation_bool",
						"value"		=>	"1",
					];

					$apiRequest = $this->NetmonClient->request('POST', 'api/', [
							'json' => $postparams,
							'auth' => [
								getenv('OBS_USERNAME'), 
								getenv('OBS_PASSWORD')
							],
					]);
					$DEVICE = json_decode($apiRequest->getBody()->getContents(), true);					
					print_r($DEVICE);
					print "\n";
					$postparams2 = [
						"action"	=>	"set_entity_attrib",
						"type"		=>	"device",
						"id"		=>	$deviceid,
						"option"	=>	"override_sysLocation_string",
						"value"		=>	$addrstring,
					];

					$apiRequest = $this->NetmonClient->request('POST', 'api/', [
							'json' => $postparams2,
							'auth' => [
								getenv('OBS_USERNAME'), 
								getenv('OBS_PASSWORD')
							],
					]);
					$DEVICE2 = json_decode($apiRequest->getBody()->getContents(), true);					
					print_r($DEVICE2);
					print "\n";
					
				}
			
			}
			
			
			
		}

		//get sites
		//$this->SNOW_LOCS
	}

/**/
}
