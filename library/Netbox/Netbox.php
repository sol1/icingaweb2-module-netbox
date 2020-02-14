<?php

namespace Icinga\Module\Netbox;

class Netbox {
	function __construct($baseurl, $token) {
		$this->baseurl = $baseurl;
		$this->token = $token;
	}

	private function get(string $resource, int $limit) {
		$ch = curl_init($this->baseurl . $resource . "?limit=" . $limit);
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
			throw new Exception("get: status $status $curlerror");
		}
		$response = json_decode($body);
		curl_close($ch);
		if (isset($response->results)) {
			return $response->results;
		}
		return $response;
	}

	public function devices(int $limit) {
		return $this->get("/dcim/devices", $limit);
	}

	public function sites() {
		return $this->get("/dcim/sites", 0);
	}

	public function deviceRoles() {
		return $this->get("/dcim/device-roles", 0);
	}

	public function tenants() {
		return $this->get("/tenancy/tenants", 0);
	}

	public function virtualMachines() {
		return $this->get("/virtualization/virtual-machines", 0);
	}

	public function services() {
		return $this->get("/ipam/services", 0);
	}
}
