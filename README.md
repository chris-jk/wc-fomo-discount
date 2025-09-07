# WooCommerce FOMO Discount Generator

Generate limited quantity, time-limited discount codes with real-time countdown and email verification to create urgency and increase conversions.

## Features

- ✅ **Real-time counter updates** - Shows live remaining codes
- ✅ **Email verification system** - Prevents fake submissions
- ✅ **Social sharing buttons** - Viral discount distribution
- ✅ **AJAX-powered interface** - Smooth, no-refresh experience
- ✅ **Automatic email notifications** - Welcome and reminder emails
- ✅ **Admin dashboard** - Complete email analytics and CSV exports
- ✅ **Auto-updater system** - Get updates automatically from GitHub
- ✅ **Rate limiting** - Prevents abuse and spam
- ✅ **Mobile responsive** - Works perfectly on all devices

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'FOMO Discounts' in your admin menu
4. Create your first campaign
5. Use the shortcode `[fomo_discount id="CAMPAIGN_ID"]` on any page

## Auto-Updates Setup

To enable automatic updates from your GitHub repository:

1. Upload your plugin to a GitHub repository
2. Edit `wc-fomo-discount.php` line 66:
   ```php
   new WCFD_Auto_Updater(__FILE__, 'YOUR_GITHUB_USERNAME', 'REPO_NAME', WCFD_VERSION);
   ```
3. Create releases in GitHub with version tags (e.g., `v1.0.1`)
4. WordPress will automatically check for updates every 6 hours

### Creating Releases

1. Push your code changes to GitHub
2. Go to your repository → Releases → Create new release
3. Tag version: `v1.0.1` (increment as needed)
4. Title: `Version 1.0.1`
5. Describe your changes in the release notes
6. Publish release

WordPress will automatically detect new releases and show update notifications in the admin area.

## Usage

### Creating Campaigns

1. Go to **FOMO Discounts** in your WordPress admin
2. Fill out the campaign form:
   - **Campaign Name**: Internal reference
   - **Discount Type**: Percentage or fixed amount
   - **Discount Value**: The discount amount
   - **Total Codes**: How many people can claim
   - **Code Validity**: Hours until individual codes expire
   - **Scope**: All products, specific products, or categories

### Displaying Widgets

Use the shortcode anywhere:
```
[fomo_discount id="1"]
```

### Email Analytics

- Go to **FOMO Discounts → Email Leads**
- View real-time statistics
- Filter by campaign
- Export email lists as CSV
- Track conversion rates

## Email Verification Flow

### For Logged-In Users
- Instant discount code (trusted email from WordPress account)
- No verification needed

### For Non-Logged-In Users
1. User enters email address
2. Receives verification email with secure link
3. Clicks link to verify and claim discount
4. Gets discount code and confirmation email

## Security Features

- ✅ **Nonce verification** on all AJAX requests
- ✅ **Rate limiting** (5 attempts per hour per IP)
- ✅ **Email verification** for non-logged-in users
- ✅ **Database transactions** prevent race conditions
- ✅ **Input sanitization** and validation
- ✅ **Secure token generation** for verification links

## Performance Features

- ✅ **Database indexing** for fast queries
- ✅ **Transient caching** for GitHub API calls
- ✅ **Optimized AJAX** with overlap prevention
- ✅ **Smooth animations** without DOM flickering
- ✅ **Automatic cleanup** of expired codes

## Customization

### Styling
Edit `assets/frontend.css` to customize the appearance.

### Email Templates
Modify the email content in the `send_verification_email()` and `process_discount_claim()` methods.

### Social Platforms
Add more social sharing platforms in the `addSocialSharing()` JavaScript function.

## Changelog

### Version 1.0.0
- Initial release
- Email verification system
- Real-time counter updates
- Social sharing integration
- Admin analytics dashboard
- Auto-updater system
- Complete security implementation

## Support

For support and feature requests, please create an issue in the GitHub repository.

## License

This plugin is licensed under the GPL v2 or later.