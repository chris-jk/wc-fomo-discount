// assets/frontend.js
jQuery(document).ready(function ($) {
    
    // Debug: Check if wcfd_ajax is loaded
    console.log('WCFD Debug: wcfd_ajax object:', typeof wcfd_ajax !== 'undefined' ? wcfd_ajax : 'NOT DEFINED');
    
    // Safety check for wcfd_ajax
    if (typeof wcfd_ajax === 'undefined') {
        console.error('WCFD Error: wcfd_ajax object not found. Plugin may not be loaded correctly.');
        return;
    }

    // Constants
    const CONSTANTS = {
        UPDATE_INTERVAL: 3000,
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

        // Start real-time updates for this widget
        startRealtimeUpdates(widget, campaignId);

        // Handle claim button click
        widget.find('.wcfd-claim-btn').on('click', function (e) {
            e.preventDefault();
            console.log('WCFD Debug: Claim button clicked for campaign:', campaignId);
            claimDiscount(widget, campaignId);
        });

        // Handle enter key in email field
        widget.find('.wcfd-email').on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                claimDiscount(widget, campaignId);
            }
        });
    });

    function startRealtimeUpdates(widget, campaignId) {
        let isUpdating = false;
        
        // Update every 3 seconds
        updateIntervals[campaignId] = setInterval(function () {
            if (isUpdating) return;
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

        // Disable button and show loading
        button.prop('disabled', true).text('Processing...');

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

                    // Update counter
                    updateCounter(widget, response.data.codes_remaining);

                    // Add code to clipboard button
                    addCopyButton(widget, response.data.code);

                    // Add social sharing
                    addSocialSharing(widget, response.data.code);

                    // Start countdown timer for code expiry
                    startExpiryCountdown(widget, expiryDate);

                } else {
                    console.error('Discount claim failed:', response);
                    let errorMsg = response.data || 'Unknown error occurred';
                    showError(widget, errorMsg);
                    button.prop('disabled', false).text('Get My Code!');
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
                button.prop('disabled', false).text('Get My Code!');
            }
        });
    }

    function showError(widget, message) {
        const errorElement = widget.find('.wcfd-error');
        errorElement.text(message).fadeIn();
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