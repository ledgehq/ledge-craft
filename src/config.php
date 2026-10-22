<?php

/**
 * Ledge configuration
 *
 * Copy this file to `config/ledge.php` in your project (or run
 * `./craft ledge/publish-config`) and uncomment the options you want to change.
 *
 * Every option resolves in the same order:
 *
 *   1. an explicit key in this file
 *   2. the matching `LEDGE_*` env var (the key in SCREAMING_SNAKE_CASE, so
 *      `diskUnhealthyAt` reads `LEDGE_DISK_UNHEALTHY_AT`)
 *   3. the default shown below
 *
 * Values may also be `$VAR` references, e.g. `'diskUnhealthyAt' => '$LEDGE_DISK_UNHEALTHY_AT'`.
 *
 * Thresholds come in `DegradedAt` / `UnhealthyAt` pairs. A check trips a tier
 * when its measured value is greater than or equal to that tier's value. Set a
 * tier to `null` (or its env var to empty / `off`) to disable it: with
 * `diskDegradedAt => null` the disk check only ever reports healthy or unhealthy.
 *
 * Status-level options accept `'healthy'`, `'degraded'`, or `'unhealthy'`.
 * `'healthy'` means the condition is still reported in the check's data but
 * never affects the overall status.
 *
 * Every check echoes the values it actually used under a `thresholds` key in
 * its result, so the Ledge dashboard can show why a site tripped.
 *
 * For per-environment differences, prefer the `LEDGE_*` env vars (or
 * `App::env()` calls in this file) over separate config files.
 */

use craft\helpers\App;

return [
    // ---------------------------------------------------------------------
    // Authentication & endpoints
    // ---------------------------------------------------------------------

    // Shared secret expected in the key header.
    // Generate one with `./craft ledge/generate-key`. Env: LEDGE_SECRET_KEY
    // 'secretKey' => App::env('LEDGE_SECRET_KEY'),

    // Name of the request header that carries the shared secret. Applies to
    // every Ledge endpoint, including acquire.
    // 'keyHeader' => 'X-Ledge-Key',

    // Also accept the key as a `?key=` query parameter. Off by default so the
    // key never lands in URLs or access logs; the `X-Ledge-Key` header is the
    // only way in. Turn on only for clients that cannot send headers.
    // 'allowQueryKey' => false,
    // Name of that query parameter, when enabled.
    // 'queryKeyParam' => 'key',

    // Site-relative path of the health endpoint.
    // 'endpointPath' => '_ledge/health',

    // Installed Composer package inventory for security-advisory matching.
    // On by default; when off the route is a 404 and the health payload omits
    // `dependenciesHash` and `composerLockHash`.
    // 'dependenciesEnabled' => true,
    // 'dependenciesPath' => '_ledge/dependencies',

    // Complete crawlable public URL map. Off by default because it exposes
    // live-but-unlinked pages.
    // 'urisEnabled' => false,
    // 'urisPath' => '_ledge/uris',

    // ---------------------------------------------------------------------
    // Checks
    // ---------------------------------------------------------------------

    // Turn individual checks off. Keys omitted here stay enabled.
    // Env alternative: LEDGE_DISABLED_CHECKS=formie,freeform
    // 'enabledChecks' => [
    //     'database' => true,
    //     'queue' => true,
    //     'disk' => true,
    //     'memory' => true,
    //     'craftVersion' => true,
    //     'plugins' => true,
    //     'debugMode' => true,
    //     'failedLogins' => true,
    //     'license' => true,
    //     'environment' => true,
    //     'formie' => true,
    //     'freeform' => true,
    //     'phpVersion' => true,
    // ],

    // Disk: percentage of the @storage volume in use.
    // 'diskDegradedAt' => 80,
    // 'diskUnhealthyAt' => 90,
    // Absolute floor in bytes; unhealthy when free space drops below it
    // regardless of percentage. Useful on very large volumes. null = off.
    // 'diskMinFreeBytes' => null,

    // Memory: percentage of PHP's memory_limit in use by the health request.
    // 'memoryDegradedAt' => 75,
    // 'memoryUnhealthyAt' => 90,

    // Queue: a job is "stale" when it has waited longer than this many
    // seconds without being picked up.
    // 'queueStaleAfter' => 300,
    // Number of failed jobs that trips each tier (null = tier disabled).
    // 'queueFailedDegradedAt' => null,
    // 'queueFailedUnhealthyAt' => 1,
    // Number of stale jobs that trips each tier.
    // 'queueStaleDegradedAt' => null,
    // 'queueStaleUnhealthyAt' => 1,

    // Failed logins: users with failed attempts inside the window (seconds).
    // 'failedLoginsWindow' => 86400,
    // 'failedLoginsDegradedAt' => 10,
    // 'failedLoginsUnhealthyAt' => 50,

    // Formie: failed notification count.
    // 'formieDegradedAt' => 1,
    // 'formieUnhealthyAt' => 11,

    // Freeform: error log line count.
    // 'freeformDegradedAt' => 1,
    // 'freeformUnhealthyAt' => 11,

    // Craft and plugin updates. Applies to the craftVersion and plugins checks.
    // 'updateAvailableStatus' => 'healthy',
    // 'criticalUpdateStatus' => 'unhealthy',

    // Status when devMode is on.
    // 'devModeStatus' => 'degraded',

    // Status when config files reference env vars that are not defined.
    // 'missingEnvVarsStatus' => 'degraded',
    // Env var names the environment check should not report as missing.
    // 'ignoredEnvVars' => [],

    // Status when a Craft or plugin license is invalid, mismatched, or astray.
    // 'licenseIssueStatus' => 'unhealthy',

    // PHP branch in its security-fixes-only phase / past end of life, per
    // php.net's release calendar.
    // 'phpSecurityOnlyStatus' => 'degraded',
    // 'phpEolStatus' => 'unhealthy',

    // ---------------------------------------------------------------------
    // Acquire (automated update testing & backups)
    // ---------------------------------------------------------------------

    // Off by default. See docs/acquire-protocol.md.
    // 'acquireEnabled' => false,
    // 'acquirePath' => '_ledge/acquire',

    // Hosts that bundle uploads and callbacks may be sent to. Replaces the
    // default entirely when set.
    // 'acquireAllowedHosts' => ['ledgehq.app', '*.ledgehq.app'],

    // fnmatch patterns of env vars to leave out of the acquisition manifest.
    // The plugin's own LEDGE_* auth material is always excluded.
    // 'acquireEnvDenylist' => ['DB_*', 'CRAFT_DB_*', '*_PASSWORD*', '*_SECRET*', '*_TOKEN*', '*_API_KEY*'],

    // 'acquireMaxBundleBytes' => 524288000,
    // 'acquireJobTtr' => 3600,

    // Base URL of the Ledge service used to discover command-signing keys.
    // File-config only (never read from the environment) because it is a
    // trust anchor. You should not need to change this.
    // 'ledgeBaseUrl' => 'https://my.ledgehq.app',
];
