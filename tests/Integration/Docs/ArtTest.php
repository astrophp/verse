<?php

declare(strict_types=1);

namespace Astro\Verse\Tests\Integration\Docs;

use Astro\Verse\Tests\Support\Docs\Pages;
use Astro\Verse\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The README's images are the files in art/.
 *
 * The README points at its images by absolute URL on the default branch, so they also render on
 * Packagist. A broken name would only show up on GitHub after a merge, so it is checked here: every
 * such URL names a file in art/, every image has alt text, every screenshot has a light and a dark
 * file, nothing in art/ is left unused, and the screenshots have the size they are taken at.
 */
final class ArtTest extends TestCase
{
    private const string ART_URL = 'https://raw.githubusercontent.com/astrophp/verse/main/art/';

    private const string GOOD_PICTURE = <<<'HTML'
        <picture>
          <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/astrophp/verse/main/art/a-dark.png">
          <img src="https://raw.githubusercontent.com/astrophp/verse/main/art/a-light.png" alt="A thing" width="100">
        </picture>
        HTML;

    #[Test]
    public function it_names_only_files_that_are_in_art_with_alt_text_both_themes_and_no_orphans(): void
    {
        $readme = Pages::read(Pages::root().'/README.md');

        $this->assertNotEmpty(self::artNamed($readme));
        $this->assertSame([], self::artProblems($readme, self::artFiles()));
    }

    #[Test]
    public function it_gives_the_screenshots_and_the_social_preview_the_size_they_are_taken_at(): void
    {
        $wrong = [];

        foreach (glob(Pages::root().'/art/*.png') ?: [] as $file) {
            $size = getimagesize($file);
            $expected = basename($file) === 'social-preview.png' ? [1280, 640] : [2880, 1800];

            if ($size === false || [$size[0], $size[1]] !== $expected) {
                $wrong[] = basename($file).' is '.($size === false ? 'not an image' : "{$size[0]}x{$size[1]}").', not '.implode('x', $expected);
            }
        }

        $this->assertFileExists(Pages::root().'/art/social-preview.png');
        $this->assertNotEmpty(glob(Pages::root().'/art/*-light.png'));
        $this->assertSame([], $wrong);
    }

    #[Test]
    public function it_notices_a_missing_file_a_missing_alt_text_a_lost_variant_and_an_orphan(): void
    {
        $good = self::GOOD_PICTURE;
        $files = ['a-light.png', 'a-dark.png'];

        $this->assertSame([], self::artProblems($good, $files));
        $this->assertSame(['a-dark.png is not in art/'], self::artProblems($good, ['a-light.png']));
        $this->assertSame([self::ART_URL.'a-light.png has no alt text'], self::artProblems(str_replace('alt="A thing"', 'alt=""', $good), $files));
        $this->assertSame([self::ART_URL.'a-light.png has no alt text'], self::artProblems(str_replace(' alt="A thing"', '', $good), $files));
        $this->assertSame(
            ['a-light.png has no -dark variant in the README', 'a-light.png: the dark <source> must come before the <img>', 'a-dark.png is in art/ but the README does not use it'],
            self::artProblems((string) preg_replace('/<source.*>\n/', '', $good), $files),
        );
        $this->assertSame(['old.png is in art/ but the README does not use it'], self::artProblems($good, [...$files, 'old.png']));
    }

    #[Test]
    public function it_notices_an_image_that_is_not_an_absolute_url_on_main_and_a_picture_put_together_wrongly(): void
    {
        $good = self::GOOD_PICTURE;
        $files = ['a-light.png', 'a-dark.png'];

        $this->assertNotSame([], self::artProblems(str_replace('https://raw.githubusercontent.com/astrophp/verse/main/', '', $good), $files));
        $this->assertContains(
            'https://raw.githubusercontent.com/astrophp/verse/dev/art/a-light.png is an art/ image but not an absolute URL on main',
            self::artProblems(str_replace('/main/art/a-light', '/dev/art/a-light', $good), $files),
        );
        $this->assertContains(
            'a-light.png: the dark <source> must come before the <img>',
            self::artProblems((string) preg_replace('#^(\s*)(<source.*>)\n(\s*)(<img.*>)#m', '$1$4'."\n".'$3$2', $good), $files),
        );
        $this->assertContains(
            'a-light.png: the <source> must name the -dark file and the <img> the -light file',
            self::artProblems(str_replace('a-dark.png', 'a-light.png', $good), $files),
        );
        $this->assertContains(
            'a-light.png: the <source> is not for prefers-color-scheme: dark',
            self::artProblems(str_replace('(prefers-color-scheme: dark)', '(min-width: 600px)', $good), $files),
        );
        $this->assertContains(
            'a-light.png: the <source> and the <img> name different images',
            self::artProblems(str_replace('srcset="https://raw.githubusercontent.com/astrophp/verse/main/art/a-dark.png"', 'srcset="https://raw.githubusercontent.com/astrophp/verse/main/art/b-dark.png"', $good), $files),
        );
    }

