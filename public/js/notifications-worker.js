const _PREFIX = '[notifications-worker] - ';
const _SERVER_CONNECTIONS = [];

if (!(self instanceof ServiceWorkerGlobalScope)) {
    throw new Error("Tried loading 'notification-worker.js' in a context other than a Service Worker.");
}

/** @type {ServiceWorkerGlobalScope} */
const selfSW = self;
selfSW.addEventListener('message', (event) => {
    self.console.log(_PREFIX + "received a message: ", event);
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
        self.console.log(_PREFIX + `tab '${event.clientId}' requested event-stream.`);
        event.respondWith(this.injectMiddleware(request, event.clientId));
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
        }
    }
}

async function injectMiddleware(request, clientId) {
    let response = await fetch(request, {
        keepalive: true
    });
    if (response.ok && response.body instanceof ReadableStream) {
        self.console.log(_PREFIX + `injecting into data stream of tab '${clientId}'.`);

        const controllers = {
            writable: undefined,
            readable: undefined,
            signal: new AbortController()
        };
        let readStream = new ReadableStream({
            start(controller) {
                controllers.readable = controller;
            },
            cancel(reason) {
                self.console.log(_PREFIX + `tab '${clientId}' closed event-stream (client-side).`);
                // tab crashed or closed down connection to event-stream, stopping pipe through stream by
                // triggering the abort signal
                controllers.signal.abort();
            }
        }, new CountQueuingStrategy({ highWaterMark: 10 }));
        let writeStream = new WritableStream({
            start(controller) {
                controllers.writable = controller;
            },
            write(chunk, controller) {
                controllers.readable.enqueue(chunk);
            },
            close() {
                // close was triggered by the server closing down the event-stream
                self.console.log(_PREFIX + `tab '${clientId}' closed event-stream (server-side).`);
                controllers.readable.close();
            },
            abort(reason) {
                // close was triggered by an abort signal (most likely by the reader / client-side)
                self.console.log(_PREFIX + `tab '${clientId}' closed event-stream (server-side).`);
                controllers.readable.close();
            }
        }, new CountQueuingStrategy({ highWaterMark: 10 }));
        // returning injected (piped) stream
        return new Response(
            response.body.pipeThrough({
                writable: writeStream,
                readable: readStream
            }, { signal: controllers.signal.signal }),
            {
                headers: response.headers,
                statusText: response.statusText,
                status: response.status
            }
        )
    }
    return response;
}
