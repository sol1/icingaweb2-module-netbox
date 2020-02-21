<?php

namespace Icinga\Module\Netbox;

class Netbox {
	function __construct($baseurl, $token) {
		$this->baseurl = $baseurl;
		$this->token = $token;
	}

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

	// device returns the unique device object named $name. An
	// exception is thrown if $name is not unique.
	public function device(string $name) {
		$devices = $this->get("/dcim/devices/?name=" . urlencode($name));
		if (count($devices) > 1) {
			throw new Exception("more than 1 device matching name" . $name);
		}
		return $devices[0];
	}

	public function sites() {
		return $this->get("/dcim/sites");
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
}
