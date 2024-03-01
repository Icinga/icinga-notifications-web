(function (Icinga) {

    'use strict';

    Icinga.Behaviors = Icinga.Behaviors || {};

    class Notification extends Icinga.EventListener {
        _prefix = '[Notification] - ';
        _eventSource = null;
        _toggleState = undefined;

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
            this._toggleState = new Icinga.Storage.StorageAwareMap.withStorage(
                Icinga.Storage.BehaviorStorage('notification'),
                'toggle'
            )
            // TODO: Remove once done testing
            this._logger.logLevel = 'debug';

            // check for required API's
            this._logger.debug(this._prefix + "checking for the required APIs and permissions.");

            let isValidated = true;
            if (!('ServiceWorker' in self)) {
                this._logger.error(this._prefix + "this browser does not support the 'Service Worker API' in the" +
                    " current context.");
                isValidated = false;
            }
            if (!('Navigator' in self)) {
                this._logger.error(this._prefix + "this browser does not support the 'Navigator API' in the" +
                    " current context.");
                isValidated = false;
            }
            if (!('Notification' in self)) {
                this._logger.error(this._prefix + "this browser does not support the 'Notification API' in the" +
                    " current context.");
                isValidated = false;
            }
            if (!isValidated) {
                // we only log the error and exit early as throwing would completely hang up the web application
                this._logger.error("The 'Notification' module is missing some required API's.");
                return;
            }

            this._logger.debug(this._prefix + "spawned.");
            this._load();
        }

        _load() {
            this._logger.debug(this._prefix + "loading.");

            // listen to render events on container for col1 (to inject notification toggle)
            this.on('rendered', '#main > #col1.container', this._renderHandler, this);

            // register service worker if not already registered
            self.navigator.serviceWorker.getRegistration(icinga.config.baseUrl).then((registration) => {
                if (registration === undefined) {
                    // no service worker registered yet, registering it
                    self.navigator.serviceWorker.register(icinga.config.baseUrl + '/notifications-worker.js', {
                        scope: icinga.config.baseUrl + '/',
                        type: 'classic'
                    }).then((registration) => {
                        let claim_once_activated = (event) => {
                            if (event.target.state === 'activated') {
                                // the tab that registers the service worker needs to tell it what Icinga's base url is:
                                registration.active.postMessage(
                                    JSON.stringify({
                                        command: 'tab_init',
                                        baseUrl: icinga.config.baseUrl
                                    })
                                );

                                registration.active.postMessage(
                                    JSON.stringify({
                                        command: 'tab_force_reclaim'
                                    })
                                );
                                // remove this event listener again as we don't need it anymore
                                registration.active.removeEventListener('statechange', claim_once_activated);
                            }
                        };
                        if (registration.installing) {
                            // listen to when the service worker gets activated
                            registration.installing.addEventListener('statechange', claim_once_activated);
                        } else if (registration.active) {
                            // if already activated
                            registration.active.postMessage(
                                JSON.stringify({
                                    command: 'tab_force_reclaim'
                                })
                            );
                        }
                    });
                }
            });
            self.navigator.serviceWorker.addEventListener('message', (event) => {
                if (event.data) {
                    let data = JSON.parse(event.data);
                    switch (data.command) {
                        case 'server_force_reconnect':
                            /*
                             * service worker requested us to open up an event-stream
                             */
                            this._openEventStream();
                            break;

                        case 'server_force_stop':
                            /*
                             * service worker requested us to stop our event-stream
                             */
                            if (this._eventSource !== null && this._eventSource.readyState !== EventSource.CLOSED) {
                                this._eventSource.close();
                            }
                            break;
                    }
                }
            });

            if (this._hasNotificationPermission() && this._hasNotificationsEnabled())
                setTimeout(() => {
                    this._openEventStream();
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

        _openEventStream() {
            if (this._hasNotificationPermission() && this._hasNotificationsEnabled()) {
                // close existing event source object if there's one
                if (this._eventSource !== null && this._eventSource.readyState !== EventSource.CLOSED) {
                    this._eventSource.close();
                }

                try {
                    this._logger.debug(this._prefix + "opening event source.");
                    this._eventSource = new EventSource(icinga.config.baseUrl + '/notifications/daemon', {withCredentials: true});
                    this._eventSource.addEventListener('message', (event) => {
                        // this._logger.debug(this._prefix + `got message from event-stream: ${event.data}`);
                    });
                    this._eventSource.addEventListener('error', (event) => {
                        // this._logger.debug(this._prefix +`got an error from event-stream: ${event}`);
                    });
                    this._eventSource.addEventListener('icinga2.notification', (event) => {
                        if (this._hasNotificationPermission() && this._hasNotificationsEnabled()) {
                            // send to service_worker if the permissions are given and the notifications enabled
                            self.navigator.serviceWorker.getRegistration(icinga.config.baseUrl + '/notifications-worker.js').then((registration) => {
                                if (registration) {
                                    registration.active.postMessage(
                                        JSON.stringify({
                                            command: 'tab_notification',
                                            notification: JSON.parse(event.data)
                                        })
                                    );
                                }
                            });
                        }
                    });
                } catch (error) {
                    // this._logger.error(this._prefix + `got an error: ${error}`);
                }
            } else {
                this._logger.warn(this._prefix + "denied opening event-stream as the notification permissions" +
                    " are missing or the notifications themselves disabled.");
            }
        }

        _renderHandler(event) {
            if (event.type === 'rendered') {
                const _this = event.data.self;
                let url = new URL(event.delegateTarget.URL);

                if (url.pathname === _this._icinga.config.baseUrl + '/account') {
                    // check permissions and storage flag
                    const state = (
                        _this._hasNotificationPermission() &&
                        _this._hasNotificationsEnabled()
                    );

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

                            if (_this._hasNotificationPermission() === false) {
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
                    form.addEventListener('submit', (event) => {
                        let hasChanged = false;
                        if (toggleInput.checked) {
                            // notifications are enabled
                            if (_this._hasNotificationPermission()) {
                                if (_this._toggleState.has('enabled') === false || (_this._toggleState.get('enabled') !== true)) {
                                    _this._toggleState.set('enabled', true);
                                    hasChanged = true;
                                }
                            }
                        } else {
                            // notifications are disabled
                            if (_this._toggleState.has('enabled')) {
                                _this._toggleState.delete('enabled');

                                hasChanged = true;
                            }
                        }

                        if (hasChanged) {
                            // inform service worker about the toggle change
                            self.navigator.serviceWorker.getRegistration(icinga.config.baseUrl + '/notifications-worker.js').then((registration) => {
                                if (registration) {
                                    registration.active.postMessage(
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
            }
        }

        _hasNotificationsEnabled() {
            return (
                (this._toggleState !== undefined) &&
                (this._toggleState.has('enabled')) &&
                (this._toggleState.get('enabled') === true)
            );
        }

        _hasNotificationPermission() {
            return ('Notification' in window) && (window.Notification.permission === 'granted');
        }
    }

    Icinga.Behaviors.Notification = Notification;
})(Icinga);
