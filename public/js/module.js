// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

(function (Icinga) {

    "use strict";

    class Notifications {
        /**
         * Constructor
         *
         * @param {Icinga.Module} module
         */
        constructor(module) {
            this.icinga = module.icinga;
            module.on('click', '.show-more[data-no-icinga-ajax] a', this.onLoadMoreClick);
        }

        /**
         * Load more results
         *
         * @param {PointerEvent} event
         *
         * @returns {boolean}
         * @todo This is partly copied from Icinga DB Web. The full implementation,
         *       once moved to ipl-web, should be used instead.
         */
        onLoadMoreClick(event) {
            event.stopPropagation();
            event.preventDefault();

            this.loadMore(event.target);

            return false;
        }

        /**
         * Load additional results and append them to the current list
         *
         * @param {HTMLAnchorElement} anchor
         *
         * @returns {void}
         */
        loadMore(anchor) {
            let showMore = anchor.parentElement;
            let progressTimer = this.icinga.timer.register(() => {
                let label = anchor.innerText;

                let dots = label.substring(label.length - 3);
                if (dots.slice(0, 1) !== '.') {
                    dots = '.  ';
                } else {
                    label = label.slice(0, -3);
                    if (dots === '...') {
                        dots = '.  ';
                    } else if (dots === '.. ') {
                        dots = '...';
                    } else if (dots === '.  ') {
                        dots = '.. ';
                    }
                }

                anchor.innerText = label + dots;
            }, null, 250);

            let url = anchor.getAttribute('href');
            let req = this.icinga.loader.loadUrl(
                // Add showCompact, we don't want controls in paged results
                this.icinga.utils.addUrlFlag(url, 'showCompact'),
                $(showMore.parentElement),
                undefined,
                undefined,
                'append',
                false,
                progressTimer
            );
            req.addToHistory = false;
            req.done(() => {
                showMore.remove();

                // Set data-icinga-url to make it available for Icinga.History.getCurrentState()
                req.$target.closest('.container').data('icingaUrl', url);

                this.icinga.history.replaceCurrentState();
            });
        }
    }

    Icinga.availableModules.notifications = Notifications;

})(Icinga);
