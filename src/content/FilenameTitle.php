<?php

declare(strict_types=1);

namespace ledgehq\craftledge\content;

/**
 * Decides whether an asset title is just its filename dressed up
 * (`IMG 4032`, `Dsc 0091`, `hero-banner-final-v2`, `Screenshot 2026 01 04 At 10.22.11`)
 * rather than something a person wrote.
 *
 * Deliberately conservative: a title only counts when it carries an
 * unmistakable filename signal. A readable title Craft generated from a
 * descriptive filename (`team-photo.jpg` → `Team photo`) is not flagged,
 * because it reads the same as one a person would type.
 */
final class FilenameTitle
{
    private const IMAGE_EXTENSION = '/\.(jpe?g|png|gif|webp|avif|heic|heif|tiff?|bmp|svg)$/i';

    private const DIMENSIONS = '/(^|[^\p{L}\p{N}])\d{2,5}[x×]\d{2,5}([^\p{L}\p{N}]|$)|@[1-4]x\b/iu';

    private const UUID = '/[0-9a-f]{8}[-\s]?[0-9a-f]{4}[-\s]?[0-9a-f]{4}[-\s]?[0-9a-f]{4}[-\s]?[0-9a-f]{12}/i';

    /** Words that name a camera, app, design tool or generic file, and precede a number. */
    private const SOURCE_WORDS = [
        'adobestock', 'artboard', 'capture', 'cimg', 'dcim', 'depositphotos', 'dji', 'download',
        'dreamstime', 'dsc', 'dscf', 'dscn', 'ellipse', 'file', 'foto', 'frame', 'freepik',
        'gettyimages', 'gopr', 'group', 'image', 'images', 'imag', 'img', 'istock', 'istockphoto',
        'layer', 'mask', 'mov', 'mvi', 'mvimg', 'pexels', 'photo', 'photos', 'pic', 'pict',
        'picture', 'pixabay', 'pxl', 'rectangle', 'scan', 'scanned', 'screen', 'screenshot',
        'shot', 'shutterstock', 'unnamed', 'unsplash', 'untitled', 'vector', 'vid', 'video',
        'whatsapp',
    ];

    /** Camera codes too short to trust unless followed by at least three digits. */
    private const SHORT_CAMERA_CODES = ['gp', 'mg', 'p', 'sam', 'wp'];

    /** Filler that appears between a source word and its number. */
    private const FILLER_WORDS = ['am', 'at', 'copy', 'of', 'pm'];

    /** Stock and image-hosting names that only ever reach a title through a filename. */
    private const STOCK_WORDS = [
        'adobestock', 'depositphotos', 'dreamstime', 'freepik', 'gettyimages', 'istock',
        'istockphoto', 'pexels', 'pixabay', 'shutterstock', 'unsplash',
    ];

    /** Export and revision suffixes; they count only after the first word. */
    private const REVISION_WORD = '/^(v\d+|final|copy|edited|exported?|resized|cropped|scaled|hires|lowres|lores|retina|optimi[sz]ed|compressed|min|draft|rev\d*|bak|tmp|temp|thumb|thumbnail|\d+px|\d+w|[1-4]x)$/';

    public static function isFilenameStyle(string $title, string $filename): bool
    {
        $title = trim($title);
        if ($title === '') {
            return false;
        }

        if (preg_match(self::IMAGE_EXTENSION, $title) || preg_match(self::DIMENSIONS, $title) || preg_match(self::UUID, $title)) {
            return true;
        }

        $words = self::words($title);
        if ($words === []) {
            return false;
        }

        if (self::isSourceNumberPattern($words) || self::hasMachineToken($words) || self::isSlug($title, $words)) {
            return true;
        }

        $stem = pathinfo($filename, PATHINFO_FILENAME);

        if (self::isVerbatimFilename($title, $stem)) {
            return true;
        }

        return self::compact($title) === self::compact($stem) && self::hasFilenameResidue($words);
    }

