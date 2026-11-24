<?php

declare(strict_types=1);

namespace ledgehq\craftledge\jobs;

use Craft;
use craft\queue\BaseJob;
use ledgehq\craftledge\acquire\AcquireException;
use ledgehq\craftledge\acquire\BackupResultBuilder;
use ledgehq\craftledge\acquire\BundleBuilder;
use ledgehq\craftledge\acquire\BundleResult;
use ledgehq\craftledge\acquire\BundleUploader;
use ledgehq\craftledge\acquire\CallbackClient;
use ledgehq\craftledge\acquire\CallbackPayloads;
use ledgehq\craftledge\acquire\ManifestBuilder;
use ledgehq\craftledge\acquire\Preflight;
use ledgehq\craftledge\acquire\TempWorkspace;
use ledgehq\craftledge\acquire\UploadAttempt;
use ledgehq\craftledge\acquire\UploadDiagnostics;
use ledgehq\craftledge\Ledge;
use ledgehq\craftledge\services\AcquireService;
use Throwable;
use yii\db\Query;

class AcquireBundleJob extends BaseJob
{
    /**
     * Ledge's upload URL is valid for 60 minutes from when it sent the
     * command; no upload retry starts later than this after acceptance.
     */
    public const UPLOAD_DEADLINE_SECONDS = 55 * 60;

    public string $runId = '';
    public string $uploadUrl = '';
    public string $bundlePubkey = '';
    public string $callbackUrl = '';
    public string $callbackToken = '';
    public string $profile = 'full';

    /**
     * Unix time the command was accepted; 0 for jobs queued by a version that
     * did not record it, which then upload without a deadline.
     */
    public int $acceptedAt = 0;

    public function execute($queue): void
    {
        $service = Ledge::getInstance()->acquire;
        $callbacks = $service->createCallbackClient($this->callbackUrl, $this->callbackToken);
        $workspace = new TempWorkspace(Craft::$app->getPath()->getRuntimePath() . '/ledge', $this->runId);
        $step = 'preflight';
        $dumpDurationMs = null;
        $encryptDurationMs = null;
        $uploadDurationMs = null;

        try {
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $callbacks->send(CallbackClient::EVENT_STARTED, $step);

            $workspace->create();
            $this->runPreflight($workspace);
            $this->setProgress($queue, 0.2);
            $callbacks->send(CallbackClient::EVENT_PROGRESS, $step);

            $step = 'dump';
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $dumpPath = $workspace->path('dump.sql');
            $dumpStart = microtime(true);
            $this->createDump($dumpPath);
            $dumpDurationMs = (int)round((microtime(true) - $dumpStart) * 1000);
            $this->setProgress($queue, 0.5);
            $callbacks->send(CallbackClient::EVENT_PROGRESS, $step);

            $step = 'manifest';
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $entries = $this->buildEntries($workspace, $dumpPath);
            $this->setProgress($queue, 0.6);
            $callbacks->send(CallbackClient::EVENT_PROGRESS, $step);

            $step = 'encrypt';
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $encryptStart = microtime(true);
            $result = (new BundleBuilder())->build(
                $entries,
                $this->getBundlePubkeyBytes(),
                Ledge::getInstance()->getSettings()->acquireMaxBundleBytes,
                $workspace,
            );
            $encryptDurationMs = (int)round((microtime(true) - $encryptStart) * 1000);
            $this->setProgress($queue, 0.8);
            $callbacks->send(CallbackClient::EVENT_PROGRESS, $step);

            $step = 'upload';
            $service->transition($this->runId, AcquireService::STATUS_RUNNING, $step);
            $upload = (new BundleUploader(Craft::createGuzzleClient()))->upload(
                $result->path,
                $this->uploadUrl,
                function(UploadAttempt $attempt, UploadDiagnostics $soFar) use ($callbacks): void {
                    $this->reportRetry($callbacks, $attempt, $soFar);
                },
                $this->acceptedAt > 0 ? (float)($this->acceptedAt + self::UPLOAD_DEADLINE_SECONDS) : null,
                [$this->callbackToken, $this->bundlePubkey],
            );
            $uploadDurationMs = $upload->durationMs;
            $this->setProgress($queue, 1.0);

            $service->transition($this->runId, AcquireService::STATUS_COMPLETED, $step, null, $result->size, $result->sha256);
            $callbacks->send(CallbackClient::EVENT_COMPLETED, $step, CallbackPayloads::completed(
                $this->completedDetail($result, $dumpDurationMs),
                ['encrypt_duration_ms' => $encryptDurationMs, 'upload_duration_ms' => $uploadDurationMs],
                memory_get_peak_usage(true),
                $upload,
            ));
        } catch (Throwable $e) {
            $reason = $e instanceof AcquireException ? $e->reason : 'unexpected_error';
            $detail = $e instanceof AcquireException ? $e->detail : $e->getMessage();
            $upload = $e instanceof AcquireException && ($e->context['upload'] ?? null) instanceof UploadDiagnostics ? $e->context['upload'] : null;

            if ($upload !== null) {
                $uploadDurationMs = $upload->durationMs;
            }

            Craft::error("Ledge acquisition {$this->runId} failed at {$step}: {$e->getMessage()}", __METHOD__);
            $service->transition($this->runId, AcquireService::STATUS_FAILED, $step, $reason);
            $callbacks->send(CallbackClient::EVENT_FAILED, $step, CallbackPayloads::failed(
                $reason,
                $detail,
                [
                    'dump_duration_ms' => $dumpDurationMs,
                    'encrypt_duration_ms' => $encryptDurationMs,
                    'upload_duration_ms' => $uploadDurationMs,
                ],
                memory_get_peak_usage(true),
                $upload,
            ));

            throw $e;
        } finally {
            $workspace->cleanup();
        }
    }

