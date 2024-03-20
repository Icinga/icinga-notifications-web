<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Hook;

use Exception;
use Generator;
use Icinga\Application\Hook;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Model\Objects;
use ipl\Html\HtmlString;
use ipl\Html\ValidHtml;
use ipl\Web\Url;
use ipl\Web\Widget\Link;

/**
 * Base hook to prepare and render objects
 */
abstract class ObjectsRendererHook
{
    /**
     * Array of Object ID tags for each source
     *
     * It has the following structure : ['object source type' => ['object ID' => [object ID tags]]].
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private static $objectIdTags = [];

    /**
     * Array of HTMLs for objects with their corresponding object IDs as keys
     *
     * It has the following structure : ['object ID' => registered HTML for the object name].
     *
     * @var array<string, ValidHtml>
     */
    private static $objectNameHtmls = [];

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
     * @return ValidHtml
     */
    abstract public function createObjectLink(array $objectIdTag): ValidHtml;

    /**
     * Register object ID tags to the cache
     *
     * @param Objects $obj
     *
     * @return void
     */
    final public static function register(Objects $obj): void
    {
        self::$objectIdTags[$obj->source->type][$obj->id] = $obj->id_tags;
    }

    /**
     * Load HTMLs to be rendered for the object names to the cache using the cached objects
     *
     * @return void
     */
    final public static function load(): void
    {
        self::prepare(self::$objectIdTags);

        self::$objectIdTags = [];
    }

    /**
     * Prepare the objects to be rendered using the given object ID tags for each source
     *
     * The supplied object ID tags must have the following structure:
     * ['object source type' => ['object ID' => [object ID tags]]].
     *
     * @param array<string, array<string, array<string, string>>> $objectIdTags Array of object ID tags for each source
     *
     * @return void
     */
    private static function prepare(array $objectIdTags): void
    {
        $idTagToObjectIdMap = [];
        foreach ($objectIdTags as $sourceType => $objects) {
            foreach ($objects as $objectId => $tags) {
                if (! isset(self::$objectNameHtmls[$objectId])) {
                    $idTagToObjectIdMap[$sourceType][] = [$objectId, $tags];
                }
            }
        }

        $objectDisplayNames = [];

        /** @var self $hook */
        foreach (Hook::all('Notifications\\ObjectsRenderer') as $hook) {
            $source = $hook->getSourceType();

            if (isset($idTagToObjectIdMap[$source])) {
                try {
                    $objectIDTagsForSource = array_map(
                        function ($object) {
                            return $object[1];
                        },
                        $idTagToObjectIdMap[$source]
                    );

                    /** @var array $objectIdTag */
                    foreach ($hook->getHtmlForObjectNames($objectIDTagsForSource) as $objectIdTag => $validHtml) {
                        foreach ($idTagToObjectIdMap[$source] as $key => $val) {
                            $diff = array_intersect_assoc($val[1], $objectIdTag);
                            if (count($diff) === count($val[1])) {
                                unset($idTagToObjectIdMap[$key]);
                                $objectDisplayNames[$val[0]] = $validHtml;

                                continue 2;
                            }
                        }
                    }
                } catch (Exception $e) {
                    Logger::error('Failed to load hook %s:', get_class($hook), $e);
                }
            }
        }

        self::$objectNameHtmls += $objectDisplayNames;
    }

    /**
     * Get the object name of the given object
     *
     * If an HTML for the object name is not loaded, it is prepared using object ID tags and the same is returned.
     *
     * @param Objects $obj
     *
     * @return ValidHtml
     */
    final public static function getObjectName(Objects $obj): ValidHtml
    {
        $objId = $obj->id;
        if (! isset(self::$objectNameHtmls[$objId])) {
            self::prepare([$obj->source->type => [$objId => $obj->id_tags]]);
        }

        if (isset(self::$objectNameHtmls[$objId])) {
            return self::$objectNameHtmls[$objId];
        }

        $objectTags = [];

        foreach ($obj->id_tags as $tag => $value) {
            $objectTags[] = sprintf('%s=%s', $tag, $value);
        }

        self::$objectNameHtmls[$objId] = new HtmlString(implode(', ', $objectTags));

        return self::$objectNameHtmls[$objId];
    }

    /**
     * Render object link for the given object
     *
     * @param Objects $object
     *
     * @return ?ValidHtml
     */
    final public static function renderObjectLink(Objects $object): ?ValidHtml
    {
        /** @var self $hook */
        foreach (Hook::all('Notifications\\ObjectsRenderer') as $hook) {
            try {
                if ($object->source->type === $hook->getSourceType()) {
                    return $hook->createObjectLink($object->id_tags);
                }
            } catch (Exception $e) {
                Logger::error('Failed to load hook %s:', get_class($hook), $e);
            }
        }

        // Fallback, if the hook is not implemented
        if (! $object->url) {
            return null;
        }

        $objUrl = Url::fromPath($object->url);

        return new Link(
            self::getObjectName($object),
            $objUrl->isExternal() ? $objUrl->getAbsoluteUrl() : $objUrl->getRelativeUrl(),
            ['class' => 'subject', 'data-base-target' => '_next']
        );
    }
}
