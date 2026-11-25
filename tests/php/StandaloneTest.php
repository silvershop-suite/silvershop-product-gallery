<?php

declare(strict_types=1);

namespace SilverShop\ProductGallery\Tests;

use SilverShop\Api\Enrichment\ProductEnrichmentExtension;
use SilverShop\ProductGallery\Enrichment\GalleryMediaProductEnrichment;
use SilverStripe\Dev\SapphireTest;

/**
 * silvershop-suite/api is optional: the class that plugs into it exists only when that module does.
 * CI also runs this suite without it, where a class that can't be loaded fails the first flush.
 */
class StandaloneTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testOptionalClassFollowsItsModule(): void
    {
        $this->assertSame(
            class_exists(ProductEnrichmentExtension::class),
            class_exists(GalleryMediaProductEnrichment::class)
        );
    }
}
