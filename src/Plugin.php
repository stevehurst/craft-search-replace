<?php

/**
 * Plugin.php
 * --------------------
 *
 * Find & Resave plugin for Craft CMS 5.
 *
 * Registers the Find & Resave utility, which searches every element's stored
 * field content, then replaces text and resaves the matching elements
 * through Craft's element service (revisions, search indexes and cache
 * invalidation all keep working).
 *
 * @since 1.0.0
 */

namespace foundbrand\findreplace;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Utilities;
use foundbrand\findreplace\services\Finder;
use foundbrand\findreplace\utilities\FindResave;
use yii\base\Event;

/**
 * @property-read Finder $finder
 */
class Plugin extends BasePlugin
{
    public const LOG_CATEGORY = 'find-replace';

    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'finder' => Finder::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@foundbrand/findreplace', __DIR__);

        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = FindResave::class;
            }
        );
    }

    public function getFinder(): Finder
    {
        /** @var Finder */
        return $this->get('finder');
    }
}
