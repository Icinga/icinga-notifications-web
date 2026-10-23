# Integrating Sources

This page covers everything an integration does for a *source*: the source hook that makes the event rule editor aware
of your objects and the class that lets you create and maintain the source in Icinga Notifications Web. See the
[overview](30-Integrations.md) for prerequisites and the public API boundary. The hook that migrates event rule filters
of v0.2.0 is part of [Upgrading](33-Integrations-Upgrading.md#4-provide-a-filter-migration-hook).

## Source Hook

The source hook, `Icinga\Module\Notifications\Hook\V2\SourceHook`, teaches Icinga Notifications Web about one **source
type**. Without it, a source of that type is still fully functional, but treated generically.

| Feature                           | Without a hook                                                                     | With a hook                                                        |
|-----------------------------------|------------------------------------------------------------------------------------|--------------------------------------------------------------------|
| Name of the source type           | The raw type, e.g. `icinga2`                                                       | `getSourceLabel()`                                                 |
| Icon of the source                | A generic icon                                                                     | `getSourceIcon()`                                                  |
| Filter editor of event rules      | Plain editor. A notice asks the user to make sure the columns are valid JSON paths | Suggestions for columns and values, validation, labels for columns |
| Event types of notification rules | Free text, no validation                                                           | A list to choose from, validated against `getEventTypes()`         |
| Object in incident details        | A link only if the event carried an `url`                                          | `createObjectLink()` renders the object, `url` is the fallback     |

### Registering the Hook

A source hook is registered **per source type**. The type is part of the hook's name, not of its interface:

```
Notifications/V2/<Type>Source
```

`<Type>` is derived from the type of the source as stored in Icinga Notifications Web: it is lowercased, converted to
camel case (delimiters are `-`, `_` and space) and its first letter is upper-cased:

| Source type | Hook name                        | Class                                                                |
|-------------|----------------------------------|----------------------------------------------------------------------|
| `icinga2`   | `Notifications/V2/Icinga2Source` | `Icinga\Module\Icingadb\ProvidedHook\Notifications\V2\Icinga2Source` |
| `my-app`    | `Notifications/V2/MyAppSource`   | `Icinga\Module\Example\ProvidedHook\Notifications\V2\MyAppSource`    |

Register it in `run.php` and put the class in the usual place for provided
hooks (`library/<Module>/ProvidedHook/Notifications/V2/<Type>Source.php`):

```php
// run.php

/** @var $this \Icinga\Application\Modules\Module */

$this->provideHook('Notifications/V2/ExampleSource');
```

The class implements `Hook\V2\SourceHook`, nothing else is required. Icinga Notifications Web makes the hook name
valid for Icinga Web by itself. If your module provides several source types, register one hook per type.

!!! note

    The type is matched after lowercasing. Two source types that only differ in case, or in delimiters, share one hook
    name. Choose a type that is stable, as it is also what event rules are bound to. Users can only create event rules
    for a type of which at least one source exists.

Things to be aware of:

* The hook is instantiated lazily, once per request, and the instance is shared by everything in that request. The
  constructor must not throw an exception. If it does, Icinga Web unloads the hook for the rest of the request (and only
  logs it with level debug) and Icinga Notifications Web behaves as if there was none. Defer anything expensive until
  a method needs it.
* Only the first registered hook of a name is used. Do not register the same type from two modules.
* The user's permissions apply, see [Permissions](30-Integrations.md#permissions).
* Icinga Notifications Web only guards some of the methods against exceptions, see the [reference](#reference).
  Exceptions from the others end in the regular Icinga Web error handling of the request.

### A Minimal Source Hook

This is a **simplified** example for a fictitious source `example` with hosts and services. Most of it is static to
keep it short. A real implementation queries its own data for suggestions, see Icinga DB Web's `Icinga2Source` for
a complete one.

```php
namespace Icinga\Module\Example\ProvidedHook\Notifications\V2;

use ArrayIterator;
use Icinga\Module\Notifications\Hook\V2\SourceHook;
use ipl\Html\ValidHtml;
use ipl\I18n\Translation;
use ipl\Stdlib\Filter\Chain;
use ipl\Stdlib\Filter\Condition;
use ipl\Web\Control\SearchBar\SearchException;
use ipl\Web\Url;
use ipl\Web\Widget\Icon;
use ipl\Web\Widget\Link;
use Traversable;

class ExampleSource implements SourceHook
{
    use Translation;

    /** @var array<string, string> Column => label */
    private array $columns;

    /** @var array<string, string> Column => path in the `relations` of an event */
    private array $paths = [
        'host.name'    => 'host.name',
        'service.name' => 'services[*].name'
    ];

    public function __construct()
    {
        $this->columns = [
            'host.name'    => $this->translate('Host Name'),
            'service.name' => $this->translate('Service Name')
        ];
    }

    public function getSourceLabel(): string
    {
        return 'Example';
    }

    public function getSourceIcon(): Icon
    {
        return new Icon('cube');
    }

    public function assertValidCondition(Condition $condition): void
    {
        if (! isset($this->columns[$condition->getColumn()])) {
            throw new SearchException($this->translate('Is not a valid column'));
        }
    }

    public function enrichCondition(Condition $condition): void
    {
        $column = $condition->getColumn();
        if (isset($this->columns[$column])) {
            $condition->metaData()->set('columnLabel', $this->columns[$column]);
        }
    }

    public function getColumnSuggestions(string $searchTerm): Traversable
    {
        foreach ($this->columns as $column => $label) {
            if (fnmatch("*$searchTerm*", $label, FNM_CASEFOLD)) {
                yield ['search' => $column, 'label' => $label, 'group' => $this->translate('Columns')];
            }
        }
    }

    public function getValueSuggestions(string $column, string $searchTerm, Chain $searchFilter): Traversable
    {
        // Simplified, a real implementation would query its data store for the values of the column
        return new ArrayIterator([]);
    }

    public function getJsonPaths(string ...$columns): array
    {
        $paths = [];
        foreach ($columns as $column) {
            $paths[$column] = [$this->paths[$column]];
        }

        return $paths;
    }

    public function getEventTypes(): array
    {
        return [
            'downtime_start' => $this->translate('Downtime Start'),
            'downtime_end'   => $this->translate('Downtime End')
        ];
    }

    public function createObjectLink(array $idTags): ?ValidHtml
    {
        if (! isset($idTags['host'])) {
            return null;
        }

        return new Link($idTags['host'], Url::fromPath('example/host', ['name' => $idTags['host']]));
    }
}
```

### Method Reference

All methods are called by Icinga Notifications Web in the context of a web request of a user. The signatures are
those of `Hook\V2\SourceHook`.

#### getSourceLabel()

```php
public function getSourceLabel(): string
```

The human readable name of the source type, e.g. `Icinga 2`.

It is shown in the type selection of event rules and in the tooltips of source badges
(`<name of the source> (<label>)`) in incident lists and details. If it throws, the exception is logged and the raw
source type is shown instead. The result is cached for the rest of the request.

#### getSourceIcon()

```php
public function getSourceIcon(): \ipl\Web\Widget\Icon
```

The icon that represents the source type. It is shown in the incident list and detail, next to the sources of an
incident, and in the list of sources. If it throws, the exception is logged and a generic icon is used. The result is
cached for the rest of the request. Use `ipl\Web\Widget\IcingaIcon` for icons from the Icinga icon font.

#### assertValidCondition()

```php
public function assertValidCondition(\ipl\Stdlib\Filter\Condition $condition): void
```

Called for each condition of an event rule filter when the user submits the filter editor, which includes the
migration of a filter. It decides whether the column of the condition exists for your source.

* Return normally if the condition is valid.
* Throw `ipl\Web\Control\SearchBar\SearchException` if it is not. Its message is shown to the user next to the column
  and has to be a translated, user-facing sentence.
* Any other exception is logged and the user sees a generic "Failed to validate column" message.

A placeholder condition that the user did not fill in can reach the hook as well. Reject it like any other unknown
column.

You may also use the method to resolve and remember information that you need for [`getJsonPaths()`](#getjsonpaths).
Icinga Notifications Web calls `getJsonPaths()` on the same hook instance in the same request after the validation,
and Icinga DB Web relies on this for custom variables. Do not rely on it across requests.

#### enrichCondition()

```php
public function enrichCondition(\ipl\Stdlib\Filter\Condition $condition): void
```

Called for each condition when a filter is parsed for the editor. Use it to attach metadata. The only metadata the
editor currently understands is `columnLabel`, which replaces the technical column name by a label in the editor:

```php
$condition->metaData()->set('columnLabel', $this->translate('Host Name'));
```

It receives conditions that it may not know, e.g. when the filter has errors. Leave them as they are. Exceptions are
logged and otherwise ignored.

#### getColumnSuggestions()

```php
public function getColumnSuggestions(string $searchTerm): \Traversable
```

Provides the suggestions for the column input of the editor. `$searchTerm` is what the user typed so far, it may
contain `*` as wildcard. The editor does **not** filter the result, your implementation has to.

The return value is a provider for `ipl\Web\FormElement\SearchSuggestions`: a `Traversable` of arrays. For columns,
the following keys are used:

| Key      | Required | Description                                                                          |
|----------|----------|--------------------------------------------------------------------------------------|
| `search` | yes      | The column, as it is passed to `assertValidCondition()` and `getJsonPaths()` later   |
| `label`  | no       | The label shown to the user, the column if omitted                                   |
| `group`  | **yes**  | The heading under which the suggestion is listed                                     |

The `group` is required for columns, Icinga Notifications Web groups the suggestions by it.

#### getValueSuggestions()

```php
public function getValueSuggestions(
    string $column,
    string $searchTerm,
    \ipl\Stdlib\Filter\Chain $searchFilter
): \Traversable
```

Provides the suggestions for the value input of a condition on `$column`. `$searchTerm` is what the user typed so far,
and again your implementation has to filter. `$searchFilter` is the filter the user has built so far, parsed from the
editor. You can use it to narrow down the suggestions, e.g. to the services of the host already selected. Its
conditions may reference columns your source does not know, so be defensive.

The return value is a `SearchSuggestions` provider, the same as for columns. `search` is the value, `label` is
optional. A `group` is not used.

If no column is selected yet, the editor does not call this method.

#### getJsonPaths()

```php
public function getJsonPaths(string ...$columns): array
```

Maps each column used in a filter to one or more JSONPaths. This is the link between what the user selects in the
editor and what the source submits.

Called when the user saves the filter, with each distinct column of the filter, after all conditions were validated.
Return an array keyed by **every** given column. Each value must be a non-empty list of strings. If a column is missing
or its list is empty, saving the filter fails.

`array<string, string[]>`, e.g.

```php
[
    'host.name'    => ['host.name'],
    'service.name' => ['services[*].name'],
    'host.vars.os' => ['host.vars.os']
]
```

Rules for the paths you return:

* A column can map to **several paths**. The daemon tries them in the given order and uses the first one that selects
  something. This is useful if the same logical column can be found at more than one place, e.g. a custom variable
  that is a dictionary in some objects and an array in others. Icinga DB Web returns several paths for custom
  variables with nested values for the same reason.
* Paths that are not valid JSONPath are dropped by the daemon. If none of a condition's paths is valid, the daemon does
  not load the rule at all and it never matches. Test your paths.
* The paths must point to places that the source actually fills in `relations`. If the path is not in the event, the
  condition cannot match, and the daemon asks the source for the missing relation, see
  [Consistency](30-Integrations.md#consistency-between-hooks-and-events).
* The returned paths are stored, they are not recomputed. If the structure of your `relations` changes later, existing
  rules keep the old paths.

!!! note

    Without a source hook, Icinga Notifications Web uses the column as it is as the only path. Users then have to write
    JSONPaths as columns.

#### getEventTypes()

```php
public function getEventTypes(): array
```

Returns the event types the source emits **outside of an incident**, i.e. with `incident: false`, as an array of
labels keyed by the event type, e.g. `['downtime_start' => 'Downtime Start']`. The keys must be what the source
submits as the event's `type`.

The types are offered when a user configures the entries of a *notification rule*. They are validated against the list.
If a rule contains a type the hook does not return (anymore), the rule shows a warning that the type is invalid.
Return an empty array if the source does not emit such events. Without a hook, users have to type the types.

#### createObjectLink()

```php
public function createObjectLink(array $idTags): ?\ipl\Html\ValidHtml
```

Creates a representation of the object the incident belongs to. `$idTags` are the **complete ID tags** of the object,
i.e. the `tags` the source submits for it, as `array<string, string>`.

Return `null` if the object cannot be found, e.g. because it was deleted or the user is not allowed to see it. Icinga
Notifications Web then falls back to a link to the `url` of the event, if the event had one.

The result is rendered in the incident detail under *Related Object*, in an element that carries the CSS classes of
your module (`icinga-module module-<name>`), so your module's styles apply. Links inside open in the next column.

An incident can have **more than one source**, as events for the same object may arrive from several sources, e.g. two
Icinga DB instances in a high availability setup, or from sources of different types. Icinga Notifications Web goes
through the sources of the incident's object and uses the first source type for which a hook exists. If that hook
returns `null`, the other sources are not tried, and the results are not combined. Therefore the hook has to be able to
resolve the object independent of which of its sources reported it. This is one reason why ID tags should identify the
object and not the source. The same applies to event rules, which are bound to a source type and not to an individual
source.

## Managing the Source

An integration can create, update and delete the source in Icinga Notifications Web, so that users do not have to
copy credentials between the two. Icinga DB Web, for example, creates the source for its Icinga DB instance when the
user enables notifications in its configuration.

The class `Icinga\Module\Notifications\Integrations\V1\Source` is the interface to that. It is a handle to the source
with a given **listener username**. Changes are in memory until you call `save()`.

```php
use Icinga\Module\Notifications\Integrations\V1\Source;

$source = Source::get('icingadb');   // the source with the listener username "icingadb", or a new one

if ($source->getName() === null) {   // the source does not exist yet
    $source->setName('Icinga DB');
}

$source->setType('icinga2')
    ->setPassword($password)         // omit for sources that connect via a Unix socket
    ->save();
```

### Methods

| Method                                | Description                                                                              |
|---------------------------------------|------------------------------------------------------------------------------------------|
| `Source::get(string $username): self` | Load the source with the given listener username. If there is none, prepare a new one    |
| `getName(): ?string`                  | The name of the source, `null` if the source does not exist yet and no name was set      |
| `setName(string $name): self`         | Set the name, shown to users                                                             |
| `setType(string $type): self`         | Set the type. Rules are bound to it, and it selects the hook, see below                  |
| `setPassword(string $password): self` | Set the password for the listener, the daemon verifies it with HTTP Basic Authentication |
| `save(): void`                        | Create or update the source and lock it                                                  |
| `delete(): void`                      | Delete the source. Does nothing for a source that does not exist                         |

The constructor of the class is not meant to be called by integrations, use `Source::get()`.

`get()` identifies the source by its **listener username** only, you cannot look it up by its name or type. The username
is the login of the source if it uses HTTP Basic Authentication, and the **operating system user** of the source's
process if it connects through the daemon's Unix socket. The daemon allows a process on the socket to submit events
for sources whose listener username equals the process' OS username, no password is involved. So a source that is
managed for the socket needs no password: call `setPassword()` only if the source connects via TCP. For a new source
without one, no password is stored. Usernames are unique, there is at most one source per listener username.

### Locked Sources

`save()` **always locks** the source. A locked source is shown as read-only in Icinga Notifications Web, as it is
maintained by an integration. Users cannot change its name, type or credentials there. They can still delete it,
after a warning that this may cause a malfunction unless the integration is disabled first. You have to handle that
case: after a deletion, `Source::get()` finds nothing, and `getName()` returns `null`. Icinga DB Web uses this to detect
that its source is missing. It shows a notice and treats notifications as disabled, so that enabling them creates the
source again.

Be aware of what `save()` writes:

* It always writes name, type, username and the locked flag. If the source already existed and was created by hand,
  it is taken over, and a TLS client certificate subject stored with it is removed. Users cannot undo this.
* The plain text password is kept in memory only until `save()` stored its hash. You can call `setPassword()` once,
  and it is gone afterwards. If you do not call `setPassword()` for an existing source, its password does not change.
* Changing the type of an existing source moves it to other event rules, as they are bound to the type.

### Transactions and Errors

`save()` and `delete()` each run in **their own transaction** on the Notifications database. If you need to change
other data atomically with the source, you cannot combine them in one transaction. Do your own work first and call
`save()` last, as Icinga DB Web does after it stored its own configuration. A failure throws, there is no return value
to check:

* `save()` throws `RuntimeException` if the name or type is missing. A new source needs both.
* `save()` and `delete()` throw `InvalidArgumentException` if the source has been deleted by somebody else after
  `Source::get()` loaded it.
* Database failures are thrown as the underlying exceptions, the transaction is rolled back.

`delete()` also deletes the event rules of the source's type if this was **the last source of its type**, and
removes the username from the source so it can be reused. If other sources of the type remain, the rules stay.

### Choosing the Type

The type you pass to `setType()` is the type that selects your source hook, so it has to match the hook name, see
[Registering the Hook](#registering-the-hook). The same string is what users see in the type selection of rules if
no hook exists. Icinga DB Web, for instance, stores `icinga2` and provides `Notifications/V2/Icinga2Source`.

### Worked Example

Icinga DB Web ties this to a toggle in its general configuration. The pattern is useful beyond that. The following
is a **simplified** extract of its `GeneralConfigForm`, without any of its UI logic:

```php
use Icinga\Module\Notifications\Integrations\V1\Source;

// The service user of the Icinga DB daemon is also the listener username,
// as the daemon connects to Icinga Notifications via its Unix socket.
$sources = [];
foreach ($serviceUsers as $serviceUser) {
    $source = Source::get($serviceUser);
    if ($source->getName() === null) {
        // Only name a new source, keep what an existing one is called
        $source->setName('Icinga DB');
    }

    $source->setType('icinga2');
    $sources[] = $source;
}

// ... store Icinga DB's own configuration in a transaction of its own ...

foreach ($sources as $source) {
    $source->save();
}
```

In that setup the source has no password, as it is identified by the OS user of the Icinga DB daemon. Because there may
be more than one daemon in a high availability setup, each with its own user, multiple sources can be needed. All of
them have the same type, so rules apply to all of them.

On the way back, Icinga DB Web checks `Source::get($serviceUser)->getName() === null` to learn that Icinga Notifications
has no source for it (yet), and tells the user.

!!! note

    Disabling the integration does not delete the source in the Icinga DB Web example. Whether to call `delete()` is
    your decision. Deleting also stops event processing for the source, and its rules are lost with it if it was the
    last of its type. Prefer leaving it alone unless the user explicitly asks to remove it.
