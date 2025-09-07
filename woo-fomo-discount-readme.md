# WooCommerce FOMO Discount Generator Plugin

## Installation

1. **Create Plugin Directory Structure:**
```
/wp-content/plugins/wc-fomo-discount/
├── wc-fomo-discount.php (main plugin file)
├── assets/
│   ├── frontend.js
│   └── frontend.css
└── README.md
```

2. **Upload Files:**
   - Copy the main PHP file to `wc-fomo-discount.php`
   - Create `assets` folder
   - Add `frontend.js` and `frontend.css` to the assets folder

3. **Activate Plugin:**
   - Go to WordPress Admin → Plugins
   - Find "WooCommerce FOMO Discount Generator"
   - Click "Activate"

## Features

### Core Functionality
- **Limited Quantity Codes:** Set total number of available discount codes
- **Time-Limited Validity:** Codes expire after X hours
- **Real-Time Counter:** Live updates showing codes remaining (updates every 3 seconds)
- **Single Use Per Customer:** Each email can only claim one code per campaign
- **Email Notifications:** Automatic email sent with discount code
- **Flexible Discounts:** Percentage or fixed amount discounts
- **Scope Options:** Apply to all products, specific products, or categories

### FOMO & Urgency Elements
- Live countdown of remaining codes
- Visual pulse effects when inventory is low (<5 codes)
- Animated counter updates when other users claim codes
- Expiry countdown timer on claimed codes
- "SOLD OUT" message when all codes are claimed

## Usage

### Creating a Campaign

1. Go to **WordPress Admin → FOMO Discounts**
2. Fill in campaign details:
   - **Campaign Name:** Internal identifier
   - **Discount Type:** Percentage or Fixed Amount
   - **Discount Value:** Amount of discount
   - **Total Codes:** Maximum codes available
   - **Code Validity:** Hours until code expires
   - **Scope:** All products, specific products, or categories
   - **IDs:** (Optional) Comma-separated product/category IDs

3. Click "Create Campaign"

### Displaying the Widget

Use the shortcode anywhere on your site:
```
[fomo_discount id="1"]
```

**Best Placement Options:**
- Homepage hero section
- Product pages
- Cart page
- Popup/modal
- Sidebar widget
- After blog posts

### How It Works

1. **Visitor Sees Widget:** Shows discount value and remaining codes
2. **Email Entry/Login:** 
   - Logged-in users: Auto-uses their account email
   - Guests: Must enter email address
3. **Claim Code:** User clicks button to claim unique code
4. **Receive Code:**
   - Displayed immediately in widget
   - Sent via email
   - Shows expiry countdown
5. **Apply at Checkout:** Code automatically restricted to claiming email

## Advanced Features

### Real-Time Updates
The widget polls the server every 3 seconds to update the counter. When codes are claimed by other users, all visitors see the count decrease in real-time, creating urgency.

### Email Restrictions
Codes are automatically restricted to the email address that claimed them, preventing sharing and ensuring single-use per customer.

### Automatic Expiry
Codes expire after the specified time period and cannot be used after expiration.

### Visual Effects
- **Gradient backgrounds** with animated overlays
- **Pulse animations** on discount values
- **Smooth transitions** when counter updates
- **"Urgent pulse"** effect when <5 codes remain
- **Success animations** when code is claimed

## Database Tables

The plugin creates two custom tables:

### wcfd_campaigns
- Stores campaign configuration
- Tracks remaining codes
- Manages campaign status

### wcfd_claimed_codes
- Records claimed codes
- Links codes to emails/users
- Tracks expiry times
- Monitors usage

## Styling Customization

The widget uses CSS classes that can be overridden in your theme:

```css
/* Example: Change widget colors */
.wcfd-discount-widget {
    background: linear-gradient(135deg, #your-color-1 0%, #your-color-2 100%);
}

/* Example: Modify button style */
.wcfd-claim-btn {
    background: #your-color;
    color: #your-text-color;
}
```

## Hooks & Filters

The plugin triggers WooCommerce's standard coupon hooks:
- `woocommerce_applied_coupon` - When code is applied
- `woocommerce_coupon_error` - If code validation fails

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.2+
- MySQL 5.6+

## Troubleshooting

### Codes Not Updating in Real-Time
- Check if AJAX requests are being blocked
- Verify wp_ajax actions are registered
- Check browser console for JavaScript errors

### Email Not Sending
- Verify WordPress email settings
- Check spam folder
- Use SMTP plugin for reliable delivery

### Widget Not Displaying
- Ensure campaign is "active" status
- Check shortcode ID matches campaign ID
- Verify WooCommerce is activated

## Security Features

- **Nonce verification** on all AJAX requests
- **Email validation** before claiming codes
- **SQL injection prevention** via prepared statements
- **Single-use enforcement** per email/campaign
- **Rate limiting** via claim restrictions

## Performance Considerations

- Widget updates use lightweight AJAX calls
- Database queries are optimized with indexes
- Client-side caching prevents redundant requests
- Cleanup intervals when page unloads

## Future Enhancements

Consider adding:
- A/B testing different discount values
- Geographic restrictions
- Minimum cart value requirements
- Social sharing for extra codes
- Countdown timer before campaign starts
- Analytics dashboard
- Bulk code generation
- Integration with email marketing platforms