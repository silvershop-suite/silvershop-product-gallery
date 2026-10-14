<?php

declare(strict_types=1);

namespace SilverShop\ProductGallery\Service;

/**
 * Normalises an author-supplied YouTube or Vimeo reference (watch URL, share URL, embed URL or a bare id) into a
 * privacy-enhanced embed URL, and parses out the provider + video id.
 *
 * Only YouTube and Vimeo are allow-listed: an arbitrary iframe `src` is never produced, so author input can never
 * inject a hostile embed. The normalised URLs are the cookie-light variants — `youtube-nocookie.com` and Vimeo's
 * `?dnt=1` (do-not-track) — which still only load once the visitor consents / clicks (see the consent-gated
 * template), so nothing third-party is fetched before then.
 */
class VideoEmbed
{
    public const PROVIDER_YOUTUBE = 'youtube';

    public const PROVIDER_VIMEO = 'vimeo';

    /**
     * Parse a reference into its provider, id and normalised privacy embed URL.
     *
     * @return array{provider: string, id: string, embedSrc: string}|null null when the input is not a recognised
     *                                                                     YouTube/Vimeo reference.
     */
    public static function parse(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $matches = [];

        // YouTube: watch?v=, youtu.be/, /embed/, /shorts/, /v/ (also the -nocookie host).
        $youtube = '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^#]*&)?v=|embed/|shorts/|v/)|youtu\.be/)([\w-]{11})~i';
        if (preg_match($youtube, $input, $matches)) {
            return self::youtube($matches[1]);
        }

        // Vimeo: vimeo.com/{id}, player.vimeo.com/video/{id}, channels/groups/album paths.
        if (preg_match('~vimeo\.com/(?:[^?#]*/)?(\d+)~i', $input, $matches)) {
            return self::vimeo($matches[1]);
        }

        // Bare id (no scheme/host/path): numeric => Vimeo, 11-char token => YouTube.
        if (!preg_match('~[/.]~', $input)) {
            if (preg_match('~^\d+$~', $input)) {
                return self::vimeo($input);
            }
            if (preg_match('~^[\w-]{11}$~', $input)) {
                return self::youtube($input);
            }
        }

        return null;
    }

    /**
     * The normalised privacy-enhanced embed URL, or null when the input is not a recognised reference.
     */
    public static function embedSrc(string $input): ?string
    {
        $parsed = self::parse($input);

        return $parsed['embedSrc'] ?? null;
    }

    /**
     * The provider slug (youtube|vimeo), or null when the input is not a recognised reference.
     */
    public static function provider(string $input): ?string
    {
        $parsed = self::parse($input);

        return $parsed['provider'] ?? null;
    }

    /**
     * The parsed video id, or null when the input is not a recognised reference.
     */
    public static function id(string $input): ?string
    {
        $parsed = self::parse($input);

        return $parsed['id'] ?? null;
    }

    /**
     * Whether the input is a recognised (allow-listed) YouTube/Vimeo reference.
     */
    public static function isValid(string $input): bool
    {
        return self::parse($input) !== null;
    }

    /**
     * Soft-detect the cookieconsent module. Kept here (rather than inline) so the one class-name is defined once and
     * the detection can be unit-tested both ways by passing an explicit class name.
     */
    public static function cookieConsentAvailable(?string $className = null): bool
    {
        $className = $className ?? 'SilverShop\\CookieConsent\\Shortcode\\ConsentEmbed';

        return class_exists($className);
    }

    /**
     * @return array{provider: string, id: string, embedSrc: string}
     */
    private static function youtube(string $id): array
    {
        return [
            'provider' => self::PROVIDER_YOUTUBE,
            'id' => $id,
            'embedSrc' => 'https://www.youtube-nocookie.com/embed/' . $id,
        ];
    }

    /**
     * @return array{provider: string, id: string, embedSrc: string}
     */
    private static function vimeo(string $id): array
    {
        return [
            'provider' => self::PROVIDER_VIMEO,
            'id' => $id,
            'embedSrc' => 'https://player.vimeo.com/video/' . $id . '?dnt=1',
        ];
    }
}
