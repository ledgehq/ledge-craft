<?php

declare(strict_types=1);

namespace ledgehq\craftledge\tests;

use ledgehq\craftledge\checks\CheckResult;
use ledgehq\craftledge\checks\PhpVersionCheck;
use ledgehq\craftledge\models\Settings;
use PHPUnit\Framework\TestCase;

final class PhpVersionCheckTest extends TestCase
{
    public function testPhasesFollowTheSupportCalendar(): void
    {
        $this->assertSame(['active', 'healthy'], $this->phase('8.3.10', '2025-06-01'));
        $this->assertSame(['security', 'healthy'], $this->phase('8.3.10', '2026-10-22'));
        $this->assertSame(['eol', 'unhealthy'], $this->phase('8.3.10', '2028-01-01'));
        $this->assertSame(['eol', 'unhealthy'], $this->phase('8.1.2', '2026-10-22'));
        $this->assertSame(['eol', 'unhealthy'], $this->phase('8.0.30', '2026-10-22'));
        $this->assertSame(['active', 'healthy'], $this->phase('8.5.0', '2026-10-22'));
        $this->assertSame(['unknown', 'healthy'], $this->phase('9.0.0', '2026-10-22'));
    }

    public function testStatusesAreConfigurable(): void
    {
        $settings = Settings::fromConfig(['phpSecurityOnlyStatus' => 'healthy', 'phpEolStatus' => 'degraded']);

        $security = (new PhpVersionCheck($settings, '8.3.0', fn(): int => (int)strtotime('2026-10-22')))->run();
        $eol = (new PhpVersionCheck($settings, '8.2.0', fn(): int => (int)strtotime('2027-03-01')))->run();

        $this->assertSame('healthy', $security?->status);
        $this->assertSame('degraded', $eol?->status);
        $this->assertSame([
            'securityOnlyStatus' => 'healthy',
            'eolStatus' => 'degraded',
            'eolDegradedWithinDays' => 180,
            'eolUnhealthyWithinDays' => 90,
        ], $eol?->meta['thresholds']);
    }

    public function testSecurityOnlyIsHealthyMoreThan180DaysBeforeEndOfLifeByDefault(): void
    {
        $result = $this->check('8.3.10', '2026-11-10');

        $this->assertSame('healthy', $result?->status);
        $this->assertNull($result?->output);
        $this->assertSame('security', $result?->meta['phase']);
        $this->assertSame('2025-12-31', $result?->meta['activeSupportUntil']);
        $this->assertSame('2027-12-31', $result?->meta['securitySupportUntil']);
        $this->assertSame(416, $result?->meta['daysUntilEol']);
        $this->assertSame([
            'securityOnlyStatus' => 'healthy',
            'eolStatus' => 'unhealthy',
            'eolDegradedWithinDays' => 180,
            'eolUnhealthyWithinDays' => 90,
        ], $result?->meta['thresholds']);

        $this->assertSame('healthy', $this->check('8.3.10', '2027-07-03')?->status);
    }

    public function testDegradedWithin180DaysOfEndOfLifeByDefault(): void
    {
        $result = $this->check('8.3.10', '2027-07-04');

        $this->assertSame('degraded', $result?->status);
        $this->assertSame(180, $result?->meta['daysUntilEol']);
        $this->assertSame('PHP 8.3 reaches end of life on 2027-12-31 (180 days)', $result?->output);
        $this->assertSame('security', $result?->meta['phase']);

        $this->assertSame('degraded', $this->check('8.3.10', '2027-10-01')?->status);
    }

    public function testUnhealthyWithin90DaysOfEndOfLifeByDefault(): void
    {
        $result = $this->check('8.3.10', '2027-10-02');

        $this->assertSame('unhealthy', $result?->status);
        $this->assertSame(90, $result?->meta['daysUntilEol']);
        $this->assertSame('PHP 8.3 reaches end of life on 2027-12-31 (90 days)', $result?->output);
    }

