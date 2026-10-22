# Upgrading Icinga Notifications Web

Specific version upgrades are described below. Please note that version upgrades are incremental.
If you are upgrading across multiple versions, make sure to follow the steps for each of them.

## Upgrading to Icinga Notifications Web v1.0

Icinga Notifications Web v1.0 is released alongside Icinga Notifications v1.0 and requires it. Upgrade the daemon
first and follow its
[upgrading notes](https://icinga.com/docs/icinga-notifications/latest/doc/04-Upgrading/#upgrading-to-icinga-notifications-v10),
which include the database schema upgrade and changes to its configuration file.

### Permissions

The `Notifications → Configuration` menu entry no longer exists. `Incidents`, `History`, `Event Rules`, `Schedules`,
`Contacts` and `Contact Groups` are now separate entries under `Notifications`. Channels, sources and the database
connection remain available as tabs under `Configuration → Modules → notifications`.

The new permissions `notifications/view/incidents` and `notifications/view/history` control access to incidents and
the notification history. Existing roles do not have them. Add them to all roles whose users need to keep seeing
incidents in Icinga Notifications Web.

!!! warning

    Restricting users to certain objects is not supported. Only grant these permissions to users who are allowed to
    see **all** events and incidents. Regular users should work with incidents in the web integration of the source.
    See [Access Control](03-Configuration.md#access-control).

### Removed Features

* The `Events` list and the event detail view have been removed. The daemon no longer stores events. Use the incident
  history and the notification history to analyze its behavior.
* The channel type Rocket.Chat has been removed. Existing Rocket.Chat channels are dropped during the schema upgrade of
  Icinga Notifications. Recreate them with another channel type, such as `webhook`, and update the event rules that
  used them.

### Event Rules

Event rules are now bound to a **source type** instead of an individual source. A rule applies to all sources of that
type. Deleting a source only deletes its event rules if it is the last source of its type.

If your event rules still contain filters in the format used before v1.0, they are marked in
`Notifications → Event Rules` as outdated. Click on the rule to migrate its filter. Review the converted filter and
save it. This is only possible if the integration of the source supports it.

Event rules can now be of the type _escalation rule_ or _notification rule_. Existing rules are escalation rules.

### Sources

Sources no longer require an integration in Icinga Web. A source that is maintained by an integration, such as Icinga
DB Web, is now shown as locked and read-only in Icinga Notifications Web.

If you have configured an Icinga DB source manually, please consult their upgrade documentation for a detailed
description of the recommended migration steps: https://icinga.com/docs/icinga-db/latest/doc/04-Upgrading/#upgrading-to-icinga-db-v160

## Upgrading to Icinga Notifications Web 0.2.0

With Icinga Notifications 0.2.0 we changed how event rule filters are configured and processed.
Previously, event rules could be associated with specific objects by referencing their tags.
This is no longer the case, and how event rules are associated with objects is now fully controlled
by the source bound to the event rule. This means each event rule must now be bound to a specific
source, which is responsible for evaluating which objects the event rule applies to. By following
the upgrading steps of Icinga Notifications 0.2.0, all your event rules have automatically been
migrated and are now bound to the Icinga 2 source that is already configured. If you have filters
configured for event rules, they need to be migrated manually though. You need to access the database
directly for this and update it accordingly.

Review the currently configured event rules and their filters:

```sql
SELECT name, object_filter FROM rule;
```

Take note of the `object_filter` values for each event rule. Store them somewhere. If you want to
migrate them, you can do this only in the UI. In the case of Icinga 2, the supported filter syntax is
the same as in Icinga DB Web and supports the same columns as restrictions do there. `hostgroup/…`
becomes `hostgroup.name=…` and `servicegroup/…` becomes `servicegroup.name=…`.

To migrate the filters in the UI, you need to update the table first:

```sql
UPDATE rule SET object_filter = NULL;
```

Now, you can re-apply the filters in the UI.
