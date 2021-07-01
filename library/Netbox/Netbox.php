<?php

namespace Icinga\Module\Netbox;

class Netbox
{
	function __construct($baseurl, $token, $proxy, $flattenseparator, $flattenkeys, $munge)
	{
		$this->baseurl = $baseurl;
		$this->token = $token;
		$this->proxy = $proxy;
		$this->flattenseperator = $flattenseparator;
		$this->flattenkeys = $flattenkeys;
		$this->munge = $munge;
	}



	private function httpget(string $url)
	{
		$ch = curl_init($url);
		if (!is_null($this->proxy)) {
			curl_setopt($ch, CURLOPT_PROXY, $this->proxy);
		}
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
	private function get(string $resource)
	{
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
	public function device(string $name)
	{
		$devices = $this->get("/dcim/devices/?name=" . urlencode($name));
		if (count($devices) > 1) {
			throw new Exception("more than 1 device matching name" . $name);
		}
		return $devices[0];
	}


	private function flattenRecursive(array &$out, $key, array $in, string $seperator)
	{
		foreach ($in as $k => $v) {
			if (is_array($v)) {
				$this->flattenRecursive($out, $key . $k . $seperator, $v, $seperator);
			} elseif (is_object($v)) {
				$this->flattenRecursive($out, $key . $k . $seperator, (array)$v, $seperator);
			} else {
				$out[$key . $k] = $v;
			}
		}
	}

	private function transform(array $in)
	{
		// default output is input
		$output = $in;

		// Flatten the returned data here if we have a flatten seperator
		if (strlen($this->flattenseperator) > 0) {
			$fnew = array();
			foreach ($output as $row) {
				$in = array();
				$out = array();
				# If flattern keys is empty but seperator isn't that means we do all
				if (empty($this->flattenkeys)) {
					$in = (array)$row;
				} else {
					# If the key is in the flatten keys array put it in the processing array in, otherwise it goes in out
					foreach ((array)$row as $k => $v) {
						if (in_array($k, $this->flattenkeys)) {
							$in[$k] = $v;
						} else {
							$out[$k] = $v;
						}
					}
				}
				$this->flattenRecursive($out, '', $in, $this->flattenseperator);
				$fnew = array_merge($fnew, [(object)$out]);
			}
			$output = $fnew;
		}

		if (!empty($this->munge)) {
			$mungeheading = str_replace("s=", "", implode("_", $this->munge));
			$mnew = array();
			foreach ($output as $row) {
				$mungevalue = array();
				foreach ($this->munge as $key) {
					if (strpos($key, "s=") !== false) {
						$mungevalue = array_merge($mungevalue, [str_replace("s=", "", $key)]);
					} else {
						$mungevalue = array_merge($mungevalue, [$row->{$key}]);
					}
				}
				$row->{$mungeheading} = implode("_", $mungevalue);
				$mnew = array_merge($mnew, [(object)$row]);
			}
			$output = $mnew;
		}

		// Because netbox changed tags and it is easy to add an array to icinga and see if a tag exists in it
		$tnew = array();
		foreach ($output as $row) {
			if (property_exists($row, 'tags')) {
				$row->tag_slugs = array();
				foreach ($row->tags as $tag) {
					$row->tag_slugs = array_merge($row->tag_slugs, [$tag->slug]);
				}
			}
			$tnew = array_merge($tnew, [(object)$row]);
		}
		$output = $tnew;

		return $output;
	}


	private function get_netbox(string $api_path, int $limit = 0)
	{
		if ($limit > 0) {
			# if api_path contains paramaters append limit otherwise create paramater
			if (strpos($api_path, '?') !== false) {
				$limit = '&limit=' . $limit;
			} else {
				$limit = '?limit=' . $limit;
			}
			$body = $this->httpget($this->baseurl . $api_path . $limit);
			$response = json_decode($body);
			return $this->transform($response->results);
		}
		return $this->transform($this->get($api_path));
	}

	// returns an array of objects. A limit of 0 returns all
	// objects from Netbox. A limit > 0 queries for and returns just $limit
	// number of results; this is useful for testing.
	public function devices(int $limit = 0, $filter)
	{
		if (empty($filter)) {
			$filter = "status=active";
		}
		return $this->get_netbox("/dcim/devices/?" . $filter, $limit);
	}

	public function platforms(int $limit = 0, $filter)
	{
		if (empty($filter)) {
			$filter = "status=active";
		}
		return $this->get_netbox("/dcim/platforms/?" . $filter, $limit);
	}

	public function sites(int $limit = 0, $filter)
	{
		if (empty($filter)) {
			$filter = "status=active";
		}
		return $this->get_netbox("/dcim/sites/?" . $filter, $limit);
	}

	public function regions(int $limit = 0, $filter)
	{
		if (empty($filter)) {
			$filter = "status=active";
		}
		return $this->get_netbox("/dcim/regions/?" . $filter, $limit);
	}

	public function deviceRoles(int $limit = 0, $filter)
	{
		if (empty($filter)) {
			$filter = "status=active";
		}
		return $this->get_netbox("/dcim/device-roles/?" . $filter, $limit);
	}

	public function tags(int $limit = 0, $filter)
	{
		if (empty($filter)) {
			$filter = "";
		}
		return $this->get_netbox("/extras/tags/?" . $filter, $limit);
	}

	public function tenants(int $limit = 0, $filter)
	{
		if (empty($filter)) {
			$filter = "status=active";
		}
		return $this->get_netbox("/tenancy/tenants/?" . $filter, $limit);
	}

	public function virtualMachines(int $limit = 0, $filter)
	{
		if (empty($filter)) {
			$filter = "status=active";
		}
		return $this->get_netbox("/virtualization/virtual-machines/?" . $filter, $limit);
	}

	// Don't exclude inactive services for now, not sure what a inactive service on a active host will do
	public function allservices(int $limit = 0, $filter)
	{
		if (empty($filter)) {
			$filter = "status=active";
		}
		return $this->get_netbox("/ipam/services/?" . $filter, $limit);
	}

	private function services(string $device, int $limit = 0)
	{
		return $this->get_netbox("/ipam/services/?device=" . urlencode($device), $limit);
	}
}
