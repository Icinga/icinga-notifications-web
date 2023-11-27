/**
 * version: 0.0.1 (rev: 1)
 */
'use strict';

class Logger {
    constructor(caller) {
        this.console = caller.console;
        this._PREFIX = 'icinga-notifications-worker';
        this._SEVERITY_DEBUG = 'DEBUG';
        this._SEVERITY_INFO = 'INFO';
        this._SEVERITY_WARN = 'WARN';
        this._SEVERITY_ERROR = 'ERROR';
        this._SEVERITY_FATAL = 'FATAL';
    }

    _log(severity, message) {
        this.console.log(this._PREFIX.toString() + ' - [' + severity + '] :: ' + message);
    }

    _error(severity, message) {
        this.console.error(this._PREFIX + ' - [' + severity + '] :: ' + message);
    }

    debug(message) {
        this._log(this._SEVERITY_DEBUG, message);
    }

    info(message) {
        this._log(this._SEVERITY_INFO, message);
    }

    warn(message) {
        this._log(this._SEVERITY_WARN, message);
    }

    error(message) {
        this._error(this._SEVERITY_ERROR, message);
    }

    fatal(message) {
        this._error(this._SEVERITY_FATAL, message);
        throw new Error(message);
    }
}

class Worker {
    constructor(caller) {
        this.caller = caller;
        this.logger = new Logger(this.caller);
        this._connections = [];
        this._proxyStreams = [];
        this._init();
    }

    _init() {
        this.logger.debug('Initialized.');
    }

    _load() {
        this.logger.debug('Loaded.');
    }

    _unload() {
        this.logger.debug('Unloaded.');
    }

