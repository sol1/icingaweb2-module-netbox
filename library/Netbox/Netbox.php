<?php

namespace Icinga\Module\Netbox;

class Netbox {
	function __construct($baseurl, $token) {
		$this->baseurl = $baseurl;
		$this->token = $token;
	}

	private $servicedb;

	private function httpget(string $url) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			"Authorization: Token " . $this->token,
		));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		$body = curl_exec($ch);
		$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$curlerror = curl_error($ch);
		if ($curlerror || $status != 200) {
			curl_close($ch);
			throw new \Exception("get $url: status $status $curlerror");
		}
		curl_close($ch);
		return $body;
	}

	// get performs the necessary HTTP GET request to fetch data from the
	// Netbox path $resource. It steps through each page of resource response.
	// get returns the JSON decoded results.
	private function get(string $resource) {
		$body = $this->httpget($this->baseurl . $resource);
		$response = json_decode($body);
		$results = array();
		if (empty($response->results)) {
			return $results;
		}
		$results = $response->results;
		while (isset($response->next)) {
			$body = $this->httpget($response->next);
			$response = json_decode($body);
			if (empty($response->results)) {
				throw new \Exception("no results field in response");
			}
			$results = array_merge($results, $response->results);
		}
		return $results;
	}

	// devices returns an array of Device objects. A limit of 0 returns all
	// devices from Netbox. A limit > 0 queries for and returns just $limit
	// number of results; this is useful for testing.
	public function devices(int $limit) {
		if ($limit > 0) {
			$body = $this->httpget($this->baseurl . "/dcim/devices/?limit=" . $limit);
			$response = json_decode($body);
			return $response->results;
		}
		return $this->get("/dcim/devices");
	}

	// returns an array of device objects, as with devices(). Any services
	// belonging to each device is added to a new field called "services". For
	// example:
	//
	//     $device->name = "mail.example.com"
	//     $device->id =1234
	//     $device->services = (SMTP->25, SSH->22)
	//
	public function devices_with_services() {
		$devices = $this->devices(0);
		$this->servicedb = $this->allservices();
		foreach ($devices as &$device) {
			$device->services = $this->lookup_services($device->name);
		}
		return $devices;
	}

	// device returns the unique device object named $name. An
	// exception is thrown if $name is not unique.
	public function device(string $name) {
		$devices = $this->get("/dcim/devices/?name=" . urlencode($name));
		if (count($devices) > 1) {
			throw new Exception("more than 1 device matching name" . $name);
		}
		$device = $devices[0];
		$device->services = $this->servicemap($device->name);
		return $device;
	}

	public function sites() {
		return $this->get("/dcim/sites");
	}

	public function regions() {
		return $this->get("/dcim/regions");
	}

	public function deviceRoles() {
		return $this->get("/dcim/device-roles");
	}

	public function tenants() {
		return $this->get("/tenancy/tenants");
	}

	public function virtualMachines() {
		return $this->get("/virtualization/virtual-machines");
	}

	public function allservices() {
		return $this->get("/ipam/services");
	}

	private function services(string $device) {
		return $this->get("/ipam/services/?device=" . urlencode($device));
	}

	// looks up services belonging to the device named $device using
	// a HTTP API request. Returns an object with fields named as the service name
	// with value set to the port of the service. For example:
	// 	$service->SMTP => 25
	// 	$service->SSH => 22
	private function servicemap(string $device) {
		$services = $this->services($device);
		if (empty($services)) {
			return array();
		}
		$m = array();
		foreach ($services as $service) {
			$m[$service->name] = $service->port;
		}
		return (object) $m;
	}

	// Looks up services belonging to the device named $device in
	// the class' current servicedb. This is intended to be used to minimise HTTP API calls.
	// lookup_services returns the same values as servicemap.
	private function lookup_services(string $device) {
		$m = array();
		foreach ($this->servicedb as $service) {
			if ($service->device->name == $device) {
				$m[$service->name] = $service->port;
			}
		}
		return (object) $m;
	}
}
