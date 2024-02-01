/* eslint-env serviceworker */

/// <reference lib="webworker" />

class IcingaNotificationsWorker {
    private static _instance: IcingaNotificationsWorker = null;
    private readonly _logger = class Logger {
        private static _prefix: string = 'icinga-notifications-worker';

        private constructor() {
        }

        public static debug(message: string): void {
            this._log(message, 'DEBUG', '#4c4f69');
        }

        public static info(message: string): void {
            this._log(message, 'INFO', '#04a5e5');
        }

        public static warn(message: string): void {
            this._log(message, 'WARN', '#df8e1d');
        }

        public static error(message: string): void {
            this._logError(message, 'ERROR', '#d20f39');
        }

        public static fatal(message: string): void {
            this._logError(message, 'FATAL', '#d20f39');
            throw new Error(message);
        }

        private static _log(payload: string, severity: string, color: string = '#000000'): void {
            self.console.log(
                '[' + this._prefix + ']  %c' + this._ljustify(severity, 5) + '%c - ' + payload,
                'color: ' + color,
                'color: init'
            );
        }

        private static _logError(payload: string | Error, severity: string, color: string = '#000000'): void {
            self.console.error(
                '[' + this._prefix + ']  %c' + this._ljustify(severity, 5) + '%c - ' + payload,
                'color: ' + color,
                'color: init'
            );
        }

        private static _ljustify(text: string, length: number, whitespace: string = ' '): string {
            if (text.length >= length) {
                return text;
            }
            for (let i: number = text.length; i < length; ++i) {
                text += whitespace;
            }
            return text;
        }
    }
    private readonly _eventsource = class InlineEventSource extends EventTarget implements IcingaNotificationsWorker.IEventSource {
        public readonly CONNECTING: 0;
        public readonly OPEN: 1;
        public readonly CLOSED: 2;
        public readonly url: string;
        public readonly withCredentials: boolean;
        public readyState: number;

        private readonly request: Request;
        private readonly abortController: AbortController;
        private lastEventId: string;
        private reconnectInterval: number;
        private readonly canReconnect: boolean;
        private resolvedUrl: string;
        private logger = IcingaNotificationsWorker._instance._logger;

        constructor(url: string, config?: IcingaNotificationsWorker.IEventSourceInit) {
            /** see https://html.spec.whatwg.org/multipage/server-sent-events.html#the-eventsource-interface **/

            // call EventTarget constructor
            super();

            // register public defaults
            this.CONNECTING = 0;
            this.OPEN = 1;
            this.CLOSED = 2;
            this.url = url;
            this.resolvedUrl = undefined;
            this.withCredentials = config ? config.withCredentials : false;

            // register private defaults
            this.abortController = new AbortController();
            this.lastEventId = '';
            this.reconnectInterval = 2500;
            this.canReconnect = true;

            // register event listeners
            this.addEventListener('open', (evt: Event): void => {
                if (this.onopen) {
                    this.onopen(evt);
                }
            });
            this.addEventListener('message', (evt: MessageEvent): void => {
                if (this.onmessage) {
                    this.onmessage(evt);
                }
            });
            this.addEventListener('error', (evt: Event | ErrorEvent): void => {
                if (this.onerror) {
                    this.onerror(evt);
                }
            });

            // create request
            this.request = new Request(
                this.url,
                {
                    mode: 'cors',
                    credentials: this.withCredentials ? 'include' : 'same-origin',
                    headers: {
                        'Accept': 'text/event-stream'
                    },
                    cache: 'no-store',
                    redirect: 'follow',
                    referrerPolicy: 'no-referrer',
                    signal: this.abortController.signal
                }
            );

            // try to connect to event source
            this.connect().then();
        }

        public onopen(evt: Event): void {
        }

        public onmessage(evt: MessageEvent): void {
        }

        public onerror(evt: Event | ErrorEvent): void {
        }

        public close(): void {
            this.readyState = this.CLOSED;
            this.abortController.abort();
        }

        private async connect(): Promise<void> {
            this.readyState = this.CONNECTING;

            // prepare request
            if (this.lastEventId) {
                this.request.headers.set('Last-Event-ID', this.lastEventId);
            }
            // fetch request
            let response: Response;
            try {
                response = await fetch(this.request);
                response = await this.handleRequest(response);

                // request seems valid, setting readyState to open
                this.readyState = this.OPEN;
                this.dispatchEvent(new Event('open'));

                // continuous parsing of the event stream
                await this.processEventStream(response.body);

                // the stream closed down, check whether a retry is needed
                if (this.canReconnect) {
                    self.setTimeout((): void => {
                        this.connect().then();
                    }, this.reconnectInterval);
                }
            } catch (e: unknown) {
                // received an error while handling the event-stream
                if (this.abortController.signal.aborted) {
                    // client closed down connection, this is intended behaviour and not an error
                    return;
                }

                this.readyState = this.CLOSED;
                const error: Error = e as Error;

                this.dispatchEvent(new ErrorEvent('error', {
                    error: error,
                    message: error.message
                }));
                this.logger.fatal('[InlineEventSource] ' + error.message);
            }
        }

        private static normalizeToLF(str: string): string {
            return str.replace(/\r\n|\r/g, '\n');
        }

        private async processEventStream(body: ReadableStream<Uint8Array>): Promise<void> {
            self.console.log('Parsing payload');
            const reader: ReadableStreamDefaultReader = body.getReader();
            const decoder: TextDecoder = new TextDecoder('utf-8');

            await this.read(reader, decoder);
            return
        }

        private async read(reader: ReadableStreamDefaultReader, decoder: TextDecoder): Promise<void> {
            const ret: ReadableStreamReadResult<any> = await reader.read();
            if (ret.done) {
                return;
            }

            const chunk: string = InlineEventSource.normalizeToLF(decoder.decode(ret.value, {stream: true}));
            this.parseChunk(chunk);
            await this.read(reader, decoder);
            return;
        }

        private parseChunk(chunk: string): void {
            // https://html.spec.whatwg.org/multipage/server-sent-events.html#event-stream-interpretation
            let lines: string[] = chunk.split('\n');
            let eventBuffer: string = '';
            let dataBuffer: string = '';
            let lastEventIdBuffer: string = '';

            for (let i: number = 0; i < lines.length; ++i) {
                let line: string = lines[i];

                let colonIndex: number = line.indexOf(':');
                if (line.length === 0) {
                    // empty line, dispatch event
                    // https://html.spec.whatwg.org/multipage/server-sent-events.html#dispatchMessage
                    if (lastEventIdBuffer !== '' && (lastEventIdBuffer !== this.lastEventId)) {
                        // got a new event identifier, replacing the last event identifier on the EventSource object
                        this.lastEventId = lastEventIdBuffer;
                    }
                    if (dataBuffer === '') {
                        // received no data
                        eventBuffer = '';
                        return;
                    } else if (dataBuffer[dataBuffer.length - 1] === '\n') {
                        // removing last \n if the line ends with it
                        dataBuffer = dataBuffer.substring(0, dataBuffer.length - 1);
                    }
                    let event: MessageEvent = new MessageEvent(
                        eventBuffer === '' ? 'message' : eventBuffer,
                        {
                            data: dataBuffer,
                            origin: this.resolvedUrl,
                            lastEventId: this.lastEventId
                        }
                    );
                    if (this.readyState !== this.CLOSED) {
                        this.dispatchEvent(event);
                        // clear the buffers as we might receive multiple events in the same chunk
                        eventBuffer = '';
                        dataBuffer = '';
                        lastEventIdBuffer = '';
                    }
                } else if (colonIndex === 0) {
                    // ignore the line as it's a comment
                    continue;
                } else {
                    if (colonIndex !== -1) {
                        let field: { name: string, value: string } = {
                            name: line.substring(0, colonIndex),
                            value: line[0] === ' ' ? line.substring(colonIndex + 3) : line.substring(colonIndex + 2)
                        }

                        // process field
                        switch (field.name) {
                            case 'event':
                                eventBuffer = field.value;
                                break;
                            case 'data':
                                dataBuffer += field.value + '\n';
                                break;
                            case 'id':
                                lastEventIdBuffer = field.value !== '' ? field.value : lastEventIdBuffer;
                                break;
                            case 'retry':
                                let retry: number = parseInt(field.value);
                                if (isNaN(retry) === false) {
                                    this.reconnectInterval = retry;
                                }
                                break;
                            default:
                                // ignore other fields
                                break;
                        }
                    }
                }
            }
        }

        private async handleRequest(response: Response): Promise<Response> {
            return new Promise<Response>((resolve, reject): void => {
                this.resolvedUrl = response.url;
                if (response.status >= 200 && response.status < 300) {
                    const contentType: string = response.headers.get('Content-Type');
                    if (contentType.startsWith('text/event-stream') === false) {
                        reject(new Error('Invalid content-type. Expected "text/event-stream", got "' + contentType + '"'));
                    }
                    resolve(response);
                } else {
                    reject(new Error('Http error: ' + response.status + ' | ' + response.statusText));
                }
            });
        }
    }

