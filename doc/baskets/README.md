# Intro
The baskets follow a series of patterns to make managing your infrastructure easier. This includes 

- The creation of parent host templates for automated templates, a common parent makes it easier to find things in tree view and for Icinga's inheritance to be used if required.
- Groups created using `Assign Filters` looking templates on the host. This means you don't need to add `Group Membership` in Sync Rule properties, just the template, it also add all groups for nested tempalates which have a parent relationship like Region and Sites.
- `keyid` is used for most object names and duplicates are removed to help ensure the automation doesn't fail.  
- Netbox objects with a count of 0 are removed to reduce, but not eliminate, unecessary Icinga groups and templates that won't be referenced by a host. 

These imported director Import Sources and Sync Rules should be altered, or parts removed, to suit your needs. These baskets represent a good starting point based on our experience. 

## Dependencies
### endpoint-automation.json
The endpoint basket depends on a Netbox tag with a `slug` of `icinga-endpoint`. 

Devices and VM's tag'd with `icinga-endpoint` are assumed to have Icinga installed on them and the corresponding Icinga `endpoint` and `zone` objects are created based on these Netbox Objects.

### host-automation.json
The host basket depends on a Netbox custom field with a `name` of `icinga_import_source`.

This is typically setup as a selection that is `required`, the selection values are initally `do-not-monitor` and `default` but can be expanded. 

`icinga_import_source` is used to control what is imported and which set of Import Source/Sync Rules a Netbox object is imported with. 

### platform-automation.json
The platform basket depends on 3 custom fields `platform_type`, `platform_version` and `platform_family`.

These custom fields are intended to define the type, family and version of a operating system beyond the Netbox platform name.

eg: 
| Name              | Version  | Family  | Type  |
|-------------------|----------|---------|-------|
| Debian 12 (x64)                   | Debian 12             | Debian            | linux |
| Debian 12 (arm)                   | Debian 12             | Debian            | linux |
| Ubuntu 24.04                      | ubuntu 24.04          | Debian            | linux |
| Redhat 8                          | Redhat 8              | RHEL              | linux |
| Rocky Linux 9                     | Rocky Linux 9         | RHEL              | linux |
| macOS 15.01                       | macOS 15              | macOS             | bsd |
| Windows Server 2022 20348.2700    | Windows Server 2022   | Windows Server    | windows |
| Windows Server 2022 20348.230     | Windows Server 2022   | Windows Server    | windows |

The automation turns the 3 custom fields into groups that allow for better targeting of specific operating systems with checks. 


# Baskets
These Director baskets are predefined sets of import and sync rules, templates and groups that for the basis of setup for automationing objects out of Netbox.

While these baskets are related and can depend on each other to work "out of the box" you are encouraged to import only the bits that fit your requirements and modify them to suit your own need. 

The baskets setup Icinga objects directly related to Netbox objects as well as organization Icinga templates to make the management of related templates easier, eg: All Netbox Cluster objects imported as Icinga host templates have a parent template called `Netbox Clusters`.

## Host Automation
Host automation isn't included here simply because hosts will generally have their own sets requirements. 

One strong recommendation is to use a Netbox custom field `icinga_import_source` as described in the [Zone and Endpoint Creation section of the main README](../../README.md)

## Cluster Automation
[`cluster-automation.json`](cluster-automation.json) imports Netbox Clusters (`virtualization/clusters`), Cluster Types (`virtualization/cluster-types`) and Cluster Groups (`virtualization/cluster-groups`). 

The Netbox Cluster host templates created from this can be imported to your Icinga host objects created from virtual machines or devices, these host objects will then have all templates and groups associated.

**Objects Created**
- Cluster Host Template
- Cluster Host Group
- Cluster Type Host Template
- Cluster Type Host Group
- Cluster Group Host Template
- Cluster Group Host Group

## Endpoint Automation
[`endpoints-automation.json`](endpoints-automation.json) is the odd one out in this list of import. Endpoints aren't referening to a Netbox object that is being automated into Icinga but the creation of Icinga `Endpoints` and `Zones`.

Automation of endpoints from Netbox into Icinga has the advantage of allowing you to use Netbox as a way to automate the installation of endpoints using tools like Ansible, etc... because both Icinga and the installation automation tools are using the same source of truth.

### Depends on in Netbox
A Netbox `tag` with the slug `icinga-endpoint` which would be applied to each device or virtual machine with Icinga to be installed on it. 

An alternative way to impliment this would be to have seperate tags for `icinga-satellite` and `icinga-agent` and have seperate imports sources and sync rules for each of these tags. The reason you need seperate import sources and sync rules with different tags is Netbox filters for tags are `and` not `or`, so we need seperate import sources.

If you have a Netbox custom field `icinga_import_source` as described in the [Zone and Endpoint Creation section of the main README](../../README.md) you can also add that to the filters. 


**Objects Created**
- Device Host Template
- Device Endpoint
- Device Zone
- Virtual Machine Host Template
- Virtual Machine Endpoint
- Virtual Machine Zone

## Platform Automation
[`platform-automation.json`](platform-automation.json) imports Netbox Platforms (`dcim/platforms`). 

The Netbox Platform host templates created from this can be imported to your Icinga host objects created from virtual machines or devices, these host objects will then have all templates and groups associated.

**Objects Created**
- Platform Host Template
- Platform Host Families Group
- Platform Host Types Group
- Platform Host Versions Group

### Depends on in Netbox
The platform automation depends on 3 custom fields on Netbox Platforms
- `platform_type` 
- `platform_family`
- `platform_version`

These custom fields would contain platform information in a monitoring friendly format. 

For example a platform that is named *Microsoft Windows Server 2019 (2004)* would have a `platform_type` of *Windows*, a `platform_family` as *Windows Server* and a `platform_version` of *Windows Server 2019*. 

## Role Automation
[`role-automation.json`](role-automation.json) imports Netbox Roles (`dcim/device-roles`). 

The Netbox Roles host templates created from this can be imported to your Icinga host objects created from virtual machines or devices, these host objects will then have all templates and groups associated.

**Objects Created**
- Roles Host Template
- Roles Host Group

## Site Automation
[`site-automation.json`](site-automation.json) imports Netbox Sites (`dcim/sites`) and Regions (`dcim/regions`). 

The Netbox Site host templates created from this can be imported to your Icinga host objects created from virtual machines or devices, these host objects will then have all templates and groups associated.

**Objects Created**
- Site Host Template
- Site Host Group
- Region Host Template
- Region Host Group

*Note the Site and Region templates use Icinga template inhertance to create a complete tree of all regions a site is part of.*

## Tag Automation
[`tag-automation.json`](site-automation.json) imports Netbox Tags (`extras/tags`). 

The Netbox Tag host groups created use an apply rule that depends on a var `host.vars.tags` added to Icinga host objects created from virtual machines or devices. The `host.vars.tags` variable should populated using the predefined `tag_slugs` column created by the import module in your sync rules for Icinga host object. 

**Objects Created**
- Tag Host Group

## Tenant Automation
[`tenant-automation.json`](tenant-automation.json) imports Netbox Tenant (`dcim/tenants`) and Tenant Groups (`dcim/tenant-groups`). 

The Netbox Tenant host templates created from this can be imported to your Icinga host objects created from virtual machines or devices, these host objects will then have all templates and groups associated.

**Objects Created**
- Tenant Host Template
- Tenant Host Group
- Tenant Group Host Template
- Tenant Group Host Group