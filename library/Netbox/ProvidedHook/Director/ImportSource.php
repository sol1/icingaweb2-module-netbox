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
	// "services" which contains an array of small service objects. For
	// example:
	//
	//     $device->name = "mail.example.com"
	//     $device->id =1234
	//     $device->services = (SMTP->25, SSH->22)
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
	// The key is the service name, and value is the port.
	private function servicearray($device, $services) {
		$m = array();
		foreach ($services as $service) {
			if ($service->device->name == $device->name) {
				$m[$service->name] = $service->port;
			}
		}
		return $m;
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
			self::TestMode => $form->translate('Test'),
			self::VMMode => $form->translate('Virtual machines')
		)));
	}

	public function fetchData(int $limit = 0) {
		$baseurl = $this->getSetting('baseurl');
		$apitoken = $this->getSetting('apitoken');
		$mode = $this->getSetting('mode');
		$netbox = new Netbox($baseurl, $apitoken);
		switch($mode) {
		case self::DeviceMode:
			$services = $netbox->allservices();
			$devices = $netbox->devices($limit);
			return $this->devices_with_services($services, $devices);
		case self::DeviceRoleMode:
			$result = $netbox->deviceRoles($limit);
			break;
		case self::ServiceMode:
			$result = $netbox->services();
			break;
		case self::SiteMode:
			$result = $netbox->sites($limit);
			break;
		case self::RegionMode:
			$result = $netbox->regions($limit);
			break;
		case self::TenantMode:
			$result = $netbox->tenants($limit);
			break;
		case self::TestMode:
			$result = $netbox->devices($limit);
			break;
		}
		return $result;
	}

	public static function getDefaultKeyColumnName() {
		return "id";
	}

	// fetch just one device object from Netbox and use the keys
	public function listColumns() {
		return array_keys(array_merge(...array_map('get_object_vars', $this->fetchData(1))));
	}

	public function getName() {
		return 'Netbox';
	}
}
