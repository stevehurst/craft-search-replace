<?php

/**
 * DefaultController.php
 * --------------------
 *
 * Search and Replace control panel section.
 *
 * - index: the search page. Searching is a GET request
 *   (`?find=…&field=…&drafts=1`), so a search can be bookmarked or reloaded.
 * - run: validates the replace/resave form and pushes a ReplaceJob onto the
 *   queue for the selected elements.
 *
 * @since 1.0.0
 */

namespace foundbrand\searchreplace\controllers;

use Craft;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use foundbrand\searchreplace\jobs\ReplaceJob;
use foundbrand\searchreplace\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class DefaultController extends Controller
{
    public const RESULT_LIMIT = 500;

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('accessPlugin-search-replace');

        return true;
    }

    public function actionIndex(): Response
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

        return $this->renderTemplate('search-replace/_index.twig', [
            'find' => $find,
            'replace' => $replace,
            'includeDrafts' => $includeDrafts,
            'scope' => $scope,
            'fieldOptions' => $finder->fieldOptions(),
            'search' => $find !== '' ? $finder->find($find, $includeDrafts, self::RESULT_LIMIT, $scope) : null,
            'limit' => self::RESULT_LIMIT,
            'lastRun' => Craft::$app->getCache()->get(ReplaceJob::SUMMARY_CACHE_KEY) ?: null,
        ]);
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $find = (string)$request->getRequiredBodyParam('find');
        $mode = $request->getRequiredBodyParam('mode');
        $replace = $mode === 'replace' ? (string)$request->getBodyParam('replace', '') : null;
        $includeDrafts = (bool)$request->getBodyParam('drafts');
        $scope = (string)$request->getBodyParam('field', '') ?: null;

        if ($find === '') {
            throw new BadRequestHttpException('Find text is required.');
        }

        if (!in_array($mode, ['replace', 'resave'], true)) {
            throw new BadRequestHttpException('Invalid mode.');
        }

        if (!Plugin::getInstance()->getFinder()->isValidScope($scope)) {
            throw new BadRequestHttpException('Invalid field.');
        }

        $targets = array_values(array_filter(
            (array)$request->getBodyParam('targets', []),
            fn($target) => is_string($target) && preg_match('/^\d+:\d+$/', $target),
        ));

        $returnUrl = UrlHelper::cpUrl('search-replace', array_filter([
            'find' => $find,
            'field' => $scope,
            'replace' => $replace,
            'drafts' => $includeDrafts ? 1 : null,
        ], fn($value) => $value !== null && $value !== ''));

        if (empty($targets)) {
            $this->setFailFlash(Craft::t('search-replace', 'Select at least one element.'));
            return $this->redirect($returnUrl);
        }

        Queue::push(new ReplaceJob([
            'find' => $find,
            'replace' => $replace,
            'scope' => $scope,
            'targets' => $targets,
        ]));

        $this->setSuccessFlash(Craft::t('search-replace', '{count, plural, =1{1 element} other{# elements}} queued for {action}.', [
            'count' => count($targets),
            'action' => $replace === null ? Craft::t('search-replace', 'resaving') : Craft::t('search-replace', 'replacing'),
        ]));

        return $this->redirect($returnUrl);
    }
}
