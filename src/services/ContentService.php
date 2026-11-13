<?php

declare(strict_types=1);

namespace ledgehq\craftledge\services;

use Craft;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\models\Volume;
use ledgehq\craftledge\content\FilenameTitle;
use ledgehq\craftledge\Ledge;
use Throwable;
use yii\base\Component;
use yii\db\Expression;

/**
 * Content checks for Ledge's accessibility view: image assets with no alt
 * text, and image assets whose title is just the filename.
 *
 * Not a health check: nothing here feeds `/health` or its status.
 *
 * Every image is counted once, in the primary site, whatever the number of
 * sites. Counts run as COUNT queries; filename titles need each title, so
 * they are read in id-keyed batches of a few columns, never as elements.
 */
class ContentService extends Component
{
    public const CACHE_KEY_PREFIX = 'ledge:content:';
    public const ALT_SOURCE_NATIVE = 'native';

    private const BATCH_SIZE = 1000;

    /**
     * The report, from cache when it is younger than `contentCacheTtl`.
     * `generatedAt` is when it was computed, not when it was served.
     *
     * @return array<string, mixed>
     */
    public function getReport(): array
    {
        $settings = Ledge::currentSettings();
        $ttl = $settings->contentCacheTtl;

        if ($ttl <= 0) {
            return $this->buildReport();
        }

        $key = self::CACHE_KEY_PREFIX . md5((string)json_encode([
            $settings->getContentAltField(),
            $settings->getContentVolumes(),
            $settings->contentSampleLimit,
            Ledge::getInstance()->getVersion(),
        ]));

        return Craft::$app->getCache()->getOrSet($key, fn(): array => $this->buildReport(), $ttl);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReport(): array
    {
        $settings = Ledge::currentSettings();
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $limit = $settings->contentSampleLimit;
        $volumes = $this->resolveVolumes($settings->getContentVolumes());
        $altField = $this->resolveAltField($settings->getContentAltField(), $volumes);

        $volumeStats = [];
        $missingAltIds = [];
        $filenameTitleIds = [];

        foreach ($volumes as $volume) {
            $images = Asset::find()
                ->volumeId($volume->id)
                ->kind(Asset::KIND_IMAGE)
                ->siteId($siteId);

            $missingAlt = $this->whereMissingAlt(clone $images, $volume, $altField);
            $missingAltCount = (int)(clone $missingAlt)->count();

            if ($limit > 0 && $missingAltCount > 0) {
                $missingAltIds[] = array_map('intval', (clone $missingAlt)
                    ->orderBy(['elements.id' => SORT_DESC])
                    ->limit($limit)
                    ->ids());
            }

            [$filenameTitleCount, $ids] = $this->scanFilenameTitles($images, $limit);
            $filenameTitleIds[] = $ids;

            $volumeStats[] = [
                'handle' => (string)$volume->handle,
                'name' => (string)$volume->name,
                'total' => (int)(clone $images)->count(),
                'missingAlt' => $missingAltCount,
                'filenameTitles' => $filenameTitleCount,
            ];
        }

        return self::assemble(
            date('c'),
            $altField ?? self::ALT_SOURCE_NATIVE,
            $volumeStats,
            $this->samples(self::newestIds($missingAltIds, $limit), $siteId),
            $this->samples(self::newestIds($filenameTitleIds, $limit), $siteId),
        );
    }

    /**
     * Shapes the response from per-volume counts and the sampled assets.
     *
     * @param array<int, array{handle: string, name: string, total: int, missingAlt: int, filenameTitles: int}> $volumes
     * @param array<int, array<string, mixed>> $missingAltSamples
     * @param array<int, array<string, mixed>> $filenameTitleSamples
     * @return array<string, mixed>
     */
    public static function assemble(
        string $generatedAt,
        string $altSource,
        array $volumes,
        array $missingAltSamples,
        array $filenameTitleSamples,
    ): array {
        return [
            'generatedAt' => $generatedAt,
            'altSource' => $altSource,
            'images' => [
                'total' => array_sum(array_column($volumes, 'total')),
                'missingAlt' => array_sum(array_column($volumes, 'missingAlt')),
                'filenameTitles' => array_sum(array_column($volumes, 'filenameTitles')),
                'volumes' => array_values($volumes),
                'samples' => [
                    'missingAlt' => array_values($missingAltSamples),
                    'filenameTitles' => array_values($filenameTitleSamples),
                ],
            ],
        ];
    }

    /**
     * Merges per-volume id lists and keeps the newest (highest) ids.
     *
     * @param array<int, int[]> $idLists
     * @return int[]
     */
    public static function newestIds(array $idLists, int $limit): array
    {
        if ($limit <= 0 || $idLists === []) {
            return [];
        }

        $ids = array_values(array_unique(array_merge(...$idLists)));
        rsort($ids);

        return array_slice($ids, 0, $limit);
    }

    /**
     * Configured volumes in sort order; unknown handles are logged and
     * skipped, so a list of only unknown handles checks nothing.
     *
     * @param string[]|null $handles
     * @return Volume[]
     */
    private function resolveVolumes(?array $handles): array
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        if ($handles === null) {
            return $volumes;
        }

        $byHandle = [];
        foreach ($volumes as $volume) {
            $byHandle[$volume->handle] = $volume;
        }

        $resolved = [];
        foreach ($handles as $handle) {
            if (isset($byHandle[$handle])) {
                $resolved[$handle] = $byHandle[$handle];
            } else {
                Craft::warning("Ledge contentVolumes: no volume with handle '{$handle}'; skipping it.", __METHOD__);
            }
        }

        return array_values(array_filter($volumes, static fn(Volume $volume): bool => isset($resolved[$volume->handle])));
    }

