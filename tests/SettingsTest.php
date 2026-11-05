<?php

declare(strict_types=1);

namespace ledgehq\craftledge\tests;

use ledgehq\craftledge\models\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    /** @var string[] */
    private array $envSet = [];

    protected function tearDown(): void
    {
        foreach ($this->envSet as $name) {
            putenv($name);
            unset($_SERVER[$name], $_ENV[$name]);
        }
        $this->envSet = [];
    }

    public function testDefaults(): void
    {
        $s = Settings::fromConfig([]);

        $this->assertSame(80, $s->diskDegradedAt);
        $this->assertSame(90, $s->diskUnhealthyAt);
        $this->assertNull($s->diskMinFreeBytes);
        $this->assertSame(75, $s->memoryDegradedAt);
        $this->assertSame(1, $s->queueFailedUnhealthyAt);
        $this->assertNull($s->queueFailedDegradedAt);
        $this->assertSame('healthy', $s->updateAvailableStatus);
        $this->assertTrue($s->isDependenciesEnabled());
        $this->assertFalse($s->allowQueryKey);
        $this->assertSame('X-Ledge-Key', $s->keyHeader);
        $this->assertSame('key', $s->queryKeyParam);
    }

    public function testConfigKeyWinsOverEnv(): void
    {
        $this->env('LEDGE_DISK_UNHEALTHY_AT', '70');

        $s = Settings::fromConfig(['diskUnhealthyAt' => 95]);

        $this->assertSame(95, $s->diskUnhealthyAt);
    }

    public function testEnvFallbackWhenKeyAbsent(): void
    {
        $this->env('LEDGE_DISK_UNHEALTHY_AT', '70');
        $this->env('LEDGE_MEMORY_DEGRADED_AT', '60');
        $this->env('LEDGE_DEPENDENCIES_ENABLED', 'false');
        $this->env('LEDGE_DEV_MODE_STATUS', 'healthy');
        $this->env('LEDGE_ALLOW_QUERY_KEY', 'true');
        $this->env('LEDGE_KEY_HEADER', 'X-Monitor-Token');
        $this->env('LEDGE_QUERY_KEY_PARAM', 'token');
        $this->env('LEDGE_IGNORED_ENV_VARS', 'FOO, BAR,,BAZ');

        $s = Settings::fromConfig([]);

        $this->assertSame(70, $s->diskUnhealthyAt);
        $this->assertSame(60, $s->memoryDegradedAt);
        $this->assertFalse($s->isDependenciesEnabled());
        $this->assertSame('healthy', $s->devModeStatus);
        $this->assertTrue($s->allowQueryKey);
        $this->assertSame('X-Monitor-Token', $s->keyHeader);
        $this->assertSame('token', $s->queryKeyParam);
        $this->assertSame(['FOO', 'BAR', 'BAZ'], $s->ignoredEnvVars);
    }

    public function testEmptyOrOffEnvDisablesNullableTier(): void
    {
        $this->env('LEDGE_DISK_DEGRADED_AT', '');
        $this->env('LEDGE_MEMORY_DEGRADED_AT', 'off');

        $s = Settings::fromConfig([]);

        $this->assertNull($s->diskDegradedAt);
        $this->assertNull($s->memoryDegradedAt);
    }

    public function testNullInConfigDisablesTier(): void
    {
        $s = Settings::fromConfig(['diskDegradedAt' => null, 'queueStaleUnhealthyAt' => null]);

        $this->assertNull($s->diskDegradedAt);
        $this->assertNull($s->queueStaleUnhealthyAt);
    }

    public function testDollarVarReferenceInConfig(): void
    {
        $this->env('MY_DISK_LIMIT', '85');

        $s = Settings::fromConfig(['diskUnhealthyAt' => '$MY_DISK_LIMIT']);

        $this->assertSame(85, $s->diskUnhealthyAt);
    }

    public function testRenamedKeysUseNewEnvNames(): void
    {
        $this->env('LEDGE_QUEUE_STALE_AFTER', '900');
        $this->env('LEDGE_FAILED_LOGINS_WINDOW', '7200');

        $s = Settings::fromConfig([]);

        $this->assertSame(900, $s->queueStaleAfter);
        $this->assertSame(7200, $s->failedLoginsWindow);
    }

    public function testDisabledChecksEnv(): void
    {
        $this->env('LEDGE_DISABLED_CHECKS', 'formie, freeform');

        $s = Settings::fromConfig([]);

        $this->assertFalse($s->enabledChecks['formie']);
        $this->assertFalse($s->enabledChecks['freeform']);
        $this->assertTrue($s->enabledChecks['disk']);
    }

    public function testExplicitEnabledChecksIgnoresDisabledChecksEnv(): void
    {
        $this->env('LEDGE_DISABLED_CHECKS', 'formie');

        $s = Settings::fromConfig(['enabledChecks' => ['disk' => false]]);

        $this->assertFalse($s->enabledChecks['disk']);
        $this->assertArrayNotHasKey('formie', $s->enabledChecks);
    }

    public function testInvalidValuesFallBackToDefaults(): void
    {
        $s = Settings::fromConfig([
            'updateAvailableStatus' => 'broken',
            'diskUnhealthyAt' => 500,
            'memoryDegradedAt' => 60,
        ]);

        $this->assertSame('healthy', $s->updateAvailableStatus);
        $this->assertSame(90, $s->diskUnhealthyAt);
        $this->assertSame(60, $s->memoryDegradedAt);
    }

    public function testAcquireJobPriority(): void
    {
        $this->assertSame(15, Settings::fromConfig([])->acquireJobPriority);
        $this->assertSame(10, Settings::fromConfig(['acquireJobPriority' => 10])->acquireJobPriority);
        $this->assertSame(15, Settings::fromConfig(['acquireJobPriority' => 0])->acquireJobPriority);

        $this->env('LEDGE_ACQUIRE_JOB_PRIORITY', '2048');
        $this->assertSame(2048, Settings::fromConfig([])->acquireJobPriority);
        $this->assertSame(10, Settings::fromConfig(['acquireJobPriority' => 10])->acquireJobPriority);
    }

    public function testUnparseableBoolKeepsDefault(): void
    {
        $s = Settings::fromConfig(['dependenciesEnabled' => 'enabled', 'urisEnabled' => 'yes']);

        $this->assertTrue($s->isDependenciesEnabled());
        $this->assertTrue($s->isUrisEnabled());
    }

    public function testUnresolvedVarReferenceForBoolKeepsDefault(): void
    {
        $s = Settings::fromConfig(['dependenciesEnabled' => '$LEDGE_DEFINITELY_UNSET_VAR']);

        $this->assertTrue($s->isDependenciesEnabled());
    }

    public function testExplicitFalseInConfigBeatsEnvTrueForToggles(): void
    {
        $this->env('LEDGE_ACQUIRE_ENABLED', 'true');
        $this->env('LEDGE_URIS_ENABLED', 'true');

        $s = Settings::fromConfig(['acquireEnabled' => false, 'urisEnabled' => false]);

        $this->assertFalse($s->isAcquireEnabled());
        $this->assertFalse($s->isUrisEnabled());
    }

    public function testEnvEnablesTogglesWhenConfigSilent(): void
    {
        $this->env('LEDGE_ACQUIRE_ENABLED', 'true');
        $this->env('LEDGE_URIS_ENABLED', '1');

        $s = Settings::fromConfig([]);

        $this->assertTrue($s->isAcquireEnabled());
        $this->assertTrue($s->isUrisEnabled());
    }

    public function testLedgeBaseUrlNeverReadsEnv(): void
    {
        $this->env('LEDGE_LEDGE_BASE_URL', 'https://evil.example');

        $s = Settings::fromConfig([]);

        $this->assertSame(Settings::DEFAULT_LEDGE_BASE_URL, $s->getLedgeBaseUrl());
    }

    public function testEnvName(): void
    {
        $this->assertSame('LEDGE_DISK_UNHEALTHY_AT', Settings::envName('diskUnhealthyAt'));
        $this->assertSame('LEDGE_ACQUIRE_ENABLED', Settings::envName('acquireEnabled'));
        $this->assertSame('LEDGE_URIS_ENABLED', Settings::envName('urisEnabled'));
        $this->assertSame('LEDGE_ACQUIRE_ALLOWED_HOSTS', Settings::envName('acquireAllowedHosts'));
    }

    private function env(string $name, string $value): void
    {
        putenv("{$name}={$value}");
        $_SERVER[$name] = $value;
        $_ENV[$name] = $value;
        $this->envSet[] = $name;
    }
}
