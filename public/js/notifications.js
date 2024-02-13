(function (Icinga) {

    'use strict';

    Icinga.Behaviors = Icinga.Behaviors || {};

    class Notification extends Icinga.EventListener {
        _prefix = '[Notification] - ';
        _jooat = function jooat(input) {
            // jenkins one at a time, rewritten from http://www.burtleburtle.net/bob/hash/doobs.html#one
            let hash, i;

            for (hash = i = 0; i < input.length; ++i) {
                hash += input.charCodeAt(i);
                hash += (hash << 10);
                hash ^= (hash >>> 6);
            }
            hash += (hash << 3);
            hash ^= (hash >>> 11);
            hash += (hash << 15);

            return hash >>> 0;
        }

        constructor(icinga) {
            super(icinga);

            // only allow to be instantiated in a web context
            if (!(self instanceof Window)) {
                this._logger.error(this._prefix + "module should not get loaded outside of a web context!");
                throw new Error("Attempted to initialize the 'Notification' module outside of a web context!");
            }

            // initialize object fields
            this._icinga = icinga;
            this._logger = icinga.logger;
            // TODO: Remove once done testing
            this._logger.logLevel = 'debug';

            // check for required API's
            this._logger.debug(this._prefix + "checking for the required APIs and permissions.");

            let isValidated = true;
            if(!('ServiceWorker' in self)) {
                this._logger.error(this._prefix + "this browser does not support the 'Service Worker API' in the" +
                    " current context.");
                isValidated = false;
            }
            if(!('Navigator' in self)) {
                this._logger.error(this._prefix + "this browser does not support the 'Notification API' in the" +
                    " current context.");
            }
            if(!isValidated) {
                throw new Error("The 'Notification' module is missing some required API's.");
            }

            this._logger.debug(this._prefix + "spawned.");
            this._load();
        }

        _load() {
            this._logger.debug(this._prefix + "loading.");

            // this.on('rendered', '#main > #col1.container', this.onRendered, this);

            // register service worker if not already registered
            self.navigator.serviceWorker.getRegistration(icinga.config.baseUrl).then((registration) => {
                if(registration === undefined) {
                    // no service worker registered yet, registering it
                    self.navigator.serviceWorker.register(icinga.config.baseUrl + '/notifications-worker.js', {
                        scope: icinga.config.baseUrl + '/',
                        type: 'classic'
                    }).then((registration) => {
                        let claim_once_activated = (event) => {
                            if (event.target.state === 'activated') {
                                registration.active.postMessage(
                                    JSON.stringify({
                                        command: 'tab_force_reclaim'
                                    })
                                );
                                // remove this event listener again as we don't need it anymore
                                registration.active.removeEventListener('statechange', claim_once_activated);
                            }
                        };
                        // listen to when the service worker gets activated
                        registration.installing.addEventListener('statechange', claim_once_activated);
                    });
                }
            });

            setTimeout(() => {
                try {
                    self.console.log("Opening event source");
                    let es = new EventSource(icinga.config.baseUrl + '/notifications/daemon', { withCredentials: true });
                    es.addEventListener('message', (event) => {
                        self.console.log("Got message from event stream: ", event.data);
                    });
                    es.addEventListener('error', (event) => {
                        self.console.error("Got an error: ", event);
                    });
                    es.addEventListener('icinga2.notification', (event) => {
                       self.console.log("Got icinga2 notification: ", event.data);
                    });
                } catch (error) {
                    self.console.error("Got an error: ", error);
                }
            }, 5000);

            this._logger.debug(this._prefix + "loaded.");
        }

        _unload() {
            this._logger.debug(this._prefix + "unloading.");

            // disconnect EventSource if there's an active connection
            if (this._eventSource && this._eventSource instanceof EventSource) {
                if (this._eventSource.readyState !== EventSource.CLOSED) {
                    this._eventSource.close();
                }
            }
            this._eventSource = null;

            this._logger.debug(this._prefix + "unloaded.");
        }

        _reload() {
            this._unload();
            this._load();
        }

        _checkPermissions() {
            return ('Notification' in window) && (window.Notification.permission === 'granted');
        }

        onRendered(event) {
            return;
            let _this = event.data.self;
            // only process main event (not the bubbled triggers)
            if (event.target === event.currentTarget && _this._running === false) {
                // activating the module as we're not on the
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

                            // if (navigator.serviceWorker.controller === null) {
                            //     /**
                            //      * hard refresh detected. This causes the browser to not forward fetch requests to
                            //      * service workers. Reloading the site fixes this.
                            //      */
                            //     setTimeout(() => {
                            //         console.log("Hard refresh detected. Reloading page to fix the service workers.");
                            //         location.reload();
                            //     }, 1000);
                            //     return;
                            // }

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
    }

    Icinga.Behaviors.Notification = Notification;
})(Icinga);
