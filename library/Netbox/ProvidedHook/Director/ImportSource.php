<?php

namespace Icinga\Module\Netbox\ProvidedHook\Director;

use Icinga\Application\Config;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Netbox\Netbox;

class ImportSource extends ImportSourceHook {
	// To keep sorted: sort, strip assignments, print new value as line number
	// Edit | sort | sed 's/ = [0-9]+;//' | awk '{ printf "%s = %d;\n", $0, NR }'
	const DeviceMode = 1;
	const DeviceRoleMode = 2;
	const DeviceTypeMode = 3;
	const PlatformMode = 4;
	const ServiceMode = 5;
	const SiteMode = 6;
	const RegionMode = 7;
	const TenantMode = 8;
	const TestMode = 9;
	const VMMode = 10;

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
	private function devices_with_services($services, $devices) {
		foreach($devices as &$device) {
			$a = $this->servicearray($device, $services);
			$device->services = (object) $a;
		}
		return $devices;
	}

	// servicearray returns an array of services belonging to $device from $services.
	// The key is the service name, and value is the entire service object.
	private function servicearray($device, $services) {
		$m = array();
		foreach ($services as $service) {
			$servicename = "";
			if ( isset($service->device) ) {
				$servicename = $service->device->name;
			} elseif ( isset($service->virtual_machine) ) {
				$servicename = $service->virtual_machine->name;
			}
			if ($servicename == $device->name) {
				$ipaddr = array();
				$cidr = array();
				foreach($this->defaultValue($service->ipaddresses, []) as $ip) {
					array_push($ipaddr, current(explode('/', $ip->address)));
					array_push($cidr, $ip->address);
				}
				$m[$service->name] = array
					(
						"port" => $service->port,
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

	private function defaultValue($var, $default) {
		return isset($var) ? $var : $default;
	}

	public static function addSettingsFormFields(QuickForm $form) {
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

		$form->addElement('select', 'mode', array(
			'label' => $form->translate('Object type to import'),
			'description' => $form->translate('Not all object types are supported'),
			'required' => true,
			'multiOptions' => array(
				self::DeviceMode => $form->translate('Devices'),
				self::DeviceRoleMode => $form->translate('Device roles'),
				self::DeviceTypeMode => $form->translate('Device types'),
				self::PlatformMode => $form->translate('Platforms'),
				self::ServiceMode => $form->translate('Services'),
				self::SiteMode => $form->translate('Sites'),
				self::RegionMode => $form->translate('Region'),
				self::TenantMode => $form->translate('Tenants'),
				self::VMMode => $form->translate('Virtual machines'),
				self::TestMode => $form->translate('Test')
			)
		));

		$form->addElement('text', 'flatten', array(
			'label' => $form->translate('Flatten seperator'),
			'description' => $form->translate('Optional flattening of the data using the supplied seperator. Flattening won\'t happen without a seperator added.'),
			'required' => false,
		));

		$form->addElement('text', 'filter', array(
			'label' => $form->translate('Search filter'),
			'required' => false,
			'description' => $form->translate('Optional search filter to the url to limit netbox data returned (Default: status=active is added without a filter selected)')
		));

		$form->addElement('text', 'proxy', array(
			'label' => $form->translate('Proxy'),
			'required' => false,
			'description' => $form->translate('Optional proxy server setting in the format <address>:<port>')
		));

	}

	public function fetchData(int $limit = 0) {
		$baseurl = $this->getSetting('baseurl');
		$apitoken = $this->getSetting('apitoken');
		$mode = $this->getSetting('mode');
		$filter = (string)$this->getSetting('filter');
		$proxy = $this->getSetting('proxy');
		$flatten = (string)$this->getSetting('flatten');
		$netbox = new Netbox($baseurl, $apitoken, $proxy, $flatten);
		switch($mode) {
		case self::DeviceMode:
			$services = $netbox->allservices();
			$devices = $netbox->devices($limit, $filter);
			return $this->devices_with_services($services, $devices, $filter);
		case self::DeviceRoleMode:
			return $netbox->deviceRoles($limit, $filter);
		case self::ServiceMode:
			return $netbox->services();
		case self::SiteMode:
			return $netbox->sites($limit, $filter);
		case self::RegionMode:
			return $netbox->regions($limit, $filter);
		case self::TenantMode:
			return $netbox->tenants($limit, $filter);
		case self::TestMode:
			return $netbox->devices($limit, $filter);
		case self::VMMode:
			$services = $netbox->allservices();
			$devices = $netbox->virtualMachines($limit, $filter);
			return $this->devices_with_services($services, $devices);
		}
	}

	public static function getDefaultKeyColumnName() {
		return "id";
	}

	public function listColumns() {
		return array_keys(array_merge(...array_map('get_object_vars', $this->fetchData(1))));
	}

	public function getName() {
		return 'Netbox';
	}
}
