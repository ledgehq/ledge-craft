# Release Notes for Ledge

## 4.7.0 - 2026-10-29
- The `phpVersion` check now trips when the running branch is close to end of life, whatever its phase: `phpEolUnhealthyWithinDays` (default `90`) and `phpEolDegradedWithinDays` (default `null`, off) count days until the branch's security support ends, inclusive, with `LEDGE_*` env fallbacks. The worse of this and the phase status wins. Adds `daysUntilEol` to the check's meta (`null` for an unknown or end-of-life branch) and both values to `thresholds`. Same behavior as 5.10.0. **Behavior change:** sites on PHP 8.2, which reaches end of life on 2026-12-31, report `unhealthy` as soon as they upgrade. Set `phpEolUnhealthyWithinDays` to `null` to keep the previous behavior

## 4.6.0 - 2026-10-22
- Added the `phpVersion` check: the running PHP branch's support phase against php.net's release calendar (`active`, `security`, `eol`, or `unknown` for a branch the plugin does not know yet), with `activeSupportUntil` / `securitySupportUntil` dates. Security-only reports `phpSecurityOnlyStatus` (default `degraded`), end of life reports `phpEolStatus` (default `unhealthy`); both have `LEDGE_*` env fallbacks. Same check and payload shape as the 5.x plugin and the Laravel package

## 4.5.1 - 2026-09-03
- Fixed: a boolean option given a value that is not a recognisable boolean (e.g. `'dependenciesEnabled' => 'enabled'`, or a `$VAR` reference to an unset env var) silently became `false`. It is now logged and the default is kept, matching how invalid ints and status levels are already handled

