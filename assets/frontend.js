// assets/frontend.js
jQuery(document).ready(function ($) {
    
    // Debug: Check if wcfd_ajax is loaded
    console.log('WCFD Debug: wcfd_ajax object:', typeof wcfd_ajax !== 'undefined' ? wcfd_ajax : 'NOT DEFINED');
    
    // Safety check for wcfd_ajax
    if (typeof wcfd_ajax === 'undefined') {
        console.error('WCFD Error: wcfd_ajax object not found. Plugin may not be loaded correctly.');
        return;
    }

    // Check for pending coupon on page load (cart/checkout pages)
    checkAndApplyPendingCoupon();

    // Constants
    const CONSTANTS = {
        UPDATE_INTERVAL: 15000, // Reduced from 3s to 15s - much less annoying
        ERROR_DISPLAY_TIME: 5000,
        COUNTDOWN_INTERVAL: 60000,
        COPY_FEEDBACK_TIME: 2000,
        NOTIFICATION_TIME: 3000,
        PULSE_DURATION: 1000,
        FADE_DURATION: 200
    };

    // Real-time counter update
    let updateIntervals = {};

    console.log('WCFD Debug: Looking for widgets...');
    $('.wcfd-discount-widget').each(function () {
        const widget = $(this);
        const campaignId = widget.data('campaign-id');
        console.log('WCFD Debug: Found widget for campaign:', campaignId);

        // Initialize accessibility features
        initializeAccessibility(widget);

        // Start real-time updates for this widget
        startRealtimeUpdates(widget, campaignId);

        // Handle claim button click
        widget.find('.wcfd-claim-btn').on('click', function (e) {
            e.preventDefault();
            console.log('WCFD Debug: Claim button clicked for campaign:', campaignId);
            
            // Stop polling when user interacts
            clearInterval(updateIntervals[campaignId]);
            console.log('WCFD: Stopped polling - user interaction');
            
            claimDiscount(widget, campaignId);
        });

        // Handle email field interactions
        widget.find('.wcfd-email').on('focus keydown', function (e) {
            // Pause polling when user is actively typing
            if (updateIntervals[campaignId]) {
                clearInterval(updateIntervals[campaignId]);
                console.log('WCFD: Paused polling - user typing');
                
                // Restart polling after 30 seconds of inactivity
                setTimeout(() => {
                    if (!widget.find('.wcfd-success').is(':visible')) {
                        startRealtimeUpdates(widget, campaignId);
                    }
                }, 30000);
            }
        });
        
        widget.find('.wcfd-email').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                clearInterval(updateIntervals[campaignId]);
                claimDiscount(widget, campaignId);
            }
        });
    });

    function startRealtimeUpdates(widget, campaignId) {
        let isUpdating = false;
        let pollCount = 0;
        const maxPolls = 20; // Stop after 20 polls (5 minutes)
        
        // Don't start polling if user has already claimed or if codes are 0
        if (widget.find('.wcfd-success').is(':visible') || widget.find('.wcfd-waitlist-form').length > 0) {
            return;
        }
        
        // Update every 15 seconds (much less aggressive)
        updateIntervals[campaignId] = setInterval(function () {
            if (isUpdating) return;
            
            // Stop polling after max attempts to prevent endless requests
            pollCount++;
            if (pollCount > maxPolls) {
                clearInterval(updateIntervals[campaignId]);
                console.log('WCFD: Stopped polling after', maxPolls, 'attempts');
                return;
            }
            
            isUpdating = true;
            $.ajax({
                url: wcfd_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'wcfd_get_campaign_status',
                    campaign_id: campaignId,
                    nonce: wcfd_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        updateCounter(widget, response.data.codes_remaining);
                    }
                },
                error: function (xhr, status, error) {
                    console.error('Failed to update campaign status:', error);
                },
                complete: function() {
                    isUpdating = false;
                }
            });
        }, CONSTANTS.UPDATE_INTERVAL);
    }

    function updateCounter(widget, codesRemaining) {
        const countElement = widget.find('.wcfd-count');
        const currentCount = parseInt(countElement.text());

        // Only update if the value actually changed
        if (currentCount !== codesRemaining) {
            // Smooth counter update with subtle animation
            countElement.text(codesRemaining);
            countElement.addClass('wcfd-count-updated');
            setTimeout(() => countElement.removeClass('wcfd-count-updated'), 600);

            // Update urgency message
            const urgencyElement = widget.find('.wcfd-urgency');
            const match = urgencyElement.text().match(/of (\d+)/);
            if (match && match[1]) {
                const totalCodes = parseInt(match[1]);
                urgencyElement.text(
                    'Hurry! Only ' + codesRemaining + ' out of ' + totalCodes + ' remaining'
                );
            }

            // Add pulse effect if codes are running low
            if (codesRemaining < 5) {
                widget.addClass('wcfd-pulse');
                setTimeout(() => widget.removeClass('wcfd-pulse'), CONSTANTS.PULSE_DURATION);
            }

            // Show waitlist form if no codes left
            if (codesRemaining === 0) {
                widget.find('.wcfd-claim-form').fadeOut(400, function() {
                    showWaitlistForm(widget, campaignId);
                });
                clearInterval(updateIntervals[widget.data('campaign-id')]);
            }
        }
    }

    function claimDiscount(widget, campaignId) {
        const button = widget.find('.wcfd-claim-btn');
        const emailField = widget.find('.wcfd-email');
        const email = emailField.length ? emailField.val().trim() : '';

        // Validate email if field exists and has content
        if (emailField.length && email) {
            if (!validateEmail(email)) {
                showError(widget, 'Please enter a valid email address');
                return;
            }
        }
        
        // For non-logged-in users, email is required
        if (emailField.length && !email) {
            showError(widget, 'Please enter your email address');
            return;
        }

        // Disable button and show loading with accessibility
        button.prop('disabled', true)
              .attr('aria-busy', 'true')
              .text('Processing...');
        
        // Announce processing to screen readers
        announceToScreenReader(widget, 'Processing your request, please wait');

        // Debug logging
        console.log('Claiming discount:', {
            campaignId: campaignId,
            email: email || '(using logged-in user email)',
            hasEmailField: emailField.length > 0
        });

        $.ajax({
            url: wcfd_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wcfd_claim_discount',
                campaign_id: campaignId,
                email: email,
                nonce: wcfd_ajax.nonce
            },
            success: function (response) {
                if (response.success) {
                    // Check if this is a verification message (for non-logged-in users)
                    if (response.data.message) {
                        // Show verification message
                        widget.find('.wcfd-claim-form').fadeOut(400, function() {
                            $(this).html('<div class="wcfd-verification-sent"><h4>📧 Check Your Email!</h4><p>' + response.data.message + '</p><p><small>Don\'t see it? Check your spam folder.</small></p></div>').fadeIn(200);
                        });
                        return;
                    }
                    
                    // Handle immediate success (logged-in users)
                    // Show success message
                    widget.find('.wcfd-claim-form').fadeOut();
                    widget.find('.wcfd-success .wcfd-code').text(response.data.code);

                    // Format expiry time
                    const expiryDate = new Date(response.data.expires_at);
                    if (isNaN(expiryDate.getTime())) {
                        console.error('Invalid expiry date:', response.data.expires_at);
                        showError(widget, 'Invalid expiry date received');
                        return;
                    }
                    const timeRemaining = getTimeRemaining(expiryDate);
                    widget.find('.wcfd-success .wcfd-expiry').html(
                        'Valid for: <strong>' + timeRemaining + '</strong>'
                    );

                    widget.find('.wcfd-success').fadeIn();
                    
                    // Announce success to screen readers
                    announceToScreenReader(widget, 'Success! Your discount code is ' + response.data.code, 'assertive');

                    // Update counter
                    updateCounter(widget, response.data.codes_remaining);

                    // Add code to clipboard button
                    addCopyButton(widget, response.data.code);

                    // Add social sharing
                    addSocialSharing(widget, response.data.code);

                    // Start countdown timer for code expiry
                    startExpiryCountdown(widget, expiryDate);

                    // Auto-apply coupon to cart
                    autoApplyCoupon(response.data.code);

                } else {
                    console.error('Discount claim failed:', response);
                    let errorMsg = response.data || 'Unknown error occurred';
                    showError(widget, errorMsg);
                    button.prop('disabled', false)
                          .attr('aria-busy', 'false')
                          .text('Get My Code!');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX failed to claim discount:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText,
                    error: error
                });
                let errorMessage = 'Connection error. Please try again.';
                if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage = xhr.responseJSON.data;
                } else if (xhr.status === 0) {
                    errorMessage = 'Network connection failed. Please check your internet connection.';
                } else if (xhr.status >= 500) {
                    errorMessage = 'Server error occurred. Please try again later.';
                }
                showError(widget, errorMessage);
                button.prop('disabled', false)
                      .attr('aria-busy', 'false')
                      .text('Get My Code!');
            }
        });
    }

    function showError(widget, message) {
        const errorElement = widget.find('.wcfd-error');
        
        // Add ARIA attributes for screen readers
        errorElement.attr({
            'role': 'alert',
            'aria-live': 'assertive'
        });
        
        errorElement.text(message).fadeIn();
        
        // Announce to screen readers
        announceToScreenReader(widget, 'Error: ' + message, 'assertive');
        
        // Focus management - move focus to error for screen readers
        errorElement.attr('tabindex', '-1').focus();
        
        setTimeout(() => {
            errorElement.fadeOut();
        }, CONSTANTS.ERROR_DISPLAY_TIME);
    }

    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    function getTimeRemaining(expiryDate) {
        const now = new Date();
        const diff = expiryDate - now;

        if (diff <= 0) {
            return 'Expired';
        }

        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));

        if (hours > 0) {
            return hours + ' hours, ' + minutes + ' minutes';
        } else {
            return minutes + ' minutes';
        }
    }

    function addCopyButton(widget, code) {
        const copyBtn = $('<button class="wcfd-copy-btn">Copy Code</button>');
        widget.find('.wcfd-success').append(copyBtn);

        copyBtn.on('click', async function () {
            try {
                // Use modern Clipboard API if available
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(code);
                } else {
                    // Fallback for older browsers
                    const temp = $('<textarea>');
                    $('body').append(temp);
                    temp.val(code).select();
                    document.execCommand('copy');
                    temp.remove();
                }

                // Show feedback
                copyBtn.text('Copied!');
                setTimeout(() => copyBtn.text('Copy Code'), CONSTANTS.COPY_FEEDBACK_TIME);
            } catch (err) {
                console.error('Failed to copy:', err);
                copyBtn.text('Failed to copy');
                setTimeout(() => copyBtn.text('Copy Code'), CONSTANTS.COPY_FEEDBACK_TIME);
            }
        });
    }

    function startExpiryCountdown(widget, expiryDate) {
        const countdownInterval = setInterval(function () {
            const remaining = getTimeRemaining(expiryDate);
            widget.find('.wcfd-expiry').html('Valid for: <strong>' + remaining + '</strong>');

            if (remaining === 'Expired') {
                clearInterval(countdownInterval);
                widget.find('.wcfd-expiry').html('<strong style="color: red;">This code has expired</strong>');
            }
        }, CONSTANTS.COUNTDOWN_INTERVAL); // Update every minute
    }

    function addSocialSharing(widget, code) {
        const currentUrl = window.location.href;
        const shareText = `🎉 Just got an exclusive discount code: ${code}! Limited time offer - grab yours now!`;
        const shareUrl = encodeURIComponent(currentUrl);
        const shareTextEncoded = encodeURIComponent(shareText);

        const socialShare = $(`
            <div class="wcfd-social-share">
                <h4>📢 Share this deal with friends!</h4>
                <div class="wcfd-social-buttons">
                    <a href="https://www.facebook.com/sharer/sharer.php?u=${shareUrl}&quote=${shareTextEncoded}" 
                       target="_blank" class="wcfd-social-btn facebook">
                        📘 Facebook
                    </a>
                    <a href="https://twitter.com/intent/tweet?text=${shareTextEncoded}&url=${shareUrl}" 
                       target="_blank" class="wcfd-social-btn twitter">
                        🐦 Twitter
                    </a>
                    <a href="https://wa.me/?text=${shareTextEncoded}%20${shareUrl}" 
                       target="_blank" class="wcfd-social-btn whatsapp">
                        💬 WhatsApp
                    </a>
                    <a href="https://t.me/share/url?url=${shareUrl}&text=${shareTextEncoded}" 
                       target="_blank" class="wcfd-social-btn telegram">
                        ✈️ Telegram
                    </a>
                    <button class="wcfd-social-btn copy wcfd-copy-link">
                        🔗 Copy Link
                    </button>
                </div>
            </div>
        `);

        widget.find('.wcfd-success').append(socialShare);

        // Handle copy link button
        socialShare.find('.wcfd-copy-link').on('click', async function() {
            const btn = $(this);
            const originalText = btn.html();
            
            try {
                const shareData = `${shareText} ${currentUrl}`;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    await navigator.clipboard.writeText(shareData);
                } else {
                    // Fallback for older browsers
                    const temp = $('<textarea>');
                    $('body').append(temp);
                    temp.val(shareData).select();
                    document.execCommand('copy');
                    temp.remove();
                }
                
                btn.html('✅ Copied!');
                setTimeout(() => btn.html(originalText), CONSTANTS.COPY_FEEDBACK_TIME);
            } catch (err) {
                console.error('Failed to copy:', err);
                btn.html('❌ Failed');
                setTimeout(() => btn.html(originalText), CONSTANTS.COPY_FEEDBACK_TIME);
            }
        });
    }

    // Add to cart notification when code is applied
    $(document.body).on('applied_coupon', function (e, coupon) {
        if (coupon.indexOf('FOMO') === 0) {
            // Show success notification
            const notification = $('<div class="wcfd-applied-notification">🎉 Discount applied successfully!</div>');
            $('body').append(notification);
            notification.fadeIn();

            setTimeout(() => {
                notification.fadeOut(() => notification.remove());
            }, CONSTANTS.NOTIFICATION_TIME);
        }
    });

    function checkAndApplyPendingCoupon() {
        if (typeof(Storage) === "undefined") {
            return;
        }

        const pendingCoupon = localStorage.getItem('wcfd_pending_coupon');
        const couponTime = localStorage.getItem('wcfd_pending_coupon_time');

        if (!pendingCoupon || !couponTime) {
            return;
        }

        // Check if coupon is still valid (within 24 hours)
        const timeDiff = Date.now() - parseInt(couponTime);
        const twentyFourHours = 24 * 60 * 60 * 1000;

        if (timeDiff > twentyFourHours) {
            localStorage.removeItem('wcfd_pending_coupon');
            localStorage.removeItem('wcfd_pending_coupon_time');
            return;
        }

        // Check if we're on cart or checkout page
        const isCartPage = $('body').hasClass('woocommerce-cart');
        const isCheckoutPage = $('body').hasClass('woocommerce-checkout');

        if (isCartPage || isCheckoutPage) {
            console.log('WCFD: Found pending coupon, attempting to apply:', pendingCoupon);
            
            // Wait a moment for WooCommerce to load
            setTimeout(function() {
                applyPendingCoupon(pendingCoupon);
            }, 500);
        }
    }

    function applyPendingCoupon(couponCode) {
        // Check if coupon is already applied
        if ($('.woocommerce-remove-coupon[data-coupon="' + couponCode + '"]').length > 0) {
            console.log('WCFD: Coupon already applied:', couponCode);
            localStorage.removeItem('wcfd_pending_coupon');
            localStorage.removeItem('wcfd_pending_coupon_time');
            return;
        }

        // Try to apply the coupon
        const $couponForm = $('.checkout_coupon, .coupon');
        if ($couponForm.length > 0) {
            const $couponInput = $couponForm.find('input[name="coupon_code"]');
            if ($couponInput.length > 0) {
                $couponInput.val(couponCode);
                $couponForm.find('button[name="apply_coupon"], input[name="apply_coupon"]').click();
                
                // Clear the pending coupon
                localStorage.removeItem('wcfd_pending_coupon');
                localStorage.removeItem('wcfd_pending_coupon_time');
            }
        }
    }

    function autoApplyCoupon(couponCode) {
        console.log('WCFD: Attempting to auto-apply coupon:', couponCode);
        
        // Store coupon for later use if cart is empty or user navigates away
        if (typeof(Storage) !== "undefined") {
            localStorage.setItem('wcfd_pending_coupon', couponCode);
            localStorage.setItem('wcfd_pending_coupon_time', Date.now());
        }

        // Try to apply coupon immediately if WooCommerce AJAX is available
        let ajaxUrl = wcfd_ajax.ajax_url;
        let nonce = '';
        
        // Check for WooCommerce checkout/cart nonce
        if (typeof wc_checkout_params !== 'undefined' && wc_checkout_params.apply_coupon_nonce) {
            nonce = wc_checkout_params.apply_coupon_nonce;
        } else if (typeof wc_add_to_cart_params !== 'undefined' && wc_add_to_cart_params.apply_coupon_nonce) {
            nonce = wc_add_to_cart_params.apply_coupon_nonce;
        }

        // Apply coupon via WooCommerce AJAX
        $.ajax({
            type: 'POST',
            url: ajaxUrl,
            data: {
                action: 'woocommerce_apply_coupon',
                coupon_code: couponCode,
                security: nonce
            },
            success: function(response) {
                if (response && (response.result === 'success' || response.success)) {
                    console.log('WCFD: Coupon applied successfully:', couponCode);
                    // Clear stored coupon since it was applied
                    if (typeof(Storage) !== "undefined") {
                        localStorage.removeItem('wcfd_pending_coupon');
                        localStorage.removeItem('wcfd_pending_coupon_time');
                    }
                    // Trigger WooCommerce events
                    $(document.body).trigger('applied_coupon', [couponCode]);
                    $(document.body).trigger('update_checkout');
                    $(document.body).trigger('wc_update_cart');
                } else {
                    console.log('WCFD: Coupon auto-apply failed, will try on cart/checkout page');
                }
            },
            error: function(xhr, status, error) {
                console.log('WCFD: Auto-apply coupon AJAX error, will try on cart/checkout page');
            }
        });
    }

    function initializeAccessibility(widget) {
        console.log('WCFD: Initializing accessibility features');
        
        // Add ARIA attributes to the widget
        widget.attr({
            'role': 'region',
            'aria-label': 'FOMO Discount Widget',
            'aria-live': 'polite'
        });

        // Add skip link for screen readers
        const skipLink = $('<a href="#" class="wcfd-skip-link wcfd-sr-only">Skip to main content</a>');
        widget.prepend(skipLink);

        // Enhance form elements
        const emailInput = widget.find('.wcfd-email');
        if (emailInput.length) {
            emailInput.attr({
                'aria-required': 'true',
                'aria-describedby': 'wcfd-email-help-' + widget.data('campaign-id'),
                'autocomplete': 'email',
                'inputmode': 'email'
            });

            // Add help text
            const helpText = $('<div id="wcfd-email-help-' + widget.data('campaign-id') + '" class="wcfd-sr-only">Enter your email address to claim your discount code</div>');
            emailInput.after(helpText);
        }

        // Enhance button
        const claimButton = widget.find('.wcfd-claim-btn');
        if (claimButton.length) {
            claimButton.attr({
                'type': 'button',
                'aria-describedby': 'wcfd-button-help-' + widget.data('campaign-id')
            });

            const buttonHelp = $('<div id="wcfd-button-help-' + widget.data('campaign-id') + '" class="wcfd-sr-only">Click to claim your exclusive discount code</div>');
            claimButton.after(buttonHelp);
        }

        // Add keyboard navigation
        widget.on('keydown', function(e) {
            // ESC key to close any modals/alerts
            if (e.key === 'Escape') {
                widget.find('.wcfd-error').fadeOut();
                widget.find('.wcfd-success').fadeOut();
            }
            
            // Enter key on focused elements
            if (e.key === 'Enter') {
                const focused = $(e.target);
                if (focused.is('.wcfd-claim-btn') || focused.is('.wcfd-email')) {
                    e.preventDefault();
                    claimButton.click();
                }
            }
        });

        // Add focus management
        widget.find('button, input, a').on('focus', function() {
            $(this).addClass('wcfd-focused');
        }).on('blur', function() {
            $(this).removeClass('wcfd-focused');
        });

        // Announce important changes
        const announcer = $('<div aria-live="assertive" aria-atomic="true" class="wcfd-sr-only" role="status"></div>');
        widget.append(announcer);
        widget.data('announcer', announcer);

        // Progress indicator
        const progressContainer = widget.find('.wcfd-codes-remaining');
        if (progressContainer.length) {
            progressContainer.attr({
                'role': 'progressbar',
                'aria-label': 'Discount codes remaining'
            });
        }

        console.log('WCFD: Accessibility features initialized');
    }

    function announceToScreenReader(widget, message, priority = 'polite') {
        const announcer = widget.data('announcer');
        if (announcer) {
            announcer.attr('aria-live', priority);
            announcer.text(message);
            
            // Clear after a short delay to allow for re-announcements
            setTimeout(() => {
                announcer.text('');
            }, 1000);
        }
    }

    function showWaitlistForm(widget, campaignId) {
        const waitlistForm = $(`
            <div class="wcfd-waitlist-form" style="animation: slideIn 0.5s ease-out;">
                <div class="wcfd-sold-out-header">
                    <h4>😱 All Gone!</h4>
                    <p>All discount codes have been claimed, but don't worry...</p>
                </div>
                
                <div class="wcfd-waitlist-signup">
                    <h4>🔔 Get Notified Next Time!</h4>
                    <p>Join our waitlist and be the first to know when we release new discount codes.</p>
                    
                    <div class="wcfd-waitlist-input-group">
                        <input type="email" class="wcfd-waitlist-email" placeholder="Enter your email address" required>
                        <button class="wcfd-waitlist-btn">Notify Me!</button>
                    </div>
                    
                    <div class="wcfd-waitlist-benefits">
                        <small>✨ Early access • 🎁 Exclusive deals • 📧 No spam, just savings</small>
                    </div>
                </div>
                
                <div class="wcfd-waitlist-error" style="display: none;"></div>
            </div>
        `);
        
        widget.find('.wcfd-claim-form').html(waitlistForm).fadeIn(200);
        
        // Handle waitlist signup
        waitlistForm.find('.wcfd-waitlist-btn').on('click', function(e) {
            e.preventDefault();
            joinWaitlist(widget, campaignId);
        });
        
        // Handle enter key in email field
        waitlistForm.find('.wcfd-waitlist-email').on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                joinWaitlist(widget, campaignId);
            }
        });
    }

    function joinWaitlist(widget, campaignId) {
        const button = widget.find('.wcfd-waitlist-btn');
        const emailField = widget.find('.wcfd-waitlist-email');
        const email = emailField.val().trim();
        
        // Validate email
        if (!email || !validateEmail(email)) {
            showWaitlistError(widget, 'Please enter a valid email address');
            return;
        }
        
        // Disable button and show loading
        button.prop('disabled', true).text('Joining...');
        
        $.ajax({
            url: wcfd_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'wcfd_join_waitlist',
                campaign_id: campaignId,
                email: email,
                nonce: wcfd_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    widget.find('.wcfd-waitlist-form').fadeOut(400, function() {
                        $(this).html(`
                            <div class="wcfd-waitlist-success">
                                <h4>🎉 You're In!</h4>
                                <p>${response.data.message}</p>
                                <div class="wcfd-waitlist-next-steps">
                                    <p><strong>What's next?</strong></p>
                                    <ul>
                                        <li>📧 Check your email for confirmation</li>
                                        <li>⚡ Get early access to future deals</li>
                                        <li>🎁 Exclusive member-only discounts</li>
                                    </ul>
                                </div>
                            </div>
                        `).fadeIn(200);
                    });
                } else {
                    showWaitlistError(widget, response.data || 'Failed to join waitlist');
                    button.prop('disabled', false).text('Notify Me!');
                }
            },
            error: function(xhr, status, error) {
                console.error('Failed to join waitlist:', error);
                showWaitlistError(widget, 'Connection error. Please try again.');
                button.prop('disabled', false).text('Notify Me!');
            }
        });
    }

    function showWaitlistError(widget, message) {
        const errorElement = widget.find('.wcfd-waitlist-error');
        errorElement.text(message).fadeIn();
        setTimeout(() => {
            errorElement.fadeOut();
        }, CONSTANTS.ERROR_DISPLAY_TIME);
    }

    // Cleanup intervals when page unloads
    $(window).on('beforeunload', function () {
        for (let id in updateIntervals) {
            clearInterval(updateIntervals[id]);
        }
    });
});