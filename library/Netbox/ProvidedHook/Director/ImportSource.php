<?php

namespace Icinga\Module\Netbox\ProvidedHook\Director;

use Icinga\Application\Config;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Netbox\Netbox;

class ImportSource extends ImportSourceHook
{
	// IMPORTANT: Existing installations store this numbers in the config. Changing them means the config needs to be redone. 
	// 			  I've grouped and left spaces so we don't need to renumber any of these again.
	// VM
	const ClusterGroupMode = 10;	
	const ClusterMode = 12;
	const ClusterTypeMode = 14;
	const VMMode = 16;
	const VMInterfaceMode = 18;

	// Device
	const DeviceMode = 20;
	const DeviceRoleMode = 22;
	const DeviceTypeMode = 24;
	const ManufacturerMode = 25;
	const DeviceInterfaceMode = 26;

	// IPAM
	const IPAddressMode = 30;
	const IPRangeMode = 32;
	
	// Where
	const LocationMode = 40;
	const RegionMode = 42;
	const SiteGroupMode = 44;
	const SiteMode = 46;

	// Who
	const TenantGroupMode = 50;
	const TenantMode = 52;
	const ContactMode = 54;
	const ContactGroupMode = 56;
	const ContactRoleMode = 58;

	// Other
	const PlatformMode = 60;
	const ServiceMode = 62;
	const TagMode = 64;

	// Circuits
	const CircuitMode = 70;
	const CircuitTypeMode = 72;
	const ProviderMode = 74;
	const ProviderNetworkMode = 76;

	// Connections
	const CableMode = 80;

	// Test mode
	const TestMode = 600;

	// TODO: VRF is linked to devices/vm's through ip's. If we need VRF's then we should
	// create an array in the import of all the linked ip's and vrf inside the importer 
	// rather than leaving it to the user to create host templates to link it all together.

	// Add a range keyid if the primary ip for the device/vm is found in a range 
	// Note: assumes ranges never overlap
	private function get_ip_range($ranges, $things) 
	{
		$output = array();
		foreach ($things as $thing) {
			$thing->ip_range_keyid = NULL;
			if (property_exists($thing, 'primary_ip_address')) {
				foreach ($ranges as $range) {
					if (ip_in_range($range->start_address, $range->end_address, $thing->primary_ip_address)){
						$thing->keyid_ip_range = $range->keyid;
						$thing->ip_range->id = $range->id;
						$thing->ip_range->url = $range->url;
						$thing->ip_range->name = $range->display;

					}
				}
			}
			$output = array_merge($output, [(object)$thing]);
		}
		return $output;
	}