    /**
     * @param eventSource EventSource
     * @private
     */
    _listenToEventSource(eventSource) {
        if (eventSource instanceof EventSource) {
            eventSource.addEventListener('message', (/** ExtendableMessageEvent event */ event) => {
                this.logger.debug(`${eventSource.url} [${event.type}] => Unhandled event occurred: ${event.data}.`);
            });
            eventSource.addEventListener('error', () => {
                if (eventSource.readyState === EventSource.CONNECTING) {
                    this.logger.debug(`${eventSource.url} [error] => Stream failed. Trying to reconnect...`);
                } else if (eventSource.readyState === EventSource.CLOSED) {
                    this.logger.debug(`${eventSource.url} [error] => Stream failed and won't reconnect anymore.`);
                    // removing the entry from the event sources
                    delete this._connections[eventSource.url];
                }
            });
            eventSource.addEventListener('open', (event) => {
                this.logger.debug(`${eventSource.url} [open] => Stream opened up.`);
            });
            eventSource.addEventListener('icinga2.notification', (event) => {
                let data = JSON.parse(event.data);
                // FIXME: Add proper notification display once we're sure what and how we want to display
                self.registration.showNotification(
                    '#' + event.lastEventId + ' - Incident is ' + data.payload.severity,
                    {
                        icon: 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNTYiIGhlaWdodD0iMjU2Ij48cGF0aCBzdHlsZT0ic3Ryb2tlOm5vbmU7ZmlsbC1ydWxlOm5vbnplcm87ZmlsbDojZmZmO2ZpbGwtb3BhY2l0eToxIiBkPSJNMzIuNjg4IDI1My4yMzhjLTkuNjI2LTEuMDM5LTE4LjM1Mi03LjAyNy0yMi43MjctMTUuNTc0LTIuMDM1LTMuOTg4LTMuMDM1LTcuOTAyLTMuMTcyLTEyLjQyNi0uMTUyLTQuOTM3Ljc2Mi05LjI1IDIuODg3LTEzLjU3NGEyOS4zMzcgMjkuMzM3IDAgMCAxIDEwLjg4Ni0xMi4wMzljMy40NS0yLjE0OCA4LjA2My0zLjcgMTIuMjktNC4xMzcgMi4wNDYtLjIxNSA1LjgxMi0uMDkgNy43NzMuMjYyIDMuOTYuNyA3LjgxMyAyLjIzOCAxMS4xMzcgNC40NSAxLjA2Mi43MTQgMS4yNzcuODEyIDEuNDE0LjY2My4yMjItLjIzOCAyNS41ODYtMzYuNjM2IDI1LjY2LTM2LjgxMi4wMjctLjA5LS41ODYtLjU5LTEuMzYtMS4xMTNBNDIuODIgNDIuODIgMCAwIDEgNTkuNyAxMzZjLS42MjUtMy4xNDgtLjc1LTQuNTYzLS43NS04LjM3NSAwLTMuOC4xMjUtNS4yMjcuNzUtOC4zNzUgMS4yMjctNi4xODggMy44NzUtMTIuMTc2IDcuNjE0LTE3LjIuNzUtMS4wMSAxLjA2Mi0xLjUyNy45ODgtMS42MjQtLjA2My0uMDktNS4wNjMtNC4yMTUtMTEuMTAyLTkuMTUzbC0xMS05LS44NzUuNzc4Yy0yLjQ4OCAyLjE2LTUuMjM4IDMuMTk5LTguNTExIDMuMTk5LTMuNjE0IDAtNi41NjMtMS4yMjctOS4xMzctMy44LTEuOTI2LTEuOTM4LTMuMDQtMy45NzMtMy41NzQtNi41NjMtLjMyOS0xLjU4Ni0uMjY2LTQuMzEzLjE0OC01LjgyNS41NC0yIDEuNzEtNC4wODUgMy4xNDgtNS42MjQgMy40MjYtMy42NzYgOC45NTQtNS4wNCAxMy42MjUtMy4zNjQgNy4wOSAyLjU0IDEwLjU0IDEwLjEyNSA3Ljc1IDE3LjA5LS40MjEgMS4wODYtLjQyMSAxLjExLS4xOTkgMS4zMzYuMTM3LjExMyA1LjIgNC4yNzMgMTEuMjc4IDkuMjM4bDExLjAyMyA5LjAyNCAxLjQxNC0xLjMxM2MxLjczNC0xLjYyNSAzLjA2My0yLjcyMiA0LjQ4NC0zLjczOCA2LjE1My00LjM0OCAxMi42MTQtNi44MzYgMjAuMTY1LTcuNzg1IDIuMTI0LS4yNjIgNy4wNzQtLjMxMyA5LjEyNC0uMDc0IDQuMTAyLjQ3MiA4LjM3NiAxLjUxMSAxMS40NSAyLjc4NS41LjE5OS45NDkuMzI0IDEgLjI3Ny4xMjUtLjE1MiAyMC40ODgtNDIuNTI3IDIwLjQ4OC00Mi42NTIgMC0uMDYzLS40MTQtLjM0OC0uOTI2LS42MjUtMi4yNjItMS4yNS01LTMuNjE0LTYuNjQ4LTUuNy00LjM1Mi01LjU3NC01LjgyNC0xMi42OTktNC4wNTEtMTkuNTM5IDIuMjEtOC40NzIgOS4yMzgtMTQuOTM3IDE3LjkzOC0xNi40NzIgMS41ODUtLjI3OCA0LjQ3Ni0uMzY0IDYuMDYyLS4xODggNi44Ljc4NSAxMi42NjQgNC4zMTMgMTYuMzg3IDkuODc1IDMuODQgNS43NSA0LjgxMiAxMi44NDggMi42NjQgMTkuNTEyLS41NTEgMS43MS0xLjk2NSA0LjUxMi0zLjAyOCA1Ljk4OC0zLjMyNCA0LjY0OS04LjMzNSA3Ljg4Ny0xNC4wNDYgOS4wMzUtMS4yMzkuMjUtMS43NzguMjktNC4yOS4yOS0yLjY4Ny0uMDEyLTIuOTc2LS4wNC00LjQzNy0uMzY0LS44NjMtLjE4Ny0xLjctLjM3NS0xLjg3NS0uMzk4LS4zLS4wNjMtLjYzNy42MjUtMTAuNTYzIDIxLjI2MS01LjYzNiAxMS43MjctMTAuMjYxIDIxLjM4Ny0xMC4yODkgMjEuNDg5LS4wMjMuMDg2LjQ2NS40MzcgMS4xMjUuODEyIDMuMjI3IDEuODM2IDUuOTc3IDMuOTEgOC43NjYgNi42MjUgMi4xNDkgMi4wOTggMy40NSAzLjYxNCA1LjA3NCA1Ljg2NCAzLjI4NSA0LjU4NSA1Ljg2NCAxMC40NDkgNi45NzMgMTUuODc1LjE0LjY0OC4yNzcgMS4wMjMuMzkgMS4wMjMuMTI2IDAgNjkuOTEtMTcuMTY0IDcwLjM0OC0xNy4zMTMuMDktLjAyMy4wNC0uNDE0LS4xNDgtMS4xNjQtLjg1Mi0zLjM1OS0uNjY0LTcuMDYyLjUzNS0xMC4yNzMgMi4xMjUtNS43NSA2Ljg0LTkuODYzIDEyLjkwMi0xMS4yNjIgMS4zODctLjMyNCA0LjU3NS0uNDQ5IDYuMDM1LS4yNSA4LjAyOCAxLjEzNyAxNC4xNjUgNy4zMjUgMTUuMjM5IDE1LjM0OC4xOTkgMS41LjA3NCA0LjUzOS0uMjUgNS45MTQtLjc3NCAzLjM1Mi0yLjM5OSA2LjM1Mi00LjcxMSA4LjY3Ni0yLjY2NCAyLjY4Ny01LjY2NCA0LjI3My05LjQ4OCA1LjAzNS0xLjY3Ni4zMjgtNC44MzYuMy02LjUyNC0uMDYzLTIuNDI2LS41MjMtNC41MjctMS40MjEtNi42MDEtMi44MTItMS40ODktMS0zLjY2NS0zLjIxMS00LjU2My00LjYyNS0uNDg4LS43NS0uNzYyLTEuMDc0LS45MzgtMS4wNzQtLjEzNiAwLTE2LjI1IDMuOTQ5LTM1LjgxMiA4Ljc3N2wtMzUuNTYzIDguNzczLjA0IDMuMzc2Yy4wNDYgMy42ODctLjExNCA1Ljc1LS42NjUgOC43MjYtMS4zNjMgNy4zNi00LjU1IDE0LjE0OS05LjMyNCAxOS44MzYtMi4xODcgMi42MDItNS42MDEgNS43NS04LjAyNyA3LjM3NS0uNDUuMzEzLS44MzYuNjEzLS44MzYuNjY0IDAgLjA0NyAzLjMzNiA1LjY2IDcuMzk4IDEyLjQ3M2w3LjQxNSAxMi4zNzUuNjg3LS4wOThjMS4wNjMtLjE1MiAyLjcyNy0uMDkgMy42MjUuMTM3LjQ1LjExMyAxLjM1Mi40NiAyIC43ODUuOTg4LjQ4OCAxLjM2My43NjYgMi4yNSAxLjY1MiAxLjE4OCAxLjE4OCAxLjg3NSAyLjM0OCAyLjM4NyA0LjAxMi4zNTEgMS4xMjUuNDE0IDMuMzc1LjEyNSA0LjQ4OC0uODg3IDMuNDYxLTMuNDM4IDYuMDEyLTYuODk5IDYuODk5LTEuMTEzLjI4OS0zLjM2My4yMjYtNC40ODgtLjEyNS0xLjY2NC0uNTEyLTIuODI0LTEuMi00LjAxMi0yLjM4Ny0uODg2LS44ODctMS4xNjQtMS4yNjItMS42NTItMi4yNS0uNzk3LTEuNjEzLTEuMDEyLTIuNTEyLTEuMDEyLTQuMTg4IDAtMi42MzYuODc1LTQuNjY0IDIuODEzLTYuNTc0bC44NTEtLjgxMi03LjI2MS0xMi4xMjVjLTMuOTg5LTYuNjY0LTcuMzAxLTEyLjE2NC03LjM2NC0xMi4yMjctLjA2Mi0uMDc0LS44NzUuMjUtMi4wNzQuODQtNC4wNzggMS45NjEtNy43NzcgMy4xNDktMTEuOTc3IDMuODI0LTQuNjM2LjczOS05Ljg4Ni43LTE0LjQzNy0uMTEzLTMuODEzLS42ODgtNy45Ni0yLjA0LTExLjExMy0zLjYyNWwtMS0uNS0yLjU1MSAzLjY2NGMtMS4zOTggMi03LjMxMyAxMC40NzMtMTMuMTM3IDE4LjgyNGwtMTAuNTc0IDE1LjE2Ljc2Mi44NGM0LjMxMiA0LjcyMyA2LjcxNSA5LjUgNy43MzggMTUuNDUuMzM2IDEuOTI1LjQ2IDUuMzYzLjI2MiA3LjM3NS0xLjE4OCAxMi4xNjQtOS44MjUgMjIuMzEyLTIxLjYzNyAyNS4zODYtMi4yMzguNTktNC4wMjMuODEzLTYuNjg4Ljg2My0xLjM3NC4wMjQtMy0uMDExLTMuNjI0LS4wNzR6bTAgMCIvPjwvc3ZnPg==',
                        body: data.payload.time
                    }
                ).then(r => console.log('showed notification'));
            });
            eventSource.addEventListener('proxy.keep_alive', (event) => {
                console.log('Received keep alive: ', event);
            });
        }
    }

