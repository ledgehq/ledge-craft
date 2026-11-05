# Ledge - Craft CMS Health Monitoring Plugin

## Project Overview

Ledge is a Craft CMS 4 plugin that exposes a secured `/health` endpoint returning standardized health check data. It's part of a two-part monitoring system—this plugin runs on client sites, while a separate Ledge Service polls and aggregates the data.

This is the **4.x line** — see the `5.x` branch for the Craft 5 release.

## Tech Stack

- **PHP:** 8.0.2+
- **Craft CMS:** 4.x
- **DDEV:** Local development environment
- **Namespace:** `ledgehq\craftledge`
- **Plugin Handle:** `ledge`

## DDEV Commands

```bash
ddev start                  # Start the environment
ddev stop                   # Stop the environment
ddev composer install       # Install dependencies
ddev composer check-cs      # Check code style (ECS)
ddev composer fix-cs        # Fix code style
ddev composer phpstan       # Run PHPStan static analysis
```

## Project Structure

```
src/
├── Ledge.php              # Main plugin class
├── checks/
│   ├── CheckInterface.php     # Contract for health checks
│   ├── CheckResult.php        # Standardized result object
│   ├── CraftVersionCheck.php  # Craft CMS update check
│   ├── DatabaseCheck.php      # DB connectivity check
│   ├── DebugModeCheck.php     # Dev mode detection
│   ├── DiskSpaceCheck.php     # Disk usage monitoring
│   ├── EnvironmentCheck.php   # Missing env vars check
│   ├── FailedLoginsCheck.php  # Failed login attempts
│   ├── FormieCheck.php        # Formie failed notifications
│   ├── FreeformCheck.php      # Freeform error log
│   ├── LicenseCheck.php       # License status check
│   ├── MemoryCheck.php        # PHP memory monitoring
│   ├── PluginVersionsCheck.php # Plugin updates check
│   ├── QueueCheck.php         # Queue monitoring check
│   └── Thresholds.php         # Shared degraded/unhealthy tier evaluation
├── config.php             # Annotated example config (copy to config/ledge.php)
├── console/
│   └── controllers/
│       └── DefaultController.php # generate-key, publish-config
├── controllers/
│   ├── AcquireController.php      # Signed acquire commands (opt-in)
│   ├── AuthenticatesWithSecretKey.php # Shared key-auth trait
│   ├── DependenciesController.php # Composer package inventory
│   ├── HealthController.php       # /health endpoint
│   └── UrisController.php         # Public URL map (opt-in)
├── models/
│   └── Settings.php       # Plugin settings (Settings::fromConfig resolves config > env > default)
└── services/
    ├── AcquireService.php
    ├── DependenciesService.php # Shared package inventory + hash
    └── HealthService.php  # Orchestrates all checks
```

## Craft CMS 4 Plugin Conventions

### Plugin Class Pattern
- Extends `craft\base\Plugin`
- Use `Craft::$app->onInit()` for deferred initialization
- Settings via `createSettingsModel()` (config-file only; no CP UI). Build with `Settings::fromConfig($fileConfig)` so env fallbacks and validation apply; never `new Settings()` + `setAttributes()`
- Register components in `config()` static method

### Registering Routes
```php
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use yii\base\Event;

Event::on(
    UrlManager::class,
    UrlManager::EVENT_REGISTER_SITE_URL_RULES,  // Note: includes "URL"
    function(RegisterUrlRulesEvent $event) {
        $event->rules['health'] = 'ledge/health/index';
    }
);
```

### Services
- Register in `config()` components array
- Access via `Ledge::getInstance()->serviceName`

### Controllers
- Extend `craft\web\Controller`
- Set `protected array|bool|int $allowAnonymous = true;` for public endpoints
- Return JSON with `$this->asJson($data)`

## Health Endpoint Spec

**Endpoint:** `/health` (configurable)
**Auth:** `X-Ledge-Key` header with secret key
**Response format:**
```json
{
  "status": "healthy|degraded|unhealthy",
  "checks": { ... }
}
```

## Health Checks Implementation Status

### Phase 1 (Core) ✓
- [x] Database connectivity
- [x] Queue status (pending, stuck, failed jobs)
- [x] Secret key authentication

### Phase 2 (Extended) ✓
- [x] Disk space usage
- [x] Memory usage
- [x] Craft/plugin versions
- [x] Debug mode detection
- [x] Failed login attempts
- [x] License status

### Phase 3 (Advanced) ✓
- [x] Form plugin monitoring (Formie/Freeform)
- [x] Missing environment variables

### Phase 4 (Future)
- [ ] Email delivery verification
- [x] Configurable status level for non-critical updates (`updateAvailableStatus`)

## Settings Conventions

- Every option has a `LEDGE_*` env fallback derived from its name (`Settings::envName()`); `ledgeBaseUrl` is deliberately excluded
- Numeric thresholds are `<check>DegradedAt` / `<check>UnhealthyAt` nullable ints; null disables that tier. Evaluate with `Thresholds::status()`
- Condition checks take a `<condition>Status` string (`healthy|degraded|unhealthy`)
- Each check puts the effective values it used under `meta['thresholds']`
- New options must be added to `src/config.php` with a comment and default

## Branches & Versioning

- `5.x` (Craft 5) is the main line. `4.x` (Craft 4) is a parallel line that is never merged; each 5.x release is ported to 4.x as its own commit and tag
- Keep version numbers in step across the lines where possible: 5.11.0 ships as 4.11.0, and a shared fix as 5.11.1 / 4.11.1
- A change only one line needs (e.g. a Craft 4 compatibility fix) gets a patch release (third digit) on that line alone, so patch numbers may drift but major.minor never does
- The lines were offset until 5.10.0 / 4.7.0; the next minor release realigns them (4.7.x jumps to 4.11.0)

## Code Style

- PHP 8.0+ features (typed properties, match expressions, constructor promotion)
- Avoid `readonly` properties (PHP 8.1+) and enum-only APIs — Craft 4 supports PHP 8.0.2
- No comments unless logic is non-obvious
- `declare(strict_types=1);` in all files
- Follow Craft's ECS configuration (no space before closure parentheses)
- Use constructor property promotion where appropriate
- Import order: alphabetical, case-insensitive, as enforced by ECS; do not hand-group by namespace

## Environment Variables

- `LEDGE_SECRET_KEY` - Health endpoint authentication key
- `LEDGE_*` - every config option, see `src/config.php`; `LEDGE_DISABLED_CHECKS` is a comma list

## Testing the Plugin

Access the health endpoint:
```bash
curl -H "X-Ledge-Key: your-secret-key" https://your-site.ddev.site/health
```

## Related Documentation

- [Craft CMS Plugin Development](https://craftcms.com/docs/4.x/extend/)
- [Craft Events Reference](https://craftcms.com/docs/4.x/extend/events.html)
