/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

const VERSION = 1;

(function (Icinga) {

    'use strict';

    const LOG_PREFIX = '[Notification] - ';

    if (! 'ServiceWorker' in self) {
        console.error(LOG_PREFIX + "this browser does not support the 'Service Worker API' in the current context");

        return;
    }

    if (! 'Navigator' in self) {
        console.error(LOG_PREFIX + "this browser does not support the 'Navigator API' in the current context");

        return;
    }

    if (! 'Notification' in self) {
        console.error(LOG_PREFIX + "this browser does not support the 'Notification API' in the current context");

        return;
    }

    Icinga.Behaviors = Icinga.Behaviors || {};

    class Notification extends Icinga.EventListener {
        eventSource = null;
        toggleState = null;
        initialized = false;
        allowedToOperate = false;

        constructor(icinga) {
            super(icinga);

            // only allow to be instantiated in a web context
            if (! self instanceof Window) {
                this.logger.error(LOG_PREFIX + "module should not get loaded outside of a web context!");
                throw new Error("Attempted to initialize the 'Notification' module outside of a web context!");
            }

            // initialize object fields
            this.icinga = icinga;
            this.logger = icinga.logger;
            this.toggleState = new Icinga.Storage.StorageAwareMap
                .withStorage(Icinga.Storage.BehaviorStorage('notification'), 'toggle')

            // listen for events
            this.on('icinga-init', null, this.onInit, this);
            this.on('rendered', '#main > .container', this.renderHandler, this);

            this.logger.debug(LOG_PREFIX + "spawned");
        }

        onInit(event) {
            event.data.self.load();
        }

        load() {
            this.logger.debug(LOG_PREFIX + "loading");

            // listen to controller (service worker) changes
            navigator.serviceWorker.addEventListener('controllerchange', (event) => {
                this.logger.debug(LOG_PREFIX + "new controller attached ", event.target.controller);
                if (event.target.controller !== null) {
                    // reset eventsource and handshake flag
                    this.allowedToOperate = false;
                    this.closeEventStream();

                    this.logger.debug(LOG_PREFIX + "send handshake to controller");
                    event.target.controller.postMessage(
                        JSON.stringify({
                            command: 'handshake',
                            version: VERSION
                        })
                    );
                }
            });

            // listen to messages from the controller (service worker)
            self.navigator.serviceWorker.addEventListener('message', (event) => {
                if (! event.data) {
                    return;
                }

                let data = JSON.parse(event.data);
                switch (data.command) {
                    case 'handshake':
                        if (data.status === 'outdated') {
                            this.logger.debug(
                                LOG_PREFIX
                                + "handshake got rejected as we're running an outdated script version"
                            );

                            // the controller declared us as an outdated script version
                            this.icinga.loader.createNotice(
                                'warning',
                                'This tab is running an outdated script version. Please reload the page!',
                                true
                            );

                            this.allowedToOperate = false;
                        } else {
                            this.logger.debug(
                                LOG_PREFIX
                                + "handshake got accepted by the controller"
                            );

                            this.allowedToOperate = true;
                            if (
                                this.initialized
                                && this.hasNotificationPermission()
                                && this.hasNotificationsEnabled()
                            ) {
                                setTimeout(() => {
                                    this.openEventStream();
                                }, 2000);
                            }
                        }

                        break;
                    case 'open_event_stream':
                        // service worker requested us to open up an event-stream
                        if (! this.allowedToOperate) {
                            // we are not allowed to open up connections, rejecting the request
                            this.logger.debug(
                                LOG_PREFIX
                                + "rejecting the request to open up an event-stream as this tab is not allowed"
                                + " to (failed the handshake with the controller)"
                            );
                            event.source.postMessage(
                                JSON.stringify({
                                    command: 'reject_open_event_stream',
                                    clientBlacklist: data.clientBlacklist
                                })
                            );
                        } else {
                            this.openEventStream();
                        }

                        break;
                    case 'close_event_stream':
                        // service worker requested us to stop our event-stream
                        this.closeEventStream();

                        break;
                }
            });

            // register service worker if it is not already
            this.getServiceWorker()
                .then((serviceWorker) => {
                    if (! serviceWorker) {
                        // no service worker registered yet, registering it
                        self.navigator.serviceWorker.register(icinga.config.baseUrl + '/notifications-worker.js', {
                            scope: icinga.config.baseUrl + '/',
                            type: 'classic'
                        }).then((registration) => {
                            let callback = (event) => {
                                if (event.target.state === 'activated') {
                                    registration.removeEventListener('statechange', callback);

                                    registration.active.postMessage(
                                        JSON.stringify({
                                            command: 'handshake',
                                            version: VERSION
                                        })
                                    );
                                }
                            };
                            registration.addEventListener('statechange', callback);
                        });
                    } else {
                        // service worker is already running, announcing ourselves
                        serviceWorker.postMessage(
                            JSON.stringify({
                                command: 'handshake',
                                version: VERSION
                            })
                        )
                    }
                })
                .finally(() => {
                    this.logger.debug(LOG_PREFIX + "loaded");
                })
        }

        unload() {
            this.logger.debug(LOG_PREFIX + "unloading");

            // disconnect EventSource if there's an active connection
            this.closeEventStream();
            this.eventSource = null;
            this.initialized = false;

            this.logger.debug(LOG_PREFIX + "unloaded");
        }

        reload() {
            this.unload();
            this.load();
        }

        openEventStream() {
            if (! this.hasNotificationPermission() || ! this.hasNotificationsEnabled()) {
                this.logger.warn(LOG_PREFIX + "denied opening event-stream as the notification permissions" +
                    " are missing or the notifications themselves disabled");

                return;
            }

            // close existing event source object if there's one
            this.closeEventStream();

            try {
                this.logger.debug(LOG_PREFIX + "opening event source");
                this.eventSource = new EventSource(
                    icinga.config.baseUrl + '/notifications/daemon',
                    {withCredentials: true}
                );
                this.eventSource.addEventListener('icinga2.notification', (event) => {
                    if (! this.hasNotificationPermission() || ! this.hasNotificationsEnabled()) {
                        return;
                    }

                    // send to service_worker if the permissions are given and the notifications enabled
                    this.getServiceWorker()
                        .then((serviceWorker) => {
                            if (serviceWorker) {
                                serviceWorker.postMessage(
                                    JSON.stringify({
                                        command: 'notification',
                                        notification: JSON.parse(event.data),
                                        baseUrl: icinga.config.baseUrl
                                    })
                                );
                            }
                        });
                });
            } catch (error) {
                this.logger.error(LOG_PREFIX + `got an error while trying to open up an event-stream:`, error);
            }
        }

        closeEventStream() {
            if (this.eventSource !== null && this.eventSource.readyState !== EventSource.CLOSED) {
                this.eventSource.close();
            }
        }

        renderHandler(event) {
            const _this = event.data.self;
            let url = new URL(event.delegateTarget.URL);

            /**
             * TODO(nc): We abuse the fact that the renderHandler method only triggers when the container
             *  in col1 (#main > #col1.container) gets rendered. This can only happen on the main interface for
             *  now (might break things if columns are introduced elsewhere in the future).
             *  This in turn requires a user to be logged in and their session validated.
             *  In the future, we should introduce a proper login event and tie the initial event-stream connection
             *  to this specific event (SSO should ALSO trigger the login event as the user lands in the
             *  interface with an authenticated session).
             */
            if (_this.initialized === false) {
                _this.initialized = true;
            }

            if (url.pathname !== _this.icinga.config.baseUrl + '/account') {
                return;
            }

            // check permissions and storage flag
            const state = _this.hasNotificationPermission() && _this.hasNotificationsEnabled();

            // account page got rendered, injecting notification toggle
            const container = event.target;
            const form = container.querySelector('.content > form[name=form_config_preferences]');
            const submitButtons = form.querySelector('div > input[type=submit]').parentNode;

            // build toggle
            const toggle = document.createElement('div');
            toggle.classList.add('control-group');

            // div .control-label-group
            const toggleLabelGroup = document.createElement('div');
            toggleLabelGroup.classList.add('control-label-group');
            toggle.appendChild(toggleLabelGroup);
            const toggleLabelSpan = document.createElement('span');
            toggleLabelSpan.setAttribute('id', 'form_config_preferences_enable_notifications-label');
            toggleLabelSpan.textContent = 'Enable notifications';
            toggleLabelGroup.appendChild(toggleLabelSpan);
            const toggleLabel = document.createElement('label');
            toggleLabel.classList.add('control-label');
            toggleLabel.classList.add('optional');
            toggleLabel.setAttribute('for', 'form_config_preferences_enable_notifications');
            toggleLabelSpan.appendChild(toggleLabel);

            // input .sr-only
            const toggleInput = document.createElement('input');
            toggleInput.setAttribute('id', 'form_config_preferences_enable_notifications');
            toggleInput.classList.add('sr-only');
            toggleInput.setAttribute('type', 'checkbox');
            toggleInput.setAttribute('name', 'show_notifications');
            toggleInput.setAttribute('value', state ? '1' : '0');
            if (state) {
                toggleInput.setAttribute('checked', 'checked');
            }
            toggle.appendChild(toggleInput);
            // listen to toggle changes
            toggleInput.addEventListener('change', () => {
                if (toggleInput.checked) {
                    toggleInput.setAttribute('value', '1');
                    toggleInput.setAttribute('checked', 'checked');

                    if (_this.hasNotificationPermission() === false) {
                        // ask for notification permission
                        window.Notification.requestPermission()
                            .then((permission) => {
                                if (permission !== 'granted') {
                                    // reset toggle back to unchecked as the permission got denied
                                    toggleInput.checked = false;
                                }
                            })
                            .catch((_) => {
                                // permission is not allowed in this context, resetting toggle
                                toggleInput.checked = false;
                            });
                    }
                } else {
                    toggleInput.setAttribute('value', '0');
                    toggleInput.removeAttribute('checked');
                }
            });

            // label .toggle-switch
            const toggleSwitch = document.createElement('label');
            toggleSwitch.classList.add('toggle-switch');
            toggleSwitch.setAttribute('for', 'form_config_preferences_enable_notifications');
            toggleSwitch.setAttribute('aria-hidden', 'true');
            toggle.appendChild(toggleSwitch);
            const toggleSwitchSlider = document.createElement('span');
            toggleSwitchSlider.classList.add('toggle-slider');
            toggleSwitch.appendChild(toggleSwitchSlider);

            form.insertBefore(toggle, submitButtons);

            // listen to submit event to update storage flag if needed
            form.addEventListener('submit', () => {
                let hasChanged = false;
                if (toggleInput.checked) {
                    // notifications are enabled
                    if (_this.hasNotificationPermission()) {
                        if (_this.toggleState.has('enabled') === false || (_this.toggleState.get('enabled') !== true)) {
                            _this.toggleState.set('enabled', true);
                            hasChanged = true;
                        }
                    }
                } else {
                    // notifications are disabled
                    if (_this.toggleState.has('enabled')) {
                        _this.toggleState.delete('enabled');

                        hasChanged = true;
                    }
                }

                if (hasChanged) {
                    // inform service worker about the toggle change
                    _this.getServiceWorker()
                        .then((serviceWorker) => {
                            if (serviceWorker) {
                                serviceWorker.postMessage(
                                    JSON.stringify({
                                        command: 'storage_toggle_update',
                                        state: toggleInput.checked
                                    })
                                );
                            }
                        });
                }
            });
        }

        hasNotificationsEnabled() {
            return (
                (this.toggleState !== null) &&
                (this.toggleState.has('enabled')) &&
                (this.toggleState.get('enabled') === true)
            );
        }

        hasNotificationPermission() {
            return ('Notification' in window) && (window.Notification.permission === 'granted');
        }

        async getServiceWorker() {
            let serviceWorker = await self.navigator.serviceWorker
                .getRegistration(icinga.config.baseUrl + '/');

            if (serviceWorker) {
                switch (true) {
                    case serviceWorker.installing !== null:
                        return serviceWorker.installing;
                    case serviceWorker.waiting !== null:
                        return serviceWorker.waiting;
                    case serviceWorker.active !== null:
                        return serviceWorker.active;
                }
            }

            return null;
        }
    }

    Icinga.Behaviors.Notification = Notification;
})(Icinga);