    /**
     * Every word is a source word, filler, or a number, with at least one
     * number: `IMG 4032`, `Copy of IMG 4032`, `Screenshot 2026 01 04 At 10.22.11`,
     * `Artboard 1 Copy 2`, `20240101 123456`. A bare number needs four digits.
     *
     * @param string[] $words
     */
    private static function isSourceNumberPattern(array $words): bool
    {
        $hasSource = false;
        $hasNumber = false;
        $digits = 0;
        $longestNumber = 0;
        $hasShortCode = false;

        foreach ($words as $word) {
            if (preg_match('/^[a-z]?\d+[a-z]?$/', $word)) {
                $hasNumber = true;
                $length = strlen((string)preg_replace('/\D/', '', $word));
                $digits += $length;
                $longestNumber = max($longestNumber, $length);
                continue;
            }

            if (preg_match('/^(' . implode('|', self::SOURCE_WORDS) . '|' . implode('|', self::SHORT_CAMERA_CODES) . ')_?(\d{3,})$/', $word, $match)) {
                $hasSource = true;
                $hasNumber = true;
                $digits += strlen($match[2]);
                $longestNumber = max($longestNumber, strlen($match[2]));
                continue;
            }

            if (in_array($word, self::SOURCE_WORDS, true)) {
                $hasSource = true;
                continue;
            }

            if (in_array($word, self::SHORT_CAMERA_CODES, true)) {
                $hasShortCode = true;
                continue;
            }

            if (in_array($word, self::FILLER_WORDS, true)) {
                continue;
            }

            return false;
        }

        if (!$hasNumber) {
            return false;
        }

        if ($hasSource) {
            return true;
        }

        if ($hasShortCode) {
            return $longestNumber >= 3;
        }

        return $digits >= 4;
    }

    /**
     * A hash or generated id: a long run of letters and digits mixed together.
     *
     * @param string[] $words
     */
    private static function hasMachineToken(array $words): bool
    {
        foreach ($words as $word) {
            if (!preg_match('/\d/', $word) || !preg_match('/[a-z]/', $word)) {
                continue;
            }

            if (preg_match('/^[0-9a-f]{12,}$/', $word) || preg_match('/^[a-z0-9]{16,}$/', $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A slug pasted in as the title: no spaces, joined by underscores, or
     * lowercase and hyphenated with a number or at least three parts.
     * `Coca-Cola` and `Wi-Fi` keep their capitals and are left alone.
     *
     * @param string[] $words
     */
    private static function isSlug(string $title, array $words): bool
    {
        if (preg_match('/\s/u', $title) || count($words) < 2) {
            return false;
        }

        if (preg_match('/[\p{L}\p{N}]_+[\p{L}\p{N}]/u', $title)) {
            return true;
        }

        if (!str_contains($title, '-') || mb_strtolower($title) !== $title) {
            return false;
        }

        return count($words) >= 3 || preg_match('/\d/', $title) === 1;
    }

    /**
     * The filename itself, unchanged, when it has separators or digits Craft
     * would have turned into spaces: `hero_banner`, `team-photo-2`.
     */
    private static function isVerbatimFilename(string $title, string $stem): bool
    {
        if ($stem === '' || mb_strtolower($title) !== mb_strtolower($stem)) {
            return false;
        }

        if (str_contains($stem, '_') || preg_match('/\d/', $stem)) {
            return true;
        }

        return str_contains($stem, '-') && mb_strtolower($title) === $title;
    }

    /**
     * Words that only a filename carries, checked once the title is known to
     * be the filename re-spaced: a revision suffix after the first word
     * (`Hero banner final v2`), a stock-site name, a five-plus digit run, or
     * a date (`Team 2024 01 15`).
     *
     * @param string[] $words
     */
    private static function hasFilenameResidue(array $words): bool
    {
        foreach ($words as $index => $word) {
            if ($index > 0 && preg_match(self::REVISION_WORD, $word)) {
                return true;
            }

            if (in_array($word, self::STOCK_WORDS, true) || preg_match('/\d{5,}/', $word)) {
                return true;
            }
        }

        return preg_match('/(^| )(19|20)\d{2} \d{1,2} \d{1,2}( |$)/', implode(' ', $words)) === 1;
    }

    /**
     * @return string[]
     */
    private static function words(string $value): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($value), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    private static function compact(string $value): string
    {
        return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
    }
}
