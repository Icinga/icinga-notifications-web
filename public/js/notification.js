(function (Icinga) {

    'use strict';

    Icinga.Behaviors = Icinga.Behaviors || {};

    class Notification extends Icinga.EventListener {
        constructor(icinga) {
            super(icinga);

            this._icinga = icinga;
            this._logger = icinga.logger;
            this._activated = false;
            this._eventSource = null;
            this._init();
        }

        _init() {
            this.on('rendered', '#main > #col1.container', this.onRendered, this);
            window.addEventListener('beforeunload', this.onUnload);
            window.addEventListener('unload', this.onUnload);

            console.log("loaded notifications.js");
        }

        _checkCompatibility() {
            let isCompatible = true;
            if (!('Notification' in window)) {
                console.error("This webbrowser does not support the Notification API.");
                isCompatible = false;
            }
            if (!('serviceWorker' in window.navigator)) {
                console.error("This webbrowser does not support the ServiceWorker API.");
                isCompatible = false;
            }
            return isCompatible;
        }

        _checkPermissions() {
            return ('Notification' in window) && (window.Notification.permission === 'granted');
        }

        onRendered(event) {
            let _this = event.data.self;
            // only process main event (not the bubbled triggers)
            if (event.target === event.currentTarget && _this._activated === false) {
                if (_this._checkCompatibility()) {
                    if (_this._checkPermissions() === false) {
                        // permissions are not granted, requesting them
                        window.Notification.requestPermission().then((permission) => {
                            if (permission !== 'granted') {
                                console.error("Notifications were requested but not granted. Skipping 'notification' workflow.")
                            }
                        });
                    }
                    // register service worker (if not already registered)
                    try {
                        navigator.serviceWorker.register(
                            'icinga-notifications-worker.js',
                            {
                                scope: '/icingaweb2/',
                                type: 'classic'
                            }
                        ).then((registration) => {
                            if (registration.installing) {
                                console.log("Service worker is installing.");
                            } else if (registration.waiting) {
                                console.log("Service worker has been installed and is waiting to be run.");
                            } else if (registration.active) {
                                console.log("Service worker has been activated.");
                            }

                            if (navigator.serviceWorker.controller === null) {
                                /**
                                 * hard refresh detected. This causes the browser to not forward fetch requests to
                                 * service workers. Reloading the site fixes this.
                                 */
                                setTimeout(() => {
                                    console.log("Hard refresh detected. Reloading page to fix the service workers.");
                                    location.reload();
                                }, 1000);
                                return;
                            }

                            // connect to the daemon endpoint (should be intercepted by the service worker)
                            setTimeout(() => {
                                _this._eventSource = new EventSource('/icingaweb2/notifications/daemon');
                                _this._activated = true;
                            }, 2500);
                        });
                    } catch (error) {
                        console.error(`Service worker failed to register: ${error}`);
                    }
                } else {
                    // unsupported in this browser, set activation to null
                    console.error("This browser doesn't support the needed APIs for desktop notifications.");
                    _this._activated = null;
                }
            }
        }

        onUnload(event) {
            // Icinga 2 module is going to unload, cleaning up notification handling
            // console.log("onUnload triggered with", event);
        }
    }

    Icinga.Behaviors.Notification = Notification;
})(Icinga);