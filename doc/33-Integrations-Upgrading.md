# Upgrading Integrations to v1.0

This page describes how to move an integration from Icinga Notifications Web **v0.2.0** to **v1.0**. If you build
a new integration, you can skip it, start with the [overview](30-Integrations.md) instead.

Icinga Notifications Web v0.3.0 is a release of the 0.x support line and has the same integration interface as v0.2.0.
The steps are the same for it.

## What Changed

| Topic                         | v0.2.0                                                                  | v1.0                                                                    |
|-------------------------------|-------------------------------------------------------------------------|-------------------------------------------------------------------------|
| Source hook                   | `Notifications/v1/Source`, `Hook\V1\SourceHook`, one per integration    | `Notifications/V2/<Type>Source`, `Hook\V2\SourceHook`, one per source type |
| Rule filter                   | The integration provided the editor, serialized the filter and (via the source) evaluated it | Icinga Notifications Web provides the editor, the integration validates and suggests, the daemon evaluates the filter against `relations` |
| Object names and links        | `Hook\ObjectsRendererHook`                                              | The source submits `name` and `summary` with each event, the object link is part of the source hook |
| Event rules                   | Bound to a source                                                       | Bound to a source type                                                  |
| Managing the source           | By hand in Icinga Notifications Web                                     | Optionally by the integration, `Integrations\V1\Source`                 |
| Incidents in your own views   | Not possible                                                            | `Integrations\V1\Incidents`, `Incident`                                 |
| Event submission              | `/process-event` with `rule_ids` and `rules_version`                    | `/v1/process-event` with `relations`                                    |

