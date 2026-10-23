# Integrating Incidents

An integration can show the incidents of its objects in its own views and let users change their relation to them,
without sending them to Icinga Notifications Web. Icinga DB Web, for example, lists the subscribers and recipients in
the detail of a host or service, and offers to subscribe to or unsubscribe from the incident of an object next to its
other quick actions.

This page covers `Icinga\Module\Notifications\Integrations\V1\Incidents` and `Incident`, and the exception
`Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException`. See the [overview](30-Integrations.md)
for prerequisites and the public API boundary.

## Concepts

**Object and ID tags.** An incident belongs to an **object**, and an object is identified by the set of **ID tags**
(`tags`) of the events the source submitted for it, e.g. `{"host": "web-01", "service": "ssh", "environment": "…"}`.
You always look incidents up by ID tags, never by an ID of Icinga Notifications Web. This has two consequences:

* The tags you use to look up an incident must be **exactly** those the source submits, every tag and every value.
  If you leave a tag out or use a different spelling, you do not find an incident, and nothing tells you why.
  See [Consistency](30-Integrations.md#consistency-between-hooks-and-events).
* Only **open** incidents are found. A closed incident is not visible through these classes.

**Roles.** A user (via the contact with the same username) has at most one of these roles for an incident:

| Role         | Meaning                                                                                                  |
|--------------|----------------------------------------------------------------------------------------------------------|
| `recipient`  | The contact is a configured recipient, i.e. an escalation of an event rule notifies the contact directly |
| `subscriber` | The contact has subscribed to the incident and receives its notifications                                |
| `manager`    | The contact has taken the incident over, e.g. by acknowledging the problem. There is at most one manager |
| `null`       | The contact has no role for the incident, or there is no contact for the user                            |

The manager is a special subscriber. In Icinga DB Web, acknowledging a problem makes the user the manager of its
incident, removing the acknowledgement removes the manager.

Roles of contacts that are reached only through a contact group or a schedule are not reflected in `getRole()`. See
[`getRecipients()`](#getrecipients) for these.

### Who Can Subscribe and Manage

A user can only have a role if a **contact with the same username** exists in Icinga Notifications. Check it before you
offer an action, and explain the reason if you cannot:

```php
use Icinga\Module\Notifications\Integrations\V1\Incidents;

Incidents::canSubscribe($user);  // bool, whether the user can subscribe to incidents
Incidents::canManage($user);     // bool, whether the user can manage incidents
```

Both take an `Icinga\User` and are `false` if no such contact exists. Currently, both check the same condition, but
check the one that matches the action you offer. Icinga DB Web disables the Subscribe and Acknowledge actions with a
tooltip that says that no Icinga Notifications contact is configured for the account.

!!! warning

    These classes do not check any permissions or restrictions of Icinga Web. It is up to you to decide **whether** a
    user may see an incident of an object and whether they may change their role. Check that against your own
    permissions and the user's restrictions *before* you call them. Do not look up incidents for objects the user is
    not allowed to see. The names of subscribers and recipients are also personal data.

## Finding Incidents

There are three factories on `Incidents`. Choose by what you know about the objects.

| Factory                         | You pass                                | Returns                          | Typical use                      |
|---------------------------------|-----------------------------------------|----------------------------------|----------------------------------|
| `Incidents::get($tags)`         | The **full** ID tags of one object      | One `Incident`, lazy             | Detail view of an object         |
| `Incidents::getAll($tagSets)`   | The **full** ID tags of many objects    | `Incidents`, iterable, countable | Multi-select actions, lists      |
| `Incidents::matchAll($tagSets)` | **Partial** tags, `null` means "absent" | `Incidents`, iterable, countable | Everything below a host, filters |

All three read through the module's database, only return open incidents and throw `InvalidArgumentException` if
`$tags`/`$tagSets` is empty or contains an empty set.

### get()

```php
public static function get(array $tags): Incident
```

Returns a handle to the open incident of the object with exactly the given ID tags. It is **lazy**, no query is
made until you use it. If there is no open incident, the first call on the instance throws an
`IncidentNotFoundException`. Treat that as the normal "no incident" case, not as an error:

```php
use Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException;
use Icinga\Module\Notifications\Integrations\V1\Incidents;

$incident = Incidents::get(['host' => 'web-01', 'service' => 'ssh', 'environment' => $environment]);

try {
    $role = $incident->getRole($user);
} catch (IncidentNotFoundException) {
    // The object has no open incident, show nothing
}
```

!!! note

    After the first `IncidentNotFoundException`, do not use the instance again. Further calls throw a
    `LogicException` instead, as the instance has used up its query. Get a new one with `Incidents::get()`, or handle
    the exception once at the first call as above.

The incident is loaded once, by the first call. `isMuted()` and `getObjectIdTags()` keep returning what it had then
for the lifetime of the instance, the other methods query the current state on each call. Instances are cheap, get a
new one per request.

### getAll()

```php
public static function getAll(iterable $tagSets): static
```

Returns the open incidents of many objects, each given with its full ID tags, e.g. all selected hosts and services of
a list. Objects without an incident are simply absent from the result.

`Incidents` is iterable and countable. It yields an `Incident` for each match, which never throws an
`IncidentNotFoundException`. The query runs on first use, and the result is cached, so you can iterate it more than once
and count it afterwards.

```php
$incidents = Incidents::getAll(array_map(
    fn (ExampleObject $object) => $object->getIdTags(),
    $selectedObjects
));

foreach ($incidents as $incident) {
    $incident->addSubscriber($user->getUsername(), []);
}
```

Methods of the yielded `Incident` may query the database each time. For a lot of objects, do not call several of them
per incident in a loop unless you have to.

### matchAll()

```php
public static function matchAll(iterable $tagSets): static
```

Use this if you do not have the full tags, e.g. to find the incident of a host *and* of all of its services.
An absent tag matches any value. A tag set to `null` requires the tag to be absent. It returns the same as
`getAll()`, but you may get multiple incidents for one entry.

```php
// host icinga2 and all of its services
Incidents::matchAll([['host' => 'icinga2']]);

// only the ssh service on icinga2
Incidents::matchAll([['host' => 'icinga2', 'service' => 'ssh']]);

// only the host icinga2, none of its services
Incidents::matchAll([['host' => 'icinga2', 'service' => null]]);

// the hosts a and b and all of their services
Incidents::matchAll([['host' => 'a'], ['host' => 'b']]);
```

Prefer `getAll()` whenever you have the full tags. `matchAll()` is less precise: in the example above, an object of an
unrelated source that only happens to have a `host` tag with the same value matches as well. Include a tag that
identifies your source, such as Icinga DB's `environment`.

## Reading the State of an Incident

All methods below throw an `IncidentNotFoundException` if the `Incident` was created by `Incidents::get()` and there is
no open incident. They reflect the state **at the time of the call**, see
[asynchronous role changes](#role-changes-are-asynchronous).

### getRole()

```php
public function getRole(\Icinga\User $user): ?string
```

The role of the user's contact: `'manager'`, `'subscriber'`, `'recipient'` or `null`, if the user has none or there is
no contact for the username. Only the contact itself counts, not the groups or schedules it is part of.

### getObjectIdTags()

```php
public function getObjectIdTags(): array
```

The full ID tags of the incident's object, `array<string, string>`. This is how you map the incidents you got from
`getAll()` or `matchAll()` back to your objects.

### isMuted()

```php
public function isMuted(): bool
```

Whether the incident is muted. A muted incident does not notify its recipients.

### hasManager()

```php
public function hasManager(): bool
```

Whether anybody manages the incident. Use it to decide whether to offer to take over the incident.

### getSubscribers()

```php
public function getSubscribers(): array
```

The managers and subscribers of the incident, as a list of

```php
[
    'name'          => 'Jane Doe',          // string, the full name of the contact
    'username'      => 'jdoe',              // ?string, the username of the contact
    'role'          => 'manager',           // 'manager' | 'subscriber'
    'roleChangedAt' => new DateTime(…)      // DateTime, when the contact got the role
]
```

### getRecipients()

```php
public function getRecipients(): array
```

The recipients that the event rules configured for the incident, as a list of

```php
[
    'type'          => 'contact',           // 'contact' | 'contactgroup' | 'schedule'
    'name'          => 'Jane Doe',          // string, the full name of a contact, else the name of the group or schedule
    'username'      => 'jdoe',              // ?string, the username of a contact, `null` for groups and schedules
    'roleChangedAt' => new DateTime(…)      // DateTime, when the entry was last changed
]
```

A recipient that is a contact group or a schedule is listed as such, not resolved to the contacts that are currently
notified.

## Changing Roles

```php
public function addManager(string $username, array $eventTypes, ?UuidInterface $uuid = null): static
public function addSubscriber(string $username, array $eventTypes, ?UuidInterface $uuid = null): static
public function removeManager(?UuidInterface $uuid = null): static
public function removeSubscriber(string $username, ?UuidInterface $uuid = null): static
```

Each method *requests* a role change and returns the incident. They throw an `IncidentNotFoundException` if there
is no open incident, and an `InvalidArgumentException` if there is no contact with the given username
(`addManager()`, `addSubscriber()` and `removeSubscriber()`). Database failures are thrown as they are.

| Method                                         | Requests                                                          | Nothing is requested if                                                      |
|------------------------------------------------|-------------------------------------------------------------------|------------------------------------------------------------------------------|
| `addManager($username, [])`                    | Make the contact the manager, only subscribed to incident updates | The incident already **has a manager**, whoever it is, including the contact |
| `addSubscriber($username, ['downtime_start'])` | Make the contact a subscriber, also subscribed to downtime starts | The contact already is a subscriber or the manager                           |
| `removeManager()`                              | Demote the **current manager**, whoever it is, to subscriber      | The incident has no manager                                                  |
| `removeSubscriber($username)`                  | Remove the subscription of the contact                            | The contact is not a subscriber, e.g. the manager or a recipient             |

Notes on the semantics:

* There is **one manager per incident**. Offer `addManager()` only if `hasManager()` is `false`. If somebody else is the
  manager, there is nothing to take over, the request is silently skipped.
* `removeManager()` has no username. It demotes whoever the manager is. If you want to demote only a specific user,
  check `getRole($user) === 'manager'` before.
* Role changes requested through this API have to pass event types. The daemon only notifies subscribers and managers
  about events with `incident: false` for the event types they chose, so contacts that got their role this way only
  receive such notifications they want. They still receive the notifications of the incident itself in any case.
* The change is for the contact with the given `$username`, which is usually the current user's. You may pass other
  usernames, but the pending state described below is only tracked for the session that requested the change.

### Role Changes Are Asynchronous

A role change is not applied when the method returns. It is **queued** for the daemon, which applies it as soon as
it processes its queue, usually within a few seconds. Until then, the state methods above do not reflect the change.
Do not render the new state directly after a request. Redirect, and use the [pending state](#pending-state) to show
that something is going on.

What the daemon does with a request:

* It applies the change to the object's incident that is **open when the daemon processes it**. If the incident
  recovered in the meantime, the change has no effect. If the object has a newer open incident by then, the change
  applies to that one.
* If the daemon decides that the request cannot be fulfilled, e.g. the incident has a manager already, the request is
  marked as failed. The user is told that their request could not be processed.
* If the daemon does not process the request within 30 seconds, e.g. because it is not running, the user is told
  that the daemon might not be running. Nothing cancels the request, so the daemon may still apply it later.

### Correlating Role Changes with Source Events

All four methods take an optional `Ramsey\Uuid\UuidInterface $uuid`. By default a new one is generated. Pass one if
the role change represents an **event of your source**, so that the change can be correlated with the source's history.

The typical case is an acknowledgement. When a user acknowledges a problem in Icinga DB Web, Icinga DB Web makes them
the manager of the incident (`addManager()`), and Icinga DB submits an event for the acknowledgement it sees in
Icinga 2. Both stand for the same occurrence and should have the same ID. Icinga DB Web derives the ID of the
acknowledgement event from the same information that Icinga DB uses, and passes it as `$uuid`.

* The UUID is used as the ID of the event the daemon creates for the role change, which appears in its history. Events
  and role changes share one queue, and the daemon ignores an ID it already processed, to protect against events that
  are delivered twice.
* A role change with a `$uuid` that was used before is **ignored**, it is assumed to be the same event delivered again.
  The daemon keeps processed entries for a few minutes at least, so do not count on a reused UUID to work later.
  **Never reuse a UUID for a different role change.** Derive it deterministically from the occurrence, e.g. from the
  time of the acknowledgement, or leave it out.
* If you do not have an event to correlate, pass nothing.

## Pending State

As the changes are asynchronous, the user needs feedback. For the *current user's session*, the `Incident` remembers
the role changes it requested, and tells you whether one is still pending.

```php
public function getPendingAction(): ?string
public function getPendingJobAttributes(): array
```

* `getPendingAction()` returns `'manage'`, `'unmanage'`, `'subscribe'` or `'unsubscribe'`, or `null` if no request is
  pending. It is `null` as well if somebody else queued a change, or if the user's session does not know about it, e.g.
  another browser. It is also `null` once the daemon finished the request, or after 30 seconds without a result.
  There is one tracked request per incident. A new request replaces the one before. Do not accept a new request while
  one is pending, as Icinga Notifications Web's own quick actions do.
* `getPendingJobAttributes()` returns HTML attributes for the **element of the quick action**, e.g. its button, while
  the request is pending: `disabled`, a `title` ("Your request is being processed") and a `data-notifications-job`
  attribute with the URL to poll the state of the request. It returns an empty array if nothing is pending.

Apply the attributes to the element, and Icinga Notifications Web does the rest. Its JavaScript finds elements with
`data-notifications-job` in the columns of Icinga Web after they are rendered, polls the state once per second and, once
the daemon is done, **reloads the closest container** that holds the element. On errors, or if the daemon did not react
in time, it shows a notification. The element has to be inside one of the columns of Icinga Web, which is then reloaded
as a whole. Your view must render the state correctly again when it is reloaded.

```php
$button = $form->getElement('btn_submit');
if ($incident->getPendingAction() === 'subscribe') {
    $button->getAttributes()
        ->add('class', 'active')
        ->set($incident->getPendingJobAttributes());
}
```

This also works for users who may not access Icinga Notifications Web at all, i.e. who lack the permission
`module/notifications`: the endpoint is available to every logged in user, and the JavaScript is loaded for every user
while the module is enabled. The state is only reported for requests of the user's own session.

## Example

This is a **simplified** end-to-end example, modeled after Icinga DB Web. It has an object with the ID tags
`host` and `service`, shows a subscribe or unsubscribe action in the detail view of the object and handles it.
Permissions of the integration itself are omitted, add them. It uses only the public API.

The one place that knows how ID tags are built. They have to match the tags of the events the source submits:

```php
namespace Icinga\Module\Example\Notifications;

use Icinga\Module\Example\Model\ExampleObject;
use Icinga\Module\Notifications\Integrations\V1\Incident;
use Icinga\Module\Notifications\Integrations\V1\Incidents;
use ipl\Stdlib\Seq;

class IncidentFinder
{
    public static function forObject(ExampleObject $object): Incident
    {
        return Incidents::get(self::tagsOf($object));
    }

    /**
     * @param iterable<ExampleObject> $objects
     */
    public static function forObjects(iterable $objects): Incidents
    {
        return Incidents::getAll(Seq::map($objects, fn (ExampleObject $object) => self::tagsOf($object)));
    }

    private static function tagsOf(ExampleObject $object): array
    {
        return isset($object->service)
            ? ['host' => $object->host, 'service' => $object->service]
            : ['host' => $object->host];
    }
}
```

A form for the action, which can render itself as pending:

```php
namespace Icinga\Module\Example\Notifications;

use Icinga\Web\Session;
use ipl\Html\Form;
use ipl\Web\Common\CsrfCounterMeasure;

class SubscriptionForm extends Form
{
    use CsrfCounterMeasure;

    protected $defaultAttributes = ['class' => 'inline'];

    protected ?array $pendingJobAttributes = null;

    public function __construct(private readonly bool $subscribe)
    {
    }

    /**
     * @param array $attributes The result of Incident::getPendingJobAttributes()
     */
    public function setPendingJob(array $attributes): static
    {
        $this->pendingJobAttributes = $attributes;

        return $this;
    }

    protected function assemble(): void
    {
        $this->addCsrfCounterMeasure(Session::getSession()->getId());
        $this->addElement('submitButton', 'btn_submit', [
            'label' => $this->subscribe ? 'Subscribe' : 'Unsubscribe'
        ]);

        if ($this->pendingJobAttributes !== null) {
            // Disables the button and lets quick-action.js reload the container once the request is processed
            $this->getElement('btn_submit')->getAttributes()
                ->add('class', 'active')
                ->set($this->pendingJobAttributes);
        }
    }
}
```

The detail view decides which action to offer. It treats the absence of an incident as the normal case:

```php
use Icinga\Application\Icinga;
use Icinga\Module\Example\Notifications\IncidentFinder;
use Icinga\Module\Example\Notifications\SubscriptionForm;
use Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException;
use Icinga\Module\Notifications\Integrations\V1\Incidents;

// In the code that assembles the detail of an $object for a $user

if (Icinga::app()->getModuleManager()->hasEnabled('notifications') && Incidents::canSubscribe($user)) {
    $incident = IncidentFinder::forObject($object);

    try {
        // The first call that needs the incident throws if there is none
        $role = $incident->getRole($user);

        if ($role !== 'manager') { // Managers are subscribed implicitly
            $action = $role === 'subscriber' ? 'unsubscribe' : 'subscribe';

            $form = (new SubscriptionForm($action === 'subscribe'))
                ->setAction($object->getActionUrl($action));
            if ($incident->getPendingAction() === $action) {
                $form->setPendingJob($incident->getPendingJobAttributes());
            }

            $detail->addHtml($form);
        }
    } catch (IncidentNotFoundException) {
        // The object has no open incident, there is nothing to subscribe to
    }
}
```

If `canSubscribe()` is `false`, render the action disabled with a title that explains why instead of hiding it.

The controller action handles the submission. After it, it redirects instead of rendering any state, as that
cannot have changed yet:

```php
use Icinga\Application\Logger;
use Icinga\Module\Example\Notifications\IncidentFinder;
use Icinga\Module\Example\Notifications\SubscriptionForm;
use Icinga\Module\Notifications\Integrations\Exception\IncidentNotFoundException;
use Icinga\Web\Controller;
use Icinga\Web\Notification;
use ipl\Html\Form;
use Throwable;

class ObjectController extends Controller
{
    public function subscribeAction(): void
    {
        $object = $this->fetchObject(); // Simplified, the integration's own way to load the object
        $username = $this->Auth()->getUser()->getUsername();

        $form = (new SubscriptionForm(true))
            ->on(Form::ON_SUBMIT, function () use ($object, $username) {
                try {
                    IncidentFinder::forObject($object)->addSubscriber($username, []);
                    Notification::success($this->translate('Your request has been submitted'));
                } catch (IncidentNotFoundException) {
                    // The incident was closed in the meantime
                    Notification::warning($this->translate('There is no open incident anymore'));
                } catch (Throwable $e) {
                    Logger::error('Failed to subscribe user "%s": %s', $username, $e);
                    Notification::error($this->translate('Failed to submit your request'));
                }

                $this->redirectNow($object->getDetailUrl());
            })
            ->handleRequest($this->getServerRequest());

        $this->addContent($form);
    }
}
```

For a multi-select of objects, use `getAll()` and iterate the result, as in the [`getAll()`](#getall) example. Icinga DB
Web does that in its `library/Icingadb/Notifications/` namespace. See `SubscribeIncidents` for subscribing and
`ManagesIncidents` for managing, which also derives the UUIDs of acknowledgements. Its quick actions are in
`library/Icingadb/Widget/Detail/QuickActions.php`.