    protected function defaultDescription(): ?string
    {
        $label = $this->profile === 'backup' ? 'backup' : 'acquisition';

        return "Ledge {$label} {$this->runId}";
    }

    private function runPreflight(TempWorkspace $workspace): void
    {
        $db = Craft::$app->getDb();
        $binary = $db->getIsMysql() ? 'mysqldump' : 'pg_dump';
        $backupCommand = Craft::$app->getConfig()->getGeneral()->backupCommand;

        $preflight = new Preflight(
            function() use ($binary, $backupCommand): bool {
                if ($backupCommand === false) {
                    return false;
                }

                if ($backupCommand !== null) {
                    return true;
                }

                $resolved = shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null');

                return is_string($resolved) && trim($resolved) !== '';
            },
            fn(string $dir): float|false => @disk_free_space($dir),
            $this->estimateDumpBytes(),
            $workspace->getDir(),
            extension_loaded('sodium'),
        );

        $preflight->run();
    }

    private function createDump(string $dumpPath): void
    {
        try {
            Craft::$app->getDb()->backupTo($dumpPath);
        } catch (Throwable $e) {
            throw new AcquireException('dump_failed', 500, $e->getMessage());
        }
    }

    /**
     * Assembles the archive contents for the bundle.
     *
     * The `backup` profile is deliberately the database and nothing else — no
     * env manifest, no metadata sidecar, no project config. Craft's project
     * config and schema version already live inside the dump (projectconfig /
     * info tables), so a bare dump still restores to a working site while
     * carrying zero secret-bearing sidecars.
     *
     * The `full` (update-run) profile additionally ships the crawl manifest
     * (env vars by denylist, facts, URIs); that manifest is never built for
     * backups.
     *
     * @return array<string, string> archive name => absolute source path
     */
    private function buildEntries(TempWorkspace $workspace, string $dumpPath): array
    {
        if ($this->profile === 'backup') {
            return ['dump.sql' => $dumpPath];
        }

        $manifestPath = $workspace->path('manifest.json');
        $denylist = Ledge::getInstance()->getSettings()->getAcquireEnvDenylist();
        $manifest = (new ManifestBuilder($denylist))->build($this->collectEnv(), $this->collectFacts());
        $this->writeJson($manifestPath, $manifest);

        return ['dump.sql' => $dumpPath, 'manifest.json' => $manifestPath];
    }