    /**
     * The configured alt-text field, if at least one checked volume's field
     * layout has it; otherwise logged and null (native alt).
     *
     * @param Volume[] $volumes
     */
    private function resolveAltField(?string $handle, array $volumes): ?string
    {
        if ($handle === null) {
            return null;
        }

        foreach ($volumes as $volume) {
            if ($this->volumeHasField($volume, $handle)) {
                return $handle;
            }
        }

        Craft::warning("Ledge contentAltField: no asset field '{$handle}' in the checked volumes; using native alt text.", __METHOD__);

        return null;
    }

    private function volumeHasField(Volume $volume, string $handle): bool
    {
        try {
            return $volume->getFieldLayout()->getFieldByHandle($handle) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Narrows the query to images with no alt text. A volume whose layout
     * lacks the configured field is judged on native alt instead.
     *
     * Craft 4's native alt is not translatable and lives in `assets.alt`;
     * blank or whitespace is missing.
     */
    private function whereMissingAlt(AssetQuery $query, Volume $volume, ?string $altField): AssetQuery
    {
        if ($altField !== null && $this->volumeHasField($volume, $altField)) {
            $query->{$altField}(':empty:');

            return $query;
        }

        return $query->andWhere(new Expression("TRIM(COALESCE([[assets.alt]], '')) = ''"));
    }

    /**
     * Reads `id, title, filename` newest first in batches and counts the
     * filename-style titles, keeping the first `$limit` ids as samples.
     *
     * @return array{0: int, 1: int[]}
     */
    private function scanFilenameTitles(AssetQuery $images, int $limit): array
    {
        $count = 0;
        $ids = [];
        $lastId = null;

        do {
            $batch = (clone $images)
                ->select(['id' => 'elements.id', 'title' => 'title', 'filename' => 'assets.filename'])
                ->orderBy(['elements.id' => SORT_DESC])
                ->limit(self::BATCH_SIZE)
                ->asArray();

            if ($lastId !== null) {
                $batch->andWhere(['<', 'elements.id', $lastId]);
            }

            $rows = $batch->all();

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];

                if (FilenameTitle::isFilenameStyle((string)($row['title'] ?? ''), (string)($row['filename'] ?? ''))) {
                    $count++;
                    if (count($ids) < $limit) {
                        $ids[] = $lastId;
                    }
                }
            }
        } while (count($rows) === self::BATCH_SIZE);

        return [$count, $ids];
    }

    /**
     * @param int[] $ids
     * @return array<int, array{id: int, filename: string, title: string, volume: string|null, url: string|null, cpEditUrl: string|null}>
     */
    private function samples(array $ids, int $siteId): array
    {
        if ($ids === []) {
            return [];
        }

        $assets = Asset::find()
            ->id($ids)
            ->siteId($siteId)
            ->status(null)
            ->fixedOrder()
            ->all();

        $samples = [];

        foreach ($assets as $asset) {
            $samples[] = [
                'id' => (int)$asset->id,
                'filename' => (string)$asset->getFilename(),
                'title' => (string)$asset->title,
                'volume' => $this->volumeHandle($asset),
                'url' => $this->safely(fn() => $asset->getUrl()),
                'cpEditUrl' => $this->safely(fn() => $asset->getCpEditUrl()),
            ];
        }

        return $samples;
    }

    private function volumeHandle(Asset $asset): ?string
    {
        try {
            return $asset->getVolume()->handle;
        } catch (Throwable) {
            return null;
        }
    }

    private function safely(callable $resolve): ?string
    {
        try {
            $value = $resolve();

            return is_string($value) && $value !== '' ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
