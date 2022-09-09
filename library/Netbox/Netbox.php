<?php

namespace Icinga\Module\Netbox;

class Netbox
{
	public $object_type;
	public $type_map = array();
	public $prefix = 'nb';

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


	private function keymaker(string $s, string $nb_type = NULL) {
		if (is_null($nb_type)) {
			$nb_type = $this->object_type;
		}
		$s = preg_replace('/[^0-9a-zA-Z_\-. ]+/i', '_', $s);
		$s = preg_replace('/__+/i', '_', $s);
		return strtolower($this->prefix . $nb_type . " " .  trim($s,"_"));
		
	}

	// Automatically make fields that are likely to be needed so we can skip config in director
	private function makeHelperKeys(array $in)	{
		$output = array();
		foreach ($in as $row) {
			// Device type uses model as the 'name' field
			// Add it first in so that name can overwrite if netbox changes it later
			if (property_exists($row, 'model')) {
				$row->keyid = $this->keymaker($row->model);
			}

			// The display of the current row, name is a better match but so do this first and overwrite name if name exists
			if (property_exists($row, 'display')) {
				$row->keyid = $this->keymaker($row->name);
			}

			// The name of the current row
			if (property_exists($row, 'name')) {
				$row->keyid = $this->keymaker($row->name);
			}

			// Extract the address for the primary ip
			// TODO: ipv6 ??
			$row->primary_ip_address = NULL;	// Make empty field for column headings if no values exist
			if (property_exists($row,'primary_ip')) {
				if (! is_null($row->primary_ip) and property_exists($row->primary_ip, 'address')) {
					$row->primary_ip_address = explode('/', $row->primary_ip->address)[0];
				}
			} 

			// IP range is odd in that it doesn't have a name
			if ($this->object_type == 'ip_range') {
				$row->keyid = $this->keymaker(explode('/', $row->display)[0]);
			}

			// Because netbox changed tags and it isn't easy to turn a dict key in an array of dicts into an new array
			$row->tag_slugs = NULL;
			if (property_exists($row, 'tags')) {
				$row->tag_slugs = array();
				foreach ($row->tags as $tag) {
					$row->tag_slugs = array_merge($row->tag_slugs, [$tag->slug]);
				}
			}

			// Headings use a call that has a single row so some fields may not be created
			// Pre create all required fields that may be used with a null value before putting in available data
			foreach ((array)$this->type_map as $k => $v) {
				$row->{$v . '_keyid'} = NULL;
			}

			// Get child object and add a parent key to the device id
			foreach ((array)$row as $k => $v) {
				if (is_object($v)) {
					// Device type is a special snowflake, it doesn't use name and also has a manufacture (it is a nice setup, it just needs more config to get the settings in Icinga)
					if ($k == 'device_type') {
						$row->device_model_keyid = $this->keymaker($v->model, 'model');
						$row->device_model = $v->model;
						$row->device_manufacturer_keyid = $this->keymaker($v->manufacturer->name, 'manufacturer');
						$row->device_manufacturer = $v->manufacturer->name;
					} elseif (! is_null($v) and property_exists($v, 'name')) {
						$key = $k;
						if (array_key_exists($k, $this->type_map)) {
							$key = $this->type_map[$k];
						}
						$row->{$key . '_keyid'} = $this->keymaker($v->name, $key);
					}
				}

			}

			// Custom fields for Netbox
			// Icinga satellite
			/* 
			{
				"icinga": {
					"satellite": {
						"client_zone": "<zone name>",
						"parent_endpoint": "<parent endpoint name>",
						"parent_fqdn": "<parent fqdn or address>",
						"parent_zone": "<parent zone name>"
					}
				}
			}
			// Icinga host in zone
			{
				"icinga": {
					"host": {
						"zone": "<zone name>"
					}
				}
			}
			// Icinga services and vars
			{
				"icinga": {
					"service": {
					}
					"var": {
					}
				}
			}
			 */

			if (property_exists($row, 'config_context') and in_array($this->object_type, ['device', 'vm'])) {
				$satellite_keys = [ "client_zone", "parent_endpoint", "parent_fqdn", "parent_zone" ];
				$host_keys = [ "zone" ];
				$other_keys = [ "service", "var" ];
				// Default empty values for column headings
				foreach ($satellite_keys as $s) {
					$row->{'icinga_satellite_' . $s} = NULL;
				}
				foreach ($host_keys as $h) {
					$row->{'icinga_host_' . $h} = NULL;
				}
				foreach ($other_keys as $o) {
					$row->{'icinga_' . $o} = NULL;
				}

				if (property_exists($row->config_context, 'icinga')) {

					// Parse the data and set values
					$icinga = $row->config_context->icinga;
					if (property_exists($icinga, 'satellite')) {
						foreach ($satellite_keys as $s) {
							if (property_exists($icinga->satellite, $s)) {
								$row->{'icinga_satellite_' . $s} = $icinga->satellite->{$s};
							}
						}
					}
					if (property_exists($icinga, 'host')) {
						foreach ($host_keys as $h) {
							if (property_exists($icinga->satellite, $h)) {
								$row->{'icinga_host_' . $h} = $icinga->satellite->{$h};
							}
						}
					}
					foreach ($other_keys as $okey) {
						if (property_exists($icinga, $okey)) {
							$row->{"icinga_" . $okey} = $icinga->{$okey};
						}
					}	

				}
			}

			$output = array_merge($output, [(object)$row]);
		}
		return $output;
	}

