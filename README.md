# SilverShop Product Gallery

Sortable media galleries for [SilverShop](https://github.com/silvershop/silvershop-core) **products** and
**variations**. Core gives each product and each variation a single `has_one Image`; this adds an ordered set of
additional media on top of it — **images and embedded YouTube/Vimeo videos**, interleaved in one sortable grid
(the Shopify "product media" model).

The module ships the data model, the CMS admin (drag-to-sort + per-item caption + bulk upload), template accessors, and
an **overridable** frontend include. The bundled include carries small inline CSS and JavaScript (a thumbnail →
main-stage swap and the video click-to-load); it is meant to be dropped in or copied into your theme and restyled.
Rendering it yourself (see below) is pure markup — you size the images and lay out the videos however you like.

## Requirements

- `silvershop/core` ^6 (SilverStripe CMS 6)
- PHP ^8.3
- `symbiote/silverstripe-gridfieldextensions` ^5 (drag-to-sort; pulled in by core)
- `colymba/gridfield-bulk-editing-tools` ^5.1 (multi-file bulk upload)
- `silvershop/cookieconsent` *(suggested, not required)* — consent-gates the video embeds through its click-to-load
  runtime. Without it the gallery uses a built-in click-to-load fallback so no third-party content is fetched before an
  explicit click.

## Installation

```bash
composer require silvershop/product-gallery
```

Then run `dev/build?flush=1`.

## How it works

- A `GalleryItem` DataObject is one media item: an `Image` **or** an embedded video, plus a `Title` (caption / alt
  text / video title) and a `Sort` position. Its `Kind` is one of:
  - `image` — an uploaded `Image` (the original behaviour; existing rows default here, so upgrading is fully
    backward-compatible).
  - `video_embed` — an external YouTube/Vimeo video: an `EmbedUrl` plus a **required** `Poster` image.
  - `video_file` — self-hosted upload. Reserved for a later release; the enum value exists so the schema is
    forward-compatible, but the authoring UI for it is intentionally deferred.
- It attaches to its owner through a **polymorphic `Parent` has_one**, so the same class serves both products and
  variations — and **per-variation video comes for free**.
- A `GalleryExtension` is applied to `SilverShop\Page\Product` and `SilverShop\Model\Variation\Variation`, adding a
  `GalleryItems` has_many edited in a GridField with:
  - **drag-to-reorder** rows (`GridFieldOrderableRows`, writes `Sort`),
  - **multi-file bulk upload** — drag a batch of images onto the grid and each becomes an `image` `GalleryItem`
    (`colymba` `BulkUploader`); videos are added via the add-new form (pick the type, paste the URL, add a poster),
  - add/edit/delete single records with a caption.
  - On a **product** the grid is on the **Images** tab; on a **variation** it's in its **edit form**.
- Core's existing single `Image` stays as the **primary / featured** image; the gallery is additional.

### Video embeds, privacy and consent

Only **YouTube** and **Vimeo** are allow-listed. Author input (watch URL, share URL, embed URL or a bare id) is
normalised to the **privacy-enhanced** embed URL — `https://www.youtube-nocookie.com/embed/{id}` or
`https://player.vimeo.com/video/{id}?dnt=1` — and an arbitrary iframe `src` is never produced.

Embeds are **consent-gated**. The template shows the poster with a play badge and never loads the third-party iframe
until the visitor consents / clicks:

- When `silvershop/cookieconsent` is installed, the item emits that module's `[consent_embed]` placeholder contract
  (`data-consent-embed` / `data-consent-category` / `data-embed-src` + Load / Always buttons), and its runtime builds
  the iframe on consent.
- When it is absent, the module falls back to a **built-in click-to-load**: the iframe is created only on an explicit
  click, so still no third-party request is made before then.

`$CookieConsentAvailable` on the product / variation reports which path is in use.

## Rendering (your theme)

Each owner gets:

- `$GalleryItems` — the sorted gallery items (each has `$Kind`, `$Title`, `$Image`, `$Poster`, `$EmbedSrc`,
  `$Thumbnail`, and the `$IsImage` / `$IsVideo` / `$IsEmbed` flags).
- `$AllImages` — an `ArrayList` of `Image` objects: the primary `Image` (if set) followed by the gallery **images**
  only. **Unchanged** — image-only, so existing loops and integrations keep working.
- `$AllMedia` — an `ArrayList` of uniform view-model rows (the primary image folded in first, then every gallery item
  of every kind, in sort order; a variation with no media of its own falls back to its product's media). Each row:
  `Kind`, `Title`, `Image`, `Poster`, `Thumbnail`, `EmbedSrc`, `Provider`, `IsImage` / `IsVideo` / `IsEmbed`,
  `CookieConsentAvailable`.

### Ready-made include

The module ships a default template, `SilverShop\Includes\GalleryImages`, that renders **one large stage with a
thumbnail grid below it** (images swap the main stage; video thumbs carry a play badge and open the consent-gated
player). Drop it into your product template in place of the standard image:

```ss
<div class="silvershop-product__media">
    <% include SilverShop\Includes\GalleryImages %>
</div>
```

Override the include in your theme to restyle it (lightbox / zoom / variation-swap with your own JS).

### Or render it yourself

```ss
<%-- Primary + gallery media, branching by kind --%>
<% loop $AllMedia %>
    <% if $IsEmbed %>
        <%-- consent-gate $EmbedSrc yourself, e.g. via silvershop/cookieconsent's [consent_embed] contract --%>
        <img src="$Poster.Fill(800,800).URL" alt="$Title">
    <% else %>
        <img src="$Image.Fill(800,800).URL" alt="$Title">
    <% end_if %>
<% end_loop %>

<%-- Or, images only (back-compat): --%>
<% loop $AllImages %>
    <img src="$Fill(800,800).URL" alt="$Owner.Title">
<% end_loop %>
```

For a variation's media on the product page, loop `$SelectedVariation.AllMedia` (or whichever variation your theme has
selected).

## License

BSD-3-Clause.
