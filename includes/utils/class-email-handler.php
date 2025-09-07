<?php
/**
 * Email Handler Utility
 * 
 * @package WC_Fomo_Discount
 * @since 2.0.0
 */

namespace WCFD\Utils;

use WCFD\Core\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Email handling functionality
 */
class Email_Handler {
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * Constructor
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }
    
    /**
     * Send verification email
     * 
     * @param string $email
     * @param string $verification_token
     * @param array $campaign_data
     * @return bool
     */
    public function send_verification_email($email, $verification_token, $campaign_data) {
        $verification_url = add_query_arg([
            'wcfd_verify' => $verification_token,
            'email' => urlencode($email)
        ], home_url());
        
        $subject = 'Verify your email to claim your discount';
        
        $message = $this->get_verification_email_template([
            'verification_url' => $verification_url,
            'campaign_title' => $campaign_data['title'] ?? 'Exclusive Discount',
            'discount_amount' => $campaign_data['discount_amount'] ?? '20%'
        ]);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
        ];
        
        $sent = wp_mail($email, $subject, $message, $headers);
        
        if ($sent) {
            $this->logger->info('Verification email sent', [
                'email' => $this->hash_email($email),
                'campaign_id' => $campaign_data['id'] ?? 0
            ]);
        } else {
            $this->logger->error('Failed to send verification email', [
                'email' => $this->hash_email($email),
                'campaign_id' => $campaign_data['id'] ?? 0
            ]);
        }
        
        return $sent;
    }
    
    /**
     * Send coupon email with auto-login
     * 
     * @param string $email
     * @param string $coupon_code
     * @param string $login_token
     * @param array $campaign_data
     * @return bool
     */
    public function send_coupon_email($email, $coupon_code, $login_token, $campaign_data) {
        $login_url = add_query_arg([
            'wcfd_login' => $login_token,
            'email' => urlencode($email)
        ], home_url());
        
        $shop_url = add_query_arg([
            'wcfd_login' => $login_token,
            'coupon' => $coupon_code
        ], wc_get_page_permalink('shop'));
        
        $cart_url = add_query_arg([
            'wcfd_login' => $login_token,
            'coupon' => $coupon_code
        ], wc_get_cart_url());
        
        $checkout_url = add_query_arg([
            'wcfd_login' => $login_token,
            'coupon' => $coupon_code
        ], wc_get_checkout_url());
        
        $subject = '🎉 Your Exclusive Discount Code: ' . $coupon_code;
        
        $message = $this->get_coupon_email_template([
            'coupon_code' => $coupon_code,
            'login_url' => $login_url,
            'shop_url' => $shop_url,
            'cart_url' => $cart_url,
            'checkout_url' => $checkout_url,
            'campaign_title' => $campaign_data['title'] ?? 'Exclusive Discount',
            'discount_amount' => $campaign_data['discount_amount'] ?? '20%',
            'expires_at' => $campaign_data['expires_at'] ?? ''
        ]);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
        ];
        
        $sent = wp_mail($email, $subject, $message, $headers);
        
        if ($sent) {
            $this->logger->info('Coupon email sent', [
                'email' => $this->hash_email($email),
                'coupon_code' => $coupon_code
            ]);
        } else {
            $this->logger->error('Failed to send coupon email', [
                'email' => $this->hash_email($email),
                'coupon_code' => $coupon_code
            ]);
        }
        
        return $sent;
    }
    
    /**
     * Send waitlist confirmation email
     * 
     * @param string $email
     * @param array $campaign_data
     * @return bool
     */
    public function send_waitlist_email($email, $campaign_data) {
        $subject = 'You\'re on the waitlist for exclusive discounts!';
        
        $message = $this->get_waitlist_email_template([
            'campaign_title' => $campaign_data['title'] ?? 'Exclusive Discounts'
        ]);
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
        ];
        
        $sent = wp_mail($email, $subject, $message, $headers);
        
        if ($sent) {
            $this->logger->info('Waitlist email sent', [
                'email' => $this->hash_email($email)
            ]);
        } else {
            $this->logger->error('Failed to send waitlist email', [
                'email' => $this->hash_email($email)
            ]);
        }
        
        return $sent;
    }
    
    /**
     * Get verification email template
     */
    private function get_verification_email_template($vars) {
        extract($vars);
        
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background: #f8f9fa; }
        .button { display: inline-block; padding: 15px 30px; background: #e74c3c; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎯 Almost There!</h1>
        </div>
        <div class="content">
            <h2>Verify your email to claim your ' . esc_html($discount_amount) . ' discount</h2>
            <p>You\'re just one click away from claiming your exclusive <strong>' . esc_html($campaign_title) . '</strong> discount!</p>
            
            <p style="text-align: center; margin: 30px 0;">
                <a href="' . esc_url($verification_url) . '" class="button">✨ Verify Email & Get Code</a>
            </p>
            
            <p><strong>What happens next?</strong></p>
            <ul>
                <li>✅ Click the button above</li>
                <li>🎁 Get your discount code instantly</li>
                <li>🛒 Code is automatically applied at checkout</li>
                <li>⚡ Start shopping immediately</li>
            </ul>
            
            <p><em>Hurry! This offer has limited quantities available.</em></p>
        </div>
        <div class="footer">
            <p>If the button doesn\'t work, copy this link: ' . esc_url($verification_url) . '</p>
            <p>© ' . date('Y') . ' ' . get_option('blogname') . '</p>
        </div>
    </div>
</body>
</html>';
    }
    
    /**
     * Get coupon email template
     */
    private function get_coupon_email_template($vars) {
        extract($vars);
        
        $expiry_text = '';
        if ($expires_at) {
            $expiry_date = new DateTime($expires_at);
            $expiry_text = '<p style="color: #e74c3c; font-weight: bold;">⏰ Expires: ' . $expiry_date->format('M j, Y g:i A') . '</p>';
        }
        
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Discount Code</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #27ae60; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background: #f8f9fa; }
        .coupon-code { background: #fff; border: 3px dashed #e74c3c; padding: 20px; text-align: center; margin: 20px 0; font-size: 24px; font-weight: bold; color: #e74c3c; }
        .button { display: inline-block; padding: 15px 25px; margin: 10px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; font-weight: bold; }
        .button.primary { background: #e74c3c; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Success! Your Discount is Ready</h1>
        </div>
        <div class="content">
            <h2>Your ' . esc_html($discount_amount) . ' discount code:</h2>
            
            <div class="coupon-code">' . esc_html($coupon_code) . '</div>
            
            ' . $expiry_text . '
            
            <h3>🚀 Start Shopping Now (Auto-Login):</h3>
            <div style="text-align: center;">
                <a href="' . esc_url($checkout_url) . '" class="button primary">🛒 Go to Checkout</a>
                <a href="' . esc_url($shop_url) . '" class="button">🛍️ Browse Shop</a>
                <a href="' . esc_url($cart_url) . '" class="button">🛒 View Cart</a>
            </div>
            
            <h3>✨ Your Benefits:</h3>
            <ul>
                <li>✅ <strong>Auto-Applied:</strong> Code automatically added at checkout</li>
                <li>🔑 <strong>Auto-Login:</strong> No need to sign in</li>
                <li>⚡ <strong>Instant Access:</strong> Start shopping immediately</li>
                <li>🎁 <strong>Exclusive:</strong> Limited quantity offer</li>
            </ul>
            
            <p><strong>Don\'t wait!</strong> This exclusive offer won\'t last long.</p>
        </div>
        <div class="footer">
            <p>Code: <strong>' . esc_html($coupon_code) . '</strong></p>
            <p>© ' . date('Y') . ' ' . get_option('blogname') . '</p>
        </div>
    </div>
</body>
</html>';
    }
    
    /**
     * Get waitlist email template
     */
    private function get_waitlist_email_template($vars) {
        extract($vars);
        
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waitlist Confirmation</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #9b59b6; color: white; padding: 20px; text-align: center; }
        .content { padding: 30px; background: #f8f9fa; }
        .footer { padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔔 You\'re In!</h1>
        </div>
        <div class="content">
            <h2>Welcome to our exclusive waitlist</h2>
            <p>Thanks for joining our waitlist for <strong>' . esc_html($campaign_title) . '</strong>!</p>
            
            <h3>🎁 What you\'ll get:</h3>
            <ul>
                <li>⚡ <strong>Early Access:</strong> First to know about new discount codes</li>
                <li>💎 <strong>Exclusive Deals:</strong> Member-only discounts</li>
                <li>🎯 <strong>Priority Access:</strong> Skip the queue for limited offers</li>
                <li>📧 <strong>No Spam:</strong> Only the best deals, no clutter</li>
            </ul>
            
            <p>We\'ll notify you as soon as new discount codes become available!</p>
        </div>
        <div class="footer">
            <p>© ' . date('Y') . ' ' . get_option('blogname') . '</p>
        </div>
    </div>
</body>
</html>';
    }
    
    /**
     * Hash email for logging (privacy)
     * 
     * @param string $email
     * @return string
     */
    private function hash_email($email) {
        return substr(md5($email), 0, 8);
    }
}