<%-- Product / variation media gallery: one large stage with a thumbnail grid below it. Loops $AllMedia (the primary
     Image followed by the sorted gallery items — images and embedded YouTube/Vimeo videos; a variation with no media
     of its own falls back to its product's media). Image-only galleries render exactly as before.

     Videos: an embed is shown as a poster + play badge and is consent-gated. When silvershop/cookieconsent is
     installed the item emits that module's [consent_embed] placeholder contract (data-consent-embed …), so its runtime
     builds the iframe only after consent; when it is absent, a minimal built-in click-to-load is used instead, so no
     third-party content is fetched before an explicit click. Every slide is rendered up front (hidden ones kept in the
     DOM) and the small inline script just toggles which is active — so the consent runtime can bind every embed on
     load. Carries small inline CSS + JS. Override it (copy into a theme) to restyle. --%>
<% if $AllMedia %>
<style>
    .product-gallery__stage { position: relative; margin: 0 0 12px; }
    .product-gallery__slide { display: none; }
    .product-gallery__slide.is-active { display: block; }
    .product-gallery__main-image { display: block; width: 100%; height: auto; }
    .product-gallery__embed { position: relative; width: 100%; aspect-ratio: 16 / 9; background: #efefef; overflow: hidden; }
    .product-gallery__embed-poster { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .product-gallery__embed--fallback .consent-embed__placeholder,
    .product-gallery__embed--fallback .product-gallery__fallback { position: absolute; inset: 0; display: flex; flex-direction: column; gap: 0.5em; align-items: center; justify-content: center; padding: 1em; text-align: center; }
    .product-gallery__embed .consent-embed__text,
    .product-gallery__embed .product-gallery__embed-note { position: relative; margin: 0; padding: 0.3em 0.6em; background: rgba(0, 0, 0, 0.55); color: #fff; border-radius: 4px; font-size: 0.85em; }
    .product-gallery__frame, .product-gallery__embed .consent-embed__frame { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
    .product-gallery__play { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 64px; height: 64px; padding: 0; border: 0; border-radius: 50%; background: rgba(0, 0, 0, 0.6); color: #fff; cursor: pointer; }
    .product-gallery__play-badge { font-size: 1.6em; line-height: 1; margin-left: 0.15em; }
    .product-gallery__grid { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; justify-content: flex-start; gap: 8px; }
    .product-gallery__grid .product-gallery__item { width: 72px; margin: 0; padding: 0; list-style: none; }
    .product-gallery__thumb { position: relative; display: block; width: 100%; padding: 0; border: 0; background: none; cursor: pointer; }
    .product-gallery__thumb img, .product-gallery__thumb-blank { display: block; width: 100%; height: auto; aspect-ratio: 1 / 1; object-fit: cover; background: #efefef; }
    .product-gallery__thumb.is-active { outline: 2px solid currentColor; outline-offset: 1px; }
    .product-gallery__thumb-badge { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: #fff; text-shadow: 0 1px 3px rgba(0, 0, 0, 0.7); font-size: 1.4em; pointer-events: none; }
</style>
<div class="product-gallery" data-product-gallery>
    <div class="product-gallery__stage">
        <% loop $AllMedia %>
            <div class="product-gallery__slide<% if $First %> is-active<% end_if %>" data-slide="$Pos">
                <% if $IsEmbed %>
                    <% include SilverShop\Includes\GalleryEmbed %>
                <% else %>
                    <img class="product-gallery__main-image" src="$Image.FitMax(1000,1000).URL" alt="$Title.ATT"<% if not $First %> loading="lazy"<% end_if %> />
                <% end_if %>
            </div>
        <% end_loop %>
    </div>
    <% if $AllMedia.Count > 1 %>
        <ul class="product-gallery__grid">
            <% loop $AllMedia %>
                <li class="product-gallery__item">
                    <button type="button" class="product-gallery__thumb<% if $First %> is-active<% end_if %>" data-slide="$Pos" aria-pressed="<% if $First %>true<% else %>false<% end_if %>">
                        <% if $Thumbnail %>
                            <img src="$Thumbnail.Fill(240,240).URL" alt="$Title.ATT" loading="lazy" />
                        <% else %>
                            <span class="product-gallery__thumb-blank"></span>
                        <% end_if %>
                        <% if $IsVideo %><span class="product-gallery__thumb-badge">&#9654;</span><% end_if %>
                    </button>
                </li>
            <% end_loop %>
        </ul>
    <% end_if %>
    <script>
    (function () {
        var script = document.currentScript;
        var gallery = script && script.closest('[data-product-gallery]');
        if (!gallery) {
            return;
        }
        var slides = gallery.querySelectorAll('.product-gallery__slide');
        var thumbs = gallery.querySelectorAll('.product-gallery__thumb');
        function activate(idx) {
            slides.forEach(function (slide) {
                slide.classList.toggle('is-active', slide.getAttribute('data-slide') === idx);
            });
            thumbs.forEach(function (thumb) {
                var on = thumb.getAttribute('data-slide') === idx;
                thumb.classList.toggle('is-active', on);
                thumb.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
        }
        thumbs.forEach(function (thumb) {
            thumb.addEventListener('click', function () {
                activate(thumb.getAttribute('data-slide'));
            });
        });
        // Built-in click-to-load fallback (used only when cookieconsent is absent): build the iframe on an explicit
        // click, so no third-party request is made before then.
        gallery.querySelectorAll('[data-gallery-embed-load]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wrap = btn.closest('[data-gallery-embed]');
                if (!wrap || wrap.getAttribute('data-loaded') === 'true') {
                    return;
                }
                var src = wrap.getAttribute('data-embed-src');
                if (!src) {
                    return;
                }
                var iframe = document.createElement('iframe');
                iframe.setAttribute('src', src);
                iframe.setAttribute('title', wrap.getAttribute('data-embed-title') || '');
                iframe.setAttribute('loading', 'lazy');
                iframe.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture');
                iframe.setAttribute('allowfullscreen', 'allowfullscreen');
                iframe.className = 'product-gallery__frame';
                wrap.innerHTML = '';
                wrap.appendChild(iframe);
                wrap.setAttribute('data-loaded', 'true');
            });
        });
    })();
    </script>
</div>
<% else %>
    <div class="silvershop-product__no-image"><%t SilverShop\Page\Product.NoImage "no image" %></div>
<% end_if %>
