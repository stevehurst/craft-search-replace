<?php

/**
 * Plugin.php
 * --------------------
 *
 * Search and Replace plugin for Craft CMS 5.
 *
 * Adds a Search and Replace section to the control panel navigation, which
 * searches every element's stored field content, then replaces text and
 * resaves the matching elements through Craft's element service (revisions,
 * search indexes and cache invalidation all keep working).
 *
 * Access is controlled by Craft's "Access Search and Replace" plugin
 * permission; admins always have access.
 *
 * @since 1.0.0
 */

namespace foundbrand\findreplace;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use foundbrand\findreplace\services\Finder;
use yii\base\Event;

/**
 * @property-read Finder $finder
 */
class Plugin extends BasePlugin
{
    public const LOG_CATEGORY = 'find-replace';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSection = true;

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
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['find-replace'] = 'find-replace/default/index';
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();

        if ($item !== null) {
            $item['label'] = Craft::t('find-replace', 'Search and Replace');
        }

        return $item;
    }

    public function getFinder(): Finder
    {
        /** @var Finder */
        return $this->get('finder');
    }
}
