/**
 * Mail Shield — cookie consent for Google Analytics.
 * Spec: documentaion/REDESIGN_BRIEF.md §12.10, issue #313 (part of epic #312).
 *
 * Loaded by partials/analytics.php. Builds and shows a small bottom notice
 * (.ms-consent, styled in assets/css/mailshield.css) with Accept/Decline
 * buttons of equal weight. gtag.js is requested only after Accept — never on
 * page load, never on Decline, and never when the visitor has not answered
 * yet. The choice is remembered in localStorage (a strictly-necessary use: it
 * only records the visitor's own answer, so it needs no consent of its own),
 * read and written inside try/catch; when storage throws or is unavailable
 * the notice is shown again on every visit rather than defaulting to loaded.
 *
 * A "Cookie settings" link elsewhere on the page (partials/public_footer.php,
 * and the equivalent footers in faq.php/blog.php) carries a
 * data-ms-cookie-settings attribute; clicking it reopens this notice so the
 * visitor can change their answer. Withdrawing (Decline, including from a
 * reopened notice) deletes the _ga* cookies on the current host and on the
 * registrable parent domain, best effort, and stops any further gtag.js
 * request.
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'ms_analytics_consent';
    var GA_ID = window.msAnalyticsId;

    if (!GA_ID || typeof document === 'undefined') {
        return;
    }

    // Strings come from #ms-i18n-consent (partials/analytics.php); the English
    // literals are the fallback when the element or a key is missing.
    var msI18n = null;
    function msT(key, fallback) {
        if (msI18n === null) {
            try {
                msI18n = JSON.parse(document.getElementById('ms-i18n-consent').textContent);
            } catch (e) {
                msI18n = {};
            }
        }
        return (msI18n && typeof msI18n[key] === 'string') ? msI18n[key] : fallback;
    }

    // The privacy link is rebuilt from literals: only the locale prefix is
    // taken from #ms-i18n-consent, and only when it is one we serve, so the
    // href can never become a javascript: or off-site URL. Add a locale here
    // when it is added to i18nSupportedLocales().
    var PRIVACY_LOCALE_PREFIX = { sv: '/sv' };
    function privacyHref() {
        var match = /^\/([a-z]{2})\//.exec(msT('consent.privacy_url', ''));
        var prefix = (match && Object.prototype.hasOwnProperty.call(PRIVACY_LOCALE_PREFIX, match[1]))
            ? PRIVACY_LOCALE_PREFIX[match[1]]
            : '';
        return prefix + '/privacy.php#cookies';
    }

    var gtagLoaded = false;
    var banner = null;

    function readConsent() {
        try {
            var value = window.localStorage.getItem(STORAGE_KEY);
            return value === 'accepted' || value === 'declined' ? value : null;
        } catch (e) {
            // Storage unavailable (private mode, blocked, etc.): treat as
            // unanswered so the notice asks again rather than loading GA.
            return null;
        }
    }

    function writeConsent(value) {
        try {
            window.localStorage.setItem(STORAGE_KEY, value);
        } catch (e) {
            // Nothing persists; the notice will simply ask again next visit.
        }
    }

    // Defence in depth (scope item 6): only origin + path + utm_* survive
    // into the page_location gtag sends, so an address or any other
    // sensitive query parameter can never reach Google even by accident.
    function sanitizedPageLocation() {
        var loc = window.location;
        try {
            var kept = [];
            var params = new URLSearchParams(loc.search || '');
            params.forEach(function (value, key) {
                if (/^utm_/i.test(key)) {
                    kept.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
                }
            });
            var qs = kept.length ? '?' + kept.join('&') : '';
            return loc.origin + loc.pathname + qs;
        } catch (e) {
            return loc.origin + loc.pathname;
        }
    }

    function loadGtag() {
        if (gtagLoaded) {
            return;
        }
        gtagLoaded = true;

        window.dataLayer = window.dataLayer || [];
        window.gtag = window.gtag || function () {
            window.dataLayer.push(arguments);
        };

        var script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA_ID);
        document.head.appendChild(script);

        window.gtag('js', new Date());
        window.gtag('config', GA_ID, { page_location: sanitizedPageLocation() });
    }

    // Best effort: GA's own cookies on this host and on the registrable
    // parent domain (e.g. www.manjo.me and manjo.me), so withdrawing consent
    // actually removes them rather than just stopping future writes.
    function deleteGaCookies() {
        try {
            var host = window.location.hostname;
            var labels = host.split('.');
            var domains = [null, host];
            if (labels.length > 2) {
                domains.push('.' + labels.slice(-2).join('.'));
            }

            var names = [];
            document.cookie.split(';').forEach(function (pair) {
                var name = pair.split('=')[0].trim();
                if (/^_ga/i.test(name)) {
                    names.push(name);
                }
            });

            names.forEach(function (name) {
                domains.forEach(function (domain) {
                    document.cookie = name + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/;' +
                        (domain ? ' domain=' + domain + ';' : '');
                });
            });
        } catch (e) {
            // Best effort only.
        }
    }

    function buildBanner() {
        if (banner) {
            return banner;
        }

        banner = document.createElement('div');
        banner.className = 'ms-consent';
        banner.id = 'ms-consent';
        banner.setAttribute('role', 'region');
        banner.setAttribute('aria-label', msT('consent.label', 'Cookie consent'));
        banner.hidden = true;

        // Built with createElement/textContent: the strings are never parsed as HTML.
        var inner = document.createElement('div');
        inner.className = 'ms-consent__inner';

        var text = document.createElement('p');
        text.className = 'ms-consent__text';
        text.appendChild(document.createTextNode(
            msT('consent.text', 'We use Google Analytics on our public pages to understand how Mail Shield is used.') + ' '
        ));
        var link = document.createElement('a');
        link.setAttribute('href', privacyHref());
        link.textContent = msT('consent.learn_more', 'Learn more');
        text.appendChild(link);
        text.appendChild(document.createTextNode('.'));

        var actions = document.createElement('div');
        actions.className = 'ms-consent__actions';
        [['decline', msT('consent.decline', 'Decline')], ['accept', msT('consent.accept', 'Accept')]].forEach(function (def) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'ms-btn ms-btn--secondary ms-consent__btn';
            button.setAttribute('data-ms-consent', def[0]);
            button.textContent = def[1];
            actions.appendChild(button);
        });

        inner.appendChild(text);
        inner.appendChild(actions);
        banner.appendChild(inner);

        document.body.appendChild(banner);

        banner.querySelector('[data-ms-consent="accept"]').addEventListener('click', function () {
            writeConsent('accepted');
            hideBanner();
            loadGtag();
        });

        banner.querySelector('[data-ms-consent="decline"]').addEventListener('click', function () {
            writeConsent('declined');
            hideBanner();
            deleteGaCookies();
        });

        return banner;
    }

    function showBanner() {
        var el = buildBanner();
        el.hidden = false;
        var acceptButton = el.querySelector('[data-ms-consent="accept"]');
        if (acceptButton && typeof acceptButton.focus === 'function') {
            try {
                acceptButton.focus();
            } catch (e) {
                // Ignore — focus is a nicety, not a requirement.
            }
        }
    }

    function hideBanner() {
        if (banner) {
            banner.hidden = true;
        }
    }

    function wireCookieSettingsLinks() {
        var links = document.querySelectorAll('[data-ms-cookie-settings]');
        for (var i = 0; i < links.length; i++) {
            links[i].addEventListener('click', function (event) {
                event.preventDefault();
                showBanner();
            });
        }
    }

    function init() {
        var consent = readConsent();

        if (consent === 'accepted') {
            loadGtag();
        } else if (consent !== 'declined') {
            showBanner();
        }

        wireCookieSettingsLinks();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
