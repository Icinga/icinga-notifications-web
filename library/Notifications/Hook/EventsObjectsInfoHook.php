<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Hook;

use Exception;
use Icinga\Application\Hook;
use Icinga\Application\Logger;
use ipl\Html\ValidHtml;

abstract class EventsObjectsInfoHook
{
    /**
     * Prepare object display names for the objects using the object ID tags
     *
     * @param array<string, array<string, string>> $objectIdTags
     *
     * @return array<string, ValidHtml>
     */
    abstract public function renderObjectDisplayNames(array $objectIdTags): array;

    /**
     * Get the source of the objects
     *
     * @return string
     */
    abstract public function getSource(): string;

    /**
     * Get the object list item widget for the given object ID tag
     *
     * @param array<string, string> $objectIdTag
     *
     * @return ValidHtml
     */
    abstract public function getHtmlForObject(array $objectIdTag): ValidHtml;

    /**
     * Get object display names for the objects using the object ID tags
     *
     * @param array<string, array<string, array<string, string>>> $objectIdTags
     *
     * @return array<string, ValidHtml>
     */
    final public static function getObjectsDisplayNames(array $objectIdTags): array
    {
        $objectDisplayNames = [];
        foreach (Hook::all('Notifications\\EventsObjectsInfo') as $hook) {
            /** @var self $hook */
            try {
                $objectDisplayNames = array_merge(
                    $objectDisplayNames,
                    $hook->renderObjectDisplayNames($objectIdTags[$hook->getSource()])
                );
            } catch (Exception $e) {
                Logger::error('Failed to load hook %s:', get_class($hook), $e);
            }
        }

        return $objectDisplayNames;
    }

    /**
     * Get the object list item widget for the object using the object ID tag
     *
     * @param string $source
     * @param array<string, string> $objectIdTag
     *
     * @return ?ValidHtml
     */
    final public static function getObjectListItemWidget(string $source, array $objectIdTag): ?ValidHtml
    {
        $objectListItemWidget = null;
        foreach (Hook::all('Notifications\\EventsObjectsInfo') as $hook) {
            /** @var self $hook */
            try {
                if ($source === $hook->getSource()) {
                    $objectListItemWidget = $hook->getHtmlForObject($objectIdTag);
                }
            } catch (Exception $e) {
                Logger::error('Failed to load hook %s:', get_class($hook), $e);
            }
        }

        return $objectListItemWidget;
    }
}
