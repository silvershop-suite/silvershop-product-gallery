<?php

declare(strict_types=1);

namespace SilverShop\ProductGallery\Enrichment;

use SilverShop\Api\Enrichment\ProductEnrichmentExtension;
use SilverShop\Page\Product;
use SilverStripe\Assets\Image;

/**
 * Adds a `media` array to the serialised product when silvershop-suite/product-gallery is installed: the video-aware
 * superset of the images-only `gallery` field (which stays, emitted by the api module's GalleryProductEnrichment).
 *
 * Each row rides {@see \SilverShop\ProductGallery\Extension\GalleryExtension::getAllMedia()} — the leading primary
 * image folded in, then the sorted gallery items of every kind. For an embedded (YouTube/Vimeo) item we expose only
 * the already-normalised privacy embed URL (youtube-nocookie / Vimeo ?dnt=1), the poster and the consent category,
 * so a client can consent-gate / click-to-load it (mirroring the storefront's `[consent_embed]` contract); no
 * third-party tracker is ever inlined.
 *
 * Lives in the gallery module (not api) so the module owns its own api projection; registered onto
 * {@see \SilverShop\Api\Serializer\ProductSerializer} only when silvershop-suite/api is installed (see _config/api.yml).
 */
class GalleryMediaProductEnrichment extends ProductEnrichmentExtension
{
    /**
     * The cookieconsent category a visitor must grant before an embed loads. Mirrors the storefront's
     * GalleryEmbed.ss placeholder (`data-consent-category="marketing"`) so the api and the theme agree.
     */
    private const CONSENT_CATEGORY = 'marketing';

    /**
     * @param array<string, mixed> $data
     */
    public function updateSerialisedProduct(array &$data, Product $product): void
    {
        $media = $this->accessor($product, 'getAllMedia');
        if (!is_iterable($media)) {
            return;
        }

        $out = [];
        foreach ($media as $row) {
            $isEmbed = (bool) $row->IsEmbed;
            $embedSrc = $row->EmbedSrc;

            $out[] = [
                'kind' => (string) $row->Kind,
                'image' => $this->serialiseImage($row->Image),
                'embedSrc' => ($isEmbed && is_string($embedSrc) && $embedSrc !== '') ? $embedSrc : null,
                'poster' => $this->serialiseImage($row->Poster),
                'consentCategory' => $isEmbed ? self::CONSENT_CATEGORY : null,
            ];
        }

        $data['media'] = $out;
    }

    /**
     * Serialise an image to the ShopImage shape (or null when absent/invalid). Inlined here — like the sibling
     * GalleryProductEnrichment — because the serializer's own protected serialiseImage() is not reachable from an
     * extension; this additionally carries width/height to fully populate ShopImage.
     *
     * @return array{id:int,url:string,alt:string,width:?int,height:?int}|null
     */
    private function serialiseImage(mixed $image): ?array
    {
        if (!($image instanceof Image) || !$image->exists()) {
            return null;
        }

        $width = (int) $image->getWidth();
        $height = (int) $image->getHeight();

        return [
            'id' => (int) $image->ID,
            'url' => (string) $image->getAbsoluteURL(),
            'alt' => (string) ($image->Title ?: $image->Name),
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
        ];
    }
}
