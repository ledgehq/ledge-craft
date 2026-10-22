# Ledge

Health, uptime, and compliance monitoring for your Craft CMS sites.

## Requirements

This plugin requires Craft CMS 4.0.0 or later, and PHP 8.0.2 or later.

> For Craft CMS 5, install the `5.x` line.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Ledge”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require ledgehq/ledge-craft

# tell Craft to install the plugin
./craft plugin/install ledge

# generate a secret key for the health endpoint
./craft ledge/generate-key
```

## Configuration

Ledge requires a secret key to authenticate requests to the `/_ledge/health` endpoint. You can either:

- Run `./craft ledge/generate-key` to generate a key and save it to your `.env` file automatically
- Add your own key to `.env` manually: `LEDGE_SECRET_KEY=your-secret-key`

The health endpoint can then be accessed by including the key in the `X-Ledge-Key` header:

```bash
curl -H "X-Ledge-Key: your-secret-key" https://your-site.com/_ledge/health
```

The header is the only accepted method by default, which keeps the key out of URLs and access logs. The header name is configurable with `keyHeader` (or `LEDGE_KEY_HEADER`). If a client cannot send headers, set `allowQueryKey` to `true` (or `LEDGE_ALLOW_QUERY_KEY=true`) to also accept a `?key=` query parameter; the parameter name is configurable with `queryKeyParam`.

### Options

Every threshold, status level, endpoint path, and toggle is configurable. The annotated list of options with their defaults lives in [src/config.php](src/config.php). To customise, copy it to `config/ledge.php` in your project, or run:

```bash
./craft ledge/publish-config
```

Each option resolves in the same order: an explicit key in `config/ledge.php` wins, then the matching `LEDGE_*` env var, then the default. The env var name is the option name in SCREAMING_SNAKE_CASE, so `diskUnhealthyAt` reads `LEDGE_DISK_UNHEALTHY_AT`. Config values may also be `$VAR` references. `ledgeBaseUrl` is the one exception and is never read from the environment.

Thresholds come in `DegradedAt` / `UnhealthyAt` pairs. A check trips a tier when its measured value is greater than or equal to that tier. Set a tier to `null` (or its env var to empty or `off`) to disable it. Checks that report a condition rather than a number (`devMode`, missing env vars, license issues, available updates, the PHP support phase) take a status level instead: `healthy`, `degraded`, or `unhealthy`.

```php
// config/ledge.php
return [
    'diskDegradedAt' => 85,
    'diskUnhealthyAt' => 95,
    'diskMinFreeBytes' => 10 * 1024 ** 3,
    'updateAvailableStatus' => 'degraded',
    'enabledChecks' => ['freeform' => false],
];
```

```bash
# or, in .env
LEDGE_DISK_UNHEALTHY_AT=95
LEDGE_DISABLED_CHECKS=freeform
```

Every check echoes the values it actually used under a `thresholds` key in its result, so Ledge can show why a site tripped rather than assuming the defaults.

## Checks

| Check | What it reports |
|---|---|
| `database` | Connectivity and response time |
| `queue` | Failed and stale jobs on Craft's queue |
| `disk` | Usage of the `@storage` volume, plus an optional absolute free-bytes floor |
| `memory` | PHP memory usage against `memory_limit` |
| `craftVersion` | Available and critical Craft updates, with the license's update eligibility |
| `plugins` | Installed plugins with available or critical updates |
| `debugMode` | `devMode` and `allowAdminChanges` |
| `failedLogins` | Users with failed login attempts inside `failedLoginsWindow` |
| `license` | Craft and plugin license status |
| `environment` | Env vars referenced from config files that are not defined |
| `formie` | Failed Formie notifications (when Formie is installed) |
| `freeform` | Freeform error log lines (when Freeform is installed) |
| `phpVersion` | Support phase of the running PHP branch (`active`, `security`, `eol`) against php.net's release calendar |

Turn a check off with `enabledChecks` or `LEDGE_DISABLED_CHECKS`.

## Dependencies

The shared key also unlocks `GET /_ledge/dependencies`, which returns the installed Composer package set (`{composer: [{name, version}], generatedAt, hash}`) so the Ledge service can match your site against published security advisories. It reads Composer's runtime data rather than `composer.lock`, so it reflects what is actually deployed and omits dev packages on `--no-dev` installs. It is on by default; it exposes the same class of information the health payload's plugin check already does.

The health payload includes the same `hash` as `dependenciesHash`, so the service only fetches the full package list when it changes. It also carries `composerLockHash`, the SHA-256 of the raw bytes of the project's `composer.lock` (`null` when the file is absent or unreadable), so the service can tell whether what is deployed matches the lockfile committed to the linked repository. The path is configurable with `dependenciesPath` (default `_ledge/dependencies`), and the endpoint can be turned off with `dependenciesEnabled` / `LEDGE_DEPENDENCIES_ENABLED=false`, in which case the route is a 404 and both hashes are omitted from the health payload.

## Acquire (automated update testing & backups)

Beyond `/health`, Ledge can act as an **acquisition agent**: on a signed command from the Ledge service, the plugin produces an encrypted bundle of the site (a database dump — plus an environment + crawlable-URL manifest for update-test runs) and uploads it to object storage. This powers automated "does this update break the site?" testing and scheduled database backups.

This capability is **off by default** and higher-privilege than `/health`: every command must carry an Ed25519 signature (verified against Ledge's published keyset) on top of the shared key, and nothing is registered until an operator opts in. To enable it:

```php
// config/ledge.php
return [
    'secretKey' => '$LEDGE_SECRET_KEY',
    'acquireEnabled' => true, // or LEDGE_ACQUIRE_ENABLED=true
];
```

Bundle uploads and callbacks may only be sent to allowlisted hosts; the allowlist defaults to `['ledgehq.app', '*.ledgehq.app']` and can be overridden with `acquireAllowedHosts` (or `LEDGE_ACQUIRE_ALLOWED_HOSTS`) for self-hosted or dev setups.

While disabled, the acquire routes return `404` and the plugin behaves exactly as a health-only install. A companion opt-in endpoint, `GET /_ledge/uris` (enabled with `urisEnabled` / `LEDGE_URIS_ENABLED`), returns the site's crawlable public URL map on demand.

See [docs/acquire-protocol.md](docs/acquire-protocol.md) for the full wire protocol, bundle format, callback events, and command-signing snippets.
