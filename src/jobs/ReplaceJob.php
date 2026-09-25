<?php

/**
 * ReplaceJob.php
 * --------------------
 *
 * Queue job that replaces text in the selected elements and resaves them
 * through Craft's element service, or just resaves them when no replacement
 * is given.
 *
 * When a field scope is set, only that field (or the title) is changed.
 * Each element is re-read from the database when the job runs, so the
 * replacement is applied to its current content, not the content from when
 * the search was run. Replacements create entry revisions (with revision
 * notes); plain resaves don't.
 *
 * A summary of the last run is cached and shown on the Search and Replace page.
 *
 * @since 1.0.0
 */

namespace foundbrand\findreplace\jobs;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\i18n\Translation;
use craft\queue\BaseJob;
use foundbrand\findreplace\Plugin;
use foundbrand\findreplace\services\Finder;
use Throwable;

class ReplaceJob extends BaseJob
{
    public const SUMMARY_CACHE_KEY = 'find-replace:last-run';

    /**
     * @var string The text to find
     */
    public string $find = '';

    /**
     * @var string|null The replacement text, or null to resave without replacing
     */
    public ?string $replace = null;

    /**
     * @var string|null Null for all fields, Finder::TITLE_KEY for titles, or a field UID
     */
    public ?string $scope = null;

    /**
     * @var string[] Targets as "elementId:siteId"
     */
    public array $targets = [];

    public function execute($queue): void
    {
        $finder = Plugin::getInstance()->getFinder();
        $elements = Craft::$app->getElements();
        $total = count($this->targets);
        $saved = 0;
        $unchanged = 0;
        $failed = [];

        foreach (array_values($this->targets) as $i => $target) {
            $this->setProgress($queue, $i / max($total, 1), Translation::prep('find-replace', 'Saving {step} of {total}', [
                'step' => $i + 1,
                'total' => $total,
            ]));

            [$elementId, $siteId] = array_map('intval', explode(':', $target, 2) + [1 => 0]);
            $element = $finder->loadElement($elementId, $siteId);

            if ($element === null) {
                $failed[] = ['target' => $target, 'label' => $target, 'error' => 'Element not found.'];
                continue;
            }

            if ($this->replace !== null) {
                if (!$this->applyReplacement($finder, $element)) {
                    $unchanged++;
                    continue;
                }

                $element->setRevisionNotes(sprintf('Search and Replace: replaced “%s” with “%s”', $this->find, $this->replace));
            } else {
                $element->resaving = true;
            }

            // Match `craft resave/*`: don't let unrelated validation rules block the save
            $element->setScenario(Element::SCENARIO_ESSENTIALS);

            try {
                if ($elements->saveElement($element)) {
                    $saved++;
                    continue;
                }

                $error = implode(' ', $element->getErrorSummary(true)) ?: 'Validation failed.';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }

            $failed[] = ['target' => $target, 'label' => $element->getUiLabel(), 'error' => $error];
            Craft::warning(sprintf('Couldn’t save element %s: %s', $target, $error), Plugin::LOG_CATEGORY);
        }

        Craft::$app->getCache()->set(self::SUMMARY_CACHE_KEY, [
            'date' => time(),
            'find' => $this->find,
            'replace' => $this->replace,
            'total' => $total,
            'saved' => $saved,
            'unchanged' => $unchanged,
            'failed' => array_slice($failed, 0, 50),
            'failedCount' => count($failed),
        ], 60 * 60 * 24 * 7);
    }

    /**
     * Applies the replacement to the element's title and stored field values.
     *
     * @return bool Whether anything changed
     */
    private function applyReplacement(Finder $finder, ElementInterface $element): bool
    {
        $stored = $finder->storedContent($element->id, $element->siteId);

        if ($stored === null) {
            return false;
        }

        $changed = false;

        if ($finder->titleInScope($this->scope) && $stored['title'] !== null && str_contains($stored['title'], $this->find)) {
            $element->title = str_replace($this->find, $this->replace, $stored['title']);
            $changed = true;
        }

        foreach ($stored['content'] as $uid => $value) {
            if (!$finder->keyInScope((string)$uid, $this->scope) || !$finder->valueContains($value, $this->find)) {
                continue;
            }

            $handle = $finder->fieldForKey($element, (string)$uid)?->handle;

            if ($handle === null) {
                continue;
            }

            // Stored values are what each field normalizes from when loading, so set the replaced value back as-is
            $element->setFieldValue($handle, $finder->replaceInValue($value, $this->find, $this->replace));
            $changed = true;
        }

        return $changed;
    }

    protected function defaultDescription(): ?string
    {
        if ($this->replace === null) {
            return Translation::prep('find-replace', 'Resaving elements containing “{find}”', [
                'find' => $this->find,
            ]);
        }

        return Translation::prep('find-replace', 'Replacing “{find}” with “{replace}”', [
            'find' => $this->find,
            'replace' => $this->replace,
        ]);
    }
}
