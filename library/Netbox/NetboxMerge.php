<?php

namespace Icinga\Module\Netbox;

class NetboxMerge
{
	// Gets multiple objects from Netbox and merges them together based on the content type.
	// Used to link services, contacts, interfaces and module bays to devices and virtual machines.
	public static function getLinkedObjects($netboxLinked, $linkservices, $linkcontacts, $linkinterfaces, $linkmodulebays, $content_type, $things)
	{
		$services = array();
		if ($linkservices) {
			$services = $netboxLinked->allservices("", 0);
		}
		$contact_assignments = array();
		if ($linkcontacts) {
			$contact_assignments = $netboxLinked->contactAssignments("content_type=" . $content_type, 0);
		}
		$interfaces = array();
		if ($linkinterfaces) {
			if ($content_type == "virtualization.virtualmachine") {
				$vm_filter = "";
				foreach ($things as $vm) {
					$vm_filter .= "&virtual_machine_id=" . $vm->id;
					if (strlen($vm_filter) > 1500) {
						$interfaces = array_merge($interfaces, $netboxLinked->virtualMachineInterfaces($vm_filter, 0));
						$vm_filter = "";
					}
				}
				$interfaces = array_merge($interfaces, $netboxLinked->virtualMachineInterfaces($vm_filter, 0));
			}
			if ($content_type == "dcim.device") {
				$device_filter = "";
				foreach ($things as $device) {
					$device_filter .= "&device_id=" . $device->id;
					if (strlen($device_filter) > 1500) {
						$interfaces = array_merge($interfaces, $netboxLinked->deviceInterfaces($device_filter, 0));
						$device_filter = "";
					}
				}
				$interfaces = array_merge($interfaces, $netboxLinked->deviceInterfaces($device_filter, 0));
			}
			if ($content_type == "dcim.virtual_chassis"){
				$virtual_chassis_filter = "";
				foreach ($things as $virtual_chassis) {
					$virtual_chassis_filter .= "&virtual_chassis_id=" . $virtual_chassis->id;
					if (strlen($virtual_chassis_filter) > 1500) {
						$interfaces = array_merge($interfaces, $netboxLinked->deviceInterfaces($virtual_chassis_filter, 0));
						$virtual_chassis_filter = "";
					}
				}
				$interfaces = array_merge($interfaces, $netboxLinked->deviceInterfaces($virtual_chassis_filter, 0));
			}
		}
		$module_bays = array();
		$modules = array();
		if ($linkmodulebays && $content_type == "dcim.device") {
			$device_filter = "";
			foreach ($things as $device) {
				$device_filter .= "&device_id=" . $device->id;
				if (strlen($device_filter) > 1500) {
					$module_bays = array_merge($module_bays, $netboxLinked->deviceModuleBays($device_filter, 0));
					$device_filter = "";
				}
			}
			$module_bays = array_merge($module_bays, $netboxLinked->deviceModuleBays($device_filter, 0));
			// One bulk fetch is cheaper than per-device once we already have the bay list
			$modules = $netboxLinked->deviceModules("", 0);
		}
		$ranges = $netboxLinked->ipRanges("", 0);
		return self::devices_with_services($services, self::get_contact_assignments($contact_assignments, self::get_ip_range($ranges, self::get_interfaces($interfaces, self::get_module_bays($module_bays, $modules, $things), $content_type))));
	}

