// SPDX-FileCopyrightText: 2026 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

(function (Icinga, $) {

    "use strict";

    /**
     * Reload incident quick actions once the job they requested has been processed
     */
    class NotificationsQuickAction extends Icinga.EventListener
    {
        /** @type {number} Polling interval in milliseconds */
        static POLL_INTERVAL = 1000;

        constructor(icinga)
        {
            super(icinga);

            this.on('rendered', '#main > .container', this.onRendered, this);
        }

        /**
         * Start watching the pending quick actions of a rendered container
         *
         * @param {Event} event
         */
        onRendered(event)
        {
            if (event.target !== event.currentTarget) {
                return;
            }

            const self = event.data.self;
            event.target.querySelectorAll('[data-notifications-job]').forEach(element => self.watchJob(element));
        }

        /**
         * Poll the state of the job the given element is waiting for
         *
         * @param {HTMLElement} element
         */
        watchJob(element)
        {
            setTimeout(async () => {
                if (! element.isConnected) {
                    return;
                }

                let data;
                try {
                    const response = await fetch(element.dataset.notificationsJob, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (! response.ok) {
                        throw new Error(`Unexpected response status ${response.status}`);
                    }

                    data = (await response.json()).data;
                } catch (e) {
                    this.icinga.logger.error('Failed to fetch the state of a quick action:', e);

                    return;
                }

                if (! data.finished) {
                    this.watchJob(element);

                    return;
                }

                if (data.message) {
                    this.icinga.loader.createNotice('error', data.message);
                }

                const $container = $(element.closest('.container'));
                this.icinga.loader.loadUrl(
                    $container.data('icingaUrl'),
                    $container,
                    undefined,
                    undefined,
                    undefined,
                    true
                );
            }, NotificationsQuickAction.POLL_INTERVAL);
        }
    }

    Icinga.Behaviors = Icinga.Behaviors || {};

    Icinga.Behaviors.NotificationsQuickAction = NotificationsQuickAction;

})(Icinga, jQuery);
