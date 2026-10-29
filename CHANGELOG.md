# Icinga Notifications Web Changelog

Please make sure to always read our [Upgrading](https://icinga.com/docs/icinga-notifications-web/latest/doc/05-Upgrading/)
documentation before switching to a new version.

## 1.0.0 (2026-10-29)

Icinga Notifications Web 1.0.0 is released alongside Icinga Notifications 1.0.0 and requires it.

If you came along with us and upgrade from v0.2.0 or v0.3.0, please read the
[upgrading documentation](https://icinga.com/docs/icinga-notifications-web/latest/doc/05-Upgrading/#upgrading-to-icinga-notifications-web-v10)
carefully, especially regarding the new permissions and the changed navigation.

This is our first stable release, and there are certain key changes:

* Sources no longer require a module for Icinga Web and event rules are no longer bound to a single source.
* Event rules are now separated into _escalation rules_ and _notification rules_
* The UI of Icinga Notifications Web is now meant only for configuration and analysis, as Icinga Web modules
  (now called _integrations_) are expected to provide access for day-to-day usage instead. 
* Icinga Notifications Web is now licensed under `GPL-3.0-only`

## 0.3.0 (2026-07-07)

This release ensures compatibility with PHP 8.5 and icinga-php-thirdparty 1.0.

## 0.2.0 (2025-11-19)

With Icinga Notifications 0.2.0 we changed how event rule filters are configured and processed.
Sources are now responsible for deciding which event rules apply to a given event. In the case
of Icinga 2, this allows associating event rules with custom variables, for example. Please
make sure to upgrade Icinga DB to 1.5.0 and Icinga DB Web to 1.3.0 to facilitate this change.

## Additional Features

Some of you asked for this in the past, and now Icinga Notifications Web provides a way to configure
contacts and contact groups using a REST API! But that's not all, the API is also thoroughly documented
using the OpenAPI standard to make it easy to integrate with other tools. Make sure to check it out:
https://icinga.com/docs/icinga-notifications-web/latest/doc/20-REST-API/

The schedule configuration got also a highly expected enhancement: Timezone support!
You can now configure a schedule to use a specific timezone other than the local timezone.
When viewing a schedule, you can temporarily switch to any other timezone, allowing you to
easily verify availability even if abroad.

### Fixes and Enhancements

A few months ago, we performed several user testing sessions, and this release includes the changes
suggested during those sessions. Expect to see various small improvements and better user experience.
The schedule configuration in particular has been improved in many ways.

## 0.1.0 (2024-07-24)

Initial release