## 4.5.0 - 2026-09-03
(Ports 5.2.0's dependencies endpoint and everything from 5.6.0 through 5.8.0 to the Craft 4 line.)
- Added `GET /_ledge/dependencies` (shared-key auth, configurable `dependenciesPath`): returns `{composer: [{name, version}], generatedAt, hash}` — the installed Composer package set for Ledge's security-advisory matching. Sourced strictly from Composer's runtime data (`InstalledVersions`), never a lockfile, so it reflects what is actually deployed and dev dependencies are absent on `--no-dev` production installs. On by default (`dependenciesEnabled`); see below for the hash and toggle
- Added `composerLockHash` to the health payload root: SHA-256 of the raw bytes of the project's `composer.lock`, `null` when the file is absent or unreadable. This is a deployment-drift signal for Ledge to compare against the lockfile at the linked repository's branch head; it is deliberately separate from `dependenciesHash`, which fingerprints Composer's runtime data and drives fetch-on-change. Omitted alongside `dependenciesHash` when `dependenciesEnabled` is off
- `GET /_ledge/dependencies` now returns a 503 with an `error` body when Composer's runtime data cannot be read, instead of a 500
- Package normalization is a dedicated `DependenciesService::normalizePackages()` step and is covered by tests that pin the `dependenciesHash` for a fixed inventory
- Every check threshold and status level is now configurable, with a `LEDGE_*` env var fallback for each option. The annotated list of options ships as `src/config.php`; copy it to `config/ledge.php` or run `./craft ledge/publish-config` (`--force` to overwrite). Resolution order for every option: explicit config key → `LEDGE_<KEY_IN_SCREAMING_SNAKE_CASE>` env var → default. Config values may be `$VAR` references (`ledgeBaseUrl` stays file-config only)
- Thresholds are `DegradedAt` / `UnhealthyAt` pairs, inclusive (value ≥ tier), and `null` (env: empty / `off`) disables a tier. New options, defaults matching previous behavior: `diskDegradedAt` 80 / `diskUnhealthyAt` 90 / `diskMinFreeBytes` null (new absolute floor), `memoryDegradedAt` 75 / `memoryUnhealthyAt` 90, `failedLoginsDegradedAt` 10 / `failedLoginsUnhealthyAt` 50, `formieDegradedAt` 1 / `formieUnhealthyAt` 11, `freeformDegradedAt` 1 / `freeformUnhealthyAt` 11, `queueFailedDegradedAt` null / `queueFailedUnhealthyAt` 1, `queueStaleDegradedAt` null / `queueStaleUnhealthyAt` 1. `diskSpaceThreshold` is replaced by the pair. `queueAgeThreshold` is renamed `queueStaleAfter` and `failedLoginWindow` is renamed `failedLoginsWindow` so the names line up with their `DegradedAt` / `UnhealthyAt` pairs; the old keys are no longer read
- Condition checks take a status level (`healthy` / `degraded` / `unhealthy`): `updateAvailableStatus` (default `healthy`) and `criticalUpdateStatus` (`unhealthy`) for the `craftVersion` and `plugins` checks, `devModeStatus` (`degraded`), `missingEnvVarsStatus` (`degraded`), `licenseIssueStatus` (`unhealthy`). Setting `updateAvailableStatus` to `degraded` closes the long-standing "configurable status for non-critical updates" item; the `plugins` check now reports that status whenever any plugin is outdated
- Every check echoes the values it actually used under a `thresholds` key in its result (e.g. `disk.thresholds: {degradedAt, unhealthyAt, minFreeBytes}`, `queue.thresholds: {failed: {...}, stale: {..., ageSeconds}}`, `plugins.thresholds: {updateAvailableStatus, criticalUpdateStatus}`), so Ledge can display the rule alongside the result instead of assuming defaults. Additive; absent on older plugin versions
- The dependencies endpoint can now be turned off with `dependenciesEnabled` / `LEDGE_DEPENDENCIES_ENABLED=false` (default on). While off the route is not registered (404) and the health payload omits `dependenciesHash` and `composerLockHash`
- `LEDGE_DISABLED_CHECKS` (comma-separated check names) disables checks from the environment when `enabledChecks` is not set in the config file
- **Breaking:** the health, dependencies, and uris endpoints no longer accept the shared key as a `?key=` query parameter by default; the `X-Ledge-Key` header is the only method out of the box, so the key never lands in URLs or access logs. Opt back in with `allowQueryKey => true` (env `LEDGE_ALLOW_QUERY_KEY=true`) for clients that cannot send headers. Cache-busting query strings that do not carry the key keep working. The three endpoints now share one validator (`AuthenticatesWithSecretKey`) instead of three copies
- Added `keyHeader` (default `X-Ledge-Key`, env `LEDGE_KEY_HEADER`): the request header every Ledge endpoint, acquire included, reads the shared secret from; and `queryKeyParam` (default `key`, env `LEDGE_QUERY_KEY_PARAM`): the query parameter name used when `allowQueryKey` is on
- Invalid option values (an unknown status level, a percentage over 100, a negative count) are now logged and replaced by the default instead of being passed through to the checks
- `acquireEnabled => false` and `urisEnabled => false` in the config file now win over `LEDGE_ACQUIRE_ENABLED=true` / `LEDGE_URIS_ENABLED=true`, matching the config-beats-env rule every other option follows. Previously the env var could re-enable a capability the config file had turned off
- `ledge/generate-key` now writes only `LEDGE_SECRET_KEY` to `.env`; the commented-out block of optional env vars it used to append is gone, since `src/config.php` is the reference for every option
- The queue check now reports both failed and stale problems in one message when both apply, instead of only the first
- `/health` root now includes `dependenciesHash`: the same SHA-256 that `GET /_ledge/dependencies` returns as `hash`, computed over the installed Composer package set. Ledge can compare it against the last value it stored and only fetch the full package list when it changes, instead of polling the dependencies endpoint on a schedule or inlining a few hundred packages into every health poll. `null` if the inventory cannot be read; absent on older plugin versions. Package enumeration moved to a shared `DependenciesService` so both places hash identical input

## 4.4.0 - 2026-08-28
- The bundle manifest and `GET /_ledge/uris` now include `sites`: every Craft site as `{handle, primary, enabled, language, baseUrl, host, path}`, with `baseUrl` resolved (aliases/env vars expanded) and `host`/`path` pre-split from it (`example.com` + `/fr` for a path-mounted site, `fr.example.com` + `/` for a host-distinguished one) so a consumer can re-home sites onto sandbox hosts without re-parsing. Previously `uris` carried only a `site` handle with nothing to resolve it against, so consumers requested every URI under the primary site's host and non-primary-only pages on multi-site installs (e.g. French-only entries) showed up as 404s. Additive and absent on older plugins; the `sites` fact is omitted (never emitted empty) on enumeration failure the same way `uris` is, and consumers must treat absence as single-site

## 4.3.0 - 2026-08-19
- The `craftVersion` check and each entry in `plugins.installed`/`plugins.outdated` now include `updateStatus`: the raw status string from Craft's update info (`eligible` / `expired` / `breakpoint`), or `null` when update info is unavailable. `expired` means an update exists but the license's one-year update window has lapsed (`craft update` would silently skip the package) — distinct from `licenseKeyStatus`, which stays `valid` in that case. Lets Ledge badge expired packages and exclude them from update runs instead of discovering the skip after a no-op update (ported from 5.4.0)

## 4.2.0 - 2026-08-18
- `/health` root now includes `cpUrl`: the site's control panel base URL (`UrlHelper::cpUrl()` with the trailing slash trimmed), so Ledge can render an "Open Control Panel" button instead of guessing `/admin`. Craft's helper handles a renamed `cpTrigger`, a `baseCpUrl` on a different domain, and `cpTrigger: null`. The value only appears in the key-authenticated health payload, so an obscured CP trigger is not exposed publicly; its presence doubles as the capability signal (absent on older plugin versions; ported from 5.3.0)

## 4.1.4 - 2026-07-16
- `ledge/default/generate-key` now also appends the other Ledge env options to `.env` as commented-out lines with their defaults (`LEDGE_ACQUIRE_ENABLED`, `LEDGE_ACQUIRE_ALLOWED_HOSTS`, `LEDGE_ACQUIRE_ENV_DENYLIST`, `LEDGE_URIS_ENABLED`), skipping any already present, so the available toggles are discoverable at setup time (ported from 5.2.4)

## 4.1.3 - 2026-07-14
- The acquire host allowlist now defaults to `['ledgehq.app', '*.ledgehq.app']`, so acquire + backups work against the Ledge service out of the box once `acquireEnabled` is on (previously an empty allowlist rejected every command). Setting `acquireAllowedHosts` or `LEDGE_ACQUIRE_ALLOWED_HOSTS` still replaces the default entirely (ported from 5.2.3)

## 4.1.2 - 2026-07-14
- Plugin critical updates now report the `plugins` check as `unhealthy` instead of `degraded`, matching the urgency of the Craft CP's critical-update banner (and the `craftVersion` check's existing behavior for critical Craft updates; ported from 5.2.2)

