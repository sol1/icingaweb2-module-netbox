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
	const SiteMode = 5;
	const TenantMode = 6;
	const VMMode = 7;

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
			self::SiteMode => $form->translate('Sites'),
			self::TenantMode => $form->translate('Tenants'),
			self::VMMode => $form->translate('Virtual machines')
		)));
	}

	public function fetchData() {
		$baseurl = $this->getSetting('baseurl');
		$apitoken = $this->getSetting('apitoken');
		$activeonly = $this->getSetting('activeonly') === 'y';
		$mode = $this->getSetting('mode');
		$netbox = new Netbox($baseurl, $apitoken);
		switch($mode) {
		case self::DeviceMode:
			return $netbox->devices(0);
		case self::DeviceRoleMode:
			return $netbox->deviceRoles();
		case self::SiteMode:
			return $netbox->sites();
		case self::TenantMode:
			return $netbox->tenants();
		}
	}

	public static function getDefaultKeyColumnName() {
		return "id";
	}

	// fetch just one device object from Netbox and use the keys
	public function listColumns() {
		$baseurl = $this->getSetting('baseurl');
		$apitoken = $this->getSetting('apitoken');
		$netbox = new Netbox($baseurl, $apitoken);
		$devices = $netbox->devices(1);
		return array_keys(array_merge(...array_map('get_object_vars', $devices)));
	}

	public function getName() {
		return 'Netbox';
	}
}
