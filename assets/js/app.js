/**
 * TempMail - JavaScript funktionalitet
 * Hanterar UI-interaktioner och AJAX-calls
 */

class TempMailApp {
    constructor() {
        this.currentAddress = '';
        this.expiresAt = null;
        this.validityTimer = null;
        this.refreshInterval = null;
        this.refreshRate = 10000; // 10 sekunder
        this.autoRefreshEnabled = true;
        this.autoRefreshCount = 0; // Räknar auto-refresh cykler
        this.lastKnownEmailId = 0; // track latest known email id to avoid full reloads
        this.isPersonalAddress = false; // track if current address is personal/private
        this.allEmails = []; // senast hämtade, ofiltrerade meddelandelistan
        this.activeAddressFilter = null; // full mottagaradress att filtrera på (pro.php)
        this.knownPersonalAddresses = []; // kontots permanenta adresser, satta av pro.php
        this.currentEmailHtml = null; // the open message's (server-purified) HTML body, for "Visa bilder"

        this.init();

        // Debug: Exponera app globalt för console-access
        window.tempMailApp = this;
        console.log('TempMail app initialized, available as window.tempMailApp');
    }
    
    /**
     * Initialisera applikationen
     */
    init() {
        this.bindEvents();
        this.updateImageToggleButton();
        this.startAutoRefresh();
        
        // Ladda statistik efter en kort fördröjning för att undvika initialiseringsproblem
        setTimeout(() => {
            this.loadStats();
        }, 1000);
        
        // Prioritera URL-adress över sparad adress
        const urlAddress = window.tempMailConfig?.urlAddress;
        const urlExpiresAt = window.tempMailConfig?.urlExpiresAt;
        const savedAddress = localStorage.getItem('tempmail_address');

        // Helper: call server to check owner of address
        const checkAddressOwner = (address) => {
            return $.ajax({
                url: 'index.php',
                method: 'POST',
                data: { action: 'check_address_owner', address },
                dataType: 'json'
            });
        };

        const handleLoadedAddress = async (address, expiresAtStr, fromUrl = false) => {
            let isPersonal = false;
            try {
                if (!address) return;
                const res = await checkAddressOwner(address);
                if (res && res.success) {
                    isPersonal = res.is_personal || false;
                    // Only block access if address is personal AND user is not the owner
                    if (res.is_personal && res.owner_pro_user_id && !res.is_owner) {
                        // Address is personal and belongs to a pro user but current session is not owner -> clear saved address
                        console.warn('Personal address belongs to pro user and current session is not owner. Clearing saved address.');
                        try { localStorage.removeItem('tempmail_address'); } catch (e) {}
                        // Show neutral UI (no address)
                        return;
                    }
                }
            } catch (e) {
                console.warn('Failed owner check for address', address, e);
                // If owner check fails, be conservative and do not show personal address by default
            }

            // If we reach here, either address is not personal or current session is owner
            this.currentAddress = address;
            this.isPersonalAddress = isPersonal;
            try { localStorage.setItem('tempmail_address', address); } catch (e) {}
            if (expiresAtStr && expiresAtStr !== 'null') {
                this.expiresAt = new Date(expiresAtStr.replace(' ', 'T'));
            } else {
                this.expiresAt = null;
            }
            this.updateUI();
            this.loadEmails();
            console.log('Loaded address:', address, 'expiresAt:', this.expiresAt, 'isPersonal:', isPersonal, 'fromUrl:', !!fromUrl);
        };

        if (urlAddress) {
            // URL-adress har högst prioritet but verify ownership before showing
            handleLoadedAddress(urlAddress, urlExpiresAt, true);
        } else if (savedAddress) {
            // Verify saved address ownership before showing
            handleLoadedAddress(savedAddress, null, false).then(() => {}).catch(()=>{});
        }

        console.log('TempMail application initialized');
    }

    /**
     * Hämta expiresAt från backend om den saknas
     */
    async fetchExpiresAt(address) {
        if (!address) return null;
        try {
            const response = await $.ajax({
                url: 'index.php',
                method: 'POST',
                data: { action: 'get_expires_at', address },
                dataType: 'json'
            });
            if (response.success && response.expires_at) {
                return new Date(response.expires_at.replace(' ', 'T'));
            }
        } catch (error) {
            console.error('Failed to fetch expiresAt:', error);
        }
        return null;
    }
    
