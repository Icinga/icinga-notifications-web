# Configuration

![Icinga Notifications Web Preview](res/notifications-preview.png)

If Icinga Web has been installed but not yet set up, please visit Icinga Web and follow the web-based setup wizard.
For Icinga Web setups already running, log in to Icinga Web with a privileged user and follow the steps below to
configure Icinga Notifications Web:

<!-- {% if not icingaDocs %} -->

## Module Activation

If you just installed Icinga Notifications Web, remember to activate it on your Icinga Web instance(s):

- Use Icinga Web's command-line interface on the webserver(s) and execute `icingacli module enable notifications`.
- Visit Icinga Web, log in as a privileged user and enable the module under `Configuration →
  Modules → notifications` by switching the state from `disabled` to `enabled`.

<!-- {% endif %} -->

## Access Control

!!! warning

    Authorization mechanics in the current release are not fully functional. For example, restricting users to certain
    objects is not supported. Do not grant users access to the module unless you are sure they are authorized to see
    **all** events and incidents.

### Permissions

| Permission                       | Description                                    |
|----------------------------------|------------------------------------------------|
| notifications/config/schedules   | Allow to configure schedules                   |
| notifications/config/event-rules | Allow to configure event rules                 |
| notifications/config/contacts    | Allow to configure contacts and contact groups |
| notifications/view/contacts      | Allow to view contacts                         |
| notifications/api                | Allow to modify configuration via API          |

## Database Configuration

Connection configuration for the database, which both,
[Icinga Notifications](https://github.com/Icinga/icinga-notifications) and [Icinga Notifications Web](https://github.com/Icinga/icinga-notifications-web), use.

!!! tip

    If not already done, initialize your database by following these [instructions](https://icinga.com/docs/icinga-notifications/latest/doc/02-Installation#setting-up-the-database).

1. Create a new resource for the Icinga Notifications database via the `Configuration → Application → Resources` menu.
2. Configure the resource you just created as the database connection for Icinga Notifications Web using the
   `Configuration → Modules → notifications → Database` menu.

## Channels Configuration

As the Icinga Notifications daemon notifies contacts in case of events and incidents, you need to configure appropriate 
communication channels.

The currently supported channels can be found [here](01-About.md#available-channels).

They can be configured through `Configuration → Modules → notifications → Channels`.

You need to configure at least one valid communication channel to fully configure Icinga Notifications Web.

## Sources Configuration

Sources are the most vital part of Icinga Notifications. Without them, no events will be processed and no notifications
will be sent. So the next thing to configure is your first source. To be able to configure sources, an integration in
Icinga Web is required. Consult the source-specific documentation on how to integrate such.

How to integrate Icinga 2 is covered in the next section, for your convenience. :)

### Adding an Icinga 2 source

Ensure that you use Icinga DB as the database backend for Icinga 2. If that is the case, you should already have
Icinga DB Web installed. This is the integration required to configure Icinga 2 as a source.

1. Navigate to `Configuration → Module → notifications → Sources` and add a new source.
2. Choose type `Icinga` and define a name as well as a set of credentials.
3. Open `/etc/icingadb/config.yml` on the host where Icinga DB is running and add the following lines:  
   The full documentation can be found [here](https://icinga.com/docs/icinga-db/latest/doc/03-Configuration/#notifications-configuration).
    ```yaml
    notifications:
      # URL to the API root.
      url: http://localhost:5680

      # Use the username and password you just defined for the credentials.
      username: icingadb
      password: insecureinsecure
    ```
4. Restart Icinga DB.