    public function testUnhealthyPastEndOfLifeByDefault(): void
    {
        $result = $this->check('8.3.10', '2028-01-01');

        $this->assertSame('unhealthy', $result?->status);
        $this->assertSame('eol', $result?->meta['phase']);
        $this->assertNull($result?->meta['daysUntilEol']);
        $this->assertSame('PHP 8.3 reached end of life on 2027-12-31', $result?->output);
    }

    public function testExplicitSecurityOnlyStatusStillApplies(): void
    {
        $result = $this->check('8.3.10', '2026-11-10', ['phpSecurityOnlyStatus' => 'degraded']);

        $this->assertSame('degraded', $result?->status);
        $this->assertSame('PHP 8.3 receives security fixes only until 2027-12-31', $result?->output);
        $this->assertSame('degraded', $result?->meta['thresholds']['securityOnlyStatus']);
    }

    public function testExplicitNullDegradedWindowStaysDisabled(): void
    {
        $result = $this->check('8.3.10', '2027-07-04', ['phpEolDegradedWithinDays' => null]);

        $this->assertSame('healthy', $result?->status);
        $this->assertNull($result?->meta['thresholds']['eolDegradedWithinDays']);
    }

    public function testApproachingEndOfLifeIsUnhealthyByDefault(): void
    {
        $this->assertSame(['security', 'degraded'], $this->phase('8.2.0', '2026-10-01'));
        $this->assertSame(['security', 'unhealthy'], $this->phase('8.2.0', '2026-10-02'));

        $result = (new PhpVersionCheck(Settings::fromConfig([]), '8.2.0', fn(): int => (int)strtotime('2026-10-28 12:00:00 UTC')))->run();

        $this->assertSame(64, $result?->meta['daysUntilEol']);
        $this->assertSame('PHP 8.2 reaches end of life on 2026-12-31 (64 days)', $result?->output);
    }

    public function testEndOfLifeWindowIsConfigurable(): void
    {
        $settings = Settings::fromConfig([
            'phpSecurityOnlyStatus' => 'healthy',
            'phpEolDegradedWithinDays' => 180,
            'phpEolUnhealthyWithinDays' => null,
        ]);
        $now = fn(): int => (int)strtotime('2026-10-28 12:00:00 UTC');

        $this->assertSame('degraded', (new PhpVersionCheck($settings, '8.2.0', $now))->run()?->status);
        $this->assertSame('healthy', (new PhpVersionCheck($settings, '8.3.0', $now))->run()?->status);
    }

    public function testWindowDoesNotOverrideAWorsePhaseStatus(): void
    {
        $settings = Settings::fromConfig(['phpSecurityOnlyStatus' => 'unhealthy', 'phpEolUnhealthyWithinDays' => null, 'phpEolDegradedWithinDays' => 90]);
        $result = (new PhpVersionCheck($settings, '8.2.0', fn(): int => (int)strtotime('2026-10-28 12:00:00 UTC')))->run();

        $this->assertSame('unhealthy', $result?->status);
        $this->assertSame('PHP 8.2 receives security fixes only until 2026-12-31', $result?->output);
    }

    public function testOutputNamesTheDate(): void
    {
        $result = (new PhpVersionCheck(Settings::fromConfig([]), '8.2.0', fn(): int => (int)strtotime('2027-03-01')))->run();

        $this->assertSame('PHP 8.2 reached end of life on 2026-12-31', $result?->output);
        $this->assertSame('2026-12-31', $result?->meta['securitySupportUntil']);
    }

    private function check(string $version, string $today, array $config = []): ?CheckResult
    {
        return (new PhpVersionCheck(Settings::fromConfig($config), $version, fn(): int => (int)strtotime($today . ' 12:00:00 UTC')))->run();
    }

    /**
     * @return array{0: string, 1: string} phase and status
     */
    private function phase(string $version, string $today): array
    {
        $result = (new PhpVersionCheck(Settings::fromConfig([]), $version, fn(): int => (int)strtotime($today . ' 12:00:00 UTC')))->run();

        return [$result?->meta['phase'], $result?->status];
    }
}
