<?php

declare(strict_types=1);

namespace SilverShop\ProductGallery\Model;

use SilverShop\ProductGallery\Service\VideoEmbed;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

/**
 * A single media item in a product (or variation) gallery: an image, or an embedded (YouTube/Vimeo) video. It carries
 * a caption and a sort position and attaches to its owner through a polymorphic has_one, so one class serves both
 * {@see \SilverShop\Page\Product} and {@see \SilverShop\Model\Variation\Variation}.
 *
 * The media type is driven by {@see self::$db} `Kind`:
 *  - `image`       — an uploaded {@see $Image} (the original, image-only behaviour; existing rows default here).
 *  - `video_embed` — an external YouTube/Vimeo video ({@see $EmbedUrl} + a required {@see Poster} image).
 *  - `video_file`  — self-hosted upload. Reserved for v1.2; the authoring UI for it is intentionally deferred, but
 *                    the enum value exists so the schema is forward-compatible.
 *
 * @property string $Title
 * @property int    $Sort
 * @property string $Kind
 * @property string $EmbedUrl
 * @property int    $ParentID
 * @property string $ParentClass
 * @property int    $ImageID
 * @property int    $PosterID
 * @method DataObject Parent()
 * @method Image Image()
 * @method Image Poster()
 */
class GalleryItem extends DataObject
{
    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO_EMBED = 'video_embed';

    public const KIND_VIDEO_FILE = 'video_file';

    private static string $table_name = 'SilverShop_GalleryItem';

    private static string $singular_name = 'Gallery Item';

    private static string $plural_name = 'Gallery Items';

    private static string $default_sort = 'Sort ASC';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Sort' => 'Int',
        'Kind' => "Enum('image,video_embed,video_file', 'image')",
        'EmbedUrl' => 'Varchar(255)',
    ];

    private static array $defaults = [
        'Kind' => self::KIND_IMAGE,
    ];

    private static array $has_one = [
        // Polymorphic: a Product or a Variation.
        'Parent' => DataObject::class,
        'Image' => Image::class,
        // Thumbnail / poster for video items (the grid thumb + the frame shown before the video plays).
        'Poster' => Image::class,
    ];

    private static array $owns = [
        'Image',
        'Poster',
    ];

    public function summaryFields(): array
    {
        return [
            'Thumbnail.StripThumbnail' => _t(self::class . '.ImageColumn', 'Image'),
            'KindLabel' => _t(self::class . '.KindColumn', 'Type'),
            'Title' => _t(self::class . '.CaptionColumn', 'Caption'),
        ];
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['Sort', 'ParentID', 'ParentClass']);

        // v1 offers image + embedded video. Self-hosted video (video_file) authoring is deferred to v1.2, so it is
        // left out of the dropdown while staying a valid enum value at the schema level.
        $kind = $fields->dataFieldByName('Kind');
        if ($kind instanceof DropdownField) {
            $kind->setSource([
                self::KIND_IMAGE => _t(self::class . '.KindImage', 'Image'),
                self::KIND_VIDEO_EMBED => _t(self::class . '.KindVideoEmbed', 'Embedded video (YouTube / Vimeo)'),
            ]);
            $kind->setDescription(_t(
                self::class . '.KindDescription',
                'Pick "Embedded video" to show a YouTube or Vimeo clip instead of an image.'
            ));
        }

        // display-logic is not a dependency, so the video fields are always shown with clear descriptions and the
        // required ones are enforced in validate(); a theme that installs display-logic can hide/show by Kind.
        $fields->dataFieldByName('EmbedUrl')?->setDescription(_t(
            self::class . '.EmbedUrlDescription',
            'Paste a YouTube or Vimeo link (watch, share or embed URL). Required when the type is "Embedded video".'
        ));
        $fields->dataFieldByName('Poster')?->setDescription(_t(
            self::class . '.PosterDescription',
            'Shown as the gallery thumbnail and before the video plays. Required when the type is "Embedded video".'
        ));
        $fields->dataFieldByName('Image')?->setDescription(_t(
            self::class . '.ImageDescription',
            'Used when the type is "Image".'
        ));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();

        if ($this->Kind === self::KIND_VIDEO_EMBED) {
            $url = trim((string) $this->EmbedUrl);
            if ($url === '') {
                $result->addFieldError('EmbedUrl', _t(
                    self::class . '.EmbedUrlRequired',
                    'Enter a YouTube or Vimeo URL for an embedded video.'
                ));
            } elseif (!VideoEmbed::isValid($url)) {
                $result->addFieldError('EmbedUrl', _t(
                    self::class . '.EmbedUrlInvalid',
                    'That does not look like a YouTube or Vimeo link. Only YouTube and Vimeo are supported.'
                ));
            }

            if (!$this->PosterID) {
                $result->addFieldError('Poster', _t(
                    self::class . '.PosterRequired',
                    'Add a poster image for the video (used as the thumbnail and the still shown before play).'
                ));
            }
        }

        return $result;
    }

    /**
     * Whether this row renders as an image (the default; empty Kind is treated as image for back-compat).
     */
    public function IsImage(): bool
    {
        return $this->Kind === self::KIND_IMAGE || $this->Kind === '' || $this->Kind === null;
    }

    /**
     * Whether this row renders as a video of any kind.
     */
    public function IsVideo(): bool
    {
        return in_array($this->Kind, [self::KIND_VIDEO_EMBED, self::KIND_VIDEO_FILE], true);
    }

    /**
     * Whether this row is an external (YouTube/Vimeo) embed.
     */
    public function IsEmbed(): bool
    {
        return $this->Kind === self::KIND_VIDEO_EMBED;
    }

    /**
     * The image used for the grid thumbnail: the uploaded Image for image rows, the Poster for video rows. Returns
     * null when the relevant image is missing.
     */
    public function Thumbnail(): ?Image
    {
        if ($this->IsVideo()) {
            $poster = $this->Poster();

            return ($poster && $poster->exists()) ? $poster : null;
        }

        $image = $this->Image();

        return ($image && $image->exists()) ? $image : null;
    }

    /**
     * The normalised privacy-enhanced embed URL for an embed row, or null.
     */
    public function EmbedSrc(): ?string
    {
        if (!$this->IsEmbed() || !$this->EmbedUrl) {
            return null;
        }

        return VideoEmbed::embedSrc((string) $this->EmbedUrl);
    }

    /**
     * The provider slug (youtube|vimeo) for an embed row, or null.
     */
    public function VideoProvider(): ?string
    {
        if (!$this->IsEmbed() || !$this->EmbedUrl) {
            return null;
        }

        return VideoEmbed::provider((string) $this->EmbedUrl);
    }

    /**
     * Human-readable label for the Kind, for the CMS summary column.
     */
    public function getKindLabel(): string
    {
        return match ($this->Kind) {
            self::KIND_VIDEO_EMBED => _t(self::class . '.KindVideoEmbed', 'Embedded video (YouTube / Vimeo)'),
            self::KIND_VIDEO_FILE => _t(self::class . '.KindVideoFile', 'Video file'),
            default => _t(self::class . '.KindImage', 'Image'),
        };
    }
}
