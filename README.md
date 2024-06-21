# Icinga Notifications Web

[![PHP Support](https://img.shields.io/badge/php-%3E%3D%207.2-777BB4?logo=PHP)](https://php.net/)
![Build Status](https://github.com/Icinga/icinga-notifications-web/actions/workflows/php.yml/badge.svg?branch=main)
[![Github Tag](https://img.shields.io/github/tag/Icinga/icinga-notifications-web.svg)](https://github.com/Icinga/icinga-notifications-web/releases/latest)

> [!WARNING]
> This is an early beta version for you to try, but do not use this in production. There may still be severe bugs.
> At the moment, we don't provide any support for this module.

Icinga Notifications is a set of components that processes received events from various sources, manages incidents and
forwards notifications to predefined contacts, consisting of:

* The [Icinga Notifications daemon](https://github.com/Icinga/icinga-notifications), which receives events and sends notifications
* Icinga Web, that provides graphical configuration
* Icinga 2 and other custom sources that propagate state updates and other events to the daemon

![Icinga Notifications Web Preview](doc/res/notifications-preview.png)

## Documentation

Icinga Notifications Web documentation is available
at [icinga.com/docs](https://icinga.com/docs/icinga-notifications-web/latest).

## License

Icinga Notifications is licensed under the terms of the [GNU General Public License Version 2](LICENSE).
