<?php

declare(strict_types=1);

namespace SilverShop\ProductGallery\Extension;

use Colymba\BulkUpload\BulkUploader;
use SilverShop\Model\Variation\Variation;
use SilverShop\ProductGallery\Model\GalleryItem;
use SilverShop\ProductGallery\Service\VideoEmbed;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\HasManyList;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;

/**
 * Adds a sortable media gallery (images + consent-gated YouTube/Vimeo video embeds) to the decorated class. Applied to
 * both {@see \SilverShop\Page\Product} and {@see \SilverShop\Model\Variation\Variation}; the gallery items attach
 * through {@see GalleryItem}'s polymorphic Parent relation, so one relation definition serves both owners.
 *
 * The owner is typed as Product for static analysis (the gallery relation is shared by both owners, and the
 * Variation-only branches are guarded by instanceof at runtime).
 *
 * @extends Extension<\SilverShop\Page\Product>
 * @method HasManyList<GalleryItem> GalleryItems()
 */
class GalleryExtension extends Extension
{
    private static array $has_many = [
        'GalleryItems' => GalleryItem::class . '.Parent',
    ];

    private static array $owns = [
        'GalleryItems',
    ];

    private static array $cascade_deletes = [
        'GalleryItems',
    ];

    public function updateCMSFields(FieldList $fields): void
    {
        $owner = $this->getOwner();

        // Drop the auto-scaffolded GalleryItems grid and its empty "Gallery items" tab; we render our own gallery
        // (below) on the Images tab, next to the core primary Product Image.
        $fields->removeByName('GalleryItems');

        // A has_many needs the owner to exist before rows can be attached to it.
        if (!$owner->isInDB()) {
            $this->addGalleryField($fields, LiteralField::create(
                'GalleryItemsSaveFirst',
                '<p class="message notice">'
                . _t(self::class . '.SaveFirst', 'Save first, then add gallery images here.')
                . '</p>'
            ));
            return;
        }

        $config = GridFieldConfig_RecordEditor::create();
        // Drag-to-reorder the rows (writes GalleryItem.Sort).
        $config->addComponent(GridFieldOrderableRows::create('Sort'));
        // Multi-file drag-and-drop: each dropped file creates a GalleryItem with its Image set.
        $config->addComponent(new BulkUploader('Image'));

        $this->addGalleryField($fields, GridField::create(
            'GalleryItems',
            _t(self::class . '.GalleryImages', 'Gallery images'),
            $owner->GalleryItems(),
            $config
        ));
    }

    /**
     * All images for this owner: the primary Image (if set) followed by the sorted gallery images. A variation with
     * no gallery of its own falls back to its product's gallery.
     *
     * @return ArrayList<Image>
     */
    public function getAllImages(): ArrayList
    {
        $owner = $this->getOwner();
        $images = ArrayList::create();

        $primary = $owner->hasMethod('Image') ? $owner->Image() : null;
        if ($primary && $primary->exists()) {
            $images->push($primary);
        }

        foreach ($owner->GalleryItems() as $item) {
            $image = $item->Image();
            if ($image && $image->exists()) {
                $images->push($image);
            }
        }

        if ($images->count() === 0 && $owner instanceof Variation) {
            $product = $owner->Product();
            if ($product && $product->exists()) {
                return $product->getAllImages();
            }
        }

        return $images;
    }

