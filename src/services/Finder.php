<?php

/**
 * Finder.php
 * --------------------
 *
 * Searches the stored content of every element (titles and all custom
 * field values in `elements_sites.content`) and maps each hit back to the
 * element and the field layout field it belongs to.
 *
 * Matching is literal and case-sensitive, the same as the replace step.
 * Relation fields (Entries, Assets, etc.) aren't stored in the content
 * column, so they can't be searched. Matrix fields are covered because each
 * nested entry is searched as its own element.
 *
 * @since 1.0.0
 */

namespace foundbrand\findreplace\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\errors\FieldNotFoundException;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Html;
use craft\helpers\Json;
use yii\base\Component;

class Finder extends Component
{
    /**
     * Pseudo field key used for element titles.
     */
    public const TITLE_KEY = '__title__';

    /**
     * Finds elements whose title or field content contains the text.
     *
     * @param string $needle The text to find
     * @param bool $includeDrafts Whether to include drafts (revisions are always skipped)
     * @param int $limit Maximum number of element/site rows to return
     * @return array{results: array<int, array<string, mixed>>, truncated: bool}
     */
    public function find(string $needle, bool $includeDrafts = false, int $limit = 500): array
    {
        if ($needle === '') {
            return ['results' => [], 'truncated' => false];
        }

        $results = [];
        $rows = $this->matchingRows($needle, $includeDrafts)->limit($limit + 1)->all();
        $truncated = count($rows) > $limit;

        foreach (array_slice($rows, 0, $limit) as $row) {
            $element = $this->loadElement((int)$row['elementId'], (int)$row['siteId'], $row['type']);

            if ($element === null) {
                continue;
            }

            $fields = $this->matchingFields($element, $row['title'], $this->decodeContent($row['content']), $needle);

            if (empty($fields)) {
                continue;
            }

            $owner = $element instanceof NestedElementInterface ? $element->getOwner() : null;

            $results[] = [
                'id' => $element->id,
                'siteId' => $element->siteId,
                'key' => $element->id . ':' . $element->siteId,
                'typeName' => $element::displayName(),
                'label' => $element->getUiLabel(),
                'ownerLabel' => $owner?->getUiLabel(),
                'cpEditUrl' => $element->getCpEditUrl() ?? $owner?->getCpEditUrl(),
                'isDraft' => $element->getIsDraft(),
                'site' => Craft::$app->getSites()->getSiteById($element->siteId)?->name,
                'fields' => $fields,
            ];
        }

        return ['results' => $results, 'truncated' => $truncated];
    }

    /**
     * Returns a query for the `elements_sites` rows that contain the text.
     */
    public function matchingRows(string $needle, bool $includeDrafts = false): Query
    {
        $db = Craft::$app->getDb();
        $content = $db->getIsPgsql() ? 'CAST([[es.content]] AS TEXT)' : 'es.content';

        // Stored JSON escapes quotes and backslashes, so also look for the escaped form
        $escaped = substr(Json::encode($needle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);

        $contentMatch = ['or', ['like', $content, $needle]];
        if ($escaped !== $needle) {
            $contentMatch[] = ['like', $content, $escaped];
        }

        $query = (new Query())
            ->select(['es.elementId', 'es.siteId', 'es.title', 'es.content', 'e.type'])
            ->from(['es' => Table::ELEMENTS_SITES])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[es.elementId]]')
            ->where([
                'e.revisionId' => null,
                'e.dateDeleted' => null,
            ])
            ->andWhere(['or', ['like', 'es.title', $needle], $contentMatch])
            ->orderBy(['e.type' => SORT_ASC, 'es.elementId' => SORT_ASC, 'es.siteId' => SORT_ASC]);

        if (!$includeDrafts) {
            $query->andWhere(['e.draftId' => null]);
        }

        return $query;
    }

    /**
     * Loads an element (including drafts and disabled elements) for a site.
     */
    public function loadElement(int $elementId, int $siteId, ?string $type = null): ?ElementInterface
    {
        $type ??= Craft::$app->getElements()->getElementTypeById($elementId);

        if ($type === null || !class_exists($type)) {
            return null;
        }

        return Craft::$app->getElements()->getElementById($elementId, $type, $siteId, [
            'status' => null,
            'drafts' => null,
            'provisionalDrafts' => null,
        ]);
    }