	// Makes 4 lists of interfaces for use a host.vars
	// 2 lists are lists of interface names as string
	// 2 lists are lists of interface names and the value of the custom field `icinga_dict` if it exists
	public static function get_interfaces($interfaces, $things, $content_type)
	{
		if (empty($interfaces)) {
			return $things;
		}
		$output = array();
		$content_name = (strpos($content_type, 'virtualmachine') !== false) ? 'virtual_machine' : 'device';
		foreach ($things as $thing) {
			if ($content_type == "dcim.virtual_chassis"){
				$member_ids = array();
				foreach ($thing->members as $member) {
    			array_push($member_ids,$member->id);
				}
			}
			$thing->interfaces_down = array();
			$thing->interfaces_up = array();
			$thing->interfaces_down_dict = (object)[];
			$thing->interfaces_up_dict = (object)[];
			foreach ($interfaces as $interface) {
				if ((isset($interface->{$content_name}->id) && ($interface->{$content_name}->id == $thing->id || (isset($member_ids) && in_array($interface->{$content_name}->id,$member_ids)))) && (!isset($interface->custom_fields->icinga_monitored) || $interface->custom_fields->icinga_monitored === true)) {
					$icinga_dict = isset($interface->custom_fields->icinga_dict) ? $interface->custom_fields->icinga_dict : (object)[];
					// {netbox_fields: {index_key:label, example_key:custom_fields.example}}
					if (isset($icinga_dict->netbox_fields)){
						foreach ($icinga_dict->netbox_fields as $key => $property_path) {
							$icinga_dict->$key = self::getValueByPath($interface, $property_path);
						}
						// Remove the mapping now that we've expanded it.
						unset($icinga_dict->netbox_fields);
					}
					if ($interface->enabled) {
						array_push($thing->interfaces_up, $interface->name);
						$thing->interfaces_up_dict->{$interface->name} = $icinga_dict;
					} else {
						array_push($thing->interfaces_down, $interface->name);
						$thing->interfaces_down_dict->{$interface->name} = $icinga_dict;
					}
				}
			}
			$output = array_merge($output, [(object)$thing]);
		}
		return $output;
	}

	private static function getValueByPath($object, $path) {
		$segments = explode('.', $path);
		$current = $object;
		foreach ($segments as $segment) {
			if (is_object($current) && isset($current->$segment)) {
				$current = $current->$segment;
			} else {
				return null;
			}
		}
		return $current;
	}

	// TODO: VRF is linked to devices/vm's through ip's. If we need VRF's then we should
	// create an array in the import of all the linked ip's and vrf inside the importer
	// rather than leaving it to the user to create host templates to link it all together.

	// Add a range keyid if the primary ip for the device/vm is found in a range
	// Note: assumes ranges never overlap
	public static function get_ip_range($ranges, $things)
	{
		$output = array();
		foreach ($things as $thing) {
			$thing->ip_range_keyid = NULL;
			$thing->ip_range_zone = NULL;
			if (property_exists($thing, 'primary_ip_address')) {
				foreach ($ranges as $range) {
					if (self::ip_in_range($range->start_address, $range->end_address, $thing->primary_ip_address)){
						$thing->ip_range_keyid = $range->keyid;
						if (property_exists($range, 'custom_fields')) {
							if (property_exists($range->custom_fields, 'icinga_zone')) {
								$thing->ip_range_zone = $range->custom_fields->icinga_zone;
							}
						}
					}
				}
			}
			$output = array_merge($output, [(object)$thing]);
		}
		return $output;
	}

	// We need to be able to check if an ip_address in a particular range
	private static function ip_in_range($lower_range_ip_address, $upper_range_ip_address, $needle_ip_address)
	{
		$min    = ip2long(explode('/', $lower_range_ip_address ?? '')[0]);
		$max    = ip2long(explode('/', $upper_range_ip_address ?? '')[0]);
		$needle = ip2long(explode('/', $needle_ip_address ?? '')[0]);

		return (($needle >= $min) AND ($needle <= $max));
	}

	// Assume the list of contacts assignments passed in are all the same type
	// as the things passed in.
	public static function get_contact_assignments($contact_assignments, $things) {
		$output = array();
		foreach ($things as $thing) {
			// make an array here for a list of contacts
			$thing->contacts = array();
			$thing->contact_keyids = array();
			$thing->contact_dicts = array();
			$thing->contact_roles_dict = array();
			foreach ($contact_assignments as $contact_assignment) {
				if ($contact_assignment->object->id == $thing->id) {
					$name = $contact_assignment->contact->name;
					$keyid = strtolower("nbcontact " . preg_replace('/__+/i', '_', preg_replace('/[^0-9a-zA-Z_\-. ]+/i', '_', $name)));
					$role_name = isset($contact_assignment->role) ? $contact_assignment->role->name : null;

					$thing->contacts[] = $name;
					$thing->contact_keyids[] = $keyid;

					if (!isset($thing->contact_roles_dict[$role_name])) {
						$thing->contact_roles_dict[$role_name] = array();
					}
					array_push($thing->contact_roles_dict[$role_name], $name);

					if (!isset($thing->contact_roles_dict[$role_name . "_keyids"])) {
						$thing->contact_roles_dict[$role_name . "_keyids"] = array();
					}
					array_push($thing->contact_roles_dict[$role_name . "_keyids"], $keyid);
				}
			}
			$output = array_merge($output, [(object)$thing]);
		}
		return $output;
	}

