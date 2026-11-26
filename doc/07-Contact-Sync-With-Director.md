# Contact Sync with Icinga Director

Contacts and contact groups can be provisioned automatically from any
[Icinga Director](https://icinga.com/docs/icinga-director/latest/) import source, e.g. from LDAP or Active Directory.
Icinga Notifications Web provides the Director job type `ContactSync (Notifications)` for this.

## How It Works

The job reads the most recent run of an import source and synchronizes its rows with the contacts and contact groups
of Icinga Notifications:

* Contacts are matched by username. A username which is not yet known creates a new contact. An existing contact with
  the same username, e.g. created manually, is adopted and updated.
* The username links the contact to the Icinga Web user of the same name. If users log in to Icinga Web via the same
  LDAP directory, they will be able to see and manage incidents they are notified about without any further steps.
* Only the address types mapped by the job are managed. Other addresses of a contact, added manually, are preserved.
  An empty value removes the address.
* The default channel is only assigned when creating a contact. Users may change it afterwards.
* Contact groups which are referenced by the import source but do not exist yet, are created. Group memberships are
  only managed for groups which are referenced by the import source or have been created by the job. Memberships in
  any other group, assigned manually, are preserved.
* Contacts and contact groups created by the job are _owned_ by it. Their UUID is derived from the name of the import
  source and the username or group name. With `Purge` enabled, owned contacts which vanish from the import source are
  deleted. Adopted contacts and groups are never deleted.
* Rows without a username, with duplicate usernames or without the address required by the contact's default channel
  are skipped and logged as warnings.

All changes of a run are applied in a single transaction.

!!! warning

    Renaming the import source makes the job lose track of the contacts and groups it owns. They are then treated
    as adopted, i.e. updated but no longer purged.

## Requirements

* Icinga Director with its [background daemon](https://icinga.com/docs/icinga-director/latest/doc/75-Background-Daemon/)
  to run jobs
* The `notifications` module must be enabled for the Director daemon, i.e. its database resource must be accessible
  wherever the daemon runs

## Example: Active Directory

### Create the Import Source

In Icinga Director, create an LDAP resource in Icinga Web and an import source in `Automation → Import Source`:

| Option        | Value                                                          |
|---------------|----------------------------------------------------------------|
| Source Type   | `Ldap`                                                         |
| Key column    | `sAMAccountName`                                               |
| Object class  | `user`                                                         |
| LDAP filter   | e.g. `memberOf=CN=Icinga Notifications,OU=Groups,DC=example,DC=com` |
| Properties    | `sAMAccountName,displayName,mail,mobile,memberOf`              |

To use the AD groups as contact groups, reduce the group DNs to their names with a property modifier on `memberOf`:

| Option            | Value                                    |
|-------------------|------------------------------------------|
| Modifier          | `Extract from a Distinguished Name (DN)` |
| Property          | `memberOf`                               |
| DN component      | `The first (leftmost) CN`                |

Further modifiers may be used to filter the groups, e.g. `Filter Array Values` to only keep groups starting with
`Icinga-`. Run the import once and check the preview.

### Create the Job

In `Automation → Jobs`, add a job of type `ContactSync (Notifications)`:

| Option            | Description                                                                              |
|-------------------|------------------------------------------------------------------------------------------|
| Import source     | The import source created above.                                                         |
| Run import        | Whether to run the import before synchronizing. Otherwise use a separate `Import` job.   |
| Username column   | Column with the username, e.g. `sAMAccountName`.                                          |
| Full name column  | Column with the full name, e.g. `displayName`. The username is used if empty.            |
| Email column      | Column with the email address, e.g. `mail`. Leave empty to not manage email addresses.   |
| Other addresses   | Further `type=column` pairs, e.g. `sms=mobile`. The types must match available channels. |
| Groups column     | Column with the contact group names, e.g. `memberOf`. Leave empty to not manage groups. |
| Default channel   | The channel assigned to new contacts. New contacts need an address of its type.          |
| Purge             | Whether to delete owned contacts which vanished from the import source.                  |

The job is run by the Director daemon according to its run interval. Use `Automation → Jobs` to check its last
result, warnings about skipped rows are logged to the Icinga Web log.