	// This is a function that you can use to add complex rules to define 
	// host zones based on netbox data, eg: ip range mapping to zone.
	// While possible using Import modifiers and Sync rule property filters 
	// forking this repo and writing your own function reduces the number require in complex setups.
	private function zoneHelper(array $in) 	{
		return $in;
	}

	private function transform(array $in)
	{
		// Makes Helper Keys then
		// runs any custom zone helper with the result
		// which is the default transform data
		$output = $this->zoneHelper($this->makeHelperKeys($in));

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

		// Create new column from munge
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

		// // Because netbox changed tags and it is easy to add an array to icinga and see if a tag exists in it
		// $tnew = array();
		// foreach ($output as $row) {
		// 	if (property_exists($row, 'tags')) {
		// 		$row->tag_slugs = array();
		// 		foreach ($row->tags as $tag) {
		// 			$row->tag_slugs = array_merge($row->tag_slugs, [$tag->slug]);
		// 		}
		// 	}
		// 	$tnew = array_merge($tnew, [(object)$row]);
		// }
		// $output = $tnew;

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

	private function default_filter($filter, $default_filter)
	{
		if (empty($filter)) {
			$filter = $default_filter;
		}
		return $filter;
	}


	// returns an array of objects. A limit of 0 returns all
	// objects from Netbox. A limit > 0 queries for and returns just $limit
	// number of results; this is useful for testing.

	//  VM's
	public function virtualMachines($filter, int $limit = 0)
	{
		$this->object_type = 'vm';
		$this->type_map = array(
			"cluster" => "cluster",
			"platform" => "platform",
			"role" => "device_role",
			"site" => "site",
			"tenant" => "tenant"
		);
		return $this->get_netbox("/virtualization/virtual-machines/?" . $this->default_filter($filter, "status=active"), $limit);
	}

	public function virtualMachineInterfaces($filter, int $limit = 0)
	{
		$this->object_type = 'vm_interface';
		$this->type_map = array(
			"virtual_machine" => "vm"
		);
		return $this->get_netbox("/virtualization/interfaces/?" . $this->default_filter($filter, ""), $limit);
	}

	public function clusters($filter, int $limit = 0)
	{
		$this->object_type = 'cluster';
		$this->type_map = array(
			"group" => "cluster_group",
			"site" => "site",
			"tenant" => "tenant",
			"type" => "cluster_type"
		);
		return $this->get_netbox("/virtualization/clusters/?" . $this->default_filter($filter, "status=active"), $limit);
	}

	public function clusterGroups($filter, int $limit = 0)
	{
		$this->object_type = 'cluster_group';
		return $this->get_netbox("/virtualization/cluster-groups/?" . $this->default_filter($filter, ""), $limit);
	}

	public function clusterTypes($filter, int $limit = 0)
	{
		$this->object_type = 'cluster_type';
		return $this->get_netbox("/virtualization/cluster-types/?" . $this->default_filter($filter, ""), $limit);
	}

	// Devices
	public function devices($filter, int $limit = 0)
	{
		$this->object_type = 'device';
		$this->type_map = array(
			"device_role" => "device_role",
			"parent_device" => "device",
			"site" => "site",
			"tenant" => "tenant"
		);
		return $this->get_netbox("/dcim/devices/?" . $this->default_filter($filter, "status=active"), $limit);
	}

	public function deviceRoles($filter, int $limit = 0)
	{
		$this->object_type = 'device_role';
		// Device role is shard between vm and devices but use diffent names, this importer uses 'device_role' to unify them
		return $this->get_netbox("/dcim/device-roles/?" . $this->default_filter($filter, ""), $limit);
	}

	public function deviceTypes($filter, int $limit = 0)
	{
		$this->object_type = 'device_type';
		$this->type_map = array(
			"manufacturer" => "manufacturer"
		);
		return $this->get_netbox("/dcim/device-types/?" . $this->default_filter($filter, ""), $limit);
	}

	public function manufacturers($filter, int $limit = 0)
	{
		$this->object_type = 'manufacturer';
		return $this->get_netbox("/dcim/manufacturers/?" . $this->default_filter($filter, ""), $limit);
	}

	public function deviceInterfaces($filter, int $limit = 0)
	{
		$this->object_type = 'device_interface';
		$this->type_map = array(
			"device" => "device",
			"parent" => "device_interface"

		);
		return $this->get_netbox("/dcim/interfaces/?" . $this->default_filter($filter, ""), $limit);
	}

	// IPAM 
	public function ipAddresses($filter, int $limit = 0)
	{
		$this->object_type = 'ip';
		$this->type_map = array(
			"assigned_object" => "device_interface",
			"device" => "device",
			"parent" => "device_interface",
			"tenant" => "tenant"
		);
		return $this->get_netbox("/ipam/ip-addresses/?" . $this->default_filter($filter, "assigned_to_interface=True"), $limit);
	}

	public function ipRanges($filter, int $limit = 0)
	{
		$this->object_type = 'ip_range';
		return $this->get_netbox("/ipam/ip-ranges/?" . $this->default_filter($filter, ""), $limit);
	}

	public function fhrpGroups($filter, int $limit = 0)
	{
		$this->object_type = 'fhrpgroup';
		return $this->get_netbox("/ipam/fhrp-groups/?" . $this->default_filter($filter, ""), $limit);
	}

	// Where
	public function locations($filter, int $limit = 0)
	{
		$this->object_type = 'location';
		$this->type_map = array(
			"parent" => "location",
			"site" => "site"
		);
		return $this->get_netbox("/dcim/locations/?" . $this->default_filter($filter, ""), $limit);
	}

	public function sites($filter, int $limit = 0)
	{
		$this->object_type = 'site';
		$this->type_map = array(
			"group" => "site_group",
			"tenant" => "tenant"
		);
		return $this->get_netbox("/dcim/sites/?" . $this->default_filter($filter, "status=active"), $limit);
	}

	public function siteGroups($filter, int $limit = 0)
	{
		$this->object_type = 'site_group';
		return $this->get_netbox("/dcim/site-groups/?" . $this->default_filter($filter, ""), $limit);
	}

	public function regions($filter, int $limit = 0)
	{
		$this->object_type = 'region';
		$this->type_map = array(
			"parent" => "region"
		);
		return $this->get_netbox("/dcim/regions/?" . $this->default_filter($filter, ""), $limit);
	}

	// Who
	public function tenants($filter, int $limit = 0)
	{
		$this->object_type = 'tenant';
		$this->type_map = array(
			"group" => "tenant_group"
		);
		return $this->get_netbox("/tenancy/tenants/?" . $this->default_filter($filter, ""), $limit);
	}

	public function tenantGroups($filter, int $limit = 0)
	{
		$this->object_type = 'tenant_group';
		return $this->get_netbox("/tenancy/tenant-groups/?" . $this->default_filter($filter, ""), $limit);
	}

	public function contacts($filter, int $limit = 0)
	{
		$this->object_type = 'contact';
		$this->type_map = array(
			"group" => "contact_group"
		);
		return $this->get_netbox("/tenancy/contacts/?" . $this->default_filter($filter, ""), $limit);
	}

	public function contactGroups($filter, int $limit = 0)
	{
		$this->object_type = 'contact_group';
		$this->type_map = array(
			"parent" => "contact_group"
		);
		return $this->get_netbox("/tenancy/contact-groups/?" . $this->default_filter($filter, ""), $limit);
	}

	public function contactModes($filter, int $limit = 0)
	{
		$this->object_type = 'contact_role';
		return $this->get_netbox("/tenancy/contact-roles/?" . $this->default_filter($filter, ""), $limit);
	}

	// TODO: contactAssignement

	// Other
	public function platforms($filter, int $limit = 0)
	{
		$this->object_type = 'platform';
		$this->type_map = array(
			"manufacturer" => "manufacturer"
		);
		return $this->get_netbox("/dcim/platforms/?" . $this->default_filter($filter, "status=active"), $limit);
	}


	public function tags($filter, int $limit = 0)
	{
		$this->object_type = 'tag';
		return $this->get_netbox("/extras/tags/?" . $this->default_filter($filter, ""), $limit);
	}


	// Circuits
	public function circuits($filter, int $limit = 0)
	{
		$this->object_type = 'circuit';
		return $this->get_netbox("/circuits/circuits/?" . $this->default_filter($filter, ""), $limit);
	}

	public function circuittypes($filter, int $limit = 0)
	{
		$this->object_type = 'circuit_type';
		return $this->get_netbox("/circuits/circuit-types/?" . $this->default_filter($filter, ""), $limit);
	}

	public function providers($filter, int $limit = 0)
	{
		$this->object_type = 'provider';
		return $this->get_netbox("/circuits/providers/?" . $this->default_filter($filter, ""), $limit);
	}

	public function providernetworks($filter, int $limit = 0)
	{
		$this->object_type = 'provider_network';
		return $this->get_netbox("/circuits/provider-networks/?" . $this->default_filter($filter, ""), $limit);
	}

	// Connections
	public function cables($filter, int $limit = 0)
	{
		$this->object_type = 'cable';
		return $this->get_netbox("/dcim/cables/?" . $this->default_filter($filter, ""), $limit);
	}

	// Don't exclude inactive services for now, not sure what a inactive service on a active host will do
	public function allservices($filter, int $limit = 0)
	{
		$this->object_type = 'service';
		$this->type_map = array(
			"device" => "device",
			"virtual_machine" => "vm"
		);		
		return $this->get_netbox("/ipam/services/?" . $this->default_filter($filter, "status=active"), $limit);
	}

	private function services(string $device, int $limit = 0)
	{
		$this->object_type = 'service';
		$this->type_map = array(
			"device" => "device",
			"virtual_machine" => "vm"
		);		
		return $this->get_netbox("/ipam/services/?device=" . urlencode($device), $limit);
	}
}