	// Attach a position-keyed module_bays dictionary to each device, mapping
	// each bay's key to the installed module's type string (or null for
	// empty bays). Bay keys are stable, Director-friendly identifiers:
	//   - bay.position "1"                     -> "bay1"
	//   - bay.position empty, name "Controller"-> "controller"
	//   - bay.position empty, name "PSU A"     -> "psu_a"
	//
	// Director apply rules can then reference a bay directly, e.g.
	//   assign where host.vars.module_bays.bay1 == "*LINECARD*"
	public static function get_module_bays($module_bays, $modules, $things)
	{
		if (empty($module_bays)) {
			return $things;
		}

		// Index modules by their installed-bay id for O(1) lookup
		$modules_by_bay_id = array();
		foreach ($modules as $module) {
			if (isset($module->module_bay->id)) {
				$modules_by_bay_id[$module->module_bay->id] = $module;
			}
		}

		// Group bays by device id so we don't rescan the full bay list per device
		$bays_by_device_id = array();
		foreach ($module_bays as $bay) {
			if (!isset($bay->device->id)) {
				continue;
			}
			$bays_by_device_id[$bay->device->id][] = $bay;
		}

		foreach ($things as $thing) {
			$thing->module_bays = (object)[];

			if (!isset($bays_by_device_id[$thing->id])) {
				continue;
			}

			foreach ($bays_by_device_id[$thing->id] as $bay) {
				$key = self::module_bay_key($bay);
				if ($key === null) {
					continue;
				}

				$installed = isset($modules_by_bay_id[$bay->id]) ? $modules_by_bay_id[$bay->id] : null;
				$thing->module_bays->{$key} = ($installed && isset($installed->module_type->model))
					? $installed->module_type->model
					: null;
			}
		}

		return $things;
	}

	// Derive a stable Director-friendly key for a NetBox module bay.
	// Numeric positions become bay1..bayN; named bays become slugified names
	// (e.g. "Controller" -> "controller", "PSU A" -> "psu_a").
	private static function module_bay_key($bay)
	{
		if (isset($bay->position) && $bay->position !== '') {
			return 'bay' . $bay->position;
		}
		if (isset($bay->name) && $bay->name !== '') {
			$slug = preg_replace('/[^0-9a-zA-Z_]/', '_', strtolower($bay->name));
			$slug = trim(preg_replace('/_+/', '_', $slug), '_');
			return $slug !== '' ? $slug : null;
		}
		return null;
	}

