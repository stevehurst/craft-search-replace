<?php

/**
 * FindResave.php
 * --------------------
 *
 * The Find & Resave control panel utility (Utilities → Find & Resave).
 *
 * Searching is a GET request on the utility page (`?find=…&field=…&drafts=1`), so a
 * search can be bookmarked or reloaded. The results form posts to
 * `find-replace/default/run`, which queues the replace or resave.
 *
 * Its ID is distinct from Craft's built-in `find-replace` utility so both
 * can be used side by side.
 *
 * @since 1.0.0
 */

namespace foundbrand\findreplace\utilities;

use Craft;
use craft\base\Utility;
use foundbrand\findreplace\jobs\ReplaceJob;
use foundbrand\findreplace\Plugin;

class FindResave extends Utility
{
    public const RESULT_LIMIT = 500;

    public static function displayName(): string
    {
        return Craft::t('find-replace', 'Find & Resave');
    }

    public static function id(): string
    {
        return 'find-resave';
    }

    public static function icon(): ?string
    {
        return 'magnifying-glass';
    }

    public static function contentHtml(): string
    {
        $request = Craft::$app->getRequest();
        $find = (string)$request->getQueryParam('find', '');
        $replace = (string)$request->getQueryParam('replace', '');
        $includeDrafts = (bool)$request->getQueryParam('drafts');
        $finder = Plugin::getInstance()->getFinder();

        $scope = (string)$request->getQueryParam('field', '') ?: null;
        if (!$finder->isValidScope($scope)) {
            $scope = null;
        }

        $search = $find !== ''
            ? $finder->find($find, $includeDrafts, self::RESULT_LIMIT, $scope)
            : null;

        return Craft::$app->getView()->renderTemplate('find-replace/_utility.twig', [
            'utilityId' => self::id(),
            'find' => $find,
            'replace' => $replace,
            'includeDrafts' => $includeDrafts,
            'scope' => $scope,
            'fieldOptions' => $finder->fieldOptions(),
            'search' => $search,
            'limit' => self::RESULT_LIMIT,
            'lastRun' => Craft::$app->getCache()->get(ReplaceJob::SUMMARY_CACHE_KEY) ?: null,
        ]);
    }
}