    /**
     * Returns the stored content for an element on a site, keyed by layout element UID.
     *
     * @return array{title: ?string, content: array<string, mixed>}|null
     */
    public function storedContent(int $elementId, int $siteId): ?array
    {
        $row = (new Query())
            ->select(['title', 'content'])
            ->from(Table::ELEMENTS_SITES)
            ->where(['elementId' => $elementId, 'siteId' => $siteId])
            ->one();

        if ($row === null) {
            return null;
        }

        return [
            'title' => $row['title'],
            'content' => $this->decodeContent($row['content']),
        ];
    }

    /**
     * Maps a stored content key (a field layout element UID) to the field it belongs to,
     * with the layout's handle and label overrides applied.
     */
    public function fieldForKey(ElementInterface $element, string $uid): ?FieldInterface
    {
        $layoutElement = $element->getFieldLayout()?->getElementByUid($uid);

        if (!$layoutElement instanceof CustomField) {
            return null;
        }

        try {
            return $layoutElement->getField();
        } catch (FieldNotFoundException) {
            // The field was deleted but its content is still stored
            return null;
        }
    }

    /**
     * Returns whether a stored value (string or nested array) contains the text.
     */
    public function valueContains(mixed $value, string $needle): bool
    {
        if (is_string($value)) {
            return str_contains($value, $needle);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->valueContains($item, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Replaces the text in a stored value, recursing into arrays.
     * Only values are changed; array keys are left alone.
     */
    public function replaceInValue(mixed $value, string $find, string $replace): mixed
    {
        if (is_string($value)) {
            return str_replace($find, $replace, $value);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->replaceInValue($item, $find, $replace);
            }
        }

        return $value;
    }

    /**
     * Returns the fields on an element whose stored value contains the text.
     *
     * @param array<string, mixed> $content
     * @return array<int, array{key: string, name: string, handle: ?string, snippets: string[]}>
     */
    private function matchingFields(ElementInterface $element, ?string $title, array $content, string $needle): array
    {
        $fields = [];

        if ($title !== null && str_contains($title, $needle)) {
            $fields[] = [
                'key' => self::TITLE_KEY,
                'name' => Craft::t('app', 'Title'),
                'handle' => 'title',
                'snippets' => $this->snippets($title, $needle),
            ];
        }

        foreach ($content as $uid => $value) {
            if (!$this->valueContains($value, $needle)) {
                continue;
            }

            $field = $this->fieldForKey($element, (string)$uid);

            $fields[] = [
                'key' => (string)$uid,
                'name' => $field?->name ?? Craft::t('find-replace', 'Unknown field (not in this element’s layout)'),
                'handle' => $field?->handle,
                'snippets' => $this->snippets($value, $needle),
            ];
        }

        return $fields;
    }

    /**
     * Builds HTML-encoded snippets around each match, with the match wrapped in <mark>.
     *
     * @return string[]
     */
    private function snippets(mixed $value, string $needle, int $radius = 60, int $max = 3): array
    {
        $snippets = [];

        foreach ($this->stringLeaves($value) as $string) {
            $offset = 0;

            while (count($snippets) < $max && ($pos = mb_strpos($string, $needle, $offset)) !== false) {
                $start = max(0, $pos - $radius);
                $end = min(mb_strlen($string), $pos + mb_strlen($needle) + $radius);

                $snippets[] =
                    ($start > 0 ? '…' : '') .
                    Html::encode(mb_substr($string, $start, $pos - $start)) .
                    '<mark>' . Html::encode($needle) . '</mark>' .
                    Html::encode(mb_substr($string, $pos + mb_strlen($needle), $end - $pos - mb_strlen($needle))) .
                    ($end < mb_strlen($string) ? '…' : '');

                $offset = $pos + mb_strlen($needle);
            }

            if (count($snippets) >= $max) {
                break;
            }
        }

        return $snippets;
    }

    /**
     * @return string[]
     */
    private function stringLeaves(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            array_push($strings, ...$this->stringLeaves($item));
        }

        return $strings;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContent(mixed $content): array
    {
        if (is_string($content)) {
            $content = Json::decodeIfJson($content);
        }

        return is_array($content) ? $content : [];
    }
}
