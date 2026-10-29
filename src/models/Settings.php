<?php

declare(strict_types=1);

namespace ledgehq\craftledge\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use ledgehq\craftledge\checks\CheckResult;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Every option resolves in the same order: an explicit key in
 * `config/ledge.php` wins, then the matching `LEDGE_*` env var, then the
 * default. The env var name is the camelCase key in SCREAMING_SNAKE_CASE
 * (`diskUnhealthyAt` → `LEDGE_DISK_UNHEALTHY_AT`). Config values may also
 * be `$VAR` references. See `src/config.php` for the annotated list.
 */
class Settings extends Model
{
    public const DEFAULT_LEDGE_BASE_URL = 'https://my.ledgehq.app';
    public const DEFAULT_ACQUIRE_ALLOWED_HOSTS = ['ledgehq.app', '*.ledgehq.app'];
    public const ENV_PREFIX = 'LEDGE_';
    public const ENV_DISABLED_CHECKS = 'LEDGE_DISABLED_CHECKS';

    /** Options that never read from the environment. */
    private const ENV_EXCLUDED = ['ledgeBaseUrl', 'enabledChecks'];

    private const STATUS_LEVELS = [
        CheckResult::STATUS_HEALTHY,
        CheckResult::STATUS_DEGRADED,
        CheckResult::STATUS_UNHEALTHY,
    ];

    public ?string $secretKey = null;
    public string $keyHeader = 'X-Ledge-Key';
    public bool $allowQueryKey = false;
    public string $queryKeyParam = 'key';
    public string $endpointPath = '_ledge/health';

    public array $enabledChecks = [
        'database' => true,
        'queue' => true,
        'disk' => true,
        'memory' => true,
        'craftVersion' => true,
        'plugins' => true,
        'debugMode' => true,
        'failedLogins' => true,
        'license' => true,
        'environment' => true,
        'formie' => true,
        'freeform' => true,
        'phpVersion' => true,
    ];

    public ?int $diskDegradedAt = 80;
    public ?int $diskUnhealthyAt = 90;
    public ?int $diskMinFreeBytes = null;

    public ?int $memoryDegradedAt = 75;
    public ?int $memoryUnhealthyAt = 90;

    public int $queueStaleAfter = 300;
    public ?int $queueFailedDegradedAt = null;
    public ?int $queueFailedUnhealthyAt = 1;
    public ?int $queueStaleDegradedAt = null;
    public ?int $queueStaleUnhealthyAt = 1;

    public int $failedLoginsWindow = 86400;
    public ?int $failedLoginsDegradedAt = 10;
    public ?int $failedLoginsUnhealthyAt = 50;

    public ?int $formieDegradedAt = 1;
    public ?int $formieUnhealthyAt = 11;
    public ?int $freeformDegradedAt = 1;
    public ?int $freeformUnhealthyAt = 11;

    public string $updateAvailableStatus = CheckResult::STATUS_HEALTHY;
    public string $criticalUpdateStatus = CheckResult::STATUS_UNHEALTHY;
    public string $devModeStatus = CheckResult::STATUS_DEGRADED;
    public string $missingEnvVarsStatus = CheckResult::STATUS_DEGRADED;
    public string $licenseIssueStatus = CheckResult::STATUS_UNHEALTHY;
    public string $phpSecurityOnlyStatus = CheckResult::STATUS_DEGRADED;
    public string $phpEolStatus = CheckResult::STATUS_UNHEALTHY;
    public ?int $phpEolDegradedWithinDays = null;
    public ?int $phpEolUnhealthyWithinDays = 90;

    public array $ignoredEnvVars = [];

    public bool $dependenciesEnabled = true;
    public string $dependenciesPath = '_ledge/dependencies';
    public bool $urisEnabled = false;
    public string $urisPath = '_ledge/uris';

    public bool $acquireEnabled = false;
    public string $acquirePath = '_ledge/acquire';
    public ?string $ledgeBaseUrl = null;
    public array $acquireAllowedHosts = [];
    public array $acquireEnvDenylist = [];
    public int $acquireMaxBundleBytes = 524288000;
    public int $acquireJobTtr = 3600;