    private _connections: IcingaNotificationsWorker.BrowserConnection[] = [];

    private _periodicCleanup: number;

    private constructor() {
        this._logger.info('spawned.');
        this.load();
    }

    public static instance(): IcingaNotificationsWorker {
        if (this._instance === null) {
            this._instance = new IcingaNotificationsWorker();
        }
        return this._instance;
    }

    private load(): void {
        this._logger.debug("loading.");

        // register service worker
        self.addEventListener('install', (evt: ExtendableEvent): void => {
            this._logger.debug('Install event triggered.');
            if (self instanceof ServiceWorkerGlobalScope) {
                if (!('EventSource' in self)) {
                    this._logger.warn('EventSource not found in a service worker context. Applying polyfill.');
                    // @ts-ignore
                    self.EventSource = this._eventsource;
                }
            }
        });
        self.addEventListener('activate', (evt: ExtendableEvent): void => {
            this._logger.debug('Activate event triggered.');
            if (self instanceof ServiceWorkerGlobalScope) {
                self.clients.claim().then(() => this._logger.debug('Clients claimed.'));
            }
        });
        self.addEventListener('fetch', (evt: FetchEvent): void => {
            const request: Request = evt.request;

            // intercept SSE requests
            if (request.headers.get('Accept') === 'text/event-stream') {
                const url: URL = new URL(request.url);
                // only intercept daemon requests
                if (url.pathname.trim() === '/icingaweb2/notifications/daemon') {
                    // return dummy stream
                    let proxyConnection: IcingaNotificationsWorker.BrowserTab | false = this.resolveTab(url, evt.clientId);
                    if (proxyConnection) {
                        evt.respondWith(new Response(
                            proxyConnection.proxyStream,
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
            }
        });
        self.addEventListener('notificationclick', (evt: NotificationEvent): void => {
            if (self instanceof ServiceWorkerGlobalScope) {
                if (!('action' in evt)) {
                    // @ts-ignore
                    self.clients.openWindow(evt.notification.data.url).then();
                } else if (evt.action === 'viewIncident') {
                    self.clients.openWindow(evt.notification.data.url).then();
                } else if (evt.action === 'dismiss') {
                    evt.notification.close();
                }
            }
        })

        this._periodicCleanup = setInterval((): void => {
            this.periodicCleanup();
        }, 3000);

        this._logger.debug("loaded.");
    }

    private unload(): void {
        this._logger.debug("unloading.");

        clearInterval(this._periodicCleanup);

        this._logger.debug("unloaded.");
    }

    public reload(): void {
        this.unload();
        this.load();
    }

    private resolveTab(url: URL, clientId: string): IcingaNotificationsWorker.BrowserTab | false {
        const urlSanitized: string = url.pathname.trim();
        const urlCode: string = IcingaNotificationsWorker.jooat(urlSanitized).toString(16).toUpperCase();
        // check if connection already exists
        if (!(urlCode in this._connections)) {
            // connection to this specific url doesn't exist yet; adding it
            try {
                this._logger.debug("Opening event source '" + urlSanitized + "'.");
                this._connections[urlCode] = new IcingaNotificationsWorker.BrowserConnection(urlSanitized);
            } catch (e: unknown) {
                const error: Error = e as Error;
                this._logger.fatal(error.message);
                return false;
            }
        }
        // check if this tab is already assigned to the connection
        if (!(clientId in this._connections[urlCode].tabs)) {
            // tab doesn't exist yet; adding it
            this._connections[urlCode].tabs[clientId] = new IcingaNotificationsWorker.BrowserTab(clientId);
        }
        // return tab
        return this._connections[urlCode].tabs[clientId];
    }

    private periodicCleanup(): void {
        // check active event sources for the amount of active tabs
        let ixConnection: string;
        for (ixConnection in this._connections) {
            let connection: IcingaNotificationsWorker.BrowserConnection = this._connections[ixConnection];

            // check if there are any active tabs
            let ixTab: string;
            for (ixTab in connection.tabs) {
                let tab: IcingaNotificationsWorker.BrowserTab = connection.tabs[ixTab];
                if (tab.isClosed) {
                    // no more reader attached to this tab, removing it from the connection object
                    delete connection.tabs[ixTab];
                }
            }

            // check if the event source can be closed, as there are no more tabs listening to it
            if (Object.keys(connection.tabs).length === 0) {
                this._logger.debug("Closing event source '" + connection.url + "' as no more tabs are using it.");
                connection.eventsource.close();
                delete this._connections[ixConnection];
            }
        }
    }

    private static jooat(input: string): number {
        // jenkins one at a time, rewritten from http://www.burtleburtle.net/bob/hash/doobs.html#one
        let hash: number, i: number;

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
}

module IcingaNotificationsWorker {
    export interface IEventSource extends EventTarget {
        /** from spec: https://html.spec.whatwg.org/multipage/server-sent-events.html#the-eventsource-interface **/
        readonly url: string;
        readonly withCredentials: boolean;

        readonly CONNECTING: 0;
        readonly OPEN: 1;
        readonly CLOSED: 2;
        readyState: number;

        onopen?: (evt: Event) => void;
        onmessage?: (evt: MessageEvent) => void;
        onerror?: (evt: Event | ErrorEvent) => void;
        close?: () => void;
    }

    export interface IEventSourceInit {
        withCredentials?: boolean;
    }

    export interface IEventSourceMessage {
        id: string;
        event: string;
        data: string;
        retry?: number;
    }

    export class BrowserTab {
        private readonly _identifier: string;
        private readonly _proxyStream: ReadableStream;
        private _isClosed: boolean;

        constructor(identifier: string) {
            let that = this;
            this._isClosed = false;
            this._identifier = identifier;
            this._proxyStream = new ReadableStream({
                start(controller: ReadableStreamDefaultController): void {
                    this.repeatingTask = setInterval((): void => {
                        that.keepalive(controller);
                    }, 10000);
                },
                cancel(): void {
                    that._isClosed = true;
                    clearInterval(this.repeatingTask);
                }
            });
        }

        get identifier(): string {
            return this._identifier;
        }

        get proxyStream(): ReadableStream {
            return this._proxyStream;
        }

        get isClosed(): boolean {
            return this._isClosed;
        }

        private keepalive(controller: ReadableStreamDefaultController): void {
            controller.enqueue(new TextEncoder().encode(':\n\n'));
        }
    }

    export class BrowserConnection {
        private readonly _url: string;
        private readonly _eventsource: EventSource;
        private _tabs: BrowserTab[];

        constructor(url: string) {
            this._url = url;
            this._tabs = [];
            this._eventsource = new EventSource(url, {withCredentials: true});
            this._eventsource.onmessage = (evt: MessageEvent): void => {
                self.console.log("Received a message: " + evt.data);
            }
            this._eventsource.addEventListener('icinga2.notification', (evt: MessageEvent): void => {
                self.console.log("Received '" + evt.type + "' from '" + evt.origin + "' with data: " + evt.data.toString());
                try {
                    let data: any = JSON.parse(evt.data);
                    let title: string = '';
                    let severity: string = this.parseSeverity(data.payload.severity);
                    if (data.payload.service !== '') {
                        title += "'" + data.payload.service + "' on ";
                    }
                    title += "'" + data.payload.host + "'";
                    // @ts-ignore
                    self.registration.showNotification(
                        title,
                        {
                            icon: 'icinga-notifications-severity-' + severity + '.webp',
                            body: title + ' changed to severity ' + severity,
                            data: {
                                url:
                                    this._url.substring(0, this._url.lastIndexOf('/'))
                                    + '/incident?id='
                                    + data.payload.incident_id
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
                } catch (e: unknown) {
                    const error: Error = e as Error;
                    self.console.log(error);
                }
            });
        }

        get url(): string {
            return this._url;
        }

        get eventsource(): EventSource {
            return this._eventsource;
        }

        get tabs(): IcingaNotificationsWorker.BrowserTab[] {
            return this._tabs;
        }

        set tabs(value: IcingaNotificationsWorker.BrowserTab[]) {
            this._tabs = value;
        }

        private parseSeverity(severity: string): string {
            switch (severity) {
                case 'ok':
                    return 'ok';
                case 'warn':
                    return 'warning';
                case 'crit':
                    return 'critical';
                default:
                    return 'unknown';
            }
        }
    }
}

export default IcingaNotificationsWorker.instance();
