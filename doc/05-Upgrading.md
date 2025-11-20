# Upgrading Icinga Notifications Web

Specific version upgrades are described below. Please note that version upgrades are incremental.
If you are upgrading across multiple versions, make sure to follow the steps for each of them.

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