!!! note

    The v0.2.0 hooks `Hook\V1\SourceHook` and `Hook\ObjectsRendererHook` still exist in v1.0 and are marked as
    deprecated. **Nothing calls them anymore.** An integration that still only implements them keeps loading without
    errors, but it has no effect: sources of its type are treated as having no integration at all, see
    [What an Integration Can Do](30-Integrations.md#what-an-integration-can-do).

## Checklist

Do the steps in this order. Steps 1 to 5 are required for what a v0.2.0 integration did. Step 6 informs you about
consequences, step 7 applies if you maintain a source process, step 8 is optional and step 9 completes the move.

### 1. Decide to Support v1.0 Only

Do **not** try to support v0.2.0 and v1.0 side by side. The old hooks are not called by v1.0, and the new hooks are not
called by v0.2.0. Keeping both only adds code that one of the two versions never runs. Move to v1.0 completely and:

* Raise the version of Icinga Notifications Web that your integration documents as required to **v1.0**. Icinga
  Notifications Web v1.0 itself requires Icinga Notifications v1.0.
* Release the change together with, or after, the v1.0 releases. Upgrade the daemon first, see its
  [upgrading notes][daemon-upgrading].

<!-- TODO(verify): There is no verified mechanism to express "requires Icinga Notifications Web >= 1.0, if it is
enabled" for an optional module dependency. See the corresponding TODO in 30-Integrations.md. -->

### 2. Register `Notifications/V2/<Type>Source`

Replace the registration and move the class. The type is the one your `getSourceType()` returned.

```php
// Before, run.php
if ($this::exists('notifications')) {
    $this->provideHook('Notifications/v1/Source');
}

// After, run.php
if ($this::exists('notifications')) {
    $this->provideHook('Notifications/V2/Icinga2Source');
}
```

| Before                                                                      | After                                                                              |
|-----------------------------------------------------------------------------|------------------------------------------------------------------------------------|
| `library/Icingadb/ProvidedHook/Notifications/V1/Source.php`                 | `library/Icingadb/ProvidedHook/Notifications/V2/Icinga2Source.php`                 |
| `Icinga\Module\Icingadb\ProvidedHook\Notifications\V1\Source`               | `Icinga\Module\Icingadb\ProvidedHook\Notifications\V2\Icinga2Source`               |
| `implements Icinga\Module\Notifications\Hook\V1\SourceHook`                 | `implements Icinga\Module\Notifications\Hook\V2\SourceHook`                        |

How `<Type>` is derived from the source type is described in
[Registering the Hook](31-Integrations-Sources.md#registering-the-hook). If your module supports several source
types, register one hook per type.

### 3. Replace the Methods of the Hook

The interfaces differ in what they ask for:

| `Hook\V1\SourceHook` (v0.2.0)                      | v1.0                                                                                      |
|----------------------------------------------------|-------------------------------------------------------------------------------------------|
| `getSourceType(): string`                          | **Removed.** The type is part of the hook's name now                                      |
| `getSourceLabel(): string`                         | Same signature, [`getSourceLabel()`](31-Integrations-Sources.md#getsourcelabel)           |
| `getSourceIcon(): Icon`                            | Same signature, [`getSourceIcon()`](31-Integrations-Sources.md#getsourceicon)             |
| `getRuleFilterTargets(int $sourceId): array`       | **Removed.** What was a "target" (e.g. hosts only) is a condition on a column of the filter now |
| `getRuleFilterEditor(string $filter): Form`        | **Removed.** Replaced by `assertValidCondition()`, `enrichCondition()`, `getColumnSuggestions()` and `getValueSuggestions()` |
| `serializeRuleFilter(Form $editor): string`        | **Removed.** Replaced by `getJsonPaths()`                                                 |
| —                                                  | New: [`getEventTypes()`](31-Integrations-Sources.md#geteventtypes)                        |
| `ObjectsRendererHook::createObjectLink(array)`     | Moved: [`createObjectLink(array $idTags)`](31-Integrations-Sources.md#createobjectlink)   |

Rule filters are **no longer serialized or evaluated by the integration**. In v0.2.0 your hook provided the editor,
turned its state into a string, and, in the case of Icinga DB Web, also into database queries that the source ran
against its own data to find out which rules apply to an event. Since v1.0, Icinga Notifications Web provides the
editor, and the daemon evaluates the stored filter against the event's `relations`. Your hook only tells the editor
what is valid and helps with suggestions, and which part of `relations` a column refers to.

Move the code of your v0.2.0 hook as follows:

| In your v0.2.0 hook                                                        | In v1.0                                                                         |
|----------------------------------------------------------------------------|---------------------------------------------------------------------------------|
| The list of allowed columns and the `ON_VALIDATE_COLUMN` handler of the editor | `assertValidCondition()`, which throws a `SearchException`                   |
| The `QueryString::ON_CONDITION` handler that sets the `columnLabel`        | `enrichCondition()`                                                             |
| The suggestion URL of the editor, e.g. `icingadb/suggest/restriction-column`, and its controller | `getColumnSuggestions()` and `getValueSuggestions()`. Icinga Notifications Web serves the suggestions and calls them, your own endpoint is not needed for this anymore |
| The type selection of `getRuleFilterTargets()` (hosts, services, both)   | A condition on a column, such as `object.type=host`. Offer the values with `getValueSuggestions()` |
| `serializeRuleFilter()`, the SQL queries                                   | `getJsonPaths()`, a JSONPath into `relations` for each column. No SQL         |

The same Icinga DB Web class, before and after, reduced to the interface:

```php
// Before (v0.2.0): Icinga\Module\Icingadb\ProvidedHook\Notifications\V1\Source
class Source implements SourceHook
{
    public function getSourceType(): string { return 'icinga2'; }
    public function getSourceLabel(): string { return 'Icinga'; }
    public function getSourceIcon(): Icon { return new IcingaIcon('icinga'); }

    public function getRuleFilterTargets(int $sourceId): array { /* hosts, services, both */ }
    public function getRuleFilterEditor(string $filter): Form { /* a SearchEditor with validation */ }
    public function serializeRuleFilter(Form $editor): string { /* JSON incl. SQL queries */ }
}

// After (v1.0): Icinga\Module\Icingadb\ProvidedHook\Notifications\V2\Icinga2Source
class Icinga2Source implements SourceHook
{
    public function getSourceLabel(): string { return 'Icinga 2'; }
    public function getSourceIcon(): Icon { return new IcingaIcon('icinga'); }

    public function assertValidCondition(Condition $condition): void { /* throws SearchException */ }
    public function enrichCondition(Condition $condition): void { /* sets columnLabel */ }
    public function getColumnSuggestions(string $searchTerm): Traversable { /* ... */ }
    public function getValueSuggestions(string $column, string $searchTerm, Chain $searchFilter): Traversable { /* ... */ }
    public function getJsonPaths(string ...$columns): array { /* e.g. 'service.name' => ['services[*].name'] */ }
    public function getEventTypes(): array { /* downtime_start, ... */ }
    public function createObjectLink(array $idTags): ?ValidHtml { /* ... */ }
}
```

Be aware of what this means for the columns you offer:

* Every column is only usable if the source **submits** the data it refers to in `relations`, see
  [`getJsonPaths()`](31-Integrations-Sources.md#getjsonpaths) and
  [Consistency](30-Integrations.md#consistency-between-hooks-and-events). A column in v0.2.0 could be anything the
  integration was able to query. Now it must be something that is part of an event.
* Icinga DB Web, for example, dropped the columns of the contacts and contact groups of an object
  (`host.user.name`, `service.usergroup.name`, …), and added `object.type` and the display names.
  Review the list for your own columns.

### 4. Provide a Filter Migration Hook

Existing rule filters cannot be read by v1.0. Icinga Notifications Web marks such rules as outdated, and lets the user
migrate them, if the integration provides a `V1FilterMigrationHook`. Without it, users have to recreate the filter of
every rule by hand.

Register `Notifications/V2/V1FilterMigration` (see the `run.php` in the
[overview](30-Integrations.md#optional-dependency) and in
[Registering the Hook](31-Integrations-Sources.md#registering-the-hook)) and implement it.

#### Migrate Existing Rule Filters

`migrateFilter()` receives the string your v0.2.0 `serializeRuleFilter()` returned. You wrote that format, so you know
how to read it. Icinga DB Web wrote this (shortened):

```json
{
  "version": 1,
  "config": {
    "type": "host",
    "filter": "host.name=web*&hostgroup.name=linux"
  },
  "queries": {
    "host": {"query": "SELECT 1 FROM host WHERE …", "parameters": []}
  }
}
```

`config.type` was the target (`host`, `service` or `all`), `config.filter` the filter in the query string syntax of
the editor, and `queries` what the source evaluated. Only the first two are of interest to the migration:

```php
class V1FilterMigration implements V1FilterMigrationHook
{
    public function getSourceType(): string
    {
        return 'icinga2';
    }

    public function migrateFilter(string $v1Filter): Filter\Chain
    {
        try {
            $data = json_decode($v1Filter, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Invalid JSON: ' . $e->getMessage(), previous: $e);
        }

        if ($data['version'] !== 1 || ! isset($data['config']['type']) || ! isset($data['config']['filter'])) {
            throw new InvalidArgumentException('Invalid v1 filter format');
        }

        // The former target is a condition now
        $typeFilter = match ($data['config']['type']) {
            'all' => null,
            'host' => Filter::equal('object.type', 'host'),
            'service' => Filter::equal('object.type', 'service'),
            default => throw new InvalidArgumentException('Unknown v1 filter type: ' . $data['config']['type'])
        };

        $filter = (new Parser($data['config']['filter']))->parse();

        if ($typeFilter !== null) {
            $filter = Filter::all($typeFilter, $filter);
        } elseif (! ($filter instanceof Filter\Chain)) {
            $filter = Filter::all($filter);
        }

        return $filter;
    }
}
```

The hook does not have to validate the result. The user reviews the filter in the editor, and the source hook
validates it as for any other filter. Columns that no longer exist, like the contact columns above, then show up as
invalid, and the user has to correct them. If you can map them, do. If a filter cannot be converted at all, throw an
`InvalidArgumentException` with a message the user can act on.

See [Filter Migration Hook](31-Integrations-Sources.md#filter-migration-hook) for the contract and what users see.

!!! note

    Icinga DB Web, for example, ships the migration for the filters its v0.2.0 hook wrote. Filters that are empty
    do not need to be migrated and are never passed to the hook.

### 5. Stop Implementing `ObjectsRendererHook`

`Hook\ObjectsRendererHook` (registered as `Notifications/ObjectsRenderer`) let an integration provide the names of the
objects of its source for the incident views, and a link to them. It is no longer called. Remove it, and provide what it
did differently:

| `ObjectsRendererHook`                                  | v1.0                                                                          |
|--------------------------------------------------------|-------------------------------------------------------------------------------|
| `getObjectNames()`, `getHtmlForObjectNames()`          | The source submits a human readable `name` with **each event**. Icinga Notifications Web only shows plain text and no longer asks the integration to render object names |
| —                                                      | The source also submits a `summary`, a single line which is also used in the incident list and in notifications |
| `createObjectLink(array $objectIdTag)`                 | `SourceHook::createObjectLink(array $idTags)`, see [createObjectLink()](31-Integrations-Sources.md#createobjectlink) |
| `getSourceType()`                                      | Not needed anymore                                                            |

Make sure the `name` is what you previously rendered, i.e. what users know your objects by. Send `url` as an
**absolute** URL, see step 7.

### 6. Review the Effects of Rules Bound to a Source Type

In v0.2.0 an event rule belonged to **one source**. In v1.0 it belongs to a **source type** and applies to all
sources of that type. For an integration this means:

* Your hooks are per type and do not know which source a rule belongs to. `getRuleFilterTargets()` received a source ID,
  nothing like that exists anymore.
* Two sources of the same type, e.g. two Icinga DB instances in a high availability setup, share their rules. You no
  longer have to keep them in sync, and cannot have different rules for them.
* An incident can have more than one source. [`createObjectLink()`](31-Integrations-Sources.md#createobjectlink) is
  called for the first source type that has a hook, and has to be able to find the object regardless of the source
  that reported it.
* When you manage the source, changing its type with [`setType()`](31-Integrations-Sources.md#choosing-the-type) moves
  it to other rules, and deleting the **last** source of a type deletes the rules of that type.
  See [Managing the Source](31-Integrations-Sources.md#managing-the-source).

### 7. Adjust How Events Are Submitted

If your integration belongs to a process that submits events, that process has to be upgraded as well, as the daemon
rejects events in the old format. The details are in the
[HTTP API documentation](https://icinga.com/docs/icinga-notifications/latest/doc/20-HTTP-API/) and in the
[upgrading notes][daemon-upgrading] of the daemon. What you have to do:

| v0.2.0                                                   | v1.0                                                                          |
|----------------------------------------------------------|-------------------------------------------------------------------------------|
| `POST /process-event`                                    | `POST /v1/process-event`. The unversioned endpoint is no longer served       |
| `rule_ids` and `rules_version`. The daemon answered with `412` and the current rules if the version was outdated | Removed. The daemon evaluates rules itself. If `relations` are incomplete, the daemon answers with `422` and lists what is missing, see below |
| `extra_tags`, `username`                                 | Removed. Put what rules need into `relations`                                 |
| `type` (`state`, …), severities like `crit` and `err`    | `type` is only for events with `incident: false`. Severities are `ok`, `warning`, `error` and `critical`, and may only be set together with `incident: true` |
| `mute`, `mute_reason`                                    | `muted`, `muted_reason`                                                        |
| —                                                        | `incident`, `close`, `notify`, `relations`, `complete_relations`, `summary`, `id` |
| A relative `url` was completed using the configured `icingaweb2-url` of the daemon | `url` has to be absolute, or empty. The daemon rejects relative ones with `400` and the option is gone |
| Authentication by username and password                 | Unchanged. In addition, a source can connect via the daemon's Unix socket, or authenticate with a TLS client certificate |

Rules are evaluated against `relations` now, so the source has to put everything into events that the columns of
your hook (step 3) refer to, and has to be prepared to be asked for what is missing. A rule on a custom variable,
for example, needs that variable in the `relations` of the events it should match. Icinga DB lets users configure
which relations are sent by default, and extends them if the daemon answers with `422`. Check
[Consistency](30-Integrations.md#consistency-between-hooks-and-events) afterwards.

<!-- TODO(verify): The lists of removed and new event fields were derived from icinga-go-library v0.8.1 (used by Icinga
Notifications v0.2.0) and the revision pinned by Icinga Notifications main (0a1f3d0c0b67). The daemon's upgrading notes
do not list them. Confirm with the daemon maintainers that this is the complete list of breaking changes, in
particular the severity names. -->

### 8. Optional: Use the New Capabilities

Not required, but this is what v1.0 offers an integration beyond the hooks:

* **Manage the source** from your module instead of asking users to create it and copy credentials, with
  [`Integrations\V1\Source`](31-Integrations-Sources.md#managing-the-source). A source managed this way is shown as
  locked in Icinga Notifications Web.
* **Show and manage incidents** in your own views with
  [`Incidents` and `Incident`](32-Integrations-Incidents.md), e.g. to subscribe to the incident of an object, or to
  list its recipients.
* **Event types** for notification rules, a new type of rule that notifies without an incident:
  [`getEventTypes()`](31-Integrations-Sources.md#geteventtypes).

### 9. Remove the v0.2.0 Implementation

When the steps above are done, delete the old code. It is never called by v1.0:

* `provideHook('Notifications/v1/Source')` in `run.php`, and the class `ProvidedHook\Notifications\V1\Source`
* The `Notifications/ObjectsRenderer` hook, if you had one
* Everything that only served the old filter editor, e.g. the code that built the SQL queries of `serializeRuleFilter()`
  and the evaluation of `rule_ids` in the source. Keep the suggestion code you reuse for the V2 hook
* The documentation of the old way, and the mention of Icinga Notifications Web v0.2.0 as supported

Then test the following with a v1.0 setup:

1. Create an event rule for your source type, pick columns and values via the suggestions, and save it.
2. Submit an event that must match it, and one that must not. Check the daemon's log or the incident history.
3. Open the incident, and check the label, the icon and the link to the object in its detail.
4. If you ship a migration hook, take a rule with a filter that was created before the upgrade and migrate it.
5. If you use `Incidents`, subscribe and unsubscribe on an object with an open incident, and verify that the pending
   state resolves.

[daemon-upgrading]: https://icinga.com/docs/icinga-notifications/latest/doc/04-Upgrading/#upgrading-to-icinga-notifications-v10
