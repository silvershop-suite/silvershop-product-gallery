<?php

declare(strict_types=1);

namespace SilverShop\ProductGallery\Tests;

use SilverShop\Model\Variation\Variation;
use SilverShop\Page\Product;
use SilverShop\ProductGallery\Extension\GalleryExtension;
use SilverShop\ProductGallery\Model\GalleryItem;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\View\SSViewer;

class GalleryTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('ProductGalleryTest');
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testExtensionAppliedToProductAndVariation(): void
    {
        $this->assertTrue(
            Product::has_extension(GalleryExtension::class),
            'Product should have the gallery extension'
        );
        $this->assertTrue(
            Variation::has_extension(GalleryExtension::class),
            'Variation should have the gallery extension'
        );
    }

    public function testKindDefaultsToImage(): void
    {
        $item = GalleryItem::create();
        $this->assertSame(GalleryItem::KIND_IMAGE, $item->Kind, 'A new gallery item defaults to the image kind');
        $this->assertTrue($item->IsImage());
        $this->assertFalse($item->IsVideo());
        $this->assertFalse($item->IsEmbed());
    }

    public function testKindEnumAcceptsAllThreeValues(): void
    {
        $values = GalleryItem::singleton()->dbObject('Kind')->enumValues();
        $this->assertSame(
            [GalleryItem::KIND_IMAGE, GalleryItem::KIND_VIDEO_EMBED, GalleryItem::KIND_VIDEO_FILE],
            array_values($values)
        );
    }

    public function testGalleryItemsAreSortedAndParentIsPolymorphic(): void
    {
        $product = Product::create(['Title' => 'Gallery Product', 'URLSegment' => 'gallery-product']);
        $product->write();

        $second = GalleryItem::create(['Title' => 'Second', 'Sort' => 2]);
        $second->ParentID = $product->ID;
        $second->ParentClass = Product::class;
        $second->write();

        $first = GalleryItem::create(['Title' => 'First', 'Sort' => 1]);
        $first->ParentID = $product->ID;
        $first->ParentClass = Product::class;
        $first->write();

        $items = $product->GalleryItems();
        $this->assertCount(2, $items, 'Both gallery items belong to the product');
        $this->assertSame('First', $items->first()->Title, 'Gallery items come back in Sort ASC order');
        $this->assertInstanceOf(
            Product::class,
            $first->Parent(),
            'The polymorphic Parent resolves back to the owning Product'
        );
    }

    public function testGetAllMediaFoldsInPrimaryImageThenSortedItemsAndGetAllImagesStaysImageOnly(): void
    {
        $product = Product::create(['Title' => 'Media Product', 'URLSegment' => 'media-product']);
        $product->ImageID = $this->makeImage('primary.png')->ID;
        $product->write();

        // A gallery image (Sort 1).
        $galleryImage = GalleryItem::create(['Title' => 'Shot', 'Sort' => 1, 'Kind' => GalleryItem::KIND_IMAGE]);
        $galleryImage->ParentID = $product->ID;
        $galleryImage->ParentClass = Product::class;
        $galleryImage->ImageID = $this->makeImage('shot.png')->ID;
        $galleryImage->write();

        // A YouTube embed (Sort 2) with a poster.
        $embed = GalleryItem::create([
            'Title' => 'Clip',
            'Sort' => 2,
            'Kind' => GalleryItem::KIND_VIDEO_EMBED,
            'EmbedUrl' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $embed->ParentID = $product->ID;
        $embed->ParentClass = Product::class;
        $embed->PosterID = $this->makeImage('poster.png')->ID;
        $embed->write();

        $media = $product->getAllMedia();
        $this->assertInstanceOf(ArrayList::class, $media);
        $this->assertCount(3, $media, 'Primary image + two gallery items');

        $rows = $media->toArray();
        // Primary core image folds in first as a synthetic image row.
        $this->assertSame(GalleryItem::KIND_IMAGE, $rows[0]->Kind);
        $this->assertSame(0, (int) $rows[0]->GalleryItemID, 'Synthetic primary row has no source item id');
        $this->assertTrue((bool) $rows[0]->IsImage);
        // Then the gallery items in Sort order.
        $this->assertSame(GalleryItem::KIND_IMAGE, $rows[1]->Kind);
        $this->assertSame('Shot', $rows[1]->Title);
        $this->assertSame(GalleryItem::KIND_VIDEO_EMBED, $rows[2]->Kind);
        $this->assertTrue((bool) $rows[2]->IsEmbed);
        $this->assertSame(
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            $rows[2]->EmbedSrc,
            'The embed row carries the normalised privacy URL'
        );
        $this->assertSame('youtube', $rows[2]->Provider);

        // getAllImages() is unchanged: image-only (primary + one gallery image), no video row.
        $images = $product->getAllImages();
        $this->assertCount(2, $images, 'getAllImages() ignores video items');
        foreach ($images as $image) {
            $this->assertInstanceOf(Image::class, $image, 'getAllImages() still returns raw Image objects');
        }
    }

    public function testGetAllMediaVariationFallsBackToProduct(): void
    {
        $product = Product::create(['Title' => 'P', 'URLSegment' => 'p-fallback']);
        $product->ImageID = $this->makeImage('p-primary.png')->ID;
        $product->write();

        $variation = Variation::create(['InternalItemID' => 'V1']);
        $variation->ProductID = $product->ID;
        $variation->write();

        $media = $variation->getAllMedia();
        $this->assertCount(1, $media, 'A variation with no media of its own falls back to its product');
        $this->assertSame(GalleryItem::KIND_IMAGE, $media->first()->Kind);
    }

    public function testVariationCanHaveItsOwnVideoEmbed(): void
    {
        // Per-variation video is free: the polymorphic Parent lets a Variation own gallery items just like a Product.
        $product = Product::create(['Title' => 'VP', 'URLSegment' => 'vp-video']);
        $product->ImageID = $this->makeImage('vp-primary.png')->ID;
        $product->write();

        $variation = Variation::create(['InternalItemID' => 'VV1']);
        $variation->ProductID = $product->ID;
        $variation->write();

        $embed = GalleryItem::create([
            'Title' => 'Variation clip',
            'Sort' => 1,
            'Kind' => GalleryItem::KIND_VIDEO_EMBED,
            'EmbedUrl' => 'https://vimeo.com/76979871',
        ]);
        $embed->ParentID = $variation->ID;
        $embed->ParentClass = Variation::class;
        $embed->PosterID = $this->makeImage('var-poster.png')->ID;
        $embed->write();

        $media = $variation->getAllMedia();
        // The variation now has its own media, so it does not fall back to the product.
        $kinds = array_map(fn ($row) => $row->Kind, $media->toArray());
        $this->assertContains(GalleryItem::KIND_VIDEO_EMBED, $kinds, 'A variation surfaces its own video embed');

        $embedRow = null;
        foreach ($media as $row) {
            if ($row->IsEmbed) {
                $embedRow = $row;
                break;
            }
        }
        $this->assertNotNull($embedRow);
        $this->assertSame('https://player.vimeo.com/video/76979871?dnt=1', $embedRow->EmbedSrc);
    }

    public function testVideoEmbedRequiresPosterAndValidProvider(): void
    {
        // Missing poster and the URL is fine -> invalid because of the poster.
        $noPoster = GalleryItem::create([
            'Kind' => GalleryItem::KIND_VIDEO_EMBED,
            'EmbedUrl' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);
        $this->assertFalse($noPoster->validate()->isValid(), 'An embed without a poster is invalid');

        // Poster present but a non-YouTube/Vimeo URL -> invalid because of the URL.
        $badUrl = GalleryItem::create([
            'Kind' => GalleryItem::KIND_VIDEO_EMBED,
            'EmbedUrl' => 'https://example.com/clip',
        ]);
        $badUrl->PosterID = $this->makeImage('v-poster.png')->ID;
        $this->assertFalse($badUrl->validate()->isValid(), 'A non-allow-listed URL is invalid');

        // Poster + valid URL -> valid.
        $ok = GalleryItem::create([
            'Kind' => GalleryItem::KIND_VIDEO_EMBED,
            'EmbedUrl' => 'https://vimeo.com/76979871',
        ]);
        $ok->PosterID = $this->makeImage('ok-poster.png')->ID;
        $this->assertTrue($ok->validate()->isValid(), 'A valid embed with a poster passes');

        // An image item has no such requirements.
        $image = GalleryItem::create(['Kind' => GalleryItem::KIND_IMAGE, 'Title' => 'Plain']);
        $this->assertTrue($image->validate()->isValid(), 'An image item is not subject to the embed rules');
    }

    public function testConsentEmbedPlaceholderContractRendersBothWays(): void
    {
        $base = [
            'IsEmbed' => true,
            'IsVideo' => true,
            'IsImage' => false,
            'EmbedSrc' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'Title' => 'Demo clip',
            'Poster' => null,
        ];

        $withConsent = (string) SSViewer::create('SilverShop\\Includes\\GalleryEmbed')
            ->process(ArrayData::create(array_merge($base, ['CookieConsentAvailable' => true])));
        $this->assertStringContainsString('data-consent-embed', $withConsent);
        $this->assertStringContainsString('data-consent-category="marketing"', $withConsent);
        $this->assertStringContainsString(
            'data-embed-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"',
            $withConsent
        );
        $this->assertStringContainsString('data-consent-embed-load', $withConsent);
        $this->assertStringContainsString('data-consent-embed-always', $withConsent);

        $fallback = (string) SSViewer::create('SilverShop\\Includes\\GalleryEmbed')
            ->process(ArrayData::create(array_merge($base, ['CookieConsentAvailable' => false])));
        $this->assertStringNotContainsString('data-consent-category', $fallback);
        $this->assertStringContainsString('data-gallery-embed', $fallback);
        $this->assertStringContainsString('data-gallery-embed-load', $fallback);
        $this->assertStringContainsString(
            'data-embed-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ"',
            $fallback
        );
    }

    public function testCookieConsentAvailableFlagIsBoolean(): void
    {
        $product = Product::create(['Title' => 'Flag', 'URLSegment' => 'flag']);
        $product->write();
        // cookieconsent is not installed in the clean-room, so detection is false here.
        $this->assertFalse($product->CookieConsentAvailable());
    }

    private function makeImage(string $filename): Image
    {
        $image = Image::create();
        $image->setFromString('fake-image-bytes', 'product-gallery-test/' . $filename);
        $image->write();

        return $image;
    }
}
