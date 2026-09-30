/**
 * Mail Shield landing page — the "From €x" price on the Pro card
 * (partials/landing/plans.php).
 *
 * One Paddle.PricePreview() call for the monthly Pro price, exactly as
 * pricing.js does it for the pricing page: the country is an ISO code detected
 * server-side or null (then Paddle geolocates by IP), and what is shown is
 * formattedTotals.total as Paddle returns it — no arithmetic, no re-formatting.
 *
 * Progressive enhancement: plans.php renders the price-free wording and only
 * emits the config block and Paddle.js on production. If Paddle.js does not
 * load or the preview fails, that wording simply stays.
 */
(function () {
    'use strict';

    var configEl = document.getElementById('ms-landing-price-config');
    var amountEl = document.querySelector('[data-landing-price]');
    if (!configEl || !amountEl || typeof window.Paddle === 'undefined') {
        return;
    }

    var config;
    try {
        config = JSON.parse(configEl.textContent);
    } catch (error) {
        return;
    }

    if (config.environment === 'sandbox') {
        Paddle.Environment.set('sandbox');
    }
    Paddle.Initialize({ token: config.token });

    var request = { items: [{ priceId: config.priceId, quantity: 1 }] };
    if (config.country) {
        request.address = { countryCode: config.country };
    }

    Paddle.PricePreview(request)
        .then(function (response) {
            var items = response.data.details.lineItems;
            for (var i = 0; i < items.length; i++) {
                if (items[i].price.id === config.priceId) {
                    amountEl.textContent = 'From ' + items[i].formattedTotals.total;
                    return;
                }
            }
        })
        .catch(function (error) {
            console.error('[landing-price] PricePreview failed', error);
        });
})();
