// noinspection JSUnresolvedReference

const _PREFIX = '[notifications-worker] - ';
const _SERVER_CONNECTIONS = {};
let _BASE_URL = '';

if (!(self instanceof ServiceWorkerGlobalScope)) {
    throw new Error("Tried loading 'notification-worker.js' in a context other than a Service Worker.");
}

/** @type {ServiceWorkerGlobalScope} */
const selfSW = self;
selfSW.addEventListener('message', (event) => {
    // self.console.log(_PREFIX + "received a message: ", event);
    this.processMessage(event);
});
selfSW.addEventListener('activate', (event) => {
    // claim all clients under own scope once the service worker gets activated
    event.waitUntil(
        selfSW.clients.claim().then(() => {
            self.console.log(_PREFIX + "claimed all tabs.");
        })
    );
});
selfSW.addEventListener('fetch', (event) => {
    // self.console.log(_PREFIX + 'fetch event triggered with: ', event);
    const request = event.request;
    const url = new URL(event.request.url);

    // only check dedicated event stream requests towards the daemon
    if (request.headers.get('accept').startsWith('text/event-stream') && url.pathname.trim() === '/icingaweb2/notifications/daemon') {
        if (Object.keys(_SERVER_CONNECTIONS).length < 2) {
            self.console.log(_PREFIX + `tab '${event.clientId}' requested event-stream.`);
            event.respondWith(this.injectMiddleware(request, event.clientId));
        }
        else {
            self.console.log(_PREFIX + `event-stream request from tab '${event.clientId}' got blocked as there's already 2 active connections.`);
            // block request as the event-stream unneeded for now (2 tabs are already connected)
            event.respondWith(new Response(
                null,
                {
                    status: 204,
                    statusText: 'No Content'
                }
            ));
        }
    }
});
selfSW.addEventListener('notificationclick', (event) => {
    event.notification.close();
    if (!('action' in event)) {
        self.clients.openWindow(event.notification.data.url).then();
    }
    else {
        switch (event.action) {
            case 'viewIncident':
                self.clients.openWindow(event.notification.data.url).then();
                break;
            case 'dismiss':
                break;
        }
    }
});

function processMessage(event) {
    if (event.data) {
        let data = JSON.parse(event.data);
        switch (data.command) {
            case 'tab_force_reclaim':
                /*
                 * trigger the claim process as there seems to be new clients in our scope which aren't under our
                 * control
                 */
                self.clients.claim().then(() => {
                    self.console.log(_PREFIX + "reclaimed all tabs.");
                });
                break;
            case 'tab_notification':
                /*
                 * displays a notification through the service worker (if the permissions have been granted)
                 */
                if (('Notification' in self) && (self.Notification.permission === 'granted')) {
                    const notification = data.notification;
                    let title = '';
                    let severity = 'unknown';

                    // match severity
                    switch (notification.payload.severity) {
                        case 'ok':
                            severity = 'ok';
                            break;
                        case 'warn':
                            severity = 'warning';
                            break;
                        case 'crit':
                            severity = 'critical';
                            break;
                    }

                    // build title
                    if (notification.payload.service !== '') {
                        title += "'" + notification.payload.service + "' on ";
                    }
                    title += "'" + notification.payload.host + "'";

                    self.registration.showNotification(
                        title,
                        {
                            icon: _BASE_URL + '/img/notifications/icinga-notifications-' + severity + '.webp',
                            body: 'changed to severity ' + severity,
                            data: {
                                url:
                                    _BASE_URL
                                    + '/notifications/incident?id='
                                    + notification.payload.incident_id
                            },
                            actions: [
                                {
                                    action: 'viewIncident', title: 'View incident'
                                },
                                {
                                    action: 'dismiss', title: 'Dismiss'
                                }
                            ]
                        }
                    ).then();
                }
                break;
            case 'tab_init':
                // received initialization, storing Icinga's base url
                _BASE_URL = data.baseUrl;
                self.console.log(_PREFIX + `set Icinga's base url to '${_BASE_URL}'`);
                break;
            case 'storage_toggle_update':
                if (data.state) {
                    // notifications got enabled
                    // ask clients to open up stream
                    self.clients.matchAll({
                        type: 'window',
                        includeUncontrolled: false
                    }).then((clients) => {
                        let clientsToOpen = 2 - (Object.keys(_SERVER_CONNECTIONS).length);
                        if (clientsToOpen > 0) {
                            for (const client of clients) {
                                if (clientsToOpen === 0) {
                                    break;
                                }

                                client.postMessage(JSON.stringify({
                                    command: 'server_force_reconnect'
                                }));
                                --clientsToOpen;
                            }
                        }
                    });
                }
                else {
                    // notifications got disabled
                    // closing existing streams
                    self.clients.matchAll({
                        type: 'window',
                        includeUncontrolled: false
                    }).then((clients) => {
                        for (const client of clients) {
                            if (client.id in _SERVER_CONNECTIONS) {
                                client.postMessage(JSON.stringify({
                                    command: 'server_force_stop'
                                }));
                            }
                        }
                    });
                }
                break;
        }
    }
}

async function injectMiddleware(request, clientId) {
    // define reference holders
    const controllers = {
            writable: undefined,
            readable: undefined,
            signal: new AbortController()
        };
    const streams = {
        writable: undefined,
        readable: undefined,
        pipe: undefined
    };

    // fetch event-stream and inject middleware
    let response = await fetch(request, {
        keepalive: true,
        signal: controllers.signal.signal
    });
    if (response.ok && response.status !== 204 && response.body instanceof ReadableStream) {
        self.console.log(_PREFIX + `injecting into data stream of tab '${clientId}'.`);
        streams.readable = new ReadableStream({
            start(controller) {
                controllers.readable = controller;

                // stream opened up, adding it to the active connections
                _SERVER_CONNECTIONS[clientId] = clientId;
            },
            cancel(reason) {
                self.console.log(_PREFIX + `tab '${clientId}' closed event-stream (client-side).`);

                // request another opened up tab to take over the connection (if there's any)
                self.clients.matchAll({
                    type: 'window',
                    includeUncontrolled: false
                }).then((clients) => {
                    for (const client of clients) {
                        if (!(client.id in _SERVER_CONNECTIONS) && client.id !== clientId) {
                            client.postMessage(JSON.stringify({
                                command: 'server_force_reconnect'
                            }));
                            break;
                        }
                    }
                });

                // remove from active connections if it exists
                if (clientId in _SERVER_CONNECTIONS) {
                    delete _SERVER_CONNECTIONS[clientId];
                }

                // tab crashed or closed down connection to event-stream, stopping pipe through stream by
                // triggering the abort signal (and stopping the writing stream as well)
                controllers.signal.abort();
            }
        }, new CountQueuingStrategy({highWaterMark: 10}));
        streams.writable = new WritableStream({
            start(controller) {
                controllers.writable = controller;
            },
            write(chunk, controller) {
                controllers.readable.enqueue(chunk);
            },
            close() {
                // close was triggered by the server closing down the event-stream
                self.console.log(_PREFIX + `tab '${clientId}' closed event-stream (server-side).`);
                // remove from active connections if it exists
                if (clientId in _SERVER_CONNECTIONS) {
                    delete _SERVER_CONNECTIONS[clientId];
                }

                // closing the reader as well
                controllers.readable.close();
            },
            abort(reason) {
                // close was triggered by an abort signal (most likely by the reader / client-side)
                self.console.log(_PREFIX + `tab '${clientId}' closed event-stream (server-side).`);
                // remove from active connections if it exists
                if (clientId in _SERVER_CONNECTIONS) {
                    delete _SERVER_CONNECTIONS[clientId];
                }
            }
        }, new CountQueuingStrategy({highWaterMark: 10}));

        // returning injected (piped) stream
        streams.pipe = response.body.pipeThrough({
                writable: streams.writable,
                readable: streams.readable
            }, {signal: controllers.signal.signal}
        );

        return new Response(
            streams.pipe,
            {
                headers: response.headers,
                statusText: response.statusText,
                status: response.status
            }
        )
    }
    return response;
}
