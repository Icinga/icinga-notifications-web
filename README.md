# Icinga Notifications Web

> **Warning**
>
> This is an early preview version for you to try, but do not use this in production. There may still be severe bugs
> and incompatible changes may happen without any notice. At the moment, we don't provide any support for this.

Icinga Notifications is a set of components that processes received events from various sources, manages incidents and
forwards notifications to predefined contacts, consisting of:

* The [Icinga Notifications daemon](https://github.com/Icinga/icinga-notifications), which receives events and sends notifications
* An Icinga Web module (this repository), that provides graphical configuration and further processing of the data collected by the daemon
* And Icinga 2 and other custom sources that propagate state updates and acknowledgement events to the daemon

## Installation

First, install the [daemon](https://github.com/Icinga/icinga-notifications).

Then install this like any other [module](https://icinga.com/docs/icinga-web/latest/doc/08-Modules/). Use `notifications` as name.

## Configuration

After you have enabled the module, create a new database resource pointing to the database you have created
during the installation process of the daemon. Then choose it as the backend for the module at:
`Configuration -> Modules -> notifications -> Database (Tab)`

Your next step should be to set up the channels you want to use to send notifications over. You do this at:
`Configuration -> Modules -> notifications -> Channels (Tab)`.

> **Note**
>
> Make sure the **daemon** is able to connect to the SMTP host or Rocket.Chat Instance!

The base configuration is now done. You can continue now by setting up your first contacts, event rules and schedules!
You do this at: `Notifications -> Configuration`

## License

Icinga Notifications is licensed under the terms of the [GNU General Public License Version 2](LICENSE).
