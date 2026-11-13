<?php

declare(strict_types=1);

namespace ledgehq\craftledge\tests;

use craft\helpers\Assets;
use ledgehq\craftledge\content\FilenameTitle;
use PHPUnit\Framework\TestCase;

final class FilenameTitleTest extends TestCase
{
    /**
     * title => filename
     */
    private const FILENAME_STYLE = [
        ['IMG_4032', 'IMG_4032.jpg'],
        ['IMG 4032', 'IMG_4032.jpg'],
        ['Img 4032', 'IMG_4032.JPG'],
        ['IMG4032', 'IMG4032.jpg'],
        ['IMG_E4032', 'IMG_E4032.heic'],
        ['Dsc 0091', 'DSC_0091.jpg'],
        ['DSCN1234', 'DSCN1234.jpg'],
        ['P1010001', 'P1010001.jpg'],
        ['MG 1234', '_MG_1234.jpg'],
        ['PXL_20240101_123456789', 'PXL_20240101_123456789.jpg'],
        ['GOPR0042', 'GOPR0042.jpg'],
        ['DJI 0042', 'DJI_0042.jpg'],
        ['Copy of IMG 4032', 'Copy of IMG_4032.jpg'],
        ['Screenshot 2026 01 04 At 10.22.11', 'Screenshot 2026-01-04 at 10.22.11.png'],
        ['Screen Shot 2019-03-04 at 10.22.11 AM', 'Screen Shot 2019-03-04 at 10.22.11 AM.png'],
        ['WhatsApp Image 2024-01-01 at 10.22.11', 'WhatsApp Image 2024-01-01 at 10.22.11.jpeg'],
        ['Artboard 1 Copy 2', 'Artboard 1 copy 2.png'],
        ['Rectangle 12', 'Rectangle 12.png'],
        ['Image 3', 'image3.png'],
        ['Shutterstock 123456789', 'shutterstock_123456789.jpg'],
        ['20240101 123456', '20240101_123456.jpg'],
        ['0091', '0091.jpg'],
        ['hero-banner-final-v2', 'hero-banner-final-v2.jpg'],
        ['Hero banner final v2', 'hero-banner-final-v2.jpg'],
        ['Hero Banner Final', 'hero-banner-final.png'],
        ['Team photo edited', 'team-photo-edited.jpg'],
        ['Logo 2x', 'logo@2x.png'],
        ['logo@2x', 'logo@2x.png'],
        ['team_photo', 'team_photo.jpg'],
        ['team_photo', 'renamed.jpg'],
        ['about-us-hero', 'renamed.jpg'],
        ['hero-2', 'hero-2.jpg'],
        ['Hero 1920x1080', 'hero-1920x1080.jpg'],
        ['Team photo.jpg', 'team.jpg'],
        ['Kelly sikkema Hl3 L Udy K Ric unsplash', 'kelly-sikkema-Hl3LUdyKRic-unsplash.jpg'],
        ['kelly-sikkema-Hl3LUdyKRic-unsplash', 'kelly-sikkema-Hl3LUdyKRic-unsplash.jpg'],
        ['Team 2024 01 15', 'team-2024-01-15.jpg'],
        ['Banner 20240115', 'banner-20240115.jpg'],
        ['5f3a9c2b1e7d4a60', '5f3a9c2b1e7d4a60.png'],
        ['3f2a9c1e 4b1c 4d2e 8f3a 1b2c3d4e5f60', '3f2a9c1e-4b1c-4d2e-8f3a-1b2c3d4e5f60.png'],
    ];

    /**
     * title => filename
     */
    private const HUMAN = [
        ['Team photo', 'team-photo.jpg'],
        ['Team Photo', 'IMG_4032.jpg'],
        ['Sunset over the lake', 'IMG_4032.jpg'],
        ['Logo', 'logo.png'],
        ['Coca-Cola', 'Coca-Cola.png'],
        ['Wi-Fi router', 'wifi.jpg'],
        ['Room 101', 'room-101.jpg'],
        ['Photo 2024 gala', 'photo-2024-gala.jpg'],
        ['Final exam prep', 'final-exam-prep.jpg'],
        ['Class of 2024', 'class-of-2024.jpg'],
        ['Hero 2024', 'hero-2024.jpg'],
        ['Our 10 year anniversary', 'anniversary.jpg'],
        ['Store front, Main St.', 'IMG_9.jpg'],
        ['Mother-in-Law suite', 'suite.jpg'],
        ['Image', 'image.png'],
        ['Screenshot of the dashboard', 'Screenshot 2026-01-04 at 10.22.11.png'],
        ['', 'IMG_4032.jpg'],
        ['   ', 'IMG_4032.jpg'],
        ['A 4x4 truck', 'truck.jpg'],
        ['Café de Flore', 'cafe-de-flore.jpg'],
    ];

    public function testFlagsFilenameStyleTitles(): void
    {
        foreach (self::FILENAME_STYLE as [$title, $filename]) {
            $this->assertTrue(
                FilenameTitle::isFilenameStyle($title, $filename),
                "Expected '{$title}' ({$filename}) to be flagged as a filename-style title",
            );
        }
    }

    public function testLeavesHumanTitlesAlone(): void
    {
        foreach (self::HUMAN as [$title, $filename]) {
            $this->assertFalse(
                FilenameTitle::isFilenameStyle($title, $filename),
                "Expected '{$title}' ({$filename}) not to be flagged",
            );
        }
    }

    public function testCraftGeneratedTitlesFromCameraFilenamesAreFlagged(): void
    {
        foreach (['IMG_4032.jpg', 'DSC_0091.JPG', 'PXL_20240101_123456789.jpg', 'hero-banner-final-v2.jpg', 'Screenshot 2026-01-04 at 10.22.11.png'] as $filename) {
            $title = Assets::filename2Title(pathinfo($filename, PATHINFO_FILENAME));

            $this->assertTrue(
                FilenameTitle::isFilenameStyle($title, $filename),
                "Expected Craft's title '{$title}' for {$filename} to be flagged",
            );
        }
    }

    public function testCraftGeneratedTitlesFromDescriptiveFilenamesAreNotFlagged(): void
    {
        foreach (['team-photo.jpg', 'sunset_over_lake.png', 'OurStoreFront.jpg', 'class-of-2024.jpg'] as $filename) {
            $title = Assets::filename2Title(pathinfo($filename, PATHINFO_FILENAME));

            $this->assertFalse(
                FilenameTitle::isFilenameStyle($title, $filename),
                "Expected Craft's title '{$title}' for {$filename} not to be flagged",
            );
        }
    }
}