    /**
     * All media for this owner as uniform view-model rows: the primary Image (if set) folded in as the leading
     * `image` row, followed by the sorted gallery items of every kind. A variation with no media of its own falls
     * back to its product's media (mirrors {@see self::getAllImages()}).
     *
     * This is the video-aware accessor; {@see self::getAllImages()} is deliberately left unchanged (image-only) so
     * existing themes, loops and the api enrichment keep working. Each row is an {@see ArrayData} with a documented
     * shape so the synthetic primary image and real gallery items read identically in a template:
     *
     *  - `Kind`                   string  image|video_embed|video_file
     *  - `Title`                  string  caption / alt text (video rows: the video title)
     *  - `Image`                  Image|null   the image (image rows only)
     *  - `Poster`                 Image|null   the poster (video rows only)
     *  - `Thumbnail`              Image|null   grid thumb: Image for image rows, Poster for video rows
     *  - `EmbedSrc`               string|null  normalised privacy embed URL (video_embed rows)
     *  - `Provider`               string|null  youtube|vimeo (video_embed rows)
     *  - `IsImage`/`IsVideo`/`IsEmbed`  bool   render-branch flags
     *  - `CookieConsentAvailable` bool    whether the cookieconsent module is present
     *  - `GalleryItemID`          int     source item id (0 for the synthetic primary image)
     *
     * @return ArrayList<ArrayData>
     */
    public function getAllMedia(): ArrayList
    {
        $owner = $this->getOwner();
        $media = ArrayList::create();

        $primary = $owner->hasMethod('Image') ? $owner->Image() : null;
        if ($primary && $primary->exists()) {
            $media->push($this->imageRow($primary, (string) $owner->Title, 0));
        }

        foreach ($owner->GalleryItems() as $item) {
            $row = $this->mediaRow($item);
            if ($row !== null) {
                $media->push($row);
            }
        }

        if ($media->count() === 0 && $owner instanceof Variation) {
            $product = $owner->Product();
            if ($product && $product->exists()) {
                return $product->getAllMedia();
            }
        }

        return $media;
    }

    /**
     * Whether the cookieconsent module is installed, so a template can emit its `[consent_embed]` placeholder
     * contract (and let its runtime load the iframe) rather than the module's own click-to-load fallback.
     */
    public function CookieConsentAvailable(): bool
    {
        return VideoEmbed::cookieConsentAvailable();
    }

    /**
     * Build a view-model row for a gallery item, or null when it has nothing renderable (e.g. an image row with no
     * image, or an embed row with no valid URL).
     */
    private function mediaRow(GalleryItem $item): ?ArrayData
    {
        if ($item->IsEmbed()) {
            $src = $item->EmbedSrc();
            if (!$src) {
                return null;
            }

            $poster = $item->Poster();
            $poster = ($poster && $poster->exists()) ? $poster : null;

            return ArrayData::create([
                'Kind' => GalleryItem::KIND_VIDEO_EMBED,
                'Title' => (string) $item->Title,
                'Image' => null,
                'Poster' => $poster,
                'Thumbnail' => $poster,
                'EmbedSrc' => $src,
                'Provider' => $item->VideoProvider(),
                'IsImage' => false,
                'IsVideo' => true,
                'IsEmbed' => true,
                'CookieConsentAvailable' => VideoEmbed::cookieConsentAvailable(),
                'GalleryItemID' => (int) $item->ID,
            ]);
        }

        // Non-embed rows (image, and the deferred self-hosted video_file) render from the uploaded Image when present.
        $image = $item->Image();
        if ($image && $image->exists()) {
            return $this->imageRow($image, (string) $item->Title, (int) $item->ID);
        }

        return null;
    }

    private function imageRow(Image $image, string $title, int $itemId): ArrayData
    {
        return ArrayData::create([
            'Kind' => GalleryItem::KIND_IMAGE,
            'Title' => $title,
            'Image' => $image,
            'Poster' => null,
            'Thumbnail' => $image,
            'EmbedSrc' => null,
            'Provider' => null,
            'IsImage' => true,
            'IsVideo' => false,
            'IsEmbed' => false,
            'CookieConsentAvailable' => VideoEmbed::cookieConsentAvailable(),
            'GalleryItemID' => $itemId,
        ]);
    }

    private function addGalleryField(FieldList $fields, FormField $field): void
    {
        // Product has a tabbed field list (place on the Images tab); Variation's is flat (just append).
        if ($fields->hasTabSet()) {
            $fields->addFieldToTab('Root.Images', $field);
        } else {
            $fields->push($field);
        }
    }
}
