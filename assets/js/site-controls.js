/* Shared site controls: theme toggle and storage
 * Applies a 'dark-mode' class to <body> when dark theme is active
 * Stores preference in localStorage under 'site_theme'
 */
/* Theme controls removed: no-op stub to keep scripts referencing siteControls safe. */
(function(){
    'use strict';
    // Ensure any previously stored theme preference or body theme classes are cleared
    try {
        try { localStorage.removeItem('theme'); } catch(e) {}
        try { localStorage.removeItem('site_theme'); } catch(e) {}
    } catch (e) {
        // ignore storage errors
    }

    // Remove theme classes if present so the site renders in its default (light) style
    try {
        document.documentElement.classList.remove('dark-theme', 'light-theme');
        document.body.classList.remove('dark-theme', 'light-theme');
    } catch (e) {
        // ignore
    }

    // Expose a no-op siteControls for compatibility
    window.siteControls = {
        setTheme: function(){ /* noop: theme removed */ }
    };
})();
