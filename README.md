# Icinga Notifications Web

![Icinga Notifications Web Preview](doc/res/notifications-preview.png)

Icinga Notifications is a set of components that processes received events from various sources, manages
incidents and forwards notifications to predefined contacts. The components are:

* [Icinga Notifications](https://icinga.com/docs/icinga-notifications/latest/doc/01-About/), the daemon, which
  receives events and sends notifications.
* Icinga Notifications Web, this module for Icinga Web, which lets administrators configure Icinga Notifications and
  analyze its behavior.
* Sources, such as Icinga DB or Icinga for Kubernetes, which submit events to the daemon.

## Big Picture

![Icinga Notifications Architecture](doc/res/notifications-architecture.png)

Sources submit events to the Icinga Notifications daemon. The daemon evaluates these events against the configured
event rules and decides whether to open an incident, when to escalate it and who to notify through which channel.
The configuration resides in a database shared by the daemon and Icinga Notifications Web.

Icinga Notifications Web is the place to configure contacts, schedules, event rules and channels, and to look up
incidents and sent notifications. Most of the configuration is done here. Sources that are maintained by an
integration, such as Icinga DB Web, are the exception. The integration creates and updates them itself, so Icinga
Notifications Web shows them read-only. For other sources, you set up the credentials here.

Compatible sources are, for example:

* Icinga 2, by using Icinga DB as backend
* Icinga for Kubernetes

Any other software that can submit events to the
[HTTP API](https://icinga.com/docs/icinga-notifications/latest/doc/20-HTTP-API/) of the daemon can act as a source
as well.

## Documentation

Icinga Notifications Web documentation is available at [icinga.com/docs](https://icinga.com/docs/icinga-notifications-web/latest).

## License

Icinga Notifications Web and its documentation are licensed under the terms of the [GNU General Public License Version 3](LICENSE.md).