    /**
     * Builds the effective settings from a `config/ledge.php` array: explicit
     * config keys win, absent keys fall back to their `LEDGE_*` env var, and
     * anything still unset keeps its default.
     */
    public static function fromConfig(array $fileConfig): self
    {
        $settings = new self();

        foreach (self::configurableKeys() as $key) {
            if (array_key_exists($key, $fileConfig)) {
                $settings->assign($key, $fileConfig[$key], 'config');
                continue;
            }

            if (in_array($key, self::ENV_EXCLUDED, true)) {
                continue;
            }

            $env = App::env(self::envName($key));
            if ($env !== null) {
                $settings->assign($key, $env, self::envName($key));
            }
        }

        if (!array_key_exists('enabledChecks', $fileConfig)) {
            $disabled = App::env(self::ENV_DISABLED_CHECKS);
            if (is_string($disabled)) {
                foreach (self::splitList($disabled) as $check) {
                    $settings->enabledChecks[$check] = false;
                }
            }
        }

        $settings->resetInvalidToDefaults();

        return $settings;
    }

    /**
     * Coerces and assigns one option. A value that cannot be coerced to the
     * property's type (e.g. `'enabled'` for a bool) is logged and skipped so
     * the default survives, rather than collapsing to false/0.
     */
    private function assign(string $key, mixed $raw, string $source): void
    {
        $value = self::coerce($key, $raw);
        $type = (new ReflectionProperty(self::class, $key))->getType();

        if ($value === null && $type instanceof ReflectionNamedType && !$type->allowsNull()) {
            Craft::warning("Ledge setting '{$key}' from {$source} is not a valid {$type->getName()}; using the default.", __METHOD__);
            return;
        }

        $this->$key = $value;
    }

    /**
     * An out-of-range or unknown value must not take the endpoint down or
     * silently change semantics; it is logged and replaced by the default.
     */
    private function resetInvalidToDefaults(): void
    {
        if ($this->validate()) {
            return;
        }

        $defaults = new self();

        foreach ($this->getErrors() as $attribute => $messages) {
            Craft::warning(
                "Ledge setting '{$attribute}' is invalid (" . implode('; ', $messages) . "); using the default.",
                __METHOD__,
            );
            $this->$attribute = $defaults->$attribute;
        }

        $this->clearErrors();
    }

