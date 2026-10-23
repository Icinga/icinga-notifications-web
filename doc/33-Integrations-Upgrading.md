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
| Event submission              | `/process-event` with `rule_ids` and `rules_version`                    | `/v1/process-event` with `relations`, see [Daemon Changes](#daemon-changes) |

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
rejects events in the old format. What changed is listed in [Listener API](#listener-api) below, together with the
other changes of the daemon that concern a source.

Rules are evaluated against `relations` now, so the source has to put everything into events that the columns of
your hook (step 3) refer to, and has to be prepared to be asked for what is missing. A rule on a custom variable,
for example, needs that variable in the `relations` of the events it should match. Icinga DB lets users configure
which relations are sent by default, and extends them if the daemon answers with `422`. Check
[Consistency](30-Integrations.md#consistency-between-hooks-and-events) afterwards.

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

## Daemon Changes

The documentation of Icinga Notifications only describes the current state. This part lists what changed for a
**source** since v0.2.0, so you know what to adjust in the process that submits events. It is derived from the daemon
at the tag v0.2.0 and at its current main branch, and from the Icinga Go library (`icinga-go-library`, IGL) at v0.8.1,
the version the daemon used in v0.2.0, and at the revision the daemon pins now. For the complete current reference, see
the [HTTP API documentation][daemon-api]. How to operate the daemon is covered by its
[configuration][daemon-config] and [upgrading notes][daemon-upgrading].

If your source is written in Go, use the `source.Client` of IGL (package `notifications/source`). It already speaks the
current API, e.g. `ProcessEvent()` takes a flag to reject incomplete relations and returns the missing ones.

### Listener API

| Topic | v0.2.0 | v1.0 |
|-------|--------|------|
| Endpoints | `/process-event`, `/incidents` | `/v1/process-event`, `/v1/incidents`. The unversioned ones are no longer served. The `/debug/…` endpoints stay unversioned |
| Transport | HTTP only, configured by `listen` | TCP, optionally with TLS, and Unix socket. The options live in the `listener` section of the daemon's configuration |
| Authentication | HTTP Basic Authentication | Unchanged for TCP. Also a TLS client certificate, which identifies the source by its subject (sending a certificate and Basic Authentication together is rejected with `400`), and, on the Unix socket, the OS user of the process, which identifies the source by its listener username |
| Rule handling | The source evaluated the rules and submitted `rule_ids` and `rules_version`. With an outdated version, the daemon answered `412` and the current rules of the source | The daemon evaluates the rules against the event's `relations`. `rule_ids`, `rules_version` and the `412` are gone |
| Missing data for rules | — | `relations`, `complete_relations`, and the header `X-Icinga-Reject-If-Relations-Incomplete`. If set, the daemon answers `422` with the missing relations as JSONPaths, see [Incomplete Relations][daemon-incomplete] and [Consistency](30-Integrations.md#consistency-between-hooks-and-events) |
| Success | `202` with a text body, after the event has been processed | `204` without a body, as soon as the event is **queued**. Processing happens afterwards, see [High Availability](#high-availability) |
| Errors | `406` for superfluous state or mute events, `412` | `400` for invalid JSON, **unknown fields** and invalid events, `401`, `405`, `422` for incomplete relations, `500` if the event could not be queued. Events that turn out to be invalid during processing are dropped and only logged |
| `/incidents` | `GET`, no filter, the JSON array of all open incidents of the source | `GET` and `POST` on `/v1/incidents`, both require the query parameter `filter`. The response is a stream of JSON objects, one per line |

#### Event Fields

| Field | v0.2.0 | v1.0 |
|-------|--------|------|
| `rules_version`, `rule_ids`, `extra_tags`, `username` | Used | **Removed**, the daemon rejects them as unknown fields |
| `type` | An enumeration: `state`, `acknowledgement-set`, `acknowledgement-cleared`, `mute`, `unmute`, `downtime-start`, `downtime-end`, `downtime-removed`, `flapping-start`, `flapping-end`, `incident-age` and `custom`. The daemon assumed `state` if it was missing | A free-form string that is **only** allowed, and then required, for events with `incident: false`. It has to match a key of [`getEventTypes()`](31-Integrations-Sources.md#geteventtypes) |
| `severity` | `ok`, `debug`, `info`, `notice`, `warning`, `err`, `crit`, `alert`, `emerg` | `ok`, `warning`, `error`, `critical`. Required if, and only if, `incident` is `true` |
| `mute`, `mute_reason` | `mute` and a reason | `muted` and `muted_reason`. The reason is required whenever `muted` is set |
| `id` | — | A UUID that identifies the event, see below |
| `summary` | — | **Required**. A single line of at most 1024 characters |
| `incident` | — | `true` opens or escalates an incident, `false` only triggers notification rules, see [Rule Evaluation][daemon-rules] |
| `close`, `notify` | — | Only `true` or omitted, and only together with `incident: true` |
| `relations`, `complete_relations` | — | See above |
| `url` | Could be relative, the daemon completed it with its option `icingaweb2-url` | **Absolute** or empty. Relative URLs are rejected with `400` and the option is gone |

An event has to set at least one of `incident` and `muted`. `muted` cannot be combined with `incident: false`, and
`muted: true` not with `close`. The state of the event, i.e. open, update or close an incident, is expressed by
`incident`, `close` and `severity`, not by `type`.

The `id` is what makes events and the history of the daemon correlate:

* It is the `event_id` in the [notification history](#history-api), and what the `$uuid` of a role change through
  [`Incident`](32-Integrations-Incidents.md#correlating-role-changes-with-source-events) refers to.
* If you resubmit an event, e.g. after a timeout, keep its `id`. The daemon ignores an event with an `id` that it has
  already processed or queued. A new event gets a new `id`, even if it looks the same.
* If the `id` is missing, the daemon derives it from a hash of the event as it was submitted. An identical event without
  an `id` is therefore treated as the same event.

**Acknowledgements and managers.** In v0.2.0 the daemon made the author of an acknowledgement the manager of the
incident when it received an `acknowledgement-set` event. This special handling is gone. Events no longer change who
manages an incident. A role change is requested with [`Incident`](32-Integrations-Incidents.md), by the user who
caused it, and processed by the daemon like an event. If the source also submits an event for the acknowledgement,
give both the same `id`.

**`/v1/incidents`.** The new endpoint can also modify incidents of the source, and its responses are streamed:

| | v0.2.0 | v1.0 |
|-|--------|------|
| Request | `GET /incidents` | `GET` or `POST /v1/incidents?filter=…`. The filter is a JSON object, or an array of objects, matched against the object's ID tags. `null` as a value requires the tag to be absent |
| `POST` | — | Closes the incident without notifying (`close`), or updates its message (`message`) |
| Response | One JSON array | NDJSON. Each line is `{"status": "success"\|"error", "result": {…}}` |
| Per incident | `incident` and `severity` and `object_tags`, with severities like `crit` and `err` | `is_muted`, `severity` and `object_tags`, with the severities above. There is no incident number |

<!-- The v0.2.0 documentation of GET /incidents names the identifier "incident_id", the code of v0.2.0 returns it as
"incident". The table follows the code. -->

#### An Event, Migrated

A v0.2.0 event for a service problem:

```
curl -u 'source-2:insecureinsecure' -d '@-' 'http://localhost:5680/process-event' <<EOF
{
  "name": "dummy-809: random fortune",
  "url": "http://localhost/icingaweb2/icingadb/service?name=random%20fortune&host.name=dummy-809",
  "tags": {"host": "dummy-809", "service": "random fortune"},
  "extra_tags": {"hostgroup/app-container": null, "servicegroup/app-mail": null},
  "type": "state",
  "severity": "crit",
  "username": "",
  "message": "Something went somewhere very wrong.",
  "rules_version": "23",
  "rule_ids": ["0"]
}
EOF
```

The same event for v1.0. This is **simplified**, a real source sends everything its rules may refer to:

```
curl -u 'source-2:insecureinsecure' -H 'X-Icinga-Reject-If-Relations-Incomplete: true' -d '@-' \
  'http://localhost:5680/v1/process-event' <<EOF
{
  "id": "9c2f6d9e-4b53-5f5e-a1a4-3f0d1c7b9d11",
  "name": "dummy-809: random fortune",
  "url": "http://localhost/icingaweb2/icingadb/service?name=random%20fortune&host.name=dummy-809",
  "tags": {"host": "dummy-809", "service": "random fortune"},
  "incident": true,
  "severity": "critical",
  "summary": "Something went somewhere very wrong.",
  "message": "Something went somewhere very wrong.",
  "relations": {
    "host": {"name": "dummy-809"},
    "services": [{"name": "random fortune"}],
    "hostgroups": [{"name": "app-container"}],
    "servicegroups": [{"name": "app-mail"}]
  },
  "complete_relations": ["host", "services", "hostgroups", "servicegroups"]
}
EOF
```

What was a `hostgroup/…` entry of `extra_tags` is an element of `relations.hostgroups` now, and the paths that your
[`getJsonPaths()`](31-Integrations-Sources.md#getjsonpaths) returns have to point there.

### History API

`/v1/notification-history` is new. A source can ask the daemon which notifications it **sent** for its objects, and
show them in its own views. Icinga DB Web, for example, shows them in the event history and in the detail of an event.

* `GET /v1/notification-history` requires the query parameters `filter`, which works like the one of `/v1/incidents`,
  and `since`, a timestamp in milliseconds. Only entries triggered at or after `since` are returned.
* The response is NDJSON, in the same format as `/v1/incidents`. Only notifications that were successfully sent are
  included, not failed attempts.
* Each entry carries the `event_id` of the event that triggered the notification. This is how a source links it to
  its own history: the `id` that the source gave the event, or the one derived by the daemon. A source that wants to
  show notifications next to its events has to submit its events with stable `id`s.
* The entries have the contact, the group or schedule it was resolved from, the channel, the message and summary of
  the event, and whether the incident was closed then. See the
  [reference][daemon-history] for the fields. For Go sources, `source.Client` has `GetNotificationHistory()` and
  `YieldNotificationHistory()`, and the entries are `source.NotificationHistory` (`history_entry.go` in IGL).

There is no wrapper for this endpoint in the classes of Icinga Notifications Web. A PHP integration has to call the HTTP
API itself, with the credentials of its source.

### High Availability

v0.2.0 had no support for several daemons, the page about distributed setups consisted of "To be done". Since v1.0
multiple daemon instances can share one database, see [Distributed Setups][daemon-ha]. For a source, this means:

* There is no leader. A daemon that receives an event only writes it to a queue in the shared database. Workers on
  any instance claim and process the entries. So `204` means **queued**, not processed. The incident and the
  notifications appear shortly afterwards. Do not wait for their effects in the same request.
* A source may submit to any instance, e.g. through a load balancer. The instances need synchronized clocks (NTP), as
  the time of the submission matters.
* Resubmitting an event after a failure or a timeout is safe, **if it keeps its `id`**. Without one, the daemon derives
  it from the content, which is a bad base to tell two similar events apart. A new event always needs a new `id`.
* Role changes requested with [`Incident`](32-Integrations-Incidents.md#role-changes-are-asynchronous) use the same
  queue. Whichever instance claims one applies it to the object's incident that is open at that time.
* On the side of the source, e.g. two Icinga DB instances in a high availability setup, objects are identified by their
  ID tags only, and sources are linked to objects through the events they submit. Rules are bound to a source **type**
  (see step 6). Several sources of the same type, e.g. one per node, share objects, incidents and rules. Each node needs
  its own source with its own listener username (or OS user), unless the nodes share that username.
  [`Source::get($username)`](31-Integrations-Sources.md#managing-the-source) is keyed by it.
* What a source does if the daemon is unreachable is up to the source. Icinga DB, for example, retries and, in a high
  availability setup, gives up its responsibility as the active instance after about five minutes of an unhealthy
  Icinga Notifications, so that another Icinga DB instance can take over. This is an example, not a requirement.

[daemon-api]: https://icinga.com/docs/icinga-notifications/latest/doc/20-HTTP-API/
[daemon-config]: https://icinga.com/docs/icinga-notifications/latest/doc/03-Configuration/#http-api-configuration
[daemon-incomplete]: https://icinga.com/docs/icinga-notifications/latest/doc/20-HTTP-API/#incomplete-relations
[daemon-rules]: https://icinga.com/docs/icinga-notifications/latest/doc/20-HTTP-API/#rule-evaluation
[daemon-history]: https://icinga.com/docs/icinga-notifications/latest/doc/20-HTTP-API/#notification-history
[daemon-ha]: https://icinga.com/docs/icinga-notifications/latest/doc/05-Distributed-Setups/
[daemon-upgrading]: https://icinga.com/docs/icinga-notifications/latest/doc/04-Upgrading/#upgrading-to-icinga-notifications-v10