	// We need to be able to check if an ip_address in a particular range
	private function ip_in_range($lower_range_ip_address, $upper_range_ip_address, $needle_ip_address)
	{
		# Get the numeric reprisentation of the IP Address with IP2long
		$min    = ip2long(explode('/', $lower_range_ip_address)[0]);
		$max    = ip2long(explode('/', $upper_range_ip_address)[0]);
		$needle = ip2long(explode('/', $needle_ip_address)[0]);

		return (($needle >= $min) AND ($needle <= $max));
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
	private function devices_with_services($services, $devices)
	{
		foreach ($devices as &$device) {
			$a = $this->servicearray($device, $services);
			$device->services = (object) $a;
			$device->service_names = array(); 
			foreach ($a as $k => $v) {
				array_push($device->service_names, $k);
			}
		}
		return $devices;
	}

	// servicearray returns an array of services belonging to $device from $services.
	// The key is the service name, and value is the entire service object.
	private function servicearray($device, $services)
	{
		$m = array();
		foreach ($services as $service) {
			$servicename = "";
			if (isset($service->device)) {
				$servicename = $service->device->name;
			} elseif (isset($service->virtual_machine)) {
				$servicename = $service->virtual_machine->name;
			}
			if ($servicename == $device->name) {
				$ipaddr = array();
				$cidr = array();
				foreach ($this->defaultValue($service->ipaddresses, []) as $ip) {
					array_push($ipaddr, current(explode('/', $ip->address)));
					array_push($cidr, $ip->address);
				}
				// This is hack for netbox 2.10+ so sync rules that assume there is only 1 port will continue to work after netbox service.port became the service.ports array
				$first_port = "";
				if (!empty($service->ports)) {
					$first_port = $service->ports[0];
				}
				$m[$service->name] = array(
					"port" => $first_port,
					"ports" => $service->ports,
					"protocol" => $this->defaultValue($service->protocol->value, NULL),
					"ipaddresses" => $ipaddr,
					"cidrs" => $cidr,
					"description" => $service->description,
					"tags" => $this->defaultValue($service->tags, []),
					"custom_fields" => $this->defaultValue($service->custom_fields, [])
				);
			}
		}
		return $m;
	}

	private function defaultValue($var, $default)
	{
		return isset($var) ? $var : $default;
	}

	public static function addSettingsFormFields(QuickForm $form)
	{
		$form->addElement('text', 'baseurl', array(
			'label' => $form->translate('Base URL'),
			'required' => true,
			'description' => $form->translate('Base URL to the Netbox API, e.g. https://netbox.example.com/api')
		));

		$form->addElement('text', 'apitoken', array(
			'label' => $form->translate('API token'),
			'required' => true,
			'description' => $form->translate('See https://netbox.example.com/user/api-tokens')
		));

		$form->addElement('text', 'proxy', array(
			'label' => $form->translate('Proxy'),
			'required' => false,
			'description' => $form->translate('Optional proxy server setting in the format <address>:<port>')
		));

		$form->addElement('select', 'mode', array(
			'label' => $form->translate('Object type to import'),
			'description' => $form->translate('Not all object types are supported'),
			'required' => true,
			'multiOptions' => array(
				// VM's
				self::ClusterMode => $form->translate('Clusters'),
				self::ClusterGroupMode => $form->translate('Cluster Groups'),
				self::ClusterTypeMode => $form->translate('Cluster Types'),
				self::VMMode => $form->translate('Virtual Machines'),
				self::VMInterfaceMode => $form->translate('Virtual Machine Interfaces'),
			
				// Device
				self::DeviceMode => $form->translate('Devices'),
				self::DeviceRoleMode => $form->translate('Device Roles'),
				self::DeviceTypeMode => $form->translate('Device Types'),
				self::ManufacturerMode => $form->translate('Manufacturers'),
				self::DeviceInterfaceMode => $form->translate('Device Interfaces'),
			
				// IPAM
				self::IPAddressMode => $form->translate('IP Addresses'),
				self::IPRangeMode => $form->translate('IP Ranges'),
				
				// Where
				self::LocationMode => $form->translate('Locations'),
				self::SiteMode => $form->translate('Sites'),
				self::SiteGroupMode => $form->translate('Site Groups'),
				self::RegionMode => $form->translate('Region'),
			
				// Who
				self::TenantMode => $form->translate('Tenants'),
				self::TenantGroupMode => $form->translate('Tenant Groups'),
				self::ContactMode => $form->translate('Contacts'),
				self::ContactGroupMode => $form->translate('Contact Groups'),
				self::ContactRoleMode => $form->translate('Contact Roles'),
			
				// Other
				self::PlatformMode => $form->translate('Platforms'),
				self::ServiceMode => $form->translate('Services'),
				self::TagMode => $form->translate('Tags'),

				// Circuits
				self::CircuitMode => $form->translate('Circuits'),
				self::CircuitTypeMode => $form->translate('Circuit Types'),
				self::ProviderMode => $form->translate('Providers'),
				self::ProviderNetworkMode => $form->translate('Provider Networks'),

				// Connections
				self::CableMode => $form->translate('Cables'),

				// Keep test at the end
				self::TestMode => $form->translate('Test')

			)
		));

		$form->addElement('text', 'flatten', array(
			'label' => $form->translate('Flatten seperator'),
			'description' => $form->translate('Optional flattening of the data using the supplied seperator. Flattening won\'t happen without a seperator added.'),
			'required' => false,
		));

		$form->addElement('text', 'flattenkeys', array(
			'label' => $form->translate('Flatten keys'),
			'description' => $form->translate('Optionally limit the flattening to specific top level keys in a comma seperated list. Flattening won\'t happen without a seperator added.'),
			'required' => false,
		));

		$form->addElement('text', 'munge', array(
			'label' => $form->translate('Munge fields'),
			'description' => $form->translate('Optional munging of existing fields into a new combined field. Comma seperated field names of existing data that can be used to create a new unique index for the importer or string using s=. eg: input: id,name output: id_name = row(id) + "_" + row(name); input: s=example,id output: example_id = "example_" + row(id) '),
			'required' => false,
		));

		$form->addElement('text', 'filter', array(
			'label' => $form->translate('Search filter'),
			'required' => false,
			'description' => $form->translate('Optional search filter to the url to limit netbox data returned (Default: status=active is added without a filter selected)')
		));

	}

	public function fetchData(int $limit = 0)
	{
		$baseurl = $this->getSetting('baseurl');
		$apitoken = $this->getSetting('apitoken');
		$proxy = $this->getSetting('proxy');
		$mode = $this->getSetting('mode');
		$filter = (string)$this->getSetting('filter');
		$flatten = (string)$this->getSetting('flatten');
		$flattenkeys = ((string)$this->getSetting('flattenkeys') == '') ? array() : explode(",", (string)$this->getSetting('flattenkeys'));
		$munge = ((string)$this->getSetting('munge') == '') ? array() : explode(",", (string)$this->getSetting('munge'));
		$netbox = new Netbox($baseurl, $apitoken, $proxy, $flatten, $flattenkeys, $munge);
		switch ($mode) {
			// VM's
			case self::VMMode:
				$services = $netbox->allservices("", 0);
				$ranges = $netbox->ipRanges("", 0);
				$devices = $this->get_ip_range($ranges, $netbox->virtualMachines($filter, $limit));
				return $this->devices_with_services($services, $devices);
			case self::ClusterMode:
				return $netbox->clusters($filter, $limit);
			case self::ClusterGroupMode:
				return $netbox->clusterGroups($filter, $limit);
			case self::ClusterTypeMode:
				return $netbox->clusterTypes($filter, $limit);
			case self::VMInterfaceMode:
				return $netbox->virtualMachineInterfaces($filter, $limit);
							
			// Device
			case self::DeviceMode:
				$services = $netbox->allservices("", 0);
				$ranges = $netbox->ipRanges("", 0);
				$devices = $this->get_ip_range($ranges, $netbox->devices($filter, $limit));
				return $this->devices_with_services($services, $devices, $filter);
			case self::DeviceRoleMode:
				return $netbox->deviceRoles($filter, $limit);
			case self::DeviceTypeMode:
				return $netbox->deviceTypes($filter, $limit);
			case self::ManufacturerMode:
				return $netbox->manufacturers($filter, $limit);
			case self::DeviceInterfaceMode:
				return $netbox->deviceInterfaces($filter, $limit);

			// IPAM
			case self::IPAddressMode:
				return $netbox->ipAddresses($filter, $limit);
			case self::IPRangeMode:
				return $netbox->ipRanges($filter, $limit);
	
			// Where			
			case self::LocationMode:
				return $netbox->locations($filter, $limit);
			case self::SiteMode:
				return $netbox->sites($filter, $limit);
			case self::SiteGroupMode:
				return $netbox->siteGroups($filter, $limit);
			case self::RegionMode:
				return $netbox->regions($filter, $limit);

			// Who
			case self::TenantMode:
				return $netbox->tenants($filter, $limit);
			case self::TenantGroupMode:
				return $netbox->tenantGroups($filter, $limit);
			case self::ContactMode:
				return $netbox->contacts($filter, $limit);
			case self::ContactGroupMode:
				return $netbox->contactGroups($filter, $limit);
			case self::ContactRoleMode:
				return $netbox->contactModes($filter, $limit);
		
			// Other
			case self::PlatformMode:
				return $netbox->platforms($filter, $limit);
			case self::ServiceMode:
				return $netbox->allservices($filter, $limit);
			case self::TagMode:
				return $netbox->tags($filter, $limit);

			// Circuits
			case self::CircuitMode:
				return $netbox->circuits($filter, $limit);
			case self::CircuitTypeMode:
				return $netbox->circuittypes($filter, $limit);
			case self::ProviderMode:
				return $netbox->providers($filter, $limit);
			case self::ProviderNetworkMode:
				return $netbox->providernetworks($filter, $limit);

			// Connections
			case self::CableMode:
				return $netbox->cables($filter, $limit);

			// Test mode should always be last
			case self::TestMode:
				return $netbox->devices($filter, $limit);
	
			}
	}

	public static function getDefaultKeyColumnName()
	{
		return "id";
	}

	public function listColumns()
	{
		return array_keys(array_merge(...array_map('get_object_vars', $this->fetchData(1))));
	}

	public function getName()
	{
		return 'Netbox';
	}
}
