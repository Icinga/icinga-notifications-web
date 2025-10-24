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

        onEntryHover(event)
        {
            const entry = event.currentTarget;
            const overlay = entry.parentElement;
            const grid = overlay.previousSibling;

            let relatedElements;
            if ('rotationPosition' in entry.dataset) {
                relatedElements = grid.querySelectorAll(
                    '[data-y-position="' + entry.dataset.rotationPosition + '"]'
                );
            } else {
                relatedElements = overlay.querySelectorAll(
                    '[data-rotation-position="' + entry.dataset.entryPosition + '"]'
                );
            }

            relatedElements.forEach((relatedElement) => {
                relatedElement.classList.add('highlighted');
            });

            let tooltip = entry.querySelector('.rotation-info')
            if (tooltip) {
                requestAnimationFrame(() => {
                    const rect = tooltip.getBoundingClientRect();
                    const padding = 10;

                    if (rect.right > window.innerWidth - padding) {
                        tooltip.classList.remove('rotation-info');
                        tooltip.classList.add('rotation-info-left');
                    }
                });
            }
        }

        onEntryLeave(event)
        {
            const entry = event.currentTarget;
            const overlay = entry.parentElement;
            const grid = overlay.previousSibling;

            let relatedElements;
            if ('rotationPosition' in entry.dataset) {
                relatedElements = grid.querySelectorAll(
                    '[data-y-position="' + entry.dataset.rotationPosition + '"]'
                );
            } else {
                relatedElements = overlay.querySelectorAll(
                    '[data-rotation-position="' + entry.dataset.entryPosition + '"]'
                );
            }

            relatedElements.forEach((relatedElement) => {
                relatedElement.classList.remove('highlighted');
            });

            let tooltip = entry.querySelector('.rotation-info-left')
            if (tooltip) {
                tooltip.classList.remove('rotation-info-left')
                tooltip.classList.add('rotation-info');
            }
        }
    }

    Icinga.Behaviors = Icinga.Behaviors || {};

    Icinga.Behaviors.NotificationsSchedule = NotificationsSchedule;
})(Icinga);
