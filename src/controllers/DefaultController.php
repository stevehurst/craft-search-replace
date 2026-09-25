<?php

/**
 * DefaultController.php
 * --------------------
 *
 * Handles the Find & Resave utility's replace/resave form: validates the
 * request and pushes a ReplaceJob onto the queue for the selected elements.
 *
 * @since 1.0.0
 */

namespace foundbrand\findreplace\controllers;

use Craft;
use craft\helpers\Queue;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use foundbrand\findreplace\jobs\ReplaceJob;
use foundbrand\findreplace\utilities\FindResave;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class DefaultController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('utility:' . FindResave::id());

        return true;
    }

    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $find = (string)$request->getRequiredBodyParam('find');
        $mode = $request->getRequiredBodyParam('mode');
        $replace = $mode === 'replace' ? (string)$request->getBodyParam('replace', '') : null;
        $includeDrafts = (bool)$request->getBodyParam('drafts');

        if ($find === '') {
            throw new BadRequestHttpException('Find text is required.');
        }

        if (!in_array($mode, ['replace', 'resave'], true)) {
            throw new BadRequestHttpException('Invalid mode.');
        }

        $targets = array_values(array_filter(
            (array)$request->getBodyParam('targets', []),
            fn($target) => is_string($target) && preg_match('/^\d+:\d+$/', $target),
        ));

        $returnUrl = UrlHelper::cpUrl('utilities/' . FindResave::id(), array_filter([
            'find' => $find,
            'replace' => $replace,
            'drafts' => $includeDrafts ? 1 : null,
        ], fn($value) => $value !== null && $value !== ''));

        if (empty($targets)) {
            $this->setFailFlash(Craft::t('find-replace', 'Select at least one element.'));
            return $this->redirect($returnUrl);
        }

        Queue::push(new ReplaceJob([
            'find' => $find,
            'replace' => $replace,
            'targets' => $targets,
        ]));

        $this->setSuccessFlash(Craft::t('find-replace', '{count, plural, =1{1 element} other{# elements}} queued for {action}.', [
            'count' => count($targets),
            'action' => $replace === null ? Craft::t('find-replace', 'resaving') : Craft::t('find-replace', 'replacing'),
        ]));

        return $this->redirect($returnUrl);
    }
}
