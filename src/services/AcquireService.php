<?php

declare(strict_types=1);

namespace ledgehq\craftledge\services;

use Craft;
use craft\base\ElementInterface;
use craft\queue\Queue;
use ledgehq\craftledge\acquire\AcquireCommand;
use ledgehq\craftledge\acquire\AcquireException;
use ledgehq\craftledge\acquire\CallbackClient;
use ledgehq\craftledge\acquire\CommandVerifier;
use ledgehq\craftledge\acquire\HostAllowlist;
use ledgehq\craftledge\acquire\KeyResolver;
use ledgehq\craftledge\acquire\SiteManifestBuilder;
use ledgehq\craftledge\acquire\UriManifestBuilder;
use ledgehq\craftledge\jobs\AcquireBundleJob;
use ledgehq\craftledge\Ledge;
use Throwable;
use yii\base\Component;

class AcquireService extends Component
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Run status is kept in the cache long enough for Ledge's watchdog to poll
    // after a run finishes; the replay-guard key only lives for the command's
    // validity window (a replay is impossible once the signature expires).
    private const STATUS_TTL = 86400;
    private const URI_BATCH_SIZE = 100;

    public function accept(string $rawBody): AcquireCommand
    {
        $settings = Ledge::getInstance()->getSettings();

        $resolver = new KeyResolver(
            Craft::createGuzzleClient(),
            Craft::$app->getCache(),
            $settings->getLedgeBaseUrl(),
        );

        $allowlist = new HostAllowlist(
            $settings->getAcquireAllowedHosts(),
            Craft::$app->getConfig()->getGeneral()->devMode,
        );

        $command = (new CommandVerifier($resolver->resolve(...), $allowlist))->verify($rawBody);

        $this->reserveRun($command);

        try {
            $this->queueJob($command, $settings->acquireJobTtr, $settings->acquireJobPriority);
        } catch (Throwable $e) {
            Craft::$app->getCache()->delete(self::seenKey($command->runId));
            Craft::$app->getCache()->delete(self::statusKey($command->runId));
            throw new AcquireException('queue_unavailable', 500, $e->getMessage());
        }

        $this->createCallbackClient($command->callbackUrl, $command->callbackToken)
            ->send(CallbackClient::EVENT_ACCEPTED, 'queued');

        return $command;
    }

    public function getStatus(string $runId): ?array
    {
        $record = Craft::$app->getCache()->get(self::statusKey($runId));

        return is_array($record) ? $record : null;
    }

    public function transition(
        string $runId,
        string $status,
        ?string $step = null,
        ?string $detail = null,
        ?int $sizeBytes = null,
        ?string $sha256 = null,
    ): void {
        $record = Craft::$app->getCache()->get(self::statusKey($runId));

        if (!is_array($record)) {
            // Status entry evicted (e.g. cache flush mid-run); rebuild it so the
            // watchdog still sees live state rather than a 404.
            $record = [
                'run_id' => $runId,
                'profile' => null,
                'size' => null,
                'sha256' => null,
                'dateCreated' => self::now(),
            ];
        }

        $record['status'] = $status;
        $record['step'] = $step;
        $record['detail'] = $detail;
        $record['dateUpdated'] = self::now();

        if ($sizeBytes !== null) {
            $record['size'] = $sizeBytes;
        }

        if ($sha256 !== null) {
            $record['sha256'] = $sha256;
        }

        $this->writeStatus($runId, $record);
    }

    public function createCallbackClient(string $callbackUrl, string $token): CallbackClient
    {
        return new CallbackClient(Craft::createGuzzleClient(), $callbackUrl, $token);
    }

    /**
     * Enumerates the crawlable, publicly-resolvable URL of every URL-enabled
     * element across all registered element types (entries, categories,
     * Commerce products, any custom type) for every site, via Craft's element
     * API so it stays DB-engine agnostic. Each type's query defaults already
     * restrict to enabled, non-trashed, non-draft/revision elements;
     * `uri(':notempty:')` keeps only those that resolve to a front-end URL.
     * `section` is the section handle for entries and null for element types
     * not organized into sections (categories, products, etc.).
     *
     * @return list<array{uri: string, site: string, section: string|null}>
     */
    public function getPublicUris(): array
    {
        $siteHandles = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteHandles[$site->id] = $site->handle;
        }

        $sectionHandles = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $sectionHandles[$section->id] = $section->handle;
        }

        $rows = [];

        foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
            /** @var class-string<ElementInterface> $elementType */
            try {
                $query = $elementType::find()
                    ->uri(':notempty:')
                    ->site('*')
                    ->asArray();

                // Batch so we never materialize a whole element type's rows at
                // once — keeps enumeration memory bounded on large sites.
                foreach ($query->each(self::URI_BATCH_SIZE) as $element) {
                    $siteId = $element['siteId'] ?? null;
                    $sectionId = $element['sectionId'] ?? null;
                    $rows[] = [
                        'uri' => $element['uri'] ?? null,
                        'siteHandle' => $siteId !== null ? ($siteHandles[$siteId] ?? null) : null,
                        'section' => $sectionId !== null ? ($sectionHandles[$sectionId] ?? null) : null,
                    ];
                }
            } catch (Throwable $e) {
                Craft::warning("Ledge URI enumeration skipped {$elementType}: {$e->getMessage()}", __METHOD__);
                continue;
            }
        }

        return (new UriManifestBuilder())->build($rows);
    }

    /**
     * Every Craft site with its resolved base URL, so a consumer of `uris`
     * can map each entry's `site` handle to a host (and path prefix) instead
     * of assuming everything lives under the primary site's URL. Aliases and
     * env vars in `baseUrl` are expanded via Craft's site model.
     *
     * @return list<array{handle: string, primary: bool, enabled: bool, language: string|null, baseUrl: string|null, host: string|null, path: string}>
     */
    public function getSites(): array
    {
        $rows = [];

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            $baseUrl = null;

            try {
                $baseUrl = $site->getBaseUrl();
            } catch (Throwable $e) {
                Craft::warning("Ledge could not resolve base URL for site {$site->handle}: {$e->getMessage()}", __METHOD__);
            }

            $rows[] = [
                'handle' => $site->handle,
                'primary' => (bool)$site->primary,
                'enabled' => (bool)$site->getEnabled(),
                'language' => $site->language,
                'baseUrl' => $baseUrl,
            ];
        }

        return (new SiteManifestBuilder())->build($rows);
    }

    private function reserveRun(AcquireCommand $command): void
    {
        // Atomic add: fails if this run_id was already seen within its validity
        // window → replay. TTL = seconds until the command expires, since a
        // replay is impossible once the signature's expires_at has passed.
        $ttl = max(1, $command->expiresAt - time());

        if (!Craft::$app->getCache()->add(self::seenKey($command->runId), true, $ttl)) {
            throw new AcquireException('replayed', 409);
        }

        $now = self::now();
        $this->writeStatus($command->runId, [
            'run_id' => $command->runId,
            'status' => self::STATUS_PENDING,
            'step' => 'queued',
            'detail' => null,
            'profile' => $command->profile,
            'size' => null,
            'sha256' => null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
        ]);
    }

    private function writeStatus(string $runId, array $record): void
    {
        Craft::$app->getCache()->set(self::statusKey($runId), $record, self::STATUS_TTL);
    }

    private static function seenKey(string $runId): string
    {
        return "ledge:acquire:seen:{$runId}";
    }

    private static function statusKey(string $runId): string
    {
        return "ledge:acquire:run:{$runId}";
    }

    private static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    private function queueJob(AcquireCommand $command, int $ttr, int $priority): void
    {
        $job = new AcquireBundleJob([
            'runId' => $command->runId,
            'uploadUrl' => $command->uploadUrl,
            'bundlePubkey' => $command->bundlePubkey,
            'callbackUrl' => $command->callbackUrl,
            'callbackToken' => $command->callbackToken,
            'profile' => $command->profile,
        ]);

        $queue = Craft::$app->getQueue();

        if ($queue instanceof Queue) {
            $queue->ttr($ttr)->priority($priority)->push($job);
        } else {
            $queue->push($job);
        }
    }
}
