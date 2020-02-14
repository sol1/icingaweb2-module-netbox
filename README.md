# Icingaweb2 Netbox

Icingaweb2 module to which, for now, just import objects from
[Netbox](https://github.com/netbox-community/netbox) into Icinga
Director.

## Install

Download and install like any icingaweb2 module; drop the files into
the modules directory in the icingaweb2 path. On Debian systems this
is `/usr/share/icingaweb2/modules`.

To run the current:

```
git clone https://github.com/sol1/icingaweb2-module-netboximport netboximport
mv netboximport /usr/share/icingaweb2/modules
icingacli module enable netboximport
```

## Configuration

Configuration is done in the web interface under the "Automation" tab
of Icinga Director. See the
[official documentation](https://www.icinga.com/docs/director/latest/doc/70-Import-and-Sync/).

### Example sync of devices to hosts

1. Add an "Import Source" with an API token, with name "Netbox devices".
2. Select "Devices" from Object type to import, then "Store" to save it.
3. Read and perform steps in the "Property Modifiers" section below.
4. Select the new import source "Netbox devices", then "Trigger import run".
5. Select the "Sync rule" tab and create a rule with the fields filled out as follows:
  * Rule name: Devices
  * Object Type: Hosts
  * Update Policy: Replace
  * Purge: Yes
6. Select the new "Devices" rule and select the "Properties" tab.
7. Add a sync property rule with the following fields set:
  * Source Name: "Netbox devices"
  * Destination field: "object_name"
  * Source Column: "name"
  * Set based on filter: "No"
8. Add another sync property with the following fields set:
  * Source Name: "Netbox devices"
  * Destination field: "address"
  * Source Column: "ipv4_address"
  * Set based on filter: "No"
9. Select the sync rule "Devices" again, then click "Trigger this sync".
10. Select "Activity log" on the left, then "Deploy pending changes".

What did we do? We created an import source "Netbox devices" which
imports Netbox devices from the Netbox API into the Director database.
The sync rule "Devices" creates Icinga Host objects from the newly
imported data in the Director database.

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

## Acknowledgements

This module was initially based on a module by Uberspace:
https://github.com/Uberspace/icingaweb2-module-netboximport

It was rewritten by Oliver at [Sol1](https://www.sol1.com.au)
