# Integrating with Icinga Notifications Web

This chapter is for developers of Icinga Web modules that want to work together with Icinga Notifications, e.g.
Icinga DB Web or Icinga for Kubernetes Web. Such a module is called an **integration** here. It assumes you know how
to build an Icinga Web module (hooks, `run.php`, `ipl-*`), but not how Icinga Notifications Web works internally.

| Page                                                       | Content                                                                  |
|------------------------------------------------------------|--------------------------------------------------------------------------|
| **Overview** (this page)                                   | What an integration can do, architecture, public API, prerequisites      |
| [Sources](31-Integrations-Sources.md)                      | Source hook, filter migration hook, managing the source                  |
| [Incidents](32-Integrations-Incidents.md)                  | Showing and managing incidents in the integration's own views            |
| [Upgrading](33-Integrations-Upgrading.md)                  | Migrating an integration from Icinga Notifications Web v0.2.0 to v1.0    |

If you already have an integration for v0.2.0, start with [Upgrading](33-Integrations-Upgrading.md).

## What an Integration Can Do

Everything an integration does is optional. A source works without any integration, you only gain convenience for
your users by providing one. The parts are independent of each other.

| Capability                                                      | What your users get                                                                                         | Page                                                                |
|-----------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------|---------------------------------------------------------------------|
| Provide a source hook (`Hook\V2\SourceHook`)                    | Column and value suggestions and validation in the event rule filter editor, the label and icon of the source, event types for notification rules and a link to the object in incident details | [Sources](31-Integrations-Sources.md#source-hook)                   |
| Provide a filter migration hook (`Hook\V2\V1FilterMigrationHook`) | A way to convert event rule filters created before v1.0 instead of having to recreate them               | [Sources](31-Integrations-Sources.md#filter-migration-hook)         |
| Manage the source (`Integrations\V1\Source`)                    | A source that is created and kept up to date by your module, e.g. by a single toggle in its configuration   | [Sources](31-Integrations-Sources.md#managing-the-source)           |
| Show and manage incidents (`Integrations\V1\Incidents`, `Incident`) | Subscribing, unsubscribing, acknowledging and listing recipients in your own object views                | [Incidents](32-Integrations-Incidents.md)                           |
| Submit events (a process, not part of the module)               | Incidents and notifications for your objects                                                                | [HTTP API of the daemon][daemon-api], [Consistency](#consistency-between-hooks-and-events) |

A source without a source hook also works. Its users get a plain filter editor without suggestions and validation, and
event types have to be typed in manually. Incident details only link to the object if the event carried an `url`.

## Architecture

![Icinga Notifications Architecture](res/notifications-architecture.png)

There are three parties. An integration module belongs to the web side, and usually has a source process next to it:

* The **source** is a process, e.g. the Icinga DB daemon. It submits events to the Icinga Notifications **daemon**
  over HTTP and is the one that has to be configured with credentials or a Unix socket.
* **Icinga Notifications** evaluates events against the event rules, opens incidents and sends notifications.
* **Icinga Notifications Web** is the configuration interface of it and the only thing your PHP code talks to.
  Both share the same database by which they communicate.

This has two consequences you need to keep in mind:

* What your hooks declare (columns, event types, ID tags) is only evaluated by Icinga Notifications Web, the daemon
  never calls into PHP. The daemon works with whatever the source *submits*. So both sides have to agree, see
  [Consistency](#consistency-between-hooks-and-events).
* Changes the integration requests through `Incident` are **asynchronous**. They are queued for the daemon and applied
  there, see [Incidents](32-Integrations-Incidents.md#role-changes-are-asynchronous).

## Public API and Versioning

!!! warning

    Class names on this page are relative to `Icinga\Module\Notifications` unless they are fully qualified.
    Only the following is public API:

    * `Icinga\Module\Notifications\Hook\V2\*`
    * `Icinga\Module\Notifications\Integrations\V1\*`
    * `Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException`

    Everything else is internal and may change or disappear in any release, even though it is technically reachable
    from your module. This includes the models (`Model\*`), `Common\Database`, `Common\JobTracker`, repositories,
    forms, controllers and the database schema. Do not use them, and do not query the Notifications database
    directly. If you are missing something, ask for it to be added to the public API.

Hooks and integration classes are versioned by their namespace: `Hook\V2`, `Integrations\V1`. A different version
lives in a different namespace and may exist side by side. The classes `Hook\V1\SourceHook` and
`Hook\ObjectsRendererHook` of v0.2.0 still exist but are deprecated and **no longer called**. Implementing them has no
effect. They are only covered in [Upgrading](33-Integrations-Upgrading.md).

<!-- TODO(verify): The compatibility policy within a namespace (e.g. whether new methods may be added to the V2 hook
interfaces) is not documented in the repository. Confirm with the maintainers before stating a guarantee here. -->

## Prerequisites

### Optional Dependency

Icinga Notifications Web is an optional dependency of your module. Never reference its classes unconditionally.
Check at runtime before you use `Integrations\V1\*` classes:

```php
// run.php of the integration

$this->provideHook('Notifications/V2/ExampleSource');
$this->provideHook('Notifications/V2/V1FilterMigration');
```

```php
use Icinga\Application\Icinga;

// In a view of the integration

if (Icinga::app()->getModuleManager()->hasEnabled('notifications')) {
    // Safe to use Icinga\Module\Notifications\Integrations\V1\* here
}
```

Because the hook name carries the version (`V2`), registering the v1.0 hooks is harmless on older versions of
Icinga Notifications Web: they never look them up.

### The Database

Icinga Notifications Web has its own database resource, configured by the administrator in
`Configuration → Modules → notifications → Database`. The integration classes use it implicitly. You neither configure
nor pass a connection.

If the module is enabled but no database has been configured, the first call into an `Integrations\V1\*` class
throws an `Icinga\Exception\ConfigurationError`. If the database is not reachable, you get the usual database
exceptions. Handle these where your view should degrade gracefully, e.g. by hiding the notification parts.

### Permissions

Icinga Web only calls a hook if the current user has the permission `module/<name>` of the module that *provides*
the hook, unless the module registered it as always-run. For your source hook this means: a user of Icinga
Notifications Web who is allowed to edit event rules, but not to use your module, gets no suggestions and validation.
If a rule was created with the hook and the hook is not available for the user, the rule filter cannot be edited, and
the user is told to contact an administrator.

Role changes through `Incident` additionally require a contact in Icinga Notifications with the **same username**
as the Icinga Web user, see [Incidents](32-Integrations-Incidents.md#who-can-subscribe-and-manage). Whether a user is
*allowed* to do it in your module, e.g. by a permission of your own, is up to you. Icinga DB Web has the permissions
`icingadb/notifications/subscribe` and `icingadb/notifications/manage` for this.

## Submitting Events

If your integration belongs to a process that *is* a source, that process has to submit events to the daemon.
That is not part of the module's API, it is a plain HTTP request. The full reference is the daemon's
[HTTP API documentation](https://icinga.com/docs/icinga-notifications/latest/doc/20-HTTP-API/). This is what an
integration needs to know.

* **Endpoint and versioning:** `POST /v1/process-event`. The former unversioned `/process-event` is no longer served.
  A successful submission returns `204`.
* **Authentication:** Depends on how the source reaches the daemon.
  Over TCP it is HTTP Basic Authentication with the username and password of the source, optionally with a TLS client
  certificate instead. Over a Unix socket, no credentials are involved: the daemon identifies the source by the
  operating system user of the connecting process and matches it with the **listener username** of the source.
  See [managing the source](31-Integrations-Sources.md#managing-the-source) for what that means for you.
* **Which rules apply** depends on the event's `incident` field:

  | `incident` | Rules evaluated    | Effect                                                              |
  |------------|--------------------|---------------------------------------------------------------------|
  | `true`     | Escalation rules   | Opens or updates an incident, triggers escalations and notifications |
  | `false`    | Notification rules | Notifies the recipients right away, no incident is opened or changed |
  | omitted    | None               | Only updates an already open incident, e.g. its message             |

* **Relations:** A rule filter is evaluated by the daemon against the event's `relations`, a free-form JSON object.
  Rules reference it via JSONPath expressions. Your source hook is what translates the columns a user picks into
  these JSONPath expressions, see [`getJsonPaths()`](31-Integrations-Sources.md#getjsonpaths).
  Only events with the `incident` field set are checked for missing relations. If the source sets the header
  `X-Icinga-Reject-If-Relations-Incomplete: true`, the daemon responds with `422` whenever relations that the
  rules need are missing, and lists them in the response body. The source can then resubmit the event with them.
  Without the header, such an event is still accepted if it has `incident: true`, if the object already has an open
  incident or if the source has a rule of the evaluated type without an object filter. Relations the source knows to
  be complete, even though they do not contain a referenced value, are declared with `complete_relations`, otherwise
  the daemon keeps asking for them.
* **Human readable names:** The event's `name` (the display name of the object) and `summary` (a single line, e.g. for
  email subjects) are shown to users and sent to channels. The module no longer asks an integration to render object
  names, the source has to send them. This replaces `ObjectsRendererHook`.
* **URLs:** `url` is optional, but if set it must be absolute, e.g.
  `https://example.com/icingaweb2/icingadb/host?name=example`. The daemon cannot resolve a relative URL and rejects
  the event with `400`. It is used for contacts in notifications and as fallback for the link to the object in
  incident details, if your hook cannot create one.
* **Event type:** Events with `incident: false` carry a `type`, e.g. `downtime_start`. Notification rules can restrict
  their entries by type, and your hook offers the list to choose from with
  [`getEventTypes()`](31-Integrations-Sources.md#geteventtypes).
* **Event IDs:** An optional `id` identifies an event. Resubmitting an event keeps its ID, a new event gets a new one.
  Role changes through `Incident` can carry such an ID as well, see
  [Incidents](32-Integrations-Incidents.md#correlating-role-changes-with-source-events).

### Consistency Between Hooks and Events

The hooks and the events are two views on the same thing, but nothing checks that they match. If they do not,
rules silently do not match, or objects cannot be found. Make sure the following holds:

| Declared in the integration                                                         | Must match what the source submits                                        |
|-------------------------------------------------------------------------------------|---------------------------------------------------------------------------|
| Hook name `Notifications/V2/<Type>Source`, `V1FilterMigrationHook::getSourceType()`, `Source::setType()` | The source type of the source, as configured in Icinga Notifications Web |
| ID tags passed to `Incidents::get()`, `getAll()` and received by `createObjectLink()` | The `tags` of the event, **all** of them. The identity of an object is derived from the complete set of tags |
| Keys of `getEventTypes()`                                                           | The `type` of `incident: false` events                                    |
| JSONPaths returned by `getJsonPaths()`                                              | The structure of `relations`                                              |
| Columns accepted by `assertValidCondition()`                                        | The relations the source is able to provide, or `complete_relations`      |
| `Source::get($username)`                                                            | The username (or OS user) the source authenticates as                     |

!!! tip

    Define the ID tags of your objects in exactly one place of your integration and use it for both, the code that
    builds events and the code that looks up incidents. Icinga DB Web, for example, derives the tags `host`,
    `service` and `environment` for both.
