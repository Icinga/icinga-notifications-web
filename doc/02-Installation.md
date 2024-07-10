<!-- {% if index %} -->

# Installing Icinga Notifications Web

The recommended way to install Icinga Notifications Web is to use prebuilt packages for
all supported platforms from our official release repository.

Please follow the steps listed for your target operating system,
which guide you through setting up the repository and installing Icinga Notifications Web.

Before installing Icinga Notifications Web, make sure you have installed
[Icinga Notifications](https://icinga.com/docs/icinga-notifications/latest/doc/02-Installation).

<!-- {% else %} -->

<!-- {% if not icingaDocs %} -->

## Installing the Package

If the [repository](https://packages.icinga.com) is not configured yet, please add it first.
Then use your distribution's package manager to install the `icinga-notifications-web` package
or install [from source](02-Installation.md.d/From-Source.md).
<!-- {% endif %} -->

This concludes the installation. Now proceed with the [configuration](03-Configuration.md).
<!-- {% endif %} -->
