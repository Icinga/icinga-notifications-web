<?php

/* Icinga Notifications Web | (c) 2024 Icinga GmbH | GPLv2 */

namespace Icinga\Module\Notifications\Hook;

use Exception;
use Generator;
use Icinga\Application\Hook;
use Icinga\Application\Logger;
use Icinga\Module\Notifications\Model\Objects;
use ipl\Html\Attributes;
use ipl\Html\BaseHtmlElement;
use ipl\Html\HtmlElement;
use ipl\Html\Text;
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
     * Array of object names with their corresponding object IDs as keys
     *
     * It has the following structure : ['object ID' => 'object name'].
     *
     * @var array<string, string>
     */
    private static $objectNames = [];

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
     * @param bool $asHtml If true loads object names as HTMLs otherwise as string
     *
     * @return void
     */
    final public static function load(bool $asHtml = true): void
    {
        self::prepare(self::$objectIdTags, $asHtml); // Prepare object names as HTML or string

        self::$objectIdTags = [];
    }

    /**
     * Prepare the objects to be rendered using the given object ID tags for each source
     *
     * The supplied object ID tags must have the following structure:
     * ['object source type' => ['object ID' => [object ID tags]]].
     *
     * @param array<string, array<string, array<string, string>>> $objectIdTags Array of object ID tags for each source
     * @param bool $asHtml When true, object names are prepared as HTML otherwise as string
     *
     * @return void
     */
    private static function prepare(array $objectIdTags, bool $asHtml = true): void
    {
        $idTagToObjectIdMap = [];
        $objectNames = $asHtml ? self::$objectNameHtmls : self::$objectNames;
        foreach ($objectIdTags as $sourceType => $objects) {
            foreach ($objects as $objectId => $tags) {
                if (! isset($objectNames[$objectId])) {
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

                    $objectNamesFromSource = $asHtml
                        ? $hook->getHtmlForObjectNames($objectIDTagsForSource)
                        : $hook->getObjectNames($objectIDTagsForSource);

                    /** @var array $objectIdTag */
                    foreach ($objectNamesFromSource as $objectIdTag => $objectName) {
                        foreach ($idTagToObjectIdMap[$source] as $key => $val) {
                            $diff = array_intersect_assoc($val[1], $objectIdTag);
                            if (count($diff) === count($val[1]) && count($diff) === count($objectIdTag)) {
                                unset($idTagToObjectIdMap[$source][$key]);

                                if ($asHtml) {
                                    $objectName = HtmlElement::create(
                                        'div',
                                        Attributes::create([
                                            'class' => [
                                                'icinga-module',
                                                'module-' . ($source === 'icinga2' ? 'icingadb' : $source)
                                            ]
                                        ]),
                                        $objectName
                                    );
                                }

                                $objectDisplayNames[$val[0]] = $objectName;

                                continue 2;
                            }
                        }
                    }
                } catch (Exception $e) {
                    Logger::error('Failed to load hook %s:', get_class($hook), $e);
                }
            }
        }

        if ($asHtml) {
            self::$objectNameHtmls += $objectDisplayNames;
        } else {
            self::$objectNames += $objectDisplayNames;
        }
    }

    /**
     * Get the object name of the given object
     *
     * If an HTML for the object name is not loaded, it is prepared using object ID tags and the same is returned.
     *
     * @param Objects $obj
     *
     * @return BaseHtmlElement
     */
    final public static function getObjectName(Objects $obj): BaseHtmlElement
    {
        $objId = $obj->id;
        if (! isset(self::$objectNameHtmls[$objId])) {
            self::prepare([$obj->source->type => [$objId => $obj->id_tags]]);
        }

        if (isset(self::$objectNameHtmls[$objId])) {
            return self::$objectNameHtmls[$objId];
        }

        self::$objectNameHtmls[$objId] = new HtmlElement(
            'div',
            null,
            Text::create(self::createObjectNameAsString($obj))
        );

        return self::$objectNameHtmls[$objId];
    }

    /**
     * Get the object name of the given object as string
     *
     * If the object name is not loaded, it is prepared using object ID tags and the same is returned.
     *
     * @param Objects $obj
     * @param bool $prepare If true prepares the object name string from the hook implementation if it is not
     *                      already present in the cache
     *
     * @return string
     */
    final public static function getObjectNameAsString(Objects $obj): string
    {
        $objId = $obj->id;
        if (! isset(self::$objectNames[$objId])) {
            self::prepare([$obj->source->type => [$objId => $obj->id_tags]], false);
        }

        if (isset(self::$objectNames[$objId])) {
            return self::$objectNames[$objId];
        }

        return self::createObjectNameAsString($obj);
    }

    /**
     * Create object name string for the given object
     *
     * @param Objects $obj
     *
     * @return string
     */
    private static function createObjectNameAsString(Objects $obj): string
    {
        $objectTags = [];

        foreach ($obj->id_tags as $tag => $value) {
            $objectTags[] = sprintf('%s=%s', $tag, $value);
        }

        return implode(', ', $objectTags);
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
                $sourceType = $hook->getSourceType();
                if ($object->source->type === $sourceType) {
                    $objectLink = $hook->createObjectLink($object->id_tags);
                    if ($objectLink === null) {
                        break;
                    }

                    return $objectLink->addAttributes([
                        'class' => [
                            'icinga-module',
                            'module-' . ($sourceType === 'icinga2' ? 'icingadb' : $sourceType)
                        ]
                    ]);
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
            Text::create(self::createObjectNameAsString($object)),
            $objUrl->isExternal() ? $objUrl->getAbsoluteUrl() : $objUrl->getRelativeUrl(),
            ['class' => 'subject', 'data-base-target' => '_next']
        );
    }
}