## 4.1.1 - 2026-07-14
- Craft and plugin version checks now flag critical updates the same way the Craft control panel does (`Update::getHasCritical()`, any release between installed and latest), instead of only checking the latest release's `critical` flag. Previously a site could show Craft's red "A critical update is available" banner while Ledge reported `isCritical: false` because the newest release itself wasn't critical (ported from 5.2.1)

## 4.1.0 - 2026-07-07
- Ported the acquire + backup capability from the 5.x line to Craft 4 / PHP 8.0.2 (feature parity; same wire protocol)
- Added the acquire capability: Ledge can command the site to produce an encrypted bundle (full DB dump + env manifest) and push it to object storage for automated update testing
  - Off by default: the whole capability is gated behind an `acquireEnabled` setting (env fallback `LEDGE_ACQUIRE_ENABLED`); while disabled the acquire routes are not registered (404) and the plugin behaves exactly as before
  - `POST /_ledge/acquire` accepts Ed25519-signed commands (`{command, signature}`, detached signature over canonical JSON minus `callback_token`); signing keys are discovered from Ledge's `/.well-known/ledge-keys` keyset (keyed by `key_id`) — the keyset base URL (`ledgeBaseUrl`) is file-config only and never derived from a command. Learned key pins never expire: a known `key_id` is never re-mapped to different key material (rotation = new `key_id`); only the fetched-keyset discovery cache has a TTL (24h)
  - Verification chain: shared key → key discovery → signature → expiry → replay protection (seen `run_id`s tracked in Craft's cache with a TTL of the command's validity window — no database table) → host allowlist (`acquireAllowedHosts`) for both `upload_url` and `callback_url`; failures return machine-readable reasons and are logged; with no allowlist configured every command is rejected
  - Bundling runs as `AcquireBundleJob` on Craft's queue: preflight (dump command, disk headroom, tmp dir, sodium) → `mysqldump` via Craft's backup pipeline → env/facts manifest (all env vars by default, narrowed by an operator-configured `acquireEnvDenylist`; the plugin's own `LEDGE_*` auth material is always excluded; plus a complete `uris` list of every crawlable URL enumerated across all URL-enabled element types and sites via Craft's element API, so the runner can crawl for regressions without raw SQL) → gzipped tar encrypted with a sealed-key + secretstream construction to the command's `bundle_pubkey` → streamed PUT to `upload_url`; temp files cleaned up on success and failure
  - Best-effort bearer-authenticated callbacks (`acquire.accepted|started|progress|completed|failed`) plus a `GET /_ledge/acquire/<run_id>` status endpoint as pull fallback (run state is cache-backed, ~24h TTL — the plugin keeps no persistent run history; Ledge is the system of record)
  - See `docs/acquire-protocol.md` for the wire protocol, bundle format, and signing snippets
  - `profile: "backup"` — the same signed command, verification, encryption, and signed-upload flow produce a scheduled backup instead of an update-test bundle. The backup bundle is **the database and nothing else** (`dump.sql` only): no env manifest, no metadata sidecar, no project config — the env manifest that packages potentially secret-bearing environment variables is `full`-only and is never built for backups. The bare dump still restores (Craft's project config + schema version live inside the dump). Backup runs report richer `acquire.completed` telemetry (`compressed_size`, `uncompressed_size`, `dump_duration_ms`, `table_count`, `sha256`) — sizes/timing only, no site data. Distinct `run_id`s queue independently; the replay guard only fires on a repeated `run_id`
- Added `GET /_ledge/uris` (shared-key auth, configurable `urisPath`): returns `{count, uris}` — the site's complete crawlable public URL map (`{uri, site, section}` across all URL-enabled element types and sites) on demand, so Ledge can fetch it without triggering an acquisition and decide which pages to check. Off by default (it exposes the full public URL map, including live-but-unlinked pages); enable via `urisEnabled` / `LEDGE_URIS_ENABLED`, while disabled the route is a 404. Enumeration is a reusable `AcquireService::getPublicUris()` method, shared with the `full`-profile bundle manifest
- `/health` root now includes: `platform` (`{name: "craftcms", version, edition}` so Ledge can identify the site type/version without digging into the checks), the project-config `configVersion` (staleness signal for open update PRs), `acquireEnabled` (whether acquire is configured and runnable on this site, so Ledge knows per-project whether it can issue acquire commands), and `backupProfileSupported` (advertises backup-profile support so Ledge can gate scheduling on it)
- Added a PHPUnit test suite covering the acquire protocol core (`composer test`)

## 4.0.7.2 - 2026-07-07
- Queue check now resolves the queue's effective `channel` the way Craft does internally (falling back to the application component ID when `Queue::$channel` is null) instead of reading the public `$channel` property directly. Previously the property was `null`, so the stale-jobs query filtered on an empty channel and matched no rows — reporting `stale: 0` and `healthy` even when hundreds of jobs were stuck
- Queue check now also flags reserved-but-abandoned jobs as stale (a job whose reservation has expired, `timeUpdated + ttr <= now`), not just jobs that were never reserved

## 4.0.7 - 2026-06-15
- Health endpoint now sends no-cache headers so it is never served from Craft Cloud's static page cache

## 4.0.6 - 2026-06-11
- Formie check now excludes trashed (soft-deleted) sent notifications from its failed-notification count by joining `elements` and filtering on `dateDeleted`, so resolving failures by trashing them in the Formie control panel clears the check

## 4.0.5 - 2026-06-02
- Environment check no longer reports a var as missing when another scanned config file resolves it (cross-file resolution)
- Freeform and environment checks now explicitly null-guard `Freeform::getInstance()` / `Ledge::getInstance()` and report a clearer degraded result on cold-path failures instead of relying on the outer `Throwable` catch

## 4.0.4 - 2026-06-02
- Freeform check now reads error counts from Freeform's logs via `LoggerService` (`getCombinedLogLineCount(['error'])`) instead of querying nonexistent `freeform_*` tables, fixing the bogus `degraded` "Unable to query Freeform data" result; per-log line totals are reported in the output as context

## 4.0.3 - 2026-06-02
- License check now reads real plugin statuses via `Plugins::getPluginInfo()` (richer per-plugin metadata than `$plugin->licenseKeyStatus` alone)
- Craft license status now read from the `licenseInfo` cache (`App::CACHE_KEY_LICENSE_INFO`) instead of a nonexistent cache key, fixing the bogus `status: false`
- Plugin `licenseKeyStatus` of `unknown` is now resolved to `none` (no license key) or `unverified` (key present but not yet validated by Craft)
- Craft license `mismatched`/`astray` statuses now flag the check as unhealthy (previously only `invalid` did)
- Licensed edition is now read from the `licenseInfo` cache (a string) rather than `getLicensedEdition()`; a mismatch between the licensed and running edition still flags the check unhealthy
- Per-plugin output now includes version, licensed edition, active edition, trial flag, license issues, and last-checked timestamp; the raw license key is intentionally excluded (a `hasLicenseKey` boolean is reported instead)
- Environment check now treats a referenced var as defined when its `CRAFT_`-prefixed equivalent is set (or vice versa), preventing false positives for sites using `CRAFT_DB_*` style env vars
- Environment check returns `degraded` instead of `unhealthy` when variables are referenced but not defined
- Added an `ignoredEnvVars` setting to suppress specific environment variables from the check
- `craftVersion` check now wraps edition retrieval in try/catch, returning a `degraded` "Check unavailable" result on failure instead of bubbling the exception

## 4.0.0 - 2026-05-27
- Renamed plugin from Pulse to Ledge
- Versioning now tracks the Craft major version (this is the Craft 4 line; see the `5.x` branch for Craft 5)
- Requires Craft CMS 4 and PHP 8.0.2+
- Package renamed to `ledgehq/ledge-craft` (previously `borders-dev/craft-pulse`)
- Namespace changed to `ledgehq\craftledge`; main class is now `Ledge`
- Plugin handle changed to `ledge` (config file is now `config/ledge.php`)
- Auth env var is now `LEDGE_SECRET_KEY`, header is `X-Ledge-Key`, default endpoint is `/_ledge/health`
- Each health check now runs inside its own try/catch; a failing check returns a "Check unavailable" result instead of bringing down the endpoint
- Added a service-level try/catch backstop in `HealthService` so unexpected exceptions can never 500 the response
- Exception messages no longer appear in the response body (they could leak DSN fragments and other sensitive detail); details are written to `Craft::error()` instead
- `/health` now returns `503` when the overall status is `unhealthy` (previously always `200`); `healthy` and `degraded` still return `200`
- Cleaned up the 401 response in `HealthController::beforeAction` to use idiomatic Yii response data flow
- Fix queue check accessing `$queue->channel` as a property instead of calling it as a method

## 0.5.3 - 2026-05-27
- Fix queue check: detect stale jobs via direct DB query (previously `timePushed` was not exposed by `getJobInfo()`)
- Replace stuck threshold with configurable `queueAgeThreshold` (default 5 minutes)
- Switch from CP settings page to `config/pulse.php` config file

## 0.5.2 - 2026-02-03
- Run `./craft pulse/generate-key` to generate a key and save it to your `.env` file automatically
- Always return 200 HTTP code on the endpoint

## 0.5.1 - 2026-01-29
- Omit formie/freeform checks from response when plugin not installed
- Include full release notes for all versions between current and latest
- Non-critical Craft/plugin updates now report healthy instead of degraded

## 0.5.0 - 2026-01-29
- Drop Craft 3 support (use v3 branch / 1.x releases for Craft 3)
- Restore typed properties on plugin class and controller
- Remove Craft 3 compatibility shims
- Remove Freeform 3 support (Freeform 4+ only)
- Use `App::parseEnv()` and `App::env()` instead of deprecated `Craft::parseEnv()`
- Normalize edition values for consistent JSON output across Craft 4/5
- Require Craft 4.0+ or 5.0+

## 0.4.0 - 2026-01-28
- Split combined "forms" check into separate "formie" and "freeform" checks
- Return degraded status when form plugin data cannot be queried

## 0.3.3 - 2026-01-28
- Set plugin display name to "Pulse"

## 0.3.2 - 2026-01-28
- Only report degraded status for plugins with critical updates
- Add OS version and database version to environment check

## 0.3.1 - 2026-01-28
- Change default endpoint from `/health` to `/_pulse/health`

## 0.2.1 - 2026-01-28
- Remove type declarations from plugin properties for Craft 3 compatibility
- Remove type declaration from `$allowAnonymous` controller property for Craft 3 compatibility
- Add `method_exists()` check for `onInit()` (not available in Craft 3)
- Use `setComponents()` for Craft 3 component registration compatibility
- Fix `getSecretKey()` to properly detect missing env var
- Improve error messages for authentication failures
- Add Freeform 3 support using native Freeform APIs with error details in response
- Remove time window filter from form checks (report all errors)

## 0.2.0 - 2026-01-28
- Add Craft 3.7+ support
- Add `method_exists()` check for `getLicensedEdition()` (not available in Craft 3)

## 0.1.0 - 2026-01-28
- Initial release
- Health endpoint with secret key authentication
- Database connectivity check
- Queue monitoring (pending, stuck, failed jobs)
- Disk space monitoring
- Memory usage monitoring
- Craft CMS version check with update detection
- Plugin version check with update detection
- Debug mode detection
- Failed login attempt monitoring
- License status check
- Environment variable validation
- Form plugin monitoring (Formie/Freeform failed notifications)
- Craft 4 and 5 support
- PHP 8.0.2+ support