    /**
     * Bind event handlers
     */
    bindEvents() {
        // Generera ny adress
        $(document).on('click', '#generateBtn, #newAddressBtn', (e) => {
            const $el = $(e.currentTarget);
            // If the element is a link with an explicit navigation href, allow normal navigation
            const href = $el.attr('href');
            if ($el.is('a') && href && href !== '#' && !href.startsWith('javascript:')) {
                // Let the browser follow the link (e.g. /pro_login.php)
                return;
            }
            e.preventDefault();
            console.log('Generate button clicked');
            this.generateNewAddress();
        });
        
        // Kopiera adress
        $(document).on('click', '#copyBtn', () => {
            this.copyAddress();
        });
        
        // Dela länk
        $(document).on('click', '#shareBtn', () => {
            this.shareLink();
        });
        
        // Filtrera inkorgen på en permanent adress (rail-listan i pro.php).
        // Delegerad: raderna byggs av pro.php efter att sidan laddats.
        $(document).on('click', '.ms-rail__addr-link', (e) => {
            e.preventDefault();
            const address = $(e.currentTarget).attr('data-address');
            if (address) this.setAddressFilter(address);
        });

        // Rensa adressfiltret. Samma knappklass i filterraden och i empty state.
        $(document).on('click', '.ms-address-filter-clear', (e) => {
            e.preventDefault();
            this.clearAddressFilter();
        });

        // Bakåt/framåt: URL:en är filtertillståndet.
        window.addEventListener('popstate', () => {
            this.applyAddressFilterFromUrl();
        });

        // Theme removed: no theme toggle handler

        // Uppdatera e-post (med IMAP-refresh)
        $(document).on('click', '#refreshBtn', () => {
            console.log('Refresh button clicked, current address:', this.currentAddress);
            this.loadEmails(true);
        });
        
        // Toggle auto-refresh
        $(document).on('click', '.auto-refresh', () => {
            this.toggleAutoRefresh();
        });
        
        // Öppna e-post
        $(document).on('click', '.email-item', (e) => {
            const emailId = $(e.currentTarget).data('email-id');
            this.openEmail(emailId);
        });

        // Modal-level 'Visa bilder' button: the message is shown in a
        // sandboxed iframe without scripts, so images cannot be loaded one by
        // one from inside it - re-render the open message with images on.
        $(document).on('click', '#modalShowImagesBtn', (e) => {
            e.preventDefault();
            if (!this.currentEmailHtml) return;
            try {
                this.renderHtmlEmail($('#emailModal').find('#emailContent'), this.currentEmailHtml, false);
                $('#modalShowImagesBtn').hide();
            } catch (err) {
                console.error('Failed to load images in modal:', err);
            }
        });

        // Open pro profile modal
        // Open pro profile modal (custom show to avoid Bootstrap Modal constructor issues)
        $(document).on('click', '#proProfileBtn', async (e) => {
            // If the profile control is a normal link (navigates to profile page), allow default navigation
            const $btn = $(e.currentTarget);
            const href = $btn.attr('href');
            if (href && href !== '#' && !href.startsWith('javascript:')) {
                // Let the browser follow the link
                return;
            }
            e.preventDefault();
            const $modal = $('#proProfileModal');
            // create backdrop if missing
            if ($('.custom-modal-backdrop').length === 0) {
                $('<div class="modal-backdrop fade show custom-modal-backdrop"></div>').appendTo('body');
            }
            // show modal (bootstrap styles rely on classes)
            $modal.addClass('show').css('display', 'block').attr('aria-hidden', 'false');
            $('body').addClass('modal-open');

            // Show loading state in modal
            $('#proProfileAlert').html('<div class="alert alert-info">Loading profile...</div>');

            try {
                const res = await $.post('pro_profile.php', { action: 'get_profile' }, null, 'json');
                if (res && res.success && res.profile) {
                    $('#proEmail').val(res.profile.email || '');
                    $('#proPassword').val('');
                    $('#proPasswordConfirm').val('');
                    $('#proPasswordCurrent').val('');
                    $('#proProfileAlert').html('');
                    if (res.profile.has_password) {
                        $('#proPasswordCurrentGroup').removeClass('d-none');
                    } else {
                        $('#proPasswordCurrentGroup').addClass('d-none');
                    }
                } else {
                    $('#proProfileAlert').html('<div class="alert alert-warning">Could not load profile data.</div>');
                }
            } catch (err) {
                console.error('Failed to load pro profile', err);
                $('#proProfileAlert').html('<div class="alert alert-danger">Network error while loading profile</div>');
            }
        });

        // Close handlers for our custom modal behavior
        $(document).on('click', '#proProfileModal .btn-close, #proProfileModal [data-bs-dismiss="modal"]', (e) => {
            e.preventDefault();
            const $modal = $('#proProfileModal');
            $modal.removeClass('show').css('display', 'none').attr('aria-hidden', 'true');
            $('.custom-modal-backdrop').remove();
            $('body').removeClass('modal-open');
        });

        // Close handlers for email modal (close button, footer close button, any data-bs-dismiss)
        $(document).on('click', '#emailModal .btn-close, #emailModal [data-bs-dismiss="modal"], #emailModal .modal-footer .btn', (e) => {
            e.preventDefault();
            const $modal = $('#emailModal');
            try {
                // Prefer Bootstrap's hide to properly remove backdrop
                $modal.modal('hide');
            } catch (err) {
                // Fallback: manually remove show classes
                $modal.removeClass('show').css('display', 'none').attr('aria-hidden', 'true');
            }
            // Remove any backdrops (both custom and bootstrap default)
            $('.custom-modal-backdrop').remove();
            $('.modal-backdrop').remove();
            $('body').removeClass('modal-open');
        });

        // Close on ESC
        $(document).on('keydown', (e) => {
            if (e.key === 'Escape') {
                const $modal = $('#proProfileModal');
                if ($modal.hasClass('show')) {
                    $modal.removeClass('show').css('display', 'none').attr('aria-hidden', 'true');
                    $('.custom-modal-backdrop').remove();
                    $('body').removeClass('modal-open');
                }
            }
        });

        // Save profile email
        $(document).on('click', '#saveProfileEmailBtn', async (e) => {
            e.preventDefault();
            const email = $('#proEmail').val();
            try {
                const res = await $.post('pro_profile.php', { action: 'update_email', email }, null, 'json');
                if (res.success) {
                    var msg = res.message || 'Confirmation link sent to the new email address';
                    $('#proProfileAlert').html('<div class="alert alert-success">' + msg + '</div>');
                } else {
                    $('#proProfileAlert').html('<div class="alert alert-danger">' + (res.error || 'Failed') + '</div>');
                }
            } catch (err) {
                $('#proProfileAlert').html('<div class="alert alert-danger">Network error</div>');
            }
        });

        // Save profile password
        $(document).on('click', '#saveProfilePasswordBtn', async (e) => {
            e.preventDefault();
            const password = $('#proPassword').val();
            const confirm = $('#proPasswordConfirm').val();
            try {
                const payload = { action: 'set_password', password, confirm };
                const res = await $.post('pro_profile.php', payload, null, 'json');
                if (res.success) {
                    $('#proProfileAlert').html('<div class="alert alert-success">Password set</div>');
                    $('#proPassword').val('');
                    $('#proPasswordConfirm').val('');
                } else {
                    $('#proProfileAlert').html('<div class="alert alert-danger">' + (res.error || 'Failed') + '</div>');
                }
            } catch (err) {
                $('#proProfileAlert').html('<div class="alert alert-danger">Network error</div>');
            }
        });

        // Cancel pending profile change (from dashboard)
        $(document).on('click', '.btn-cancel-pending', async function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!id) return;
            if (!confirm('Avbryt denna väntande ändring?')) return;
            try {
                const res = await $.post('pro_profile.php', { action: 'cancel_pending_change', id: id }, null, 'json');
                        if (res && res.success) {
                            var $item = $('#pending-item-' + id);
                            var $card = $item.closest('.card');
                            $item.fadeOut(200, function(){
                                $(this).remove();
                                // If no more pending items, remove the whole card/module
                                var remaining = $card.find('.list-group-item').length;
                                if (remaining === 0) {
                                    $card.fadeOut(200, function(){ $(this).remove(); });
                                } else {
                                    // Update header badge count text
                                    $card.find('.card-header .badge').text(remaining + ' pending');
                                }
                            });
                        } else {
                    alert('Kunde inte avbryta: ' + (res && res.error ? res.error : 'Unknown'));
                }
            } catch (err) {
                alert('Network error');
            }
        });

        // Resend pending profile confirmation email (from dashboard)
        $(document).on('click', '.resend-pending-link', async function(e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!id) return;
            try {
                const res = await $.post('pro_profile.php', { action: 'resend_pending_change', id: id }, null, 'json');
                if (res && res.success) {
                    alert(res.message || 'Confirmation email resent successfully.');
                } else {
                    alert('Kunde inte skicka om: ' + (res && res.error ? res.error : 'Unknown'));
                }
            } catch (err) {
                alert('Network error');
            }
        });

        // Toggle block images button
        $(document).on('click', '#imageToggle', () => {
            try {
                const currentlyBlocked = localStorage.getItem('block_images') === '1';
                localStorage.setItem('block_images', currentlyBlocked ? '0' : '1');
                this.updateImageToggleButton();
            } catch (e) {
                console.warn('Could not toggle block_images in localStorage', e);
            }
        });
        
        // Hantera keyboard shortcuts
        $(document).keydown((e) => {
            // Ctrl/Cmd + R = Refresh
            if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
                e.preventDefault();
                this.loadEmails();
            }
            
            // Ctrl/Cmd + N = New address
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                // If user is a logged-in pro user, generate normally. Otherwise navigate to pro login.
                try {
                    const isPro = window.tempMailConfig && window.tempMailConfig.isPro;
                    if (isPro) {
                        this.generateNewAddress();
                    } else {
                        // navigate guests to pro login page
                        window.location.href = '/pro_login.php';
                    }
                } catch (err) {
                    // Fallback: navigate to pro login for safety
                    window.location.href = '/pro_login.php';
                }
            }
            
            // Ctrl/Cmd + C = Copy address (when focused on address)
            if ((e.ctrlKey || e.metaKey) && e.key === 'c' && $('.email-address').is(':focus')) {
                e.preventDefault();
                this.copyAddress();
            }
        });
        
        // Fokus på adress när man klickar
        $(document).on('click', '.email-address', function() {
            $(this).focus().select();
        });
    }
    
    /**
     * Generera ny e-postadress
     */
    async generateNewAddress() {
        try {
            this.showLoading('#generateBtn, #newAddressBtn', 'Genererar...');

            const response = await $.ajax({
                url: 'index.php',
                method: 'POST',
                data: { action: 'generate' },
                dataType: 'json',
                error: function(xhr, status, error) {
                    console.error('AJAX error:', status, error, xhr.responseText);
                    $('#generateBtn, #newAddressBtn').removeClass('btn-primary').addClass('btn-danger');
                    $('#generateBtn, #newAddressBtn').text('Error! Try again');
                    window.tempMailApp?.showNotification('AJAX error: ' + status + ' ' + error, 'error');
                }
            });

            console.log('AJAX response (generate):', response);

            if (response.success && response.address) {
                this.currentAddress = response.address;
                localStorage.setItem('tempmail_address', this.currentAddress);
                this.expiresAt = response.expires_at ? new Date(response.expires_at.replace(' ', 'T')) : null;
                this.updateURL();
                this.updateUI();
                this.startValidityCountdown();
                this.loadEmails();
                this.showNotification('New email address generated!', 'success');
                $('#generateBtn, #newAddressBtn').removeClass('btn-danger').addClass('btn-primary');
                $('#generateBtn, #newAddressBtn').text('Generate New Address');
            } else {
                this.showNotification('Could not generate address: ' + (response.error || 'Unknown error'), 'error');
                console.error('Backend error (generate):', response);
                $('#generateBtn, #newAddressBtn').removeClass('btn-primary').addClass('btn-danger');
                $('#generateBtn, #newAddressBtn').text('Error! Try again');
            }

        } catch (error) {
            console.error('Error generating address (catch):', error);
            this.showNotification('Network error when generating address: ' + error, 'error');
            $('#generateBtn, #newAddressBtn').removeClass('btn-primary').addClass('btn-danger');
            $('#generateBtn, #newAddressBtn').text('Network error! Try again');
        } finally {
            setTimeout(() => {
                $('#generateBtn, #newAddressBtn').removeClass('btn-danger').addClass('btn-primary');
                $('#generateBtn, #newAddressBtn').text('Generate New Address');
            }, 4000);
            this.hideLoading('#generateBtn, #newAddressBtn', 'Generate new address');
        }
    }
    
    /**
     * Ladda e-postmeddelanden (utan IMAP-refresh)
     */
    async loadEmails(forceRefresh = false) {
        if (!this.currentAddress) {
            return;
        }
        
        try {
            const actionText = forceRefresh ? 'Fetching from server...' : 'Updating...';
            this.showLoading('#refreshBtn', actionText);
            this.updateStatus('loading');
            
            const requestData = { 
                action: forceRefresh ? 'refresh_emails' : 'get_emails',
                address: this.currentAddress 
            };
            
            console.log('Loading emails with data:', requestData);
            
            const response = await $.ajax({
                url: 'index.php',
                method: 'POST',
                data: requestData,
                dataType: 'json'
            });
            
            console.log('Email response:', response);
            
            if (response.success) {
                // Behåll hela svaret ofiltrerat; filtret är en ren vy över det.
                this.allEmails = response.emails || [];
                this.updateLastKnownEmailId(this.allEmails);
                this.renderFilteredEmails();
                this.updateStatus('online');
                
                // Uppdatera tidsstämpel
                $('#lastUpdate').text(this.formatTime(new Date()));

                // The personal counters follow the inbox, so new mail moves them
                // straight away instead of on the next once-a-minute tick.
                this.loadStats();
            } else {
                    if ((response.error || '').toLowerCase().includes('ogiltig adress')) {
                        this.showNotification('Invalid address, new address created', 'info');
                        await this.generateNewAddress();
                    } else {
                        this.showNotification('Could not load emails: ' + (response.error || 'Unknown error'), 'error');
                        this.updateStatus('offline');
                    }
            }
            
        } catch (error) {
            console.error('Error loading emails:', error);
            this.showNotification('Network error when loading emails', 'error');
            this.updateStatus('offline');
        } finally {
            this.hideLoading('#refreshBtn', 'Refresh');
        }
    }
    
    /**
     * Rendera den filtrerade vyn över den senast hämtade listan
     */
    renderFilteredEmails() {
        let emails = this.allEmails || [];
        if (this.activeAddressFilter) {
            const filter = this.activeAddressFilter.toLowerCase();
            emails = emails.filter(e => (e.to_address || '').toLowerCase() === filter);
        }
        this.displayEmails(emails);
    }

    /**
     * Filtrera inkorgen på en permanent adress. Ingen ny hämtning: hela listan
     * ligger redan i this.allEmails.
     */
    setAddressFilter(address, options = {}) {
        const value = String(address == null ? '' : address).trim();
        if (!value) return;
        this.activeAddressFilter = value;
        this.renderFilteredEmails();
        this.syncAddressFilterUI();
        if (options.updateUrl !== false) this.updateAddressFilterUrl();
    }

    /**
     * Ta bort adressfiltret och visa samtliga meddelanden igen
     */
    clearAddressFilter(options = {}) {
        if (!this.activeAddressFilter) return;
        this.activeAddressFilter = null;
        this.renderFilteredEmails();
        this.syncAddressFilterUI();
        if (options.updateUrl !== false) this.updateAddressFilterUrl();
    }

    /**
     * Adresserna i rail-listan (pro.php). Behövs för att kunna avgöra om en
     * adress i ?filter_address= verkligen tillhör det inloggade kontot.
     */
    setKnownPersonalAddresses(addresses) {
        this.knownPersonalAddresses = Array.isArray(addresses) ? addresses.slice() : [];
        this.applyAddressFilterFromUrl();
    }

    /**
     * Applicera filtret som URL:en pekar ut, om adressen är kontots egen.
     * Okänd adress → visa allt (fail open).
     */
    applyAddressFilterFromUrl() {
        let requested = null;
        try {
            requested = new URLSearchParams(window.location.search).get('filter_address');
        } catch (e) {
            requested = null;
        }
        const known = this.knownPersonalAddresses || [];
        const match = requested
            ? known.find(a => String(a).toLowerCase() === String(requested).toLowerCase())
            : null;
        if (match) {
            this.setAddressFilter(match, { updateUrl: false });
        } else if (this.activeAddressFilter) {
            this.clearAddressFilter({ updateUrl: false });
        }
    }

    /**
     * Spegla filtret i URL:en utan att ladda om sidan
     */
    updateAddressFilterUrl() {
        if (!history.pushState) return;
        const params = new URLSearchParams(window.location.search);
        if (this.activeAddressFilter) {
            params.set('filter_address', this.activeAddressFilter);
        } else {
            params.delete('filter_address');
        }
        const query = params.toString();
        const newUrl = window.location.pathname + (query ? '?' + query : '');
        history.pushState({ filterAddress: this.activeAddressFilter }, '', newUrl);
    }

    /**
     * Markera vald adress i rail-listan och visa filterraden ovanför listan
     */
    syncAddressFilterUI() {
        const filter = this.activeAddressFilter ? this.activeAddressFilter.toLowerCase() : null;

        $('#railAddressList .ms-rail__addr').each(function () {
            const $row = $(this);
            const $link = $row.find('.ms-rail__addr-link');
            const address = String($link.attr('data-address') || '').toLowerCase();
            const isActive = !!filter && address === filter;
            $row.toggleClass('is-active', isActive);
            if (isActive) {
                $link.attr('aria-current', 'true');
            } else {
                $link.removeAttr('aria-current');
            }
        });

        const $bar = $('#addressFilterBar');
        if (!$bar.length) return;
        if (this.activeAddressFilter) {
            $('#addressFilterLabel').text(this.activeAddressFilter);
            $bar.prop('hidden', false);
        } else {
            $bar.prop('hidden', true);
        }
    }

    /**
     * Räkna högsta kända meddelande-id över hela listan, så bakgrundskollen
     * inte rapporterar nya mail bara för att filtret döljer dem.
     */
    updateLastKnownEmailId(emails) {
        try {
            if (emails && emails.length) {
                let maxId = 0;
                emails.forEach(e => { if (e && e.id && Number(e.id) > maxId) maxId = Number(e.id); });
                if (maxId > this.lastKnownEmailId) this.lastKnownEmailId = maxId;
            }
        } catch (e) {
            console.warn('Failed updating lastKnownEmailId', e);
        }
    }

    /**
     * Visa e-postmeddelanden
     */
    displayEmails(emails) {
        const container = $('#emailList');

        if (emails.length === 0) {
            if (this.activeAddressFilter) {
                // Ett aktivt filter som inte matchar något är inte samma sak som
                // en tom inkorg — säg vilken adress som saknar mail.
                container.html(`
                    <div class="ms-mail-empty">
                        <p class="ms-mail-empty__line">No messages for ${this.escapeHtml(this.activeAddressFilter)}</p>
                        <p class="ms-mail-empty__hint">Nothing has been sent to this address yet.
                            <button type="button" class="ms-address-filter-clear">Clear filter</button>
                        </p>
                    </div>
                `);
                $('#emailCount').text(0);
                return;
            }
            container.html(`
                <div class="ms-mail-empty">
                    <p class="ms-mail-empty__line">No messages yet</p>
                    <p class="ms-mail-empty__hint">Messages appear here automatically.</p>
                </div>
            `);
            // Uppdatera email counter badge till 0
            $('#emailCount').text(0);
            return;
        }

        const emailsHtml = emails.map((email, index) => {
            const time = this.formatTime(new Date(email.received_at));
            const preview = this.getEmailPreview(email.body_text || email.body_html);
            // Extract local part (before @) for display
            let toLocal = '';
            if (email.to_address) {
                try {
                    toLocal = String(email.to_address).split('@')[0];
                } catch (e) {
                    toLocal = email.to_address;
                }
            }
            // Monogram för avataren: första tecknet i avsändaren, annars '?'
            const fromAddress = String(email.from_address || '').trim();
            const initial = fromAddress ? fromAddress.charAt(0).toUpperCase() : '?';
            // Staggered entrance: the ms-rise helper in mailshield.css carries
            // the prefers-reduced-motion guard. Capped so a long list does not
            // sit invisible behind a growing delay.
            const riseDelay = Math.min(index, 5) * 60;

            return `
                <div class="email-item ms-rise" data-email-id="${this.escapeHtml(email.id)}" style="--ms-rise-delay:${riseDelay}ms">
                    <span class="ms-mail__avatar" aria-hidden="true">${this.escapeHtml(initial)}</span>
                    <span class="ms-mail__head">
                        <span class="ms-mail__from">${this.escapeHtml(email.from_address)}</span>
                        <span class="ms-mail__time">${this.escapeHtml(time)}</span>
                    </span>
                    <span class="ms-mail__sub">
                        <span class="ms-mail__subject">${this.escapeHtml(email.subject || '(No subject)')}</span>
                        ${toLocal ? `<span class="ms-chip ms-mail__to">To: ${this.escapeHtml(toLocal)}</span>` : ''}
                    </span>
                    <span class="ms-mail__preview">${this.escapeHtml(preview)}</span>
                </div>
            `;
        }).join('');

        container.html(emailsHtml);
        
        // Uppdatera email counter badge
        $('#emailCount').text(emails.length);
    }

    /**
     * Lightweight check to see whether there are new emails since last known id
     */
    async checkForNewEmails() {
        if (!this.currentAddress) return { success: false };
        try {
            const response = await $.ajax({
                url: 'index.php',
                method: 'POST',
                data: { action: 'has_new_emails', address: this.currentAddress, last_known_id: this.lastKnownEmailId || 0 },
                dataType: 'json',
                timeout: 5000
            });
            return response;
        } catch (err) {
            // Don't spam console — treat as non-fatal
            console.warn('checkForNewEmails failed', err);
            return { success: false };
        }
    }
    
    /**
     * Öppna e-postmeddelande i modal
     */
    async openEmail(emailId) {
        try {
            const response = await $.ajax({
                url: 'index.php',
                method: 'POST',
                data: { 
                    action: 'get_email',
                    email_id: emailId 
                },
                dataType: 'json'
            });
            
            if (response.success && response.email) {
                // Attach attachments array to email object for modal display
                response.email.attachments = response.attachments || [];
                this.showEmailModal(response.email);
            } else {
                // If the server indicates the message has expired, show a friendly toast
                const err = (response && response.error) ? response.error.toString().toLowerCase() : '';
                if (err.includes('förfall') || err.includes('expired') || err.includes('har förfallit')) {
                    this.showNotification('Det här meddelandet har förfallit och kan inte visas.', 'warning', 5000);
                } else {
                    this.showNotification(response.error || 'Could not load email message', 'error');
                }
            }
            
        } catch (error) {
            console.error('Error loading email:', error);
            this.showNotification('Network error when loading message', 'error');
        }
    }
    
    /**
     * Visa e-post i modal
     */
    showEmailModal(email) {
        const modal = $('#emailModal');
        
        // Sätt innehåll
        modal.find('.modal-title').text(email.subject || '(No subject)');
        modal.find('#emailFrom').text(email.from_address);
        modal.find('#emailTo').text(email.to_address);
        modal.find('#emailDate').text(this.formatTime(new Date(email.received_at)));
        
        // Visa innehåll
        const contentContainer = modal.find('#emailContent');
        // Forget the previous message's HTML so the modal-level "Visa bilder"
        // button can never re-render a message other than the open one.
        this.currentEmailHtml = null;
        if (email.body_html) {
            // Rendered in a sandboxed iframe, never injected into this page:
            // mail HTML is attacker-controlled (see renderHtmlEmail()).
            this.currentEmailHtml = String(email.body_html);
            const blockImages = (localStorage.getItem('block_images') === '1');
            this.renderHtmlEmail(contentContainer, this.currentEmailHtml, blockImages);
        } else if (email.body_text) {
            // Escape HTML then parse simple Markdown-like markers (**bold**, *italic*, _italic_)
            const escaped = this.escapeHtml(email.body_text);
            const parsed = this.parseSimpleMarkdown(escaped);
            contentContainer.html(parsed);
        } else {
            contentContainer.html('<p class="text-muted">No content available</p>');
        }
        
        // Visa attachments (if any)
        const attachments = email.attachments || [];
        const attachmentsHtml = [];
        if (attachments.length) {
            attachments.forEach(att => {
                const url = att.download_url || ('/files.php?id=' + encodeURIComponent(att.id));
                const dl = `<div class="tm-attachment-item mb-2">
                    <a class="btn btn-sm btn-outline-primary" href="${url}" target="_blank" rel="noreferrer noopener">Ladda ner</a>
                    <small class="ms-2">${this.escapeHtml(att.filename)}</small>
                </div>`;
                attachmentsHtml.push(dl);
            });
        }

        // Visa modal
        // The modal-level "Visa bilder" button is only useful while the open
        // message has images held back by the block-images preference.
        const modalBtn = modal.find('#modalShowImagesBtn');
        if (modalBtn && modalBtn.length) {
            if (contentContainer.find('.ms-mail-frame').attr('data-blocked-images')) {
                modalBtn.show();
            } else {
                modalBtn.hide();
            }
        }

        // Render attachments section below content
        const attachmentsContainer = modal.find('#emailAttachments');
        if (attachmentsContainer && attachmentsContainer.length) {
            if (attachmentsHtml.length) {
                attachmentsContainer.html(attachmentsHtml.join('\n'));
                attachmentsContainer.show();
            } else {
                attachmentsContainer.hide();
            }
        }

        modal.modal('show');
    }
    
    /**
     * Kopiera e-postadress
     */
    async copyAddress() {
        if (!this.currentAddress) {
            this.showNotification('No address to copy', 'error');
            return;
        }
        
        const fullAddress = `${this.currentAddress}@${window.tempMailConfig.domain}`;
        
        try {
            await navigator.clipboard.writeText(fullAddress);
            this.showNotification('Adress kopierad!', 'success');
        } catch (error) {
            // Fallback för äldre webbläsare
            const textArea = document.createElement('textarea');
            textArea.value = fullAddress;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            this.showNotification('Adress kopierad!', 'success');
        }
    }
    
    /**
     * Dela länk för aktuell adress
     */
    async shareLink() {
        if (!this.currentAddress) {
            this.showNotification('No address to share', 'error');
            return;
        }
        
        // Always point at the public reader: built from the current page, a
        // link shared from pro.php sent the recipient to the login instead.
        const url = new URL('inbox.php', window.location.href);
        url.searchParams.set('address', this.currentAddress);
        const shareUrl = url.toString();
        
        try {
            await navigator.clipboard.writeText(shareUrl);
            this.showNotification('Länk kopierad! Andra kan nu använda samma adress.', 'success');
        } catch (error) {
            // Fallback för äldre webbläsare
            const textArea = document.createElement('textarea');
            textArea.value = shareUrl;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            this.showNotification('Länk kopierad! Andra kan nu använda samma adress.', 'success');
        }
    }
    
    /**
     * Hämta och uppdatera statistik
     */
    async loadStats() {
        // The signed-in account's own numbers (get_stats in index.php). The
        // block is rendered only for a signed-in user on inbox.php/pro.php, so
        // the markup decides whether there is anything to fill in. The
        // system-wide totals are server-rendered on the landing page.
        if (!document.getElementById('userStats')) {
            return;
        }
        if (this.statsRequest) {
            return;
        }

        try {
            this.statsRequest = $.ajax({
                url: 'index.php',
                method: 'POST',
                data: { action: 'get_stats' },
                dataType: 'json'
            });
            const response = await this.statsRequest;

            if (response.success && response.stats) {
                const stats = response.stats;

                this.animateStatNumber('#statsEmails', stats.emails || 0);
                this.animateStatNumber('#statsReceived24h', stats.received_24h || 0);
                this.animateStatNumber('#statsAddresses', stats.addresses || 0);
                $('#statsStorage').text(
                    this.formatBytes(stats.storage_bytes || 0) + ' / ' + this.formatBytes(stats.quota_bytes || 0)
                );
            }
        } catch (error) {
            console.error('Error loading stats:', error);
        } finally {
            this.statsRequest = null;
        }
    }

    /**
     * Human-readable byte count for the storage stat.
     */
    formatBytes(bytes) {
        const units = ['B', 'KB', 'MB', 'GB'];
        let value = Number(bytes) || 0;
        let i = 0;
        while (value >= 1024 && i < units.length - 1) {
            value /= 1024;
            i++;
        }
        const digits = (i === 0 || value >= 10) ? 0 : 1;
        return value.toLocaleString('sv-SE', { maximumFractionDigits: digits }) + ' ' + units[i];
    }

    // Theme removed: no toggleTheme/loadTheme methods

    updateImageToggleButton() {
        const btn = document.getElementById('imageToggle');
        if (!btn) return;
        const blocked = (localStorage.getItem('block_images') === '1');
        const icon = btn.querySelector('i');
        if (blocked) {
            btn.classList.add('btn-danger');
            btn.classList.remove('btn-outline-light');
            btn.title = 'Bilder blockeras (klicka för att visa)';
            if (icon) icon.className = 'fas fa-eye-slash';
        } else {
            btn.classList.remove('btn-danger');
            btn.classList.add('btn-outline-light');
            btn.title = 'Blockera externa bilder';
            if (icon) icon.className = 'fas fa-image';
        }
    }

    /**
     * Animera sifferuppdatering för statistik
     */
    animateStatNumber(selector, newValue) {
        const $element = $(selector);
        $element.stop(true);
        const currentValue = parseInt($element.text().replace(/\D/g, ''), 10) || 0;
        
        if (currentValue !== newValue) {
            $element.prop('Counter', currentValue).animate({
                Counter: newValue
            }, {
                duration: 1000,
                easing: 'swing',
                step: function (now) {
                    $element.text(Math.ceil(now).toLocaleString('sv-SE'));
                }
            });
        }
    }
    
    /**
     * Starta automatisk uppdatering
     */
    startAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
        }
        
        if (this.autoRefreshEnabled) {
            this.refreshInterval = setInterval(() => {
                if (document.visibilityState === 'visible') {
                    this.autoRefreshCount++;
                    
                    // Hämta e-post om vi har en adress
                    if (this.currentAddress) {
                        // Gör en IMAP-refresh var 5:e auto-refresh (var 50:e sekund)
                        const shouldForceRefresh = (this.autoRefreshCount % 5 === 0);
                        if (shouldForceRefresh) {
                            this.loadEmails(true);
                        } else {
                            // Background check: only fetch full emails if there are new ones
                            (async () => {
                                try {
                                    const chk = await this.checkForNewEmails();
                                    if (chk && chk.success && chk.has_new) {
                                        this.loadEmails(false);
                                    }
                                } catch (e) {
                                    // ignore background check failures
                                }
                            })();
                        }
                    }
                    
                    // Hämta statistik var 6:e auto-refresh (var minut)
                    if (this.autoRefreshCount % 6 === 0) {
                        this.loadStats();
                    }
                }
            }, this.refreshRate);
            
            this.updateAutoRefreshIndicator();
        }
    }
    
    /**
     * Toggle automatisk uppdatering
     */
    toggleAutoRefresh() {
        this.autoRefreshEnabled = !this.autoRefreshEnabled;
        
        if (this.autoRefreshEnabled) {
            this.startAutoRefresh();
            this.showNotification('Auto-refresh enabled', 'success');
        } else {
            if (this.refreshInterval) {
                clearInterval(this.refreshInterval);
                this.refreshInterval = null;
            }
            this.showNotification('Auto-refresh disabled', 'info');
        }
        
        this.updateAutoRefreshIndicator();
    }
    
    /**
     * Uppdatera UI
     */
    updateUI() {
        if (this.currentAddress) {
            const fullAddress = `${this.currentAddress}@${window.tempMailConfig.domain}`;
            $('#currentEmail').text(fullAddress);
            $('.email-container').removeClass('d-none');
            $('#initial-generator').addClass('d-none');
            $('#generateBtn, #newAddressBtn').text('Generate New Address');
            console.log('updateUI: expiresAt =', this.expiresAt, 'isPersonal =', this.isPersonalAddress);
            this.startValidityCountdown();
            // Update privacy indicator
            if (this.isPersonalAddress) {
                $('#privacyIndicator').removeClass('d-none');
                $('#privacyText').text('Private');
                $('#privacyIndicator i').removeClass('fa-globe').addClass('fa-lock');
            } else {
                $('#privacyIndicator').removeClass('d-none');
                $('#privacyText').text('Public');
                $('#privacyIndicator i').removeClass('fa-lock').addClass('fa-globe');
            }
        } else {
            $('.email-container').addClass('d-none');
            $('#initial-generator').removeClass('d-none');
            $('#generateBtn, #newAddressBtn').text('Get Email Address');
            this.stopValidityCountdown();
            $('#validityText').text('Valid for 24 hours');
            $('#privacyIndicator').addClass('d-none');
        }
    }

    /**
     * Starta och uppdatera nedräkning för giltighet
     */
    startValidityCountdown() {
        this.stopValidityCountdown();
        if (!this.expiresAt) {
            $('#validityText').text('Valid for 24 hours');
            return;
        }
        this.updateValidityText();
        this.validityTimer = setInterval(() => {
            this.updateValidityText();
        }, 1000);
    }

    stopValidityCountdown() {
        if (this.validityTimer) {
            clearInterval(this.validityTimer);
            this.validityTimer = null;
        }
    }

    /**
     * Uppdatera giltighetstexten
     */
    updateValidityText() {
        if (!this.expiresAt) {
            $('#validityText').text('Valid for 24 hours till automatically deleted');
            return;
        }
        const now = new Date();
        let diff = Math.floor((this.expiresAt.getTime() - now.getTime()) / 1000);
        // Treat very long expirations as 'Unlimited' (e.g. personal/pro addresses with multi-year TTL)
        const unlimitedThresholdSeconds = 60 * 60 * 24 * 365 * 5; // 5 years
        if (diff > unlimitedThresholdSeconds) {
            $('#validityText').text('Unlimited');
            return;
        }
        if (diff <= 0) {
            $('#validityText').text('Utgången – automatically deleted');
            this.stopValidityCountdown();
            return;
        }
        const hours = Math.floor(diff / 3600);
        const minutes = Math.floor((diff % 3600) / 60);
        let timeText = '';
        if (hours > 0) {
            timeText = `${hours}h ${minutes}m`;
        } else if (minutes > 0) {
            timeText = `${minutes}m`;
        } else {
            timeText = `${diff % 60}s`;
        }
        $('#validityText').text(`${timeText} till automatically deleted`);
    }
    
    /**
     * Uppdatera URL med aktuell adress
     */
    updateURL() {
        if (this.currentAddress && history.pushState) {
            // Behåll övriga parametrar (t.ex. filter_address) när adressen byts.
            const params = new URLSearchParams(window.location.search);
            params.set('address', this.currentAddress);
            const newUrl = `${window.location.pathname}?${params.toString()}`;
            history.pushState({address: this.currentAddress}, '', newUrl);
        }
    }
    
    /**
     * Uppdatera status-indikator
     */
    updateStatus(status, text = '') {
        const indicator = $('.status-indicator');
        indicator.removeClass('status-online status-offline loading');
        
        switch (status) {
            case 'online':
                indicator.addClass('status-online').html('<i class="fas fa-circle"></i> Online ' + text);
                break;
            case 'offline':
                indicator.addClass('status-offline').html('<i class="fas fa-exclamation-circle"></i> Offline');
                break;
            case 'loading':
                indicator.addClass('loading').html('<div class="spinner"></div> Laddar...');
                break;
        }
    }
    
    /**
     * Uppdatera auto-refresh indikator
     */
    updateAutoRefreshIndicator() {
        let indicator = $('.auto-refresh');
        
        if (indicator.length === 0) {
            indicator = $('<div class="auto-refresh"></div>').appendTo('body');
        }
        
        if (this.autoRefreshEnabled) {
            indicator.html('<i class="fas fa-sync-alt"></i> Auto-refresh: ON').show();
        } else {
            indicator.html('<i class="fas fa-pause"></i> Auto-refresh: OFF').show();
        }
    }
    
    /**
     * Visa loading-state för knapp
     */
    showLoading(selector, text) {
        const btn = $(selector);
        btn.prop('disabled', true);
        btn.data('original-text', btn.text());
        btn.html(`<span class="spinner-border spinner-border-sm me-2"></span>${text}`);
    }
    
    /**
     * Dölj loading-state för knapp
     */
    hideLoading(selector, text = null) {
        const btn = $(selector);
        btn.prop('disabled', false);
        const originalText = text || btn.data('original-text');
        btn.text(originalText);
    }
    
    /**
     * Visa notification
     */
    showNotification(message, type = 'info', duration = 3000) {
        // Ta bort befintliga notifikationer
        $('.copy-notification').remove();
        
        const notification = $(`
            <div class="copy-notification ${type}">
                <i class="fas fa-${this.getNotificationIcon(type)}"></i> ${message}
            </div>
        `);
        
        $('body').append(notification);
        
        // Animera in
        setTimeout(() => notification.addClass('show'), 100);
        
        // Animera ut efter duration
        setTimeout(() => {
            notification.removeClass('show');
            setTimeout(() => notification.remove(), 300);
        }, duration);
    }
    
    /**
     * Hämta ikon för notifikation
     */
    getNotificationIcon(type) {
        switch (type) {
            case 'success': return 'check';
            case 'error': return 'exclamation-triangle';
            case 'warning': return 'exclamation';
            default: return 'info';
        }
    }
    
    /**
     * Formatera tid
     */
    formatTime(date) {
        const now = new Date();
        const diff = now.getTime() - date.getTime();
        const minutes = Math.floor(diff / 60000);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);
        
        if (minutes < 1) return 'Nu';
        if (minutes < 60) return `${minutes}m sedan`;
        if (hours < 24) return `${hours}t sedan`;
        if (days < 7) return `${days}d sedan`;
        
        return date.toLocaleDateString('sv-SE', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    /**
     * Hämta förhandsvisning av e-post
     */
    getEmailPreview(content, maxLength = 150) {
        if (!content) return 'Inget innehåll tillgängligt';
        
        // Ta bort HTML-taggar
        const text = content.replace(/<[^>]*>/g, '');
        
        // Ta bort extra whitespace
        const cleaned = text.replace(/\s+/g, ' ').trim();
        
        if (cleaned.length <= maxLength) return cleaned;
        
        return cleaned.substring(0, maxLength) + '...';
    }
    
    /**
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Parse a very small subset of Markdown in plain text: **bold**, *italic* or _italic_
     * The function first expects an already-escaped input (so HTML is safe), then
     * converts the markdown markers to <strong>/<em> tags and preserves paragraphs/line breaks.
     */
    parseSimpleMarkdown(escapedText) {
        if (!escapedText) return escapedText;

        // Normalize newlines
        let s = escapedText.replace(/\r\n?/g, '\n');

        // Convert **bold** first (non-greedy)
        s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

        // Convert _italic_ (underscores) and *italic* (single asterisks)
        // After bold is replaced, single asterisks represent italics.
        s = s.replace(/_(.+?)_/g, '<em>$1</em>');
        s = s.replace(/\*(.+?)\*/g, '<em>$1</em>');

        // Split into paragraphs on double newlines, and convert single newlines to <br>
        const paras = s.split(/\n\n+/).map(p => p.replace(/\n/g, '<br>'));
        return paras.map(p => `<p>${p}</p>`).join('');
    }
    
    /**
     * Render an HTML mail body into `container` inside a sandboxed iframe.
     *
     * Mail HTML is attacker-controlled. The server already runs it through
     * HTMLPurifier (get_email in index.php); this is the second layer:
     *  - the markup is parsed with DOMParser, which runs no script and loads
     *    no image, and is never assigned to innerHTML in this document;
     *  - it is shown through `srcdoc` in an iframe whose sandbox has no
     *    allow-scripts and no allow-same-origin, so even markup that slipped
     *    past both filters cannot run script or reach this origin;
     *  - a CSP inside the frame forbids script, plugins, frames and forms,
     *    and - while images are blocked - every image load;
     *  - links open in a new tab (<base target="_blank">) with no referrer.
     *
     * With `blockImages`, each <img> becomes a placeholder naming its host;
     * the modal-level "Visa bilder" button re-renders with images on.
     */
    renderHtmlEmail(container, html, blockImages) {
        const doc = new DOMParser().parseFromString(String(html || ''), 'text/html');

        // Belt and braces: the sandbox + CSP already neutralise these.
        doc.querySelectorAll('script, iframe, frame, frameset, object, embed, applet, form, base, meta, link, noscript, template')
            .forEach(el => el.remove());
        doc.querySelectorAll('*').forEach(el => {
            Array.from(el.attributes).forEach(attr => {
                const name = attr.name.toLowerCase();
                const value = String(attr.value || '').replace(/[\u0000- ]+/g, '').toLowerCase();
                if (name.startsWith('on') || name === 'srcdoc' || name === 'formaction'
                    || (/^(href|src|action|xlink:href|background|poster)$/.test(name)
                        && /^(javascript|vbscript|data):/.test(value))) {
                    el.removeAttribute(attr.name);
                }
            });
        });

        doc.querySelectorAll('a[href]').forEach(a => {
            a.setAttribute('target', '_blank');
            a.setAttribute('rel', 'noopener noreferrer');
        });

        let blocked = 0;
        doc.querySelectorAll('img').forEach(img => {
            const src = img.getAttribute('src') || '';
            if (!blockImages) {
                img.setAttribute('referrerpolicy', 'no-referrer');
                img.setAttribute('loading', 'lazy');
                img.setAttribute('decoding', 'async');
                return;
            }
            blocked++;
            const placeholder = doc.createElement('span');
            placeholder.className = 'ms-mail-img-blocked';
            let host = '';
            try { host = src ? new URL(src, window.location.href).host : ''; } catch (e) { host = ''; }
            placeholder.textContent = host ? ('Image blocked: ' + host) : 'Image blocked';
            img.replaceWith(placeholder);
        });

        // The frame cannot see this page's stylesheets, so hand it the
        // current --ms-* token values and style it with those.
        const rootStyle = getComputedStyle(document.documentElement);
        const tokens = ['--ms-font-sans', '--ms-text', '--ms-text-muted', '--ms-bg-raised',
            '--ms-bg-sunken', '--ms-border', '--ms-accent', '--ms-fs-body', '--ms-fs-sm',
            '--ms-lh-body', '--ms-space-1', '--ms-space-2', '--ms-space-4', '--ms-radius-sm']
            .map(name => {
                const value = rootStyle.getPropertyValue(name).trim().replace(/[;{}<>]/g, '');
                return value ? `${name}:${value};` : '';
            }).join('');

        const imgSrc = blockImages ? "'none'" : 'https: http:';
        const csp = `default-src 'none'; img-src ${imgSrc}; style-src 'unsafe-inline'; font-src 'none'; `
            + "script-src 'none'; object-src 'none'; frame-src 'none'; form-action 'none'; base-uri 'none'";

        const srcdoc = '<!DOCTYPE html><html><head><meta charset="utf-8">'
            + `<meta http-equiv="Content-Security-Policy" content="${csp}">`
            + '<meta name="referrer" content="no-referrer">'
            + '<base target="_blank">'
            + `<style>:root{${tokens}}`
            + 'html,body{margin:0;background:var(--ms-bg-raised);color:var(--ms-text);}'
            + 'body{padding:var(--ms-space-4);font-family:var(--ms-font-sans);font-size:var(--ms-fs-body);'
            + 'line-height:var(--ms-lh-body);overflow-wrap:anywhere;}'
            + 'a{color:var(--ms-accent);}img{max-width:100%;height:auto;}table{max-width:100%;}'
            + '.ms-mail-img-blocked{display:inline-block;padding:var(--ms-space-1) var(--ms-space-2);'
            + 'border:1px dashed var(--ms-border);border-radius:var(--ms-radius-sm);'
            + 'background:var(--ms-bg-sunken);color:var(--ms-text-muted);font-size:var(--ms-fs-sm);}'
            + '</style></head><body>'
            + (doc.body ? doc.body.innerHTML : '')
            + '</body></html>';

        const frame = document.createElement('iframe');
        frame.className = 'ms-mail-frame';
        frame.setAttribute('sandbox', 'allow-popups allow-popups-to-escape-sandbox');
        frame.setAttribute('referrerpolicy', 'no-referrer');
        frame.setAttribute('title', 'Message content');
        if (blocked > 0) frame.setAttribute('data-blocked-images', String(blocked));
        frame.srcdoc = srcdoc;

        const nodes = [];
        if (blocked > 0) {
            const note = document.createElement('p');
            note.className = 'ms-mail-frame__note';
            note.textContent = blocked === 1
                ? '1 external image is blocked. Use "Visa bilder" to load it.'
                : `${blocked} external images are blocked. Use "Visa bilder" to load them.`;
            nodes.push(note);
        }
        nodes.push(frame);
        const target = container && container.jquery ? container[0] : container;
        if (target) target.replaceChildren(...nodes);
        return frame;
    }
}

// Initialisera när DOM är laddat
$(document).ready(() => {
    window.tempMailApp = new TempMailApp();
});

// Hantera visibility change för att pausa/återuppta auto-refresh
document.addEventListener('visibilitychange', () => {
    if (window.tempMailApp) {
        if (document.visibilityState === 'visible') {
            window.tempMailApp.startAutoRefresh();
        }
    }
});

// Service Worker registration (för offline support)
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Register service worker from its new location
        navigator.serviceWorker.register('/assets/js/sw.js')
            .then(registration => {
                console.log('SW registered: ', registration);
            })
            .catch(registrationError => {
                console.log('SW registration failed: ', registrationError);
            });
    });
}