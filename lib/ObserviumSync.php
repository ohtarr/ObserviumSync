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
	public $NM_DEVICES;		//array of switches from Network Management Platform
	public $SNOW_LOCS;			//array of locations from SNOW
	public $logmsg = "";

    public function __construct()
	{
		global $DB;
		$this->NM_DEVICES = $this->Netman_get_devices();		//populate array of switches from Network Management Platform
		$this->SNOW_LOCS = $this->Snow_get_valid_locations();	//populate a list of locations from SNOW

		if (empty($this->NM_DEVICES)		||
			empty($this->SNOW_LOCS)
			)
		{
			$DB->log("ObserviumSync failed: 1 or more data sources are empty!");
			exit();
		}
	}

	public function __destruct()
	{
		global $DB;
		if ($this->logmsg){
			$DB->log($this->logmsg);		
		}
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
						"target_ip"			=>	"10.123.123.70",									//optional
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
		return $snowlocs;												//return new array
	}

	/*
	returns array of active switches from Network Management Platform

    [wscganorswd01] => Array
        (
            [id] => 39716
            [name] => xxxxxxxxswd01
            [ip] => 10.5.5.1
            [snmploc] => Array
                (
                    [site] => xxxxxxxx
                    [erl] => xxxxxxxx
                    [desc] => xxxxxxxx
                )

        )
	/**/
	public function Netman_get_devices(){

		$CERTFILE   = "/opt/networkautomation/netman.pem";
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
		];

		$URL = 'https:/'.'/netman/information/api/search/';

		$request = \Httpful\Request::post($URL);
		foreach( $OPTIONS as $key => $val) {
				$request->addOnCurlOption($key, $val);
		}
		$DEVICEIDS = $request	->body(json_encode($postparams))				//parameters to send in body
							->send()										//execute the request
							->body;											//only give us the body back
		
		$DEVICEIDS = get_object_vars($DEVICEIDS);

		$DEVICEIDS = $DEVICEIDS[results];

		foreach($DEVICEIDS as $deviceid){
			$URL = 'https:/'.'/netman/information/api/retrieve/?id=' . $deviceid;
 
			$request = \Httpful\Request::get($URL);
			foreach( $OPTIONS as $key => $val) {
					$request->addOnCurlOption($key, $val);
			}
//			$request->expectsJson()										//we expect JSON back from the api
			$response = $request-> send();
			//\metaclassing\Utility::dumper($response->body);
			$newarray[$deviceid] = \metaclassing\Utility::objectToArray($response->body->object);
			
		}
		return $newarray;

	}

	public function Obs_groups_to_add(){
		
	
	}
}