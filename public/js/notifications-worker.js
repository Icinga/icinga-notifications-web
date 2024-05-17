/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

const VERSION = {
    WORKER: 1,
    SCRIPT: 1
};

const PREFIX = '[notifications-worker] - ';
const SERVER_CONNECTIONS = {};

self.console.log(PREFIX + `started worker on <version: ${VERSION.WORKER}>`);

if (! (self instanceof ServiceWorkerGlobalScope)) {
    throw new Error("Tried loading 'notification-worker.js' in a context other than a Service Worker");
}

/** @type {ServiceWorkerGlobalScope} */
const selfSW = self;

selfSW.addEventListener('message', (event) => {
    processMessage(event);
});
selfSW.addEventListener('activate', (event) => {
    // claim all clients
    event.waitUntil(selfSW.clients.claim());
});
selfSW.addEventListener('install', (event) => {
    event.waitUntil(selfSW.skipWaiting());
});
selfSW.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(event.request.url);

    // only check dedicated event stream requests towards the daemon
    if (
        ! request.headers.get('accept').startsWith('text/event-stream')
        || url.pathname.trim() !== '/icingaweb2/notifications/daemon'
    ) {
        return;
    }

    if (Object.keys(SERVER_CONNECTIONS).length < 2) {
        self.console.log(PREFIX + `<tab: ${event.clientId}> requested event-stream`);
        event.respondWith(injectMiddleware(request, event.clientId));
    } else {
        self.console.log(
            PREFIX
            + `event-stream request from <tab: ${event.clientId}> got blocked as there's already 2 active connections`
        );
        // block request as the event-stream unneeded for now (2 tabs are already connected)
        event.respondWith(new Response(
            null,
            {
                status: 204,
                statusText: 'No Content'
            }
        ));
    }
});
selfSW.addEventListener('notificationclick', (event) => {
    event.notification.close();
    if (! ('action' in event)) {
        void self.clients.openWindow(event.notification.data.url);
    } else {
        switch (event.action) {
            case 'viewIncident':
                void self.clients.openWindow(event.notification.data.url);
                break;
            case 'dismiss':
                break;
        }
    }
});

function processMessage(event) {
    if (! event.data) {
        return;
    }

    let data = JSON.parse(event.data);
    switch (data.command) {
        case 'handshake':
            if (data.version === VERSION.SCRIPT) {
                self.console.log(
                    PREFIX
                    + `accepting handshake from <tab: ${event.source.id}> <version: ${data.version}>`
                );
                event.source.postMessage(
                    JSON.stringify({
                        command: 'handshake',
                        status: 'accepted'
                    })
                );
            } else {
                self.console.log(
                    PREFIX
                    + `denying handshake from <tab: ${event.source.id}> <version: ${data.version}> as it does not `
                    + `run the desired version: ${VERSION.SCRIPT}`
                );
                event.source.postMessage(
                    JSON.stringify({
                        command: 'handshake',
                        status: 'outdated'
                    })
                );
            }

            break;
        case 'notification':
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
                    case 'warning':
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

                void self.registration.showNotification(
                    title,
                    {
                        icon: data.baseUrl + '/img/notifications/icinga-notifications-' + severity + '.webp',
                        body: 'changed to severity ' + severity,
                        data: {
                            url:
                                data.baseUrl
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
                );
            }

            break;
        case 'storage_toggle_update':
            if (data.state) {
                // notifications got enabled
                // ask clients to open up stream
                self.clients
                    .matchAll({type: 'window', includeUncontrolled: false})
                    .then((clients) => {
                        let clientsToOpen = 2 - (Object.keys(SERVER_CONNECTIONS).length);
                        if (clientsToOpen > 0) {
                            for (const client of clients) {
                                if (clientsToOpen === 0) {
                                    break;
                                }

                                client.postMessage(JSON.stringify({
                                    command: 'open_event_stream',
                                    clientBlacklist: []
                                }));
                                --clientsToOpen;
                            }
                        }
                    });
            } else {
                // notifications got disabled
                // closing existing streams
                self.clients
                    .matchAll({type: 'window', includeUncontrolled: false})
                    .then((clients) => {
                        for (const client of clients) {
                            if (client.id in SERVER_CONNECTIONS) {
                                client.postMessage(JSON.stringify({
                                    command: 'close_event_stream'
                                }));
                            }
                        }
                    });
            }

            break;
        case 'reject_open_event_stream':
            // adds the client to the blacklist, as it rejected our request
            data.clientBlacklist.push(event.source.id);
            self.console.log(PREFIX + `<tab: ${event.source.id}> rejected the request to open an event  stream`);

            selfSW.clients
                .matchAll({type: 'window', includeUncontrolled: false})
                .then((clients) => {
                    for (const client of clients) {
                        if (! data.clientBlacklist.includes(client.id) && ! (client.id in SERVER_CONNECTIONS)) {
                            client.postMessage(JSON.stringify({
                                command: 'open_event_stream',
                                clientBlacklist: data.clientBlacklist
                            }));

                            return;
                        }
                    }
                });

            break;
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

    if (! response.ok || response.status === 204 || ! response.body instanceof ReadableStream) {
        return response;
    }

    self.console.log(PREFIX + `injecting into data stream of <tab: ${clientId}>`);
    streams.readable = new ReadableStream({
        start(controller) {
            controllers.readable = controller;

            // stream opened up, adding it to the active connections
            SERVER_CONNECTIONS[clientId] = clientId;
        },
        cancel(reason) {
            self.console.log(PREFIX + `<tab: ${clientId}> closed event-stream (client-side)`);

            // request another opened up tab to take over the connection (if there's any)
            self.clients
                .matchAll({type: 'window', includeUncontrolled: false})
                .then((clients) => {
                    for (const client of clients) {
                        if (! (client.id in SERVER_CONNECTIONS) && client.id !== clientId) {
                            client.postMessage(JSON.stringify({
                                command: 'open_event_stream',
                                clientBlacklist: []
                            }));

                            break;
                        }
                    }
                });

            removeActiveClient(clientId);

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
            self.console.log(PREFIX + `<tab: ${clientId}> closed event-stream (server-side)`);
            removeActiveClient(clientId);

            // closing the reader as well
            controllers.readable.close();
        },
        abort(reason) {
            // close was triggered by an abort signal (most likely by the reader / client-side)
            self.console.log(PREFIX + `<tab: ${clientId}> closed event-stream (server-side)`);
            removeActiveClient(clientId);
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
    );
}

function removeActiveClient(clientId) {
    // remove from active connections if it exists
    if (clientId in SERVER_CONNECTIONS) {
        delete SERVER_CONNECTIONS[clientId];
    }
}
