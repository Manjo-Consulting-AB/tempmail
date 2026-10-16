/*
 * Language menu in the public footer (partials/public_footer.php).
 *
 * The menu is a native <details>: it opens, closes and its links work without
 * this script. This only adds what <details> lacks for a menu: Escape closes it
 * and returns focus to the toggle, and a click or focus outside closes it.
 */
(function () {
    'use strict';

    var menus = document.querySelectorAll('[data-ms-langmenu]');
    if (!menus.length) {
        return;
    }

    function closeAll(except) {
        for (var i = 0; i < menus.length; i++) {
            if (menus[i] !== except && menus[i].open) {
                menus[i].open = false;
            }
        }
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        var inside = target && target.closest ? target.closest('[data-ms-langmenu]') : null;
        closeAll(inside);
    });

    document.addEventListener('focusin', function (event) {
        var target = event.target;
        var inside = target && target.closest ? target.closest('[data-ms-langmenu]') : null;
        closeAll(inside);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        for (var i = 0; i < menus.length; i++) {
            if (menus[i].open) {
                menus[i].open = false;
                var toggle = menus[i].querySelector('summary');
                if (toggle) {
                    toggle.focus();
                }
            }
        }
    });
})();
