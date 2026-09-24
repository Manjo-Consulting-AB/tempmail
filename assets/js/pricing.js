/**
 * Mail Shield pricing page (pricing.php) — Paddle.js glue.
 *
 * Prices: one Paddle.PricePreview() call covering every price ID of every
 * plan (monthly, yearly and one-time), so the billing toggle only swaps text
 * and never refetches. A one-time plan ({ once: 'pri_…' }) ignores the toggle.
 * What is displayed is formattedTotals.total exactly as Paddle returns it —
 * no arithmetic, no re-formatting — which is also what checkout charges.
 *
 * Country: config.country is an ISO code detected server-side, or null. When
 * it is null no address is sent and Paddle geolocates the visitor by IP.
 *
 * Subscribe: Paddle.Checkout.open() for the exact price ID on screen, as the
 * one-page overlay, prefilling the email of a signed-in visitor.
 */
(function () {
    'use strict';

    var configEl = document.getElementById('ms-pricing-config');
    if (!configEl || typeof window.Paddle === 'undefined') {
        showError();
        return;
    }

    var config = JSON.parse(configEl.textContent);
    var period = 'month';
    var prices = {};   // priceId -> formattedTotals.total

    var amountEls = document.querySelectorAll('.ms-pricing__amount');
    var perEls = document.querySelectorAll('[data-per]');
    var subscribeEls = document.querySelectorAll('.ms-pricing__subscribe');
    var periodEls = document.querySelectorAll('.ms-pricing__period');

    function showError() {
        var el = document.getElementById('ms-pricing-error');
        if (el) el.hidden = false;
    }

    function currentPriceId(tierIndex) {
        var priceId = config.tiers[tierIndex].priceId;
        return priceId.once || priceId[period];
    }

    function render() {
        amountEls.forEach(function (el) {
            var formatted = prices[currentPriceId(Number(el.getAttribute('data-tier')))];
            el.textContent = formatted || '…';
        });
        perEls.forEach(function (el) {
            el.textContent = period === 'year' ? '/ year' : '/ month';
        });
        subscribeEls.forEach(function (el) {
            // Buttons only render for a signed-in visitor (pricing.php), and
            // checkout needs the account id to link the payment.
            el.disabled = !config.userId || !prices[currentPriceId(Number(el.getAttribute('data-tier')))];
        });
        periodEls.forEach(function (el) {
            el.setAttribute('aria-pressed', el.getAttribute('data-period') === period ? 'true' : 'false');
        });
    }

    if (config.environment === 'sandbox') {
        Paddle.Environment.set('sandbox');
    }
    Paddle.Initialize({ token: config.token });

    var items = [];
    config.tiers.forEach(function (tier) {
        Object.keys(tier.priceId).forEach(function (key) {
            items.push({ priceId: tier.priceId[key], quantity: 1 });
        });
    });

    var request = { items: items };
    if (config.country) {
        request.address = { countryCode: config.country };
    }

    Paddle.PricePreview(request)
        .then(function (response) {
            response.data.details.lineItems.forEach(function (item) {
                prices[item.price.id] = item.formattedTotals.total;
            });
            render();
        })
        .catch(function (error) {
            console.error('[pricing] PricePreview failed', error);
            showError();
        });

    periodEls.forEach(function (el) {
        el.addEventListener('click', function () {
            period = el.getAttribute('data-period') === 'year' ? 'year' : 'month';
            render();
        });
    });

    subscribeEls.forEach(function (el) {
        el.addEventListener('click', function () {
            var checkout = {
                items: [{ priceId: currentPriceId(Number(el.getAttribute('data-tier'))), quantity: 1 }],
                settings: {
                    displayMode: 'overlay',
                    variant: 'one-page',
                    successUrl: config.successUrl
                }
            };
            if (config.email) {
                checkout.customer = { email: config.email };
            }
            if (config.userId) {
                // Carried onto the transaction and subscription; paddle_sync.php
                // uses it to link the payment to this pro_users row.
                checkout.customData = { pro_user_id: String(config.userId) };
            }
            Paddle.Checkout.open(checkout);
        });
    });

    render();
})();
