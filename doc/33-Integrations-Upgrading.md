# Upgrading Integrations to v1.0

This page describes how to move an integration from Icinga Notifications Web **v0.2.0** to **v1.0**. If you build
a new integration, you can skip it, start with the [overview](30-Integrations.md) instead.

Icinga Notifications Web v0.3.0 is a release of the 0.x support line and has the same integration interface as v0.2.0.
The steps are the same for it.

## What Changed

| Topic                       | v0.2.0                                                                                       | v1.0                                                                                                                                      |
|-----------------------------|----------------------------------------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------------------------------------|
| Source hook                 | `Notifications/v1/Source`, `Hook\V1\SourceHook`, one per integration                         | `Notifications/V2/<Type>Source`, `Hook\V2\SourceHook`, one per source type                                                                |
| Rule filter                 | The integration provided the editor, serialized the filter and (via the source) evaluated it | Icinga Notifications Web provides the editor, the integration validates and suggests, the daemon evaluates the filter against `relations` |
| Object names and links      | `Hook\ObjectsRendererHook`                                                                   | The source submits `name` and `summary` with each event, the object link is part of the source hook                                       |
| Event rules                 | Bound to a source                                                                            | Bound to a source type                                                                                                                    |
| Managing the source         | By hand in Icinga Notifications Web                                                          | Optionally by the integration, `Integrations\V1\Source`                                                                                   |
| Incidents in your own views | Not possible                                                                                 | `Integrations\V1\Incidents`, `Incident`                                                                                                   |

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
* Release the change together with, or after, the v1.0 releases.

### 2. Register `Notifications/V2/<Type>Source`

Replace the registration and move the class. The type is the one your `getSourceType()` returned.

```php
// Before, run.php
$this->provideHook('Notifications/v1/Source');

// After, run.php
$this->provideHook('Notifications/V2/Icinga2Source');
```

Move the class to the location the new hook name implies, e.g. `ProvidedHook/Notifications/V2/Icinga2Source.php`,
and let it implement `Hook\V2\SourceHook` instead of `Hook\V1\SourceHook`.

How the hook name and class location are derived from the source type is described in
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

In v0.2.0, the filter of an event rule was a string that only the integration could interpret and evaluate. Since
v1.0, the daemon evaluates a JSON filter by itself, and filters created by a v0.2.0 integration cannot be read anymore.
Icinga Notifications Web marks rules that still have such a filter as outdated, and lets the user migrate them, if the
integration provides a `Icinga\Module\Notifications\Hook\V2\V1FilterMigrationHook`. Without it, users have to
recreate the filter of every rule by hand.

#### Registering the Hook

Register it as `Notifications/V2/V1FilterMigration`, next to the source hook, and put the class where provided hooks
go:

```php
// run.php
$this->provideHook('Notifications/V2/V1FilterMigration');
```

The class is by convention `ProvidedHook\Notifications\V2\V1FilterMigration`. Unlike the source hook, the name of this
hook is the same for all source types. Icinga Notifications Web picks the one whose `getSourceType()` matches the type
of the rule. The permission rules of [Permissions](30-Integrations.md#permissions) apply as for any hook.

#### The v0.2.0 Format

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
the editor, and `queries` what the source evaluated. Only the first two are of interest to the migration.

#### Implementing the Hook

This is the migration of Icinga DB Web, slightly **simplified**:

```php
namespace Icinga\Module\Icingadb\ProvidedHook\Notifications\V2;

use Icinga\Module\Notifications\Hook\V2\V1FilterMigrationHook;
use InvalidArgumentException;
use ipl\Stdlib\Filter;
use ipl\Web\Filter\Parser;
use JsonException;

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

#### getSourceType()

```php
public function getSourceType(): string
```

The source type this hook migrates filters for, the same as the type of your
[source hook](31-Integrations-Sources.md#registering-the-hook).

#### migrateFilter()

```php
public function migrateFilter(string $v1Filter): \ipl\Stdlib\Filter\Chain
```

Receives the filter exactly as the v0.2.0 `serializeRuleFilter()` returned it, and returns it as a filter chain.

* Throw `InvalidArgumentException` if the filter cannot be migrated, e.g. because it is invalid or uses something that
  no longer exists. The message is shown to the user and the exception is logged. Make the message understandable.
* The result **should** only contain conditions that your source hook's `assertValidCondition()` accepts, so convert
  columns that were renamed. It does not have to, as the user reviews the result and every change is validated by the
  source hook as usual. Columns that no longer exist, like the contact columns above, then show up as invalid, and the
  user has to correct them. If you can map them, do.
* A rule without a filter does not need a migration, and the hook is not called for it.

#### What Users See

1. In the list of event rules, a rule that still has a v0.2.0 filter is marked as outdated. Clicking it opens the
   migration instead of the regular rule detail.
2. Icinga Notifications Web calls your hook and shows the result in the filter editor, together with a note that the
   filter is in an old format and has to be reviewed.
3. The user reviews the filter and saves it. On saving, the filter is validated and stored by the regular
   mechanisms, i.e. `assertValidCondition()` and `getJsonPaths()` of the source hook.

If there is neither of both hooks available, or any one throws, the user sees a message, "No migration hook found for
source type …" or the one of your exception, and is unable to complete the migration.

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

### 7. Adjust How Events Are Submitted

If your integration belongs to a process that submits events, that process has to be upgraded as well, as the daemon
rejects events in the old format. See the daemon's
[HTTP API documentation](https://icinga.com/docs/icinga-notifications/latest/doc/20-HTTP-API/).

Rules are evaluated against `relations` now, so the source has to put everything into events that the columns of
your hook (step 3) refer to, and has to be prepared to be asked for what is missing. A rule on a custom variable,
for example, needs that variable in the `relations` of the events it should match.
Check [Consistency](30-Integrations.md#consistency-between-hooks-and-events) afterwards.

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