    /**
     * `LEDGE_*` env var name for a config key: `diskUnhealthyAt` →
     * `LEDGE_DISK_UNHEALTHY_AT`.
     */
    public static function envName(string $key): string
    {
        return self::ENV_PREFIX . strtoupper((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    }

    public function getSecretKey(): ?string
    {
        if ($this->secretKey) {
            return App::parseEnv($this->secretKey);
        }

        return App::env('LEDGE_SECRET_KEY');
    }

    /**
     * The acquire capability is off by default; an operator opts in explicitly
     * via config (`'acquireEnabled' => true`) or the LEDGE_ACQUIRE_ENABLED env
     * var (resolved by fromConfig(), config wins). When disabled the acquire
     * routes are not registered at all (404).
     */
    public function isAcquireEnabled(): bool
    {
        return $this->acquireEnabled;
    }

    /**
     * The public-URIs endpoint is off by default; enable it via config
     * (`'urisEnabled' => true`) or the LEDGE_URIS_ENABLED env var (resolved by
     * fromConfig(), config wins). While disabled the `/_ledge/uris` route is
     * not registered at all (404).
     */
    public function isUrisEnabled(): bool
    {
        return $this->urisEnabled;
    }

    /**
     * The dependencies endpoint is on by default. When disabled the route is
     * not registered (404) and the health payload omits `dependenciesHash`
     * and `composerLockHash`; their absence is how Ledge detects the feature
     * is off.
     */
    public function isDependenciesEnabled(): bool
    {
        return $this->dependenciesEnabled;
    }

    /**
     * Deliberately file-config only — no env parsing. The keyset base URL is
     * a trust anchor; it must never be attacker-influenceable or derived from
     * an incoming command.
     */
    public function getLedgeBaseUrl(): string
    {
        return rtrim($this->ledgeBaseUrl ?: self::DEFAULT_LEDGE_BASE_URL, '/');
    }

    /**
     * Defaults to the ledgehq.app domain (apex + subdomains) so acquire works
     * out of the box once enabled; any configured or env-provided allowlist
     * replaces the default entirely.
     *
     * @return string[]
     */
    public function getAcquireAllowedHosts(): array
    {
        $entries = $this->acquireAllowedHosts;

        if (empty($entries)) {
            $env = App::env('LEDGE_ACQUIRE_ALLOWED_HOSTS');
            if (is_string($env) && $env !== '') {
                $entries = explode(',', $env);
            }
        }

        $hosts = [];

        foreach ($entries as $entry) {
            $parsed = App::parseEnv((string)$entry);
            if (is_string($parsed) && trim($parsed) !== '') {
                $hosts[] = trim($parsed);
            }
        }

        return $hosts ?: self::DEFAULT_ACQUIRE_ALLOWED_HOSTS;
    }

    /**
     * Operator-configured fnmatch patterns of env vars to exclude from the
     * acquisition manifest. Empty by default (all vars included); the plugin's
     * own LEDGE_* auth material is always excluded regardless.
     *
     * @return string[]
     */
    public function getAcquireEnvDenylist(): array
    {
        $entries = $this->acquireEnvDenylist;

        if (empty($entries)) {
            $env = App::env('LEDGE_ACQUIRE_ENV_DENYLIST');
            if (is_string($env) && $env !== '') {
                $entries = explode(',', $env);
            }
        }

        $patterns = [];

        foreach ($entries as $entry) {
            $parsed = App::parseEnv((string)$entry);
            if (is_string($parsed) && trim($parsed) !== '') {
                $patterns[] = trim($parsed);
            }
        }

        return $patterns;
    }

    public function rules(): array
    {
        $percent = [
            'diskDegradedAt', 'diskUnhealthyAt',
            'memoryDegradedAt', 'memoryUnhealthyAt',
        ];
        $counts = [
            'diskMinFreeBytes',
            'queueFailedDegradedAt', 'queueFailedUnhealthyAt',
            'queueStaleDegradedAt', 'queueStaleUnhealthyAt',
            'failedLoginsDegradedAt', 'failedLoginsUnhealthyAt',
            'formieDegradedAt', 'formieUnhealthyAt',
            'freeformDegradedAt', 'freeformUnhealthyAt',
            'phpEolDegradedWithinDays', 'phpEolUnhealthyWithinDays',
        ];
        $statuses = [
            'updateAvailableStatus', 'criticalUpdateStatus', 'devModeStatus',
            'missingEnvVarsStatus', 'licenseIssueStatus',
            'phpSecurityOnlyStatus', 'phpEolStatus',
        ];

        return [
            [['endpointPath', 'acquirePath', 'urisPath', 'dependenciesPath', 'keyHeader', 'queryKeyParam'], 'required'],
            [['endpointPath', 'acquirePath', 'urisPath', 'dependenciesPath', 'keyHeader', 'queryKeyParam'], 'string'],
            [$percent, 'integer', 'min' => 1, 'max' => 100],
            [$counts, 'integer', 'min' => 0],
            [['queueStaleAfter', 'failedLoginsWindow', 'acquireMaxBundleBytes', 'acquireJobTtr'], 'integer', 'min' => 1],
            [$statuses, 'in', 'range' => self::STATUS_LEVELS],
        ];
    }

    /**
     * @return string[]
     */
    private static function configurableKeys(): array
    {
        $keys = [];

        foreach ((new ReflectionClass(self::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic()) {
                $keys[] = $property->getName();
            }
        }

        return $keys;
    }

    /**
     * Normalizes a raw config or env value to the property's declared type.
     * Strings are passed through `App::parseEnv`, so `'$LEDGE_FOO'` works in
     * the config file. For nullable ints, an empty string or one of
     * `null|none|off|false` clears the value (disables that tier). Returns
     * null for a bool that cannot be parsed; assign() decides what that means.
     */
    private static function coerce(string $key, mixed $value): mixed
    {
        $type = (new ReflectionProperty(self::class, $key))->getType();
        if (!$type instanceof ReflectionNamedType) {
            return $value;
        }

        if (is_string($value)) {
            $value = App::parseEnv($value);
        }

        $nullable = $type->allowsNull();

        return match ($type->getName()) {
            'bool' => is_bool($value) ? $value : App::parseBooleanEnv($value),
            'int' => self::coerceInt($value, $nullable),
            'string' => $value === null ? ($nullable ? null : '') : (is_scalar($value) ? (string)$value : ''),
            'array' => is_array($value) ? $value : (is_string($value) ? self::splitList($value) : []),
            default => $value,
        };
    }

    private static function coerceInt(mixed $value, bool $nullable): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int)$value;
        }

        if ($value === null || $value === false) {
            return $nullable ? null : 0;
        }

        if (is_string($value)) {
            $trimmed = strtolower(trim($value));
            if ($trimmed === '' || in_array($trimmed, ['null', 'none', 'off', 'false'], true)) {
                return $nullable ? null : 0;
            }
            if (is_numeric($trimmed)) {
                return (int)$trimmed;
            }
        }

        return $nullable ? null : 0;
    }

    /**
     * @return string[]
     */
    private static function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn(string $v): bool => $v !== ''));
    }
}
