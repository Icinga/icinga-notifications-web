# Icinga Notifications Web Changelog

Please make sure to always read our [Upgrading](https://icinga.com/docs/icinga-notifications-web/latest/doc/05-Upgrading/)
documentation before switching to a new version.

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