    /**
     * @param controller ReadableStreamDefaultController
     * @private
     */
    _sendProxyKeepAlive(controller) {
        controller.enqueue(
            new TextEncoder().encode(
                'event: proxy.keep_alive\n'
                + 'data: ' + JSON.stringify({
                    time: new Date().toISOString().split('.')[0] + '+00:00',
                    payload: 'keep-alive'
                }) + '\n'
                + 'id: -1\n'
                + 'retry: 500\n\n'
            )
        );
    }

    /**
     * @param url URL
     * @param clientId string
     * @returns {ReadableStream}
     */
    getProxyStream(url, clientId) {
        if (!(url in this._connections)) {
            // FIXME: Firefox doesn't support EventSource in a service worker context because they are "special", add polyfill for firefox
            this._connections[url] = new EventSource(url);
            // register event listeners onto object
            this._listenToEventSource(this._connections[url]);
        }
        if (!(clientId in this._proxyStreams)) {
            let _this = this;
            this._proxyStreams[clientId] = new ReadableStream({
                start(controller) {
                    this.interval = setInterval(() => {
                        _this._sendProxyKeepAlive(controller);
                    }, 10000);
                },
                cancel() {
                    clearInterval(this.interval);
                    delete _this._proxyStreams[clientId];

                    // need to use the Object.keys property as array.length won't return the count as we're in an async state
                    if (Object.keys(_this._proxyStreams).length === 0) {
                        // there are no remaining tabs opened up, closing main connection if not already closed by EventSource.CLOSED event
                        if (_this._connections[url]) {
                            _this._connections[url].close();
                            delete _this._connections[url];
                        }
                    }
                }
            });
        }
        return this._proxyStreams[clientId];
    }
}

self.addEventListener('install', (/** ExtendableEvent event */ event) => {
    self.console.log('Install event triggered.');

    self.worker = new Worker(self);
});
self.addEventListener('activate', (/** ExtendableEvent event */ event) => {
    self.console.log('Activate event triggered.');
    self.clients.claim().then(r => self.console.log('clients claimed!'));

    self.worker._load();
});

self.addEventListener('fetch', (/** FetchEvent event */ event) => {
    const request = event.request;

    // only intercept SSE requests
    if (request.headers.get('Accept') === 'text/event-stream') {
        const url = new URL(request.url);
        if (url.pathname.trim() === '/icingaweb2/notifications/daemon') {
            // SSE request towards the daemon. This intercepts the requests and returns a dummy stream
            const stream = self.worker.getProxyStream(url, event.clientId);
            event.respondWith(new Response(
                stream,
                {
                    headers: {
                        'Content-Type': 'text/event-stream; charset=utf-8',
                        'Transfer-Encoding': 'chunked',
                        'Connection': 'keep-alive'
                    }
                }
            ));
        }
    }
});
