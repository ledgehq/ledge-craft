<?php

declare(strict_types=1);

namespace ledgehq\craftledge\tests;

use ledgehq\craftledge\services\ContentService;
use PHPUnit\Framework\TestCase;

final class ContentServiceTest extends TestCase
{
    public function testAssembleSumsVolumesIntoTheContractShape(): void
    {
        $sample = [
            'id' => 123,
            'filename' => 'IMG_4032.jpg',
            'title' => 'IMG 4032',
            'volume' => 'images',
            'url' => null,
            'cpEditUrl' => 'https://site.example/admin/assets/edit/123-IMG_4032.jpg',
        ];

        $report = ContentService::assemble(
            '2026-11-13T10:00:00+00:00',
            'native',
            [
                ['handle' => 'images', 'name' => 'Images', 'total' => 1200, 'missingAlt' => 300, 'filenameTitles' => 40],
                ['handle' => 'docs', 'name' => 'Documents', 'total' => 730, 'missingAlt' => 112, 'filenameTitles' => 37],
            ],
            [$sample],
            [],
        );

        $this->assertSame([
            'generatedAt' => '2026-11-13T10:00:00+00:00',
            'altSource' => 'native',
            'images' => [
                'total' => 1930,
                'missingAlt' => 412,
                'filenameTitles' => 77,
                'volumes' => [
                    ['handle' => 'images', 'name' => 'Images', 'total' => 1200, 'missingAlt' => 300, 'filenameTitles' => 40],
                    ['handle' => 'docs', 'name' => 'Documents', 'total' => 730, 'missingAlt' => 112, 'filenameTitles' => 37],
                ],
                'samples' => [
                    'missingAlt' => [$sample],
                    'filenameTitles' => [],
                ],
            ],
        ], $report);
    }

    public function testAssembleWithNoVolumesReportsZeros(): void
    {
        $report = ContentService::assemble('2026-11-13T10:00:00+00:00', 'altText', [], [], []);

        $this->assertSame('altText', $report['altSource']);
        $this->assertSame(0, $report['images']['total']);
        $this->assertSame(0, $report['images']['missingAlt']);
        $this->assertSame(0, $report['images']['filenameTitles']);
        $this->assertSame([], $report['images']['volumes']);
        $this->assertSame(['missingAlt' => [], 'filenameTitles' => []], $report['images']['samples']);
    }

    public function testNewestIdsMergesVolumesAndKeepsHighestIds(): void
    {
        $this->assertSame([90, 50, 40], ContentService::newestIds([[50, 10], [90, 40, 5]], 3));
        $this->assertSame([7, 3], ContentService::newestIds([[3], [7, 3]], 25));
        $this->assertSame([], ContentService::newestIds([[1, 2]], 0));
        $this->assertSame([], ContentService::newestIds([], 25));
    }
}
