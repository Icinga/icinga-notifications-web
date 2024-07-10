# Installing Icinga Notifications Web from Source

Please see the Icinga Web documentation on
[how to install modules](https://icinga.com/docs/icinga-web-2/latest/doc/08-Modules/#installation) from source.
Make sure you use `notifications` as the module name. The following requirements must also be met.

### Requirements

- PHP (≥7.2)
- PHP needs the following extensions to be installed and activated:
    - `json`
- [MySQL](https://www.php.net/manual/en/ref.pdo-mysql.php)
  or [PostgreSQL](https://www.php.net/manual/en/ref.pdo-pgsql.php) PDO PHP libraries
- [Icinga Notifications](https://github.com/Icinga/icinga-notifications)
- [Icinga Web](https://github.com/Icinga/icingaweb2) (≥2.9)
- [Icinga PHP Library (ipl)](https://github.com/Icinga/icinga-php-library) (≥0.12.0)
- [Icinga PHP Thirdparty](https://github.com/Icinga/icinga-php-thirdparty) (≥0.11.0)

<!-- {% include "02-Installation.md" %} -->
