<?php

// SPDX-FileCopyrightText: 2024 Icinga GmbH <https://icinga.com>
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Icinga\Module\Notifications\Hook;

use Generator;
use ipl\Html\ValidHtml;

/**
 * Base hook to prepare and render objects
 *
 * @deprecated Sources are expected to transmit human readable object names already with each event.
 */
abstract class ObjectsRendererHook
{
    /**
     * Get the object names for the objects using the object ID tags
     *
     * @param array<array<string, string>> $objectIdTags Array of object ID tags of objects belonging to the source
     *
     * @return Generator<array<string, string>, string> Generator for object names with their object ID tags as keys
     */
    abstract public function getObjectNames(array $objectIdTags): Generator;

    /**
     * Get the HTML for the object names for the objects using the object ID tags
     *
     * @param array<array<string, string>> $objectIdTags Array of object ID tags of the objects belonging to the source
     *
     * @return Generator<array<string, string>, ValidHtml> Generator for object name HTMLs with their object ID tags
     *                                                     as keys
     */
    abstract public function getHtmlForObjectNames(array $objectIdTags): Generator;

    /**
     * Get the source type of the objects
     *
     * @return string
     */
    abstract public function getSourceType(): string;

    /**
     * Create the object link for the given object ID tag
     *
     * @param array<string, string> $objectIdTag
     *
     * @return ?ValidHtml Returns null if no object with given tag found
     */
    abstract public function createObjectLink(array $objectIdTag): ?ValidHtml;
}