	// devices_with_services returns a copy of $devices with any services
	// from $services belonging to it merged in. Each device has a new field
	// "services" which contains an array of service objects belonging to the
	// device. For example:
	//
	//     $device->name = "mail.example.com"
	//     $device->id =1234
	//     $device->services = (SMTP->port: 25, SSH->addresses...)
	//
	// The services are cast to objects from arrays because Director requires the
	// data as a stdClass.
	public static function devices_with_services($services, $devices)
	{
		# first pass is for getting the list of columns for service dicts as these columns are dynamic
		$icinga_list_type_keys = [];
		$icinga_dict_type_keys = [];
		foreach ($devices as &$device) {
			$service_array = self::servicearray($device, $services);
			foreach ($service_array as $k => $v) {
				// dict
				if (property_exists($v['custom_fields'], 'icinga_dict_type') && isset($v['custom_fields']->icinga_dict_type)) {
					$icinga_dict_type_keys = array_unique(array_merge($icinga_dict_type_keys, self::valuetolist($v['custom_fields']->icinga_dict_type)));
				}
				// list
				if (property_exists($v['custom_fields'], 'icinga_list_type') && isset($v['custom_fields']->icinga_list_type)) {
					$icinga_list_type_keys = array_unique(array_merge($icinga_list_type_keys, self::valuetolist($v['custom_fields']->icinga_list_type)));
				}
			}
		}

		# second pass is for the values for columns
		foreach ($devices as &$device) {
			$service_array = self::servicearray($device, $services);
			$device->services = (object) $service_array;
			$device->service_names = array();
			// setting empty values at the device level
			// dict
			foreach ($icinga_dict_type_keys as $var_type) {
				$icinga_dict_type_name = 'service_dict_' . $var_type;
				if (!isset($device->{$icinga_dict_type_name})) {
					$device->{$icinga_dict_type_name} = (object)[];
				}
			}
			// list
			foreach ($icinga_list_type_keys as $var_type) {
				$icinga_list_type_name = 'service_list_' . $var_type;
				if (!isset($device->{$icinga_list_type_name})) {
					$device->{$icinga_list_type_name} = [];
				}
			}

			foreach ($service_array as $k => $v) {
				array_push($device->service_names, $k);

				// if icinga_dict_type is set and icinga_dict exists then add to service_dict_<typename>
				if (property_exists($v['custom_fields'], 'icinga_dict') && property_exists($v['custom_fields'], 'icinga_dict_type')) {
					foreach ($icinga_dict_type_keys as $var_type) {
						if (self::contains($v['custom_fields']->icinga_dict_type, $var_type)) {
							$key_name = 'service_dict_' . $var_type;
							$icinga_dict = isset($v['custom_fields']->icinga_dict) ? $v['custom_fields']->icinga_dict : (object)[];
							$device->{$key_name}->{$k} = $icinga_dict;
						}
					}
				}

				// if icinga_list exists and icinga_monitored is not false and icinga_list_type doesn't exist then add to default service_dict_<service name>
				if (property_exists($v['custom_fields'], 'icinga_list') && !property_exists($v['custom_fields'], 'icinga_list_type') && (!isset($v['custom_fields']->icinga_monitored) || $v['custom_fields']->icinga_monitored === true)) {
					$key_name = 'service_list_' . $k;
					if (!isset($device->{$key_name})) {
						$device->{$key_name} = [];
					}
					$device->{$key_name} = array_merge($device->{$key_name}, self::valuetolist($v['custom_fields']->icinga_list));
				}

				// if icinga_list_type is set and icinga_list exists then add to service_list_<typename>
				if (property_exists($v['custom_fields'], 'icinga_list') && property_exists($v['custom_fields'], 'icinga_list_type')) {
					foreach ($icinga_list_type_keys as $var_type) {
						if (self::contains($v['custom_fields']->icinga_list_type, $var_type)) {
							$key_name = 'service_list_' . $var_type;
							$device->{$key_name} = array_merge($device->{$key_name}, self::valuetolist($v['custom_fields']->icinga_list));
						}
					}
				}
			}
		}
		return $devices;
	}

	private static function contains($haystack, $needle)
	{
		return in_array($needle, self::valuetolist($haystack));
	}

	// Takes a comma separated string or list and returns a list
	private static function valuetolist($value)
	{
		$list = [];
		if (is_string($value) && !$value == '') {
			$list = explode(',', $value);
		} elseif (is_array($value) && !empty($value)) {
			$list = $value;
		} else {
			// TODO: this isn't right, it needs to throw an error to director
		}
		return array_map('trim', $list);
	}

	// servicearray returns an array of services belonging to $device from $services.
	// The key is the service name, and value is the entire service object.
	private static function servicearray($device, $services)
	{
		$m = array();
		foreach ($services as $service) {
			$servicename = "";
			if (isset($service->parent)) {
				$servicename = $service->parent->name;
			} elseif (isset($service->device)) {
				$servicename = $service->device->name;
			} elseif (isset($service->virtual_machine)) {
				$servicename = $service->virtual_machine->name;
			}
			if ($servicename == $device->name) {
				$ipaddr = array();
				$cidr = array();
				foreach (self::defaultValue($service->ipaddresses, []) as $ip) {
					array_push($ipaddr, current(explode('/', $ip->address)));
					array_push($cidr, $ip->address);
				}
				// Hack for netbox 2.10+: sync rules assuming one port continue to work after service.port became service.ports array
				$first_port = "";
				if (!empty($service->ports)) {
					$first_port = $service->ports[0];
				}
				$m[$service->name] = array(
					"port" => $first_port,
					"ports" => $service->ports,
					"protocol" => self::defaultValue($service->protocol->value, NULL),
					"ipaddresses" => $ipaddr,
					"cidrs" => $cidr,
					"description" => $service->description,
					"tags" => self::defaultValue($service->tags, []),
					"custom_fields" => self::defaultValue($service->custom_fields, [])
				);
			}
		}
		return $m;
	}

	private static function defaultValue($var, $default)
	{
		return isset($var) ? $var : $default;
	}
}
