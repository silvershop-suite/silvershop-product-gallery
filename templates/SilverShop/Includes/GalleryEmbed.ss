<%-- One embedded-video slide. Scope: an $AllMedia row of Kind video_embed. When silvershop/cookieconsent is installed
     ($CookieConsentAvailable) this emits that module's [consent_embed] placeholder contract (data-consent-embed /
     data-consent-category / data-embed-src + Load / Always buttons), so its runtime builds the iframe only after
     consent. When it is absent, a built-in click-to-load fallback (data-gallery-embed) is used instead. Either way the
     normalised privacy embed URL ($EmbedSrc: youtube-nocookie.com / vimeo ?dnt=1) is only fetched after consent / an
     explicit click; the poster is shown until then. --%>
<% if $CookieConsentAvailable %>
    <div class="product-gallery__embed consent-embed consent-embed--16x9" data-consent-embed data-consent-category="marketing" data-embed-src="$EmbedSrc.ATT" data-embed-title="$Title.ATT">
        <% if $Poster %><img class="product-gallery__embed-poster" src="$Poster.FitMax(1000,1000).URL" alt="$Title.ATT" /><% end_if %>
        <div class="consent-embed__placeholder">
            <p class="consent-embed__text"><%t SilverShop\ProductGallery\Extension\GalleryExtension.VideoConsentNotice 'Loads from YouTube or Vimeo and may set cookies.' %></p>
            <button type="button" class="consent__btn consent__btn--primary" data-consent-embed-load><%t SilverShop\ProductGallery\Extension\GalleryExtension.LoadVideo 'Load video' %></button>
            <button type="button" class="consent__btn consent__btn--link" data-consent-embed-always><%t SilverShop\ProductGallery\Extension\GalleryExtension.AllowVideoAlways 'Always allow' %></button>
        </div>
    </div>
<% else %>
    <div class="product-gallery__embed product-gallery__embed--fallback" data-gallery-embed data-embed-src="$EmbedSrc.ATT" data-embed-title="$Title.ATT">
        <% if $Poster %><img class="product-gallery__embed-poster" src="$Poster.FitMax(1000,1000).URL" alt="$Title.ATT" /><% end_if %>
        <div class="product-gallery__fallback">
            <button type="button" class="product-gallery__play" data-gallery-embed-load aria-label="<%t SilverShop\ProductGallery\Extension\GalleryExtension.PlayVideo 'Play video' %>">
                <span class="product-gallery__play-badge" aria-hidden="true">&#9654;</span>
            </button>
            <p class="product-gallery__embed-note"><%t SilverShop\ProductGallery\Extension\GalleryExtension.VideoConsentNotice 'Loads from YouTube or Vimeo and may set cookies.' %></p>
        </div>
    </div>
<% end_if %>