    /**
     * The README's image tags: <img>, with its src and alt. Alt is null when the tag has none.
     *
     * @return list<array{src: string, alt: ?string}>
     */
    private static function artImages(string $readme): array
    {
        preg_match_all('/<img\b[^>]*>/i', $readme, $tags);

        return array_map(static function (string $tag): array {
            preg_match('/\bsrc="([^"]*)"/i', $tag, $src);
            $hasAlt = preg_match('/\balt="([^"]*)"/i', $tag, $alt) === 1;

            return ['src' => $src[1] ?? '', 'alt' => $hasAlt ? $alt[1] : null];
        }, $tags[0]);
    }

    /**
     * Every URL of an image the README uses: <img src> and <source srcset>.
     *
     * @return list<string>
     */
    private static function artReferences(string $readme): array
    {
        preg_match_all('/\bsrcset="([^"]*)"/i', $readme, $sets);

        return array_values(array_unique([...array_column(self::artImages($readme), 'src'), ...$sets[1]]));
    }

    /**
     * The files of art/ that the README names by absolute URL.
     *
     * @return list<string>
     */
    private static function artNamed(string $readme): array
    {
        $names = [];

        foreach (self::artReferences($readme) as $url) {
            if (str_starts_with($url, self::ART_URL)) {
                $names[] = substr($url, strlen(self::ART_URL));
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * What is wrong with the README's images, given the files in art/.
     *
     * @param  list<string>  $files  the names of the files in art/, README.md and the social preview left out
     * @return list<string>
     */
    private static function artProblems(string $readme, array $files): array
    {
        $problems = [];
        $named = self::artNamed($readme);

        foreach ($named as $name) {
            if (! in_array($name, $files, true)) {
                $problems[] = "{$name} is not in art/";
            }
        }

        foreach (self::artImages($readme) as $image) {
            if (trim((string) $image['alt']) === '') {
                $problems[] = "{$image['src']} has no alt text";
            }

            if ($image['src'] !== '' && ! str_starts_with($image['src'], 'https://') && ! file_exists(Pages::root().'/'.$image['src'])) {
                $problems[] = "{$image['src']} does not exist";
            }
        }

        foreach ($named as $name) {
            foreach (['-light' => '-dark', '-dark' => '-light'] as $from => $to) {
                $pair = preg_replace('/'.$from.'\.(png|svg)$/', $to.'.$1', $name);

                if ($pair !== $name && ! in_array($pair, $named, true)) {
                    $problems[] = "{$name} has no {$to} variant in the README";
                }
            }
        }

        foreach (self::artReferences($readme) as $url) {
            if (preg_match('#(^|/)art/#', $url) === 1 && ! str_starts_with($url, self::ART_URL)) {
                $problems[] = "{$url} is an art/ image but not an absolute URL on main";
            }
        }

        preg_match_all('#<picture>(.*?)</picture>#s', $readme, $pictures);

        foreach ($pictures[1] as $picture) {
            $source = strpos($picture, '<source');
            $image = strpos($picture, '<img');
            preg_match('/<source\b[^>]*>/', $picture, $sourceTag);
            $sourceHtml = $sourceTag[0] ?? '';
            preg_match('/\bsrcset="([^"]*)"/', $sourceHtml, $dark);
            preg_match('/<img\b[^>]*\bsrc="([^"]*)"/', $picture, $light);
            $darkUrl = $dark[1] ?? '';
            $lightUrl = $light[1] ?? '';
            $label = basename($light[1] ?? 'picture');

            if ($source === false || $image === false || $source > $image) {
                $problems[] = "{$label}: the dark <source> must come before the <img>";
            } elseif (! str_contains($sourceHtml, 'media="(prefers-color-scheme: dark)"')) {
                $problems[] = "{$label}: the <source> is not for prefers-color-scheme: dark";
            } elseif (preg_match('/-dark\.\w+$/', $darkUrl) !== 1 || preg_match('/-light\.\w+$/', $lightUrl) !== 1) {
                $problems[] = "{$label}: the <source> must name the -dark file and the <img> the -light file";
            } elseif (preg_replace('/-dark(\.\w+)$/', '-light$1', $darkUrl) !== $lightUrl) {
                $problems[] = "{$label}: the <source> and the <img> name different images";
            }
        }

        foreach ($files as $file) {
            if (! in_array($file, $named, true)) {
                $problems[] = "{$file} is in art/ but the README does not use it";
            }
        }

        return array_values(array_unique($problems));
    }

    /**
     * @return list<string>
     */
    private static function artFiles(): array
    {
        $files = array_map('basename', glob(Pages::root().'/art/*') ?: []);

        return array_values(array_diff($files, ['README.md', 'social-preview.png']));
    }
}
