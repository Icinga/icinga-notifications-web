# Configuration

If Icinga Web has been installed but not yet set up, please visit Icinga Web and follow the web-based setup wizard.
For Icinga Web setups already running, log in to Icinga Web with a privileged user and follow the steps below to
configure Icinga Notifications Web:

<!-- {% if not icingaDocs %} -->

## Module Activation

If you just installed the module, do not forget to activate it on your Icinga Web instance(s) by using your
preferred way:

- Access the Icinga 2 command-line interface on your master(s) and execute `icingacli module enable notifications`.
- Visit Icinga Web, log in as a privileged user and activate the module under `Configuration →
  Modules → Notifications` by switching the state from `disabled` to `enabled`.

<!-- {% endif %} -->

## Database Configuration

Connection configuration for the database, which both,
[Icinga Notifications](https://github.com/Icinga/icinga-notifications) and [Icinga Notifications Web](https://github.com/Icinga/icinga-notifications-web), use.

!!! tip
   If not already done, initialize your database by following
   the [instructions](https://icinga.com/docs/icinga-notifications/latest/doc/02-Installation#setting-up-the-database).

1. Create a new resource for the Icinga Notifications database via the `Configuration → Application → Resources` menu.
2. Configure the resource you just created as the database connection for the Icinga Notifications Web module using the
   `Configuration → Modules → notifications → Database` menu.

## Channels Configuration

As this module notifies contacts in case of events and incidents, you need to configure appropriate communication
channels.

The currently supported channels can be found in the [Icinga Notifications documentation](https://icinga.com/docs/icinga-notifications/latest/doc/10-Channels#available-channels).

You need to configure at least one valid communication channel to be able to supply your contacts with notifications.

## Sources Configuration

The notifications module operates on data fed by miscellaneous sources and is therefore not restricted to Icinga 2 only.
Though, any other external system needs to
respect [a specific data structure when providing its own data](https://icinga.com/docs/icinga-notifications/latest/doc/02-Installation#process-event).

You need to provide at least one valid source for this module to function properly.

### Adding an Icinga 2 source

If you want the notifications module to process Icinga 2 events, you will need to add it as a source:

1. Navigate to `Configuration → Module → notifications → Sources` and add a new source.
2. Choose type `Icinga` and provide Icinga 2 API credentials with the following
   [permissions](https://icinga.com/docs/icinga-2/latest/doc/12-icinga2-api/#overview):
   - events/*
   - objects/query/*
3. Enable `Verify API Certificate` if you want
   [Icinga Notifications](https://github.com/Icinga/icinga-notifications) to check for the certificate
   validity of the given REST API endpoint.
