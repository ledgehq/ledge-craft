<?php

declare(strict_types=1);

namespace ledgehq\craftledge\checks;

use Closure;
use Craft;
use ledgehq\craftledge\Ledge;
use ledgehq\craftledge\models\Settings;
use Throwable;

/**
 * Support phase of the running PHP branch against php.net's release
 * calendar. `active` is healthy, `security` (bug fixes have stopped) reports
 * `phpSecurityOnlyStatus`, `eol` reports `phpEolStatus`. Branches the table
 * does not know are reported as `unknown` and never trip. Mirrors the
 * Laravel package's `phpVersion` check.
 */
class PhpVersionCheck implements CheckInterface
{
    /**
     * branch => [active support ends, security support ends], per
     * https://www.php.net/supported-versions.php (2025 policy: two years of
     * active support followed by two years of security fixes).
     */
    public const SUPPORT = [
        '8.0' => ['2022-11-26', '2023-11-26'],
        '8.1' => ['2023-11-25', '2025-12-31'],
        '8.2' => ['2024-12-31', '2026-12-31'],
        '8.3' => ['2025-12-31', '2027-12-31'],
        '8.4' => ['2026-12-31', '2028-12-31'],
        '8.5' => ['2027-12-31', '2029-12-31'],
    ];

    /**
     * @param Closure(): int|null $now
     */
    public function __construct(
        private readonly ?Settings $settings = null,
        private readonly ?string $version = null,
        private readonly ?Closure $now = null,
    ) {
    }

    public function getName(): string
    {
        return 'phpVersion';
    }

    public function run(): ?CheckResult
    {
        try {
            $settings = $this->settings ?? Ledge::currentSettings();
            $version = $this->version ?? PHP_VERSION;
            $branch = implode('.', array_slice(explode('.', $version), 0, 2));
            $support = self::SUPPORT[$branch] ?? null;
            $now = $this->now !== null ? ($this->now)() : time();

            $phase = 'unknown';

            if ($support !== null) {
                [$activeUntil, $securityUntil] = $support;
                $phase = match (true) {
                    $now > self::endOfDay($securityUntil) => 'eol',
                    $now > self::endOfDay($activeUntil) => 'security',
                    default => 'active',
                };
            }

            $eolStatus = $settings->phpEolStatus;
            $securityStatus = $settings->phpSecurityOnlyStatus;

            $meta = [
                'version' => $version,
                'branch' => $branch,
                'phase' => $phase,
                'activeSupportUntil' => $support[0] ?? null,
                'securitySupportUntil' => $support[1] ?? null,
                'thresholds' => ['securityOnlyStatus' => $securityStatus, 'eolStatus' => $eolStatus],
            ];

            if ($phase === 'eol' && $eolStatus !== CheckResult::STATUS_HEALTHY) {
                return new CheckResult($this->getName(), $eolStatus, $meta, "PHP {$branch} reached end of life on {$support[1]}");
            }

            if ($phase === 'security' && $securityStatus !== CheckResult::STATUS_HEALTHY) {
                return new CheckResult($this->getName(), $securityStatus, $meta, "PHP {$branch} receives security fixes only until {$support[1]}");
            }

            return CheckResult::healthy($this->getName(), $meta);
        } catch (Throwable $e) {
            Craft::error('Ledge phpVersion check failed: ' . $e->getMessage(), __METHOD__);
            return CheckResult::degraded($this->getName(), [], 'Check unavailable');
        }
    }

    private static function endOfDay(string $date): int
    {
        return (int)strtotime($date . ' 23:59:59 UTC');
    }
}
