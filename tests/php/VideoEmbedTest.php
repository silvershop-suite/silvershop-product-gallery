<?php

declare(strict_types=1);

namespace SilverShop\ProductGallery\Tests;

use SilverShop\ProductGallery\Service\VideoEmbed;
use SilverStripe\Dev\SapphireTest;

class VideoEmbedTest extends SapphireTest
{
    protected $usesDatabase = false;

    /**
     * Every supported YouTube spelling parses to the same id and normalises to the privacy (nocookie) embed URL.
     */
    public function testYoutubeVariantsNormaliseToNoCookie(): void
    {
        $inputs = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'http://youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/watch?list=PLxyz&v=dQw4w9WgXcQ&t=10s',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'dQw4w9WgXcQ',
        ];

        $expected = 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ';
        foreach ($inputs as $input) {
            $parsed = VideoEmbed::parse($input);
            $this->assertNotNull($parsed, $input);
            $this->assertSame(VideoEmbed::PROVIDER_YOUTUBE, $parsed['provider'], $input);
            $this->assertSame('dQw4w9WgXcQ', $parsed['id'], $input);
            $this->assertSame($expected, $parsed['embedSrc'], $input);
            $this->assertSame($expected, VideoEmbed::embedSrc($input), $input);
        }
    }

    /**
     * Every supported Vimeo spelling parses to the same id and normalises to the do-not-track embed URL.
     */
    public function testVimeoVariantsNormaliseToDoNotTrack(): void
    {
        $inputs = [
            'https://vimeo.com/76979871',
            'https://player.vimeo.com/video/76979871',
            'https://vimeo.com/channels/staffpicks/76979871',
            'https://vimeo.com/76979871?share=copy',
            '76979871',
        ];

        foreach ($inputs as $input) {
            $parsed = VideoEmbed::parse($input);
            $this->assertNotNull($parsed, $input);
            $this->assertSame(VideoEmbed::PROVIDER_VIMEO, $parsed['provider'], $input);
            $this->assertSame('76979871', $parsed['id'], $input);
            $this->assertSame('https://player.vimeo.com/video/76979871?dnt=1', $parsed['embedSrc'], $input);
        }
    }

    /**
     * Only YouTube and Vimeo are allow-listed; anything else is rejected (so no arbitrary iframe src is ever produced).
     */
    public function testRejectsNonAllowlistedInput(): void
    {
        $inputs = [
            'https://example.com/video/123',
            'https://www.dailymotion.com/video/x7xyz',
            'https://malicious.test/evil',
            'not a url',
            '',
            '   ',
        ];

        foreach ($inputs as $input) {
            $this->assertNull(VideoEmbed::parse($input), $input);
            $this->assertFalse(VideoEmbed::isValid($input), $input);
            $this->assertNull(VideoEmbed::embedSrc($input), $input);
            $this->assertNull(VideoEmbed::provider($input), $input);
        }
    }

    /**
     * The cookieconsent soft-detection returns true/false purely on whether the class exists — tested both ways by
     * passing an existing and a non-existing class name.
     */
    public function testCookieConsentDetectionBothWays(): void
    {
        $this->assertTrue(VideoEmbed::cookieConsentAvailable(self::class));
        $this->assertFalse(VideoEmbed::cookieConsentAvailable('No\\Such\\Class\\Here'));
    }
}
