<?php

namespace Icinga\Module\Netbox\ProvidedHook\Director;

use Icinga\Application\Config;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Netbox\Netbox;
use Icinga\Module\Netbox\NetboxMerge;

class ImportSource extends ImportSourceHook
{

	// MODE SELECT
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

// Virtual Chassis
	const VirtualChassisMode = 28;

	// IPAM
	const IPAddressMode = 30;
	const IPRangeMode = 32;
	const FHRPGroupMode = 36;
	const FHRPGroupSplitMode = 37;
	
	// Where
	const LocationMode = 40;
	const RegionMode = 42;
	const SiteGroupMode = 44;
	const SiteMode = 46;
	const RackMode = 48;

	// Who
	const TenantGroupMode = 50;
	const TenantMode = 52;
	const ContactMode = 54;
	const ContactGroupMode = 56;
	const ContactRoleMode = 58;
	const ContactAssignmentMode = 59;

	// Other
	const PlatformMode = 60;
	const ServiceMode = 62;
	const TagMode = 64;
	const CustomFieldChoiceExtraChoices = 66;

	// Circuits
	const CircuitMode = 70;
	const CircuitTypeMode = 72;
	const ProviderMode = 74;
	const ProviderNetworkMode = 76;

	// Connections
	const CableMode = 80;

	// Test mode
	const TestMode = 600;


	public static function addSettingsFormFields(QuickForm $form)
	{
		$global_config = Config::module('netbox');

		$global_baseurl = $global_config->get('netbox', 'baseurl');
		$form->addElement('text', 'baseurl', array(
			'label' => $form->translate('Base URL'),
			'required' => ($global_baseurl == ''),
			'placeholder' => ($global_baseurl != '' ? $global_baseurl : 'https://netbox.example.com/api'),
			'description' => $form->translate('Base URL to the Netbox API, e.g. https://netbox.example.com/api')
		));

		$form->addElement('text', 'apitoken', array(
			'label' => $form->translate('API token'),
			'required' => ($global_config->get('netbox', 'apitoken') == ''),
			'description' => $form->translate('See https://netbox.example.com/user/api-tokens')
		));

		$form->addElement('text', 'proxy', array(
			'label' => $form->translate('Proxy'),
			'required' => false,
			'placeholder' => $global_config->get('netbox', 'proxy'),
			'description' => $form->translate('Optional proxy server setting in the format <address>:<port>')
		));

		$form->addElement('checkbox', 'ssl_enable', array(
			'label' => $form->translate('Enable SSL checks'),
			'required' => false,
			'description' => $form->translate('Checking this box will cause the Netbox import module enable SSL certificate verification and SSL hostname check.')
			// 'value' => 1 // Checked by default 
			//TODO: in future versions uncomment the value, inital releases not getting this so the current disabled behaviour is saved to the database
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

				// Virtual Chassis
				self::VirtualChassisMode => $form->translate('Virtual Chassis'),
			
				// IPAM
				self::IPAddressMode => $form->translate('IP Addresses'),
				self::IPRangeMode => $form->translate('IP Ranges'),
				self::FHRPGroupMode => $form->translate("FHRP Groups"),
				self::FHRPGroupSplitMode => $form->translate("FHRP Groups Split (on IP)"),
				
				// Where
				self::LocationMode => $form->translate('Locations'),
				self::SiteMode => $form->translate('Sites'),
				self::SiteGroupMode => $form->translate('Site Groups'),
				self::RackMode => $form->translate('Racks'),
				self::RegionMode => $form->translate('Regions'),
			
				// Who
				self::TenantMode => $form->translate('Tenants'),
				self::TenantGroupMode => $form->translate('Tenant Groups'),
				self::ContactMode => $form->translate('Contacts'),
				self::ContactGroupMode => $form->translate('Contact Groups'),
				self::ContactRoleMode => $form->translate('Contact Roles'),
				self::ContactAssignmentMode => $form->translate('Contact Assignments'),
			
				// Other
				self::PlatformMode => $form->translate('Platforms'),
				self::ServiceMode => $form->translate('Services'),
				self::TagMode => $form->translate('Tags'),
				self::CustomFieldChoiceExtraChoices => $form->translate('Custom Field Choice Choices'),

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

		$form->addElement('checkbox', 'linked_services', array(
			'label' => $form->translate('Link Services'),
			'required' => false,
			'description' => $form->translate('Checking this box will link Service objects for devices and virtual machines during their import. WARNING: This could increase API load to Netbox if you have a lot of services.')
		));

		$form->addElement('checkbox', 'linked_contacts', array(
			'label' => $form->translate('Link Contacts'),
			'required' => false,
			'description' => $form->translate('Checking this box will link Contact objects for devices and virtual machines during their import. WARNING: This could increase API load to Netbox if you have a lot of contact assignements.')
		));

		$form->addElement('checkbox', 'linked_interfaces', array(
			'label' => $form->translate('Link Interfaces'),
			'required' => false,
			'description' => $form->translate('Checking this box will link Interface objects for devices and virtual machines during their import. WARNING: This could increase API load to Netbox if you have a lot of interfaces.')
		));

		$form->addElement('checkbox', 'linked_module_bays', array(
			'label' => $form->translate('Link Module Bays'),
			'required' => false,
			'description' => $form->translate('Checking this box will link Module Bay objects for devices during their import. Each device gets a host.vars.module_bays dictionary keyed by bay (bay1, bay2, controller, ...) with the installed module type as the value (or null for empty bays). Apply rules can dispatch directly, e.g. "assign where host.vars.module_bays.bay1 == \"*LINECARD*\"". WARNING: increases API load to Netbox.')
		));

		$form->addElement('checkbox', 'parse_all_data_for_listcolumns', array(
			'label' => $form->translate('Parse all data for list columns'),
			'required' => false,
			'description' => $form->translate('Checking this box will cause the Netbox import module to parse the full data set when listing coloumns instead of a single row. WARNING: This increases API load to Netbox and slow parts of director config management down.')
		));

		// $form->addElement('multiCheckbox', 'associations', array(
		// 	'label' => $form->translate("Associate additional data"),
		// 	'required' => false,
		// 	'description' => $form->translate('Optionally associate deeply linked data with these objects where possible.'),
		// 	'multiOptions' => array(
		// 		self::ServiceAssociation => $form->translate('Services'),
		// 		self::IPRangeAssociation => $form->translate('IP Range'),
		// 		self::FHRPAssociation => $form->translate('FHRP Groups')
		// 	)
		// ));

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
		$linkcontacts = $this->getSetting('linked_contacts');
		$linkservices = $this->getSetting('linked_services');
		$parsealldataforlistcolumns = $this->getSetting('parse_all_data_for_listcolumns');
		$linkinterfaces = $this->getSetting('linked_interfaces');
		$linkmodulebays = $this->getSetting('linked_module_bays');
		$sslenable = $this->getSetting('ssl_enable');
		$netbox = Netbox::fromConfig($baseurl, $apitoken, $proxy, $sslenable, $flatten, $flattenkeys, $munge);

		if ($parsealldataforlistcolumns) {
			// We need to set the limit to 0 to parse the data from Netbox and create column headings 
			if ($limit = 1) {
				$limit = 0;
			}
		}

		switch ($mode) {
			// VM's
			case self::VMMode:
				// We need a second instance of Netbox here without filtering, flattening or munging to get the linked objects.
				$netboxLinked = Netbox::fromConfig($baseurl, $apitoken, $proxy, $sslenable);
				return NetboxMerge::getLinkedObjects($netboxLinked, $linkservices, $linkcontacts, $linkinterfaces, $linkmodulebays, "virtualization.virtualmachine", $netbox->virtualMachines($filter, $limit));
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
				// We need a second instance of Netbox here without filtering, flattening or munging to get the linked objects.
				$netboxLinked = Netbox::fromConfig($baseurl, $apitoken, $proxy, $sslenable);
				return NetboxMerge::getLinkedObjects($netboxLinked, $linkservices, $linkcontacts, $linkinterfaces, $linkmodulebays, "dcim.device", $netbox->devices($filter, $limit));
			case self::DeviceRoleMode:
				return $netbox->deviceRoles($filter, $limit);
			case self::DeviceTypeMode:
				return $netbox->deviceTypes($filter, $limit);
			case self::ManufacturerMode:
				return $netbox->manufacturers($filter, $limit);
			case self::DeviceInterfaceMode:
				return $netbox->deviceInterfaces($filter, $limit);
			case self::VirtualChassisMode:
				$netboxLinked = Netbox::fromConfig($baseurl, $apitoken, $proxy, $sslenable);
				return NetboxMerge::getLinkedObjects($netboxLinked, $linkservices, $linkcontacts, $linkinterfaces, $linkmodulebays, "dcim.virtual_chassis", $netbox->virtualChassis($filter, $limit));

			// IPAM
			case self::IPAddressMode:
				return $netbox->ipAddresses($filter, $limit);
			case self::IPRangeMode:
				return $netbox->ipRanges($filter, $limit);
				case self::FHRPGroupMode:
			return $netbox->fhrpGroups($filter, $limit);
				case self::FHRPGroupSplitMode:
				$ranges = $netbox->ipRanges("", 0);
				return NetboxMerge::get_ip_range($ranges, $netbox->fhrpGroupsSplit($filter, $limit));
							
			// Where			
			case self::LocationMode:
				return $netbox->locations($filter, $limit);
			case self::SiteMode:
				return $netbox->sites($filter, $limit);
			case self::SiteGroupMode:
				return $netbox->siteGroups($filter, $limit);
            case self::RackMode:
                return $netbox->racks($filter, $limit);
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
				return $netbox->contactRoles($filter, $limit);
			case self::ContactAssignmentMode:
				return $netbox->contactAssignments($filter, $limit);
					
			// Other
			case self::PlatformMode:
				return $netbox->platforms($filter, $limit);
			case self::ServiceMode:
				return $netbox->allservices($filter, $limit);
			case self::TagMode:
				return $netbox->tags($filter, $limit);
			case self::CustomFieldChoiceExtraChoices:
				return $netbox->customfieldchoiceextrachoices($filter, $limit);

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
