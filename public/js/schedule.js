(function (Icinga) {

    "use strict";

    try {
        var Sortable = require('icinga/icinga-php-library/vendor/Sortable');
    } catch (e) {
        console.warn('Unable to provide Drag&Drop in the schedule detail. Libraries not available:', e);
        return;
    }

    class NotificationsSchedule extends Icinga.EventListener {
        constructor(icinga)
        {
            super(icinga);

            this.on('rendered', '#main > .container', this.onRendered, this);
            this.on('end', '#notifications-schedule .sidebar', this.onDrop, this);
            this.on('mouseenter', '#notifications-schedule .entry', this.onEntryHover, this);
            this.on('mouseleave', '#notifications-schedule .entry', this.onEntryLeave, this);
        }

        /**
         * Make the sidebar sortable and add drag&drop support.
         *
         * @param event The event object.
         */
        onRendered(event)
        {
            if (event.target !== event.currentTarget) {
                return; // Nested containers are not of interest
            }

            const schedule = event.target.querySelector('#notifications-schedule');
            if (! schedule) {
                return;
            }

            const sideBar = schedule.querySelector('.sidebar');
            if (! sideBar) {
                event.data.self.logger.error('Unable to find sidebar in schedule detail.');

                return;
            }

            Sortable.create(sideBar, {
                scroll: true,
                direction: 'vertical',
                draggable: '.rotation-name',
                handle: '.rotation-name > i[data-drag-initiator]'
            });
        }

        /**
         * Handle drop event on the sidebar.
         *
         * @param event The event object.
         */
        onDrop(event)
        {
            event = event.originalEvent;
            if (event.to === event.from && event.newIndex === event.oldIndex) {
                // The user dropped the rotation at its previous position
                return;
            }

            const nextRow = event.item.nextSibling;

            let newPriority;
            if (event.oldIndex > event.newIndex) {
                // The rotation was moved up
                newPriority = Number(nextRow.querySelector(':scope > form').priority.value);
            } else {
                // The rotation was moved down
                if (nextRow.matches('.rotation-name')) {
                    newPriority = Number(nextRow.querySelector(':scope > form').priority.value) + 1;
                } else {
                    newPriority = '0';
                }
            }

            const form = event.item.querySelector(':scope > form');
            form.priority.value = newPriority;
            form.requestSubmit();
        }

        /**
         * Handle hover (`mouseenter`) event on schedule entries.
         *
         * @param event The mouse event object.
         */
        onEntryHover(event)
        {
            event.data.self.handleEntryHover(event, true);
        }

        /**
         * Handle hover (`mouseleave`) event on schedule entries.
         *
         * @param event The mouse event object.
         */
        onEntryLeave(event)
        {
            event.data.self.handleEntryHover(event);
        }

        /**
         * Handle hover (`mouseenter`|`mouseleave`) events on schedule entries.
         *
         * @param event The mouse event object.
         * @param {boolean} isHovered Whether the entry is hovered.
         */
        handleEntryHover(event, isHovered = false) {
            const entry = event.currentTarget;
            const overlay = entry.parentElement;
            const grid = overlay.previousSibling;
            const sideBar = grid.previousSibling;

            let relatedElements;
            if ('rotationPosition' in entry.dataset) {
                relatedElements = Array.from(
                    grid.querySelectorAll('[data-y-position="' + entry.dataset.rotationPosition + '"]')
                );

                relatedElements.push(sideBar.childNodes[Number(entry.dataset.rotationPosition)]);
            } else {
                relatedElements = overlay.querySelectorAll(
                    '[data-rotation-position="' + entry.dataset.entryPosition + '"]'
                );
            }

            if (isHovered) {
                relatedElements.forEach((relatedElement) => {
                    relatedElement.classList.add('highlighted');
                });
            } else {
                relatedElements.forEach((relatedElement) => {
                    relatedElement.classList.remove('highlighted');
                });
            }
        }
    }

    Icinga.Behaviors = Icinga.Behaviors || {};

    Icinga.Behaviors.NotificationsSchedule = NotificationsSchedule;
})(Icinga);
