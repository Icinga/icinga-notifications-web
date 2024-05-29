<?php

namespace Icinga\Module\Notifications\Widget\TimeGrid;

use ipl\Web\Url;
use Traversable;

interface EntryProvider
{
    /**
     * Get all entries to show on the grid
     *
     * @return Traversable<int, Entry>
     */
    public function getEntries(): Traversable;

    /**
     * Get the URL to use for the given grid step
     *
     * @param GridStep $step A step, as calculated by the grid
     *
     * @return ?Url
     */
    public function getStepUrl(GridStep $step): ?Url;

    /**
     * Get the URL to show any extraneous entries which don't fit onto the given grid step
     *
     * This is called each time an entire day has passed on the grid and a step represents the end of the day, even
     * if there are no extraneous entries to show. Depending on the structure of the grid, and the flow of steps,
     * it might be necessary to conditionally return a URL here, to avoid showing the same URL multiple times.
     *
     * @param GridStep $step A step, as calculated by the grid
     *
     * @return ?Url
     */
    public function getExtraEntryUrl(GridStep $step): ?Url;
}
