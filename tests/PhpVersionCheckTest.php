<?php

declare(strict_types=1);

namespace ledgehq\craftledge\tests;

use ledgehq\craftledge\checks\PhpVersionCheck;
use ledgehq\craftledge\models\Settings;
use PHPUnit\Framework\TestCase;

final class PhpVersionCheckTest extends TestCase
{
    public function testPhasesFollowTheSupportCalendar(): void
    {
        $this->assertSame(['active', 'healthy'], $this->phase('8.3.10', '2025-06-01'));
        $this->assertSame(['security', 'degraded'], $this->phase('8.3.10', '2026-10-22'));
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
        $this->assertSame(['securityOnlyStatus' => 'healthy', 'eolStatus' => 'degraded'], $eol?->meta['thresholds']);
    }

    public function testOutputNamesTheDate(): void
    {
        $result = (new PhpVersionCheck(Settings::fromConfig([]), '8.2.0', fn(): int => (int)strtotime('2027-03-01')))->run();

        $this->assertSame('PHP 8.2 reached end of life on 2026-12-31', $result?->output);
        $this->assertSame('2026-12-31', $result?->meta['securitySupportUntil']);
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