    private function completedDetail(BundleResult $result, int $dumpDurationMs): array
    {
        if ($this->profile === 'backup') {
            return (new BackupResultBuilder())->payload($result, $dumpDurationMs, $this->countTables());
        }

        return [
            'size' => $result->size,
            'sha256' => $result->sha256,
        ];
    }

    private function reportRetry(CallbackClient $callbacks, UploadAttempt $attempt, UploadDiagnostics $soFar): void
    {
        $payload = CallbackPayloads::uploadRetried($soFar);

        Craft::warning("Ledge acquisition {$this->runId} upload {$payload['reason']}; retrying in {$attempt->retryInMs}ms", __METHOD__);
        $callbacks->send(CallbackClient::EVENT_UPLOAD_RETRIED, 'upload', $payload);
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false || file_put_contents($path, $json) === false) {
            throw new AcquireException('tmp_not_writable', 500, $path);
        }
    }

    private function countTables(): ?int
    {
        try {
            $db = Craft::$app->getDb();

            if ($db->getIsMysql()) {
                $count = (new Query())
                    ->from('information_schema.TABLES')
                    ->where('TABLE_SCHEMA = DATABASE()')
                    ->count('*', $db);
            } else {
                $count = (new Query())
                    ->from('information_schema.tables')
                    ->where(['table_schema' => 'public'])
                    ->count('*', $db);
            }

            return is_numeric($count) ? (int)$count : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function estimateDumpBytes(): ?int
    {
        try {
            $db = Craft::$app->getDb();

            if ($db->getIsMysql()) {
                $sum = (new Query())
                    ->from('information_schema.TABLES')
                    ->where('TABLE_SCHEMA = DATABASE()')
                    ->sum('DATA_LENGTH + INDEX_LENGTH', $db);
            } else {
                $sum = $db->createCommand('SELECT pg_database_size(current_database())')->queryScalar();
            }

            return is_numeric($sum) ? (int)$sum : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function getBundlePubkeyBytes(): string
    {
        $bytes = base64_decode($this->bundlePubkey, true);

        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_BOX_PUBLICKEYBYTES) {
            throw new AcquireException('encrypt_failed', 500, 'invalid bundle_pubkey');
        }

        return $bytes;
    }

    private function collectEnv(): array
    {
        return array_merge(getenv(), $_ENV);
    }

    private function collectFacts(): array
    {
        $db = Craft::$app->getDb();
        $extensions = get_loaded_extensions();
        sort($extensions);

        $plugins = [];
        foreach (Craft::$app->getPlugins()->getAllPluginInfo() as $handle => $info) {
            if (!empty($info['isInstalled'])) {
                $plugins[$handle] = $info['version'] ?? null;
            }
        }

        $facts = [
            'php' => [
                'version' => PHP_VERSION,
                'extensions' => $extensions,
            ],
            'database' => [
                'driver' => $db->getDriverName(),
                'version' => $db->getSchema()->getServerVersion(),
            ],
            'craft' => [
                'version' => Craft::$app->getVersion(),
                'edition' => Craft::$app->edition->name,
            ],
            'configVersion' => Craft::$app->getInfo()->configVersion,
            'plugins' => $plugins,
        ];

        // Omit `sites`/`uris` entirely on catastrophic failure so the runner
        // falls back to its own discovery rather than trusting an empty list.
        try {
            $facts['sites'] = Ledge::getInstance()->acquire->getSites();
        } catch (Throwable $e) {
            Craft::warning('Ledge site enumeration failed: ' . $e->getMessage(), __METHOD__);
        }

        try {
            $facts['uris'] = Ledge::getInstance()->acquire->getPublicUris();
        } catch (Throwable $e) {
            Craft::warning('Ledge URI enumeration failed: ' . $e->getMessage(), __METHOD__);
        }

        return $facts;
    }
}
