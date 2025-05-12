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

