# Icingaweb2 Netbox

Icingaweb2 module to which, for now, just import objects from
[Netbox](https://github.com/netbox-community/netbox) into Icinga
Director.

Note: Tags from 2.10.3 onward match the major netbox version, the 4th version number is for this module only.

## Install

Releases are managed on GitHub. [Latest](https://github.com/sol1/icingaweb2-module-netbox/releases/latest)

Installing a new release involves removing any old release from the icingaweb2 module path, then copying in the new files.
For example on Debian systems the path is `/usr/share/icingaweb2/modules`.

To install a new release, first uninstall any existing release:

```
rm -r /usr/share/icingaweb2/modules/netbox
```

Download and extract the new release, then
move the module into the icingaweb2 modules path.
For example for version 3.6.1.3:

```
curl -L https://github.com/sol1/icingaweb2-module-netbox/archive/v3.6.1.3.tar.gz | tar xz
mv icingaweb2-module-netbox-3.6.1.3 /usr/share/icingaweb2/modules/netbox
icingacli module enable netbox
```

You can also use git:

```
git clone https://github.com/sol1/icingaweb2-module-netbox.git netbox
mv netbox /usr/share/icingaweb2/modules
icingacli module enable netbox
```

## Configuration

Configuration is done in the web interface under the "Automation" tab
of Icinga Director, [official documentation](https://www.icinga.com/docs/director/latest/doc/70-Import-and-Sync/).

### Module specific options

#### Key column name
This is used by director and icingaweb2 to find objects before sync rules are parsed, your Icinga object names should use the key column name to avoid issues.

#### Base URL
Url to netbox install api with no trailing slash (/).

eg: `http://netbox.example.com/api`

#### API Token
Netbox api token

#### Object type to import
Netbox object set to be imported

_Import types `Devices` and `Virtual Machines` also import linked Services, Linked Contacts and IP Ranges from Netbox_
_Import type `FHRP Groups Split (on IP)` also import linked IP Ranges from Netbox_

#### Flatten seperator
This will take nested data (`{ "foo": { "bar": "123 }, "bar": "321" }`) and use the seperator specified to flatten it (`{ foo__bar: 123, "bar": 321" }`)

#### Flatten keys
This option causes the flattening to occur to the listed keys only. Provide a comma seperated list of keys you want to flatten here such as `config_context,site,tenant` 

#### Munge fields
This will take existing fields from netbox and combine them, the data is also combined. The list of fields to munge needs to be added as comma seperated field names (`slug,name`). It also supports adding strings using the syntax `s=thestring`

Examples of this are 
* combining `name` and `id` into a new field `name_id`, syntax: `name,id`
* adding a identifier `netbox` to `slug` to create a new field `netbox_slug` so all objects are prefixed with `netbox_<device slug>`, syntax: `s=netbox,slug`

_Note `keyid` and `*_keyid` is a built in munge of the object type and Icinga safe object name_

#### Search filter
Adds the filter string to the api call. The default filter is `status=active`, if you add your own filter it overwrites the default filter value.

#### Proxy
Proxy server ip, hostname, port combo in the format `proxy.example.com:1234`

#### Link Services/Contacts
For Object that link to Services and Contacts toggle the linking.

### Example sync of devices to hosts

1. Add an "Import Source" with an API token, with name "Netbox devices".
2. Set the "Key column name" value to "keyid" 
3. Select "Devices" from Object type to import, then "Store" to save it.
4. Read and perform steps in the "Property Modifiers" section below.
5. Select the new import source "Netbox devices", then "Trigger import run".
6. Select the "Sync rule" tab and create a rule with the fields filled out as follows:
  * Rule name: Netbox Devices -> Hosts
  * Object Type: Hosts
  * Update Policy: Replace
  * Purge: Yes
7. Select the new "Netbox Devices -> Hosts" rule and select the "Properties" tab.
8. Add a sync property rule with the following fields set:
  * Source Name: Netbox devices
  * Destination field: object_name
  * Source Column: keyid
  * Set based on filter: No
9. Add another sync property with the following fields set:
  * Source Name: Netbox devices
  * Destination field: address
  * Source Column: ipv4_address
  * Set based on filter: No
10. Select the sync rule "Netbox Devices -> Hosts" again, then click "Trigger this sync".
11. Select "Activity log" on the left, then "Deploy pending changes".

What did we do? We created an import source "Netbox devices" which
imports Netbox devices from the Netbox API into the Director database.
The sync rule "Devices" creates Icinga Host objects from the newly
imported data in the Director database.


### Linking netbox object in icinga
If we wanted the details of a netbox site, lat/long for example, into a icinga host
create a Import Source for the site and a sync rule that creates a Icinga hosts template 
with all the site details based on the site slug.

Then in the device host object you will be able to import this site host template using
the site that exists on the netbox device.

Netbox site -> Icinga site host template (Icinga object uses the Netbox site `keyid` value for it's name)
Netbox device -> Icinga device host with import for site host template (Icinga object uses the Netbox device `site_keyid` to match the site template name)

## Property Modifiers

netboximport fetches all objects with all their fields (e.g. name,
primary_ip, config_context). Since the data mostly consists of nested
objects, a property modifier is required to access useful data from
the object. For example, the site name for a device is held in an
object like so:

```
"site": {
  "name": "frf-sin-sg",
  "id": 2220,
},
```

To use the site name as a property in icinga configuration we need a
property modifier:

![Import source - Modifiers](doc/screenshot/import-modifier-3.png)

We use the "Get specific array element" modifier to access the value
of the key "name". The result is stored in the property `site_name`.
`site_name` can be used in sync rules to make icinga configuration.

For another example, the primary address of a device is stored in a
nested object in a format which icinga cannot use without some
modification.

```
"primary_ip": {
  "address": "192.168.0.1/24",
  "id": 1234
}
```

Get the address key as above:

![Import source - Modifiers](doc/screenshot/import-modifier-1.png)

Strip the subnet suffix:

![Import source - Modifiers](doc/screenshot/import-modifier-2.png)

## Convenience Fields for Common Modifiers
Some of the data Netbox contains benefits from consistent Director import source modifiers, rather than require you to setup these Import Source Property Modifiers for each import the Netbox Import Module creates them for you. 
These include object names, linked object names, primary ip address and config context data to manage satellite creation.

The Netbox Import Module creates top level keys with default null values with the following parameters

### Object names and Linked Object Names
For the object themselves a field `keyid` is added for use as Icinga object name. 
The `keyid` is a sanatized Netbox object name, replacing characters that aren't in `[^0-9a-zA-Z_\-. ]` with `_` and making the name lowercase, then a prefix is added for the Netbox object type.

---
**NOTE**

This format was chosen as Icinga is case insensitive for names where as Netbox is case sensitive. It would be good to setup a [custom validator](https://demo.netbox.dev/static/docs/customization/custom-validation/) in Netbox that ensures the `name` entered into Netbox, when sanatised in this method, is unique for the all objects of that type.

---

For linked objects a field is added with the Netbox object type followed by `_keyid` for the field name, the same object name sanitiation occurs for these `_keyid` as above. This allows simple linking of hosts and host templates using the linked keyid's when Icinga objects use the keyid for object name.

eg: a Netbox device `Foo (123)` would contain import source fields below:
```
name: "Foo (123)"
keyid: "vmdevice_foo_123"
site: {
  name: "Bar"
}
site_keyid: "site_bar"
```

and the site `Bar` would contain import source fields below:
```
name: "bar"
keyid: "site_bar"
```

### Device Model and Manufacturer
The device model and manufacturer names are extracted from `device_type.model` and `device_type.manufacturer.name`.
```
device_type: {
    id: 155,
    url: "https://netbox.example.com/api/dcim/device-types/155/",
    display: "AB123c",
    manufacturer: {
        id: 35,
        url: "https://netbox.example.com/api/dcim/manufacturers/35/",
        display: "ACME",
        name: "ACME",
        slug: "acme"
    },
    model: "AB123c",
    slug: "ab123c"
}
device_manufacturer: "ACME",
device_model: "AB123c"
```

### Primary IP, Primary IPv4 and Primary IPv6
The ip address from `primary_ip.address`, `primary_ip4.address`, `primary_ip6.address` or FHRP Group IP address in split mode is extracted and added to `primary_ip_address`, `primary_ip4_address` and `primary_ip6_address`.
```
primary_ip :{
  address: "127.0.0.1/32"
}
primary_ip_address: "127.0.0.1"
```

### IP Range
If the custom field `icinga_zone` in Netbox on the IP Range objects exists the value will be added to device and virtual machine Import Sources `ip_range_zone` if the Primary IP address of the device/vm is in the IP Range.

This has been added to aid in the setup of Satellite, Agent and Host deployment without the need to manually specify these details on each device. 

### Tags
Netbox tags are a list of dictionaries. The slug values from these dictionaries are extracted to `tag_slugs` which is a list of strings. This can be then used in Icinga Apply Rules when seeing if a list contains a value. 

### Icinga info in config context auto extraction
```			
// Icinga satellite 
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
```
If any of the above is found in `config_context` for devices or vm's the importer will automatically create `icinga_satellite_<key>`, `icinga_host_<key>`, `icinga_service`, `icinga_var`, `icinga_service_type` or `icinga_var_type`. 
The `icinga_service_type` and `icinga_var_type` are string values of `icinga_service` and `icinga_var`, the `_type` vars can be used in filters to determine if the values have been set (filters can't test dicts/objects).

This allows the easy configuration of host and satellites from Netbox with accuration zone and endpoint information. It also allows vars or service vars to be placed on the host for easy parsing.

This structure useful outside of the Netbox Import Module in automated satellite deployment tools like ansible which can use the same values to install and configure a satellites.

## Best Practices
- Use `keyid` for object names.
- For Icinga `host` objects set the `display name` to Netbox object name.
- Icinga can link back to Netbox through Shared Navigation with the url `https://netbox.example.com/search/?q=$host.display_name$` if you make the display name the Netbox object name.
- If filtering Import Sources from Netbox, single selection fields work best as they ensure each Netbox object is only imported once for all Import Sources. Examples of this are `Device Roles` or custom fields of type `Selection`.
- Filtering Import Sources is prefered to using Import Source Modifiers or Sync Rule Filter Expression's.
- A dedicated import source custom field in Netbox is helpful for Netbox users to easily see if a object is part of monitoring. 
  - This custom field should be of type `Selection`, have a `Choice Set` and be `Required`. 
  - The `Choice Set` should have a option `Do not monitor`, typically this is the default value.
  - If setting this up after data has been added to Netbox use Netbox Bulk Update to ensure all objects contain a value.
  - If you forget to use Netbox Bulk Update and now have a mix of `Choice Set` and null values the Netbox api can help.
- Breaking up Import Sources for Hosts can be useful for the following reasons, just don't get carried away
  - Reducing the number of sync rule filters needed by grouping host types together
  - Reducing the splash area of user induced automation errors
  - Making Import Source imports and their sync rules less monolithic
- Automated Region and Tenant templates with no vars and a Sync Rule Update Policy of Merge are useful for managing shared settings. eg: Adding ping vars to a `nbregion australia` Template so all hosts in that region get their own ping values.
- You can nest Netbox data that has a parent child relationship such as Region into a Icinga host template inheritance tree. eg: `Region` (manually created host template) -> `nbregion australia` -> `nbregion new south wales` -> `nbregion sydney` -> `nbsite sol1`.
- Lists to groups can be done using a Sync Rule Property `assign_filter`'s. eg: To make Icinga Groups from Netbox tags create Icinga Host Group objects from a Netbox `tags` Import Source and set the `assign_filter` value to `%22${name}%22=host.vars.tags`, the `host.vars.tags` is the list set on host objects from the value `tag_slugs`, the Netbox Tag object `name` is the value in this list.
- You can push large nested dicts in `icinga_service` or `icinga_var`, eg: `icinga_var = {"arrive": "hello", "leave": "goodbye"}` to `host.vars.arrive = "hello"` and `host.vars.leave = "goodbye"`using a single Sync Rule Property with All Custom Vars. When doing this All Custom Vars should be the first var based property and it should use a filter `icinga_var_type=object` so it is only added if Netbox config context has dict values for these vars. If All Custom Vars isn't first is can remove previously set vars, if All Custom Vars isn't filtered it can remove preveiously set vars regardless of order, both the filter and order are needed.

## Baskets (experimental)
This repository contains baskets in `docs/baskets` directory to help you configure your host automation, they have been broken up so you can import the bits you want. 
It is recommeneded that after you import the baskets you require and modify them to suit your needs you then save them again. 

The baskets follow a series of patterns to make managing your infrastructure easier. This includes 

- The creation of parent host templates for automated templates, a common parent makes it easier to find things in tree view and for Icinga's inheritance to be used if required.
- Groups created using `Assign Filters` looking templates on the host. This means you don't need to add `Group Membership` in Sync Rule properties, just the template, it also add all groups for nested tempalates which have a parent relationship like Region and Sites.
- `keyid` is used for most object names and duplicates are removed to help ensure the automation doesn't fail.  
- Netbox objects with a count of 0 are removed to reduce, but not eliminate, unecessary Icinga groups and templates that won't be referenced by a host. 

These imported director Import Sources and Sync Rules should be altered, or parts removed, to suit your needs. These baskets represent a good starting point based on our experience. 

### Import Source Filtering
While this automation doesn't include many filters it is likely that some filtering will be added specific to your setup. The typical filter sets we use are a *dedicated import source custom field in Netbox* as outlined in Best Practices above along with tags to identify Icinga cluster elements, eg: `icinga-headend`, `icinga-satellite` and `icinga-agent`.

### Zone and Endpoint Creation
Zone and endpoint creation can be automated from Netbox, but the exact rules will depend on how you use Netbox to define the zones. As such the included Basket is a guide to how zones and endpoints can be automated. 

In practice we've found a dedicated Netbox import source custom field that is `required` and has a negative value `do_not_monitor` allows us to import all zones and endpoints by using a filter `cf_icinga_import_source__n=do_not_monitor` as "breaking up" zone and endpoint creation doesn't have any value. Where as "breaking up" host creation Import Source and Sync Rules can have value. Doing this however this does seperates the creation of host object and zone and endpoint objects.

## Acknowledgements

This module was initially based on a module by Uberspace:
https://github.com/Uberspace/icingaweb2-module-netboximport

It was rewritten by Oliver and Matt at [Sol1](https://www.sol1.com.au) and is maintained by Matt at [Sol1](https://www.sol1.com.au)
