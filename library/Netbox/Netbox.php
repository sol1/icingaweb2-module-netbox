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
		if (strpos($resource, '?') !== false) {
			$resource = $resource . '&limit=1000';
		} else {
			$resource = $resource . '?limit=1000';
		}
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

	// device returns the unique device object named $name. An
	// exception is thrown if $name is not unique.
	public function device(string $name) {
		$devices = $this->get("/dcim/devices/?name=" . urlencode($name));
		if (count($devices) > 1) {
			throw new Exception("more than 1 device matching name" . $name);
		}
		return $devices[0];
	}

	private function get_netbox(string $api_path, int $limit = 0) {
		if ($limit > 0) {
			# if api_path contains paramaters append limit otherwise create paramater
			if (strpos($api_path, '?') !== false) {
				$limit = '&limit=' . $limit;
			} else {
				$limit = '?limit=' . $limit;
			}
			$body = $this->httpget($this->baseurl . $api_path . $limit);
			$response = json_decode($body);
			return $response->results;
		}
		return $this->get($api_path);
	}

	// returns an array of objects. A limit of 0 returns all
	// objects from Netbox. A limit > 0 queries for and returns just $limit
	// number of results; this is useful for testing.
	public function devices(int $limit = 0) {
		return $this->get_netbox("/dcim/devices/?status=active", $limit);
	}

	public function sites(int $limit = 0) {
		return $this->get_netbox("/dcim/sites/?status=active", $limit);
	}

	public function regions(int $limit = 0) {
		return $this->get_netbox("/dcim/regions/?status=active", $limit);
	}

	public function deviceRoles(int $limit = 0) {
		return $this->get_netbox("/dcim/device-roles/?status=active", $limit);
	}

	public function tenants(int $limit = 0) {
		return $this->get_netbox("/tenancy/tenants/?status=active", $limit);
	}

	public function virtualMachines(int $limit = 0) {
		return $this->get_netbox("/virtualization/virtual-machines/?status=active", $limit);
	}

	// Don't exclude inactive services for now, not sure what a inactive service on a active host will do
	public function allservices(int $limit = 0) {
		return $this->get_netbox("/ipam/services/", $limit);
	}

	private function services(string $device, int $limit = 0) {
		return $this->get_netbox("/ipam/services/?device=" . urlencode($device), $limit);
	}
}
