# Desktop Notifications

With Icinga Notifications, users are able to enable desktop notifications which will inform them about severity
changes in incidents they are notified about.

> **Note**
>
> This feature is currently considered experimental and might not work as expected in all cases.
> We will continue to improve this feature in the future. Your feedback is highly appreciated.

## How It Works

A user can enable this feature in their account preferences, in case Icinga Web is being accessed by using a secure
connection. Once enabled, the web interface will establish a persistent connection to the web server which will push
notifications to the user's browser. This connection is only established when the user is logged in and has the web
interface open. This means that if the browser is closed, no notifications will be shown.

For this reason, desktop notifications are not meant to be a primary notification method. This is also the reason
why they will only show up for incidents a contact is notified about by other means, e.g. email.

In order to link a contact to the currently logged-in user, both the contact's and the user's username must match.

### Supported Browsers

All browsers [supported by Icinga Web](https://icinga.com/docs/icinga-web/latest/doc/02-Installation/#browser-support)
can be used to receive desktop notifications. Though, most mobile browsers are excluded, due to their aggressive energy
saving mechanisms.

## Setup

To get this to work, a background daemon needs to be accessible by HTTP through the same location as the web
interface. Each connection is long-lived as the daemon will push messages by using SSE (Server-Sent-Events)
to each connected client.

### Configure The Daemon

The daemon is configured in the `config.ini` file located in the module's configuration directory. The default
location is `/etc/icingaweb2/modules/notifications/config.ini`.

In there, add a new section with the following content:

```ini
[daemon]
host = [::] ; The IP address to listen on
port = 9001 ; The port to listen on
```

The values shown above are the default values. You can adjust them to your needs.

### Configure The Webserver

Since connection handling is performed by the background daemon itself, you need to configure your web server to
proxy requests to the daemon. The following examples show how to configure Apache and Nginx. They're based on the
default configuration Icinga Web ships with if you've used the `icingacli setup config webserver` command.

Adjust the base URL `/icingaweb2` to your needs and the IP address and the port to what you have configured in the
daemon's configuration.

**Apache**

```
<LocationMatch "^/icingaweb2/notifications/v(?<version>\d+)/subscribe">
    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
    RequestHeader set X-Icinga-Notifications-Protocol-Version %{MATCH_VERSION}e
    ProxyPass http://127.0.0.1:9001 connectiontimeout=30 timeout=30 flushpackets=on
    ProxyPassReverse http://127.0.0.1:9001
</LocationMatch>
```

**Nginx**

```
location ~ ^/icingaweb2/notifications/v(\d+)/subscribe$ {
    proxy_pass http://127.0.0.1:9001;
    proxy_set_header Connection "";
    proxy_set_header X-Icinga-Notifications-Protocol-Version $1;
    proxy_http_version 1.1;
    proxy_buffering off;
    proxy_cache off;
    chunked_transfer_encoding off;
}
```

> **Note**
>
> Since these connections are long-lived, the default web server configuration might impose a too small limit on
> the maximum number of connections. Make sure to adjust this limit to a higher value. If working correctly, the
> daemon will limit the number of connections per client to 2.

### Enable The Daemon

The default `systemd` service, shipped with package installations, runs the background daemon.

<!-- {% if not icingaDocs %} -->

> **Note**
>
> If you haven't installed this module from packages, you have to configure this as a `systemd` service yourself by just
> copying the example service definition from `/usr/share/icingaweb2/modules/notifications/config/systemd/icinga-notifications-web.service`
> to `/etc/systemd/system/icinga-notifications-web.service`.
<!-- {% endif %} -->

You can run the following command to enable and start the daemon.
```
systemctl enable --now icinga-notifications-web.service
```
