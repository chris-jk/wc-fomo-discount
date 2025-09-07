# WooCommerce FOMO Discount API Documentation

## Overview

This document describes the APIs, hooks, filters, and integration points available in WooCommerce FOMO Discount plugin version 2.0.0+.

## Table of Contents

- [REST API Endpoints](#rest-api-endpoints)
- [WordPress Hooks](#wordpress-hooks)
- [Filters](#filters)
- [JavaScript APIs](#javascript-apis)
- [PHP Classes](#php-classes)
- [Database Schema](#database-schema)

## REST API Endpoints

### Get Active Campaigns

```
GET /wp-json/wcfd/v1/campaigns
```

**Parameters:**
- `limit` (optional): Number of campaigns to retrieve (default: 10)

**Response:**
```json
[
  {
    "id": 1,
    "campaign_name": "Black Friday Sale",
    "discount_type": "percent",
    "discount_value": 25,
    "codes_remaining": 89,
    "status": "active"
  }
]
```

### Claim Discount Code

```
POST /wp-json/wcfd/v1/claim
```

**Parameters:**
- `campaign_id` (required): Campaign ID
- `email` (required): User email address

**Response:**
```json
{
  "code": "FOMO1234ABCD",
  "expires_at": "2024-01-15 23:59:59",
  "codes_remaining": 88
}
```

### Get Statistics (Admin Only)

```
GET /wp-json/wcfd/v1/stats
```

**Response:**
```json
{
  "active_campaigns": 5,
  "total_codes_claimed": 1250,
  "conversion_rate": 12.5
}
```

## WordPress Hooks

### Actions

#### Campaign Management

```php
do_action('wcfd_campaign_created', $campaign_id, $campaign_data);
```
Fired when a new campaign is created.

```php
do_action('wcfd_campaign_updated', $campaign_id, $new_data, $old_data);
```
Fired when a campaign is updated.

```php
do_action('wcfd_discount_claimed', $campaign_id, $email, $coupon_code);
```
Fired when a discount code is successfully claimed.

#### Email Events

```php
do_action('wcfd_verification_email_sent', $email, $campaign_id, $token);
```
Fired when an email verification is sent.

```php
do_action('wcfd_email_verified', $email, $campaign_id);
```
Fired when an email is successfully verified.

#### Performance Monitoring

```php
do_action('wcfd_performance_metric', $operation, $duration, $memory_used);
```
Fired when a performance metric is recorded.

```php
do_action('wcfd_log_entry', $log_entry);
```
Fired when a log entry is created.

### Filters

#### Campaign Filters

```php
apply_filters('wcfd_campaign_data_before_save', $data, $campaign_id);
```
Filter campaign data before saving to database.

```php
apply_filters('wcfd_discount_value_calculation', $value, $campaign, $context);
```
Filter the calculated discount value.

#### Validation Filters

```php
apply_filters('wcfd_validate_email_dns', false);
```
Enable/disable DNS validation for email addresses.

```php
apply_filters('wcfd_disposable_email_domains', $domains);
```
Filter the list of disposable email domains.

```php
apply_filters('wcfd_max_fixed_discount', 10000);
```
Filter the maximum allowed fixed discount amount.

#### Performance Filters

```php
apply_filters('wcfd_slow_operation_threshold', 1000);
```
Filter the threshold (in milliseconds) for slow operation warnings.

```php
apply_filters('wcfd_record_performance_metrics', false);
```
Enable/disable performance metrics recording to database.

#### Email Filters

```php
apply_filters('wcfd_verification_email_subject', $subject, $campaign);
```
Filter verification email subject.

```php
apply_filters('wcfd_verification_email_message', $message, $token, $campaign);
```
Filter verification email message content.

## JavaScript APIs

### Frontend Widget API

```javascript
// Initialize a FOMO discount widget
WCFD.Widget.init({
    campaignId: 123,
    container: '#fomo-widget',
    style: 'modern'
});

// Event listeners
$(document).on('wcfd:discount_claimed', function(e, data) {
    console.log('Discount claimed:', data.code);
});

$(document).on('wcfd:widget_loaded', function(e, campaignId) {
    console.log('Widget loaded for campaign:', campaignId);
});
```

### Admin Dashboard API

```javascript
// Get campaign statistics
WCFD.Admin.getCampaignStats(campaignId).then(function(stats) {
    console.log('Campaign stats:', stats);
});

// Export data
WCFD.Admin.exportData('emails', {
    campaign_id: 123,
    format: 'csv'
});
```

## PHP Classes

### Core Classes

#### Logger

```php
use WCFD\Core\Logger;

$logger = new Logger();
$logger->info('Message', ['context' => 'data']);
$logger->error('Error message', ['error_code' => 500]);
```

#### Validator

```php
use WCFD\Core\Validator;

$validator = new Validator($logger);
$is_valid = $validator->validate_email('user@example.com');
if ($validator->has_errors()) {
    $errors = $validator->get_errors();
}
```

#### Campaign Manager

```php
use WCFD\Core\Campaign_Manager;

$campaign_manager = new Campaign_Manager($logger, $db_manager, $validator);
$campaign_id = $campaign_manager->create_campaign($data);
$result = $campaign_manager->claim_discount($campaign_id, $email, $ip_address);
```

### Utility Classes

#### Performance Monitor

```php
use WCFD\Utils\Performance_Monitor;

$monitor = new Performance_Monitor($logger, $db_manager);
$monitor->start_timer('operation_name');
// ... perform operation
$monitor->end_timer('operation_name');
```

#### Rate Limiter

```php
use WCFD\Utils\Rate_Limiter;

$rate_limiter = new Rate_Limiter($logger);
if ($rate_limiter->is_rate_limited($ip_address, 'claim_discount')) {
    // Handle rate limiting
}
```

## Database Schema

### Tables

#### wcfd_campaigns

```sql
CREATE TABLE wcfd_campaigns (
    id int(11) NOT NULL AUTO_INCREMENT,
    campaign_name varchar(255) NOT NULL,
    discount_type enum('percent','fixed') NOT NULL,
    discount_value decimal(10,2) NOT NULL,
    total_codes int(11) NOT NULL,
    codes_remaining int(11) NOT NULL,
    status enum('active','paused','ended') DEFAULT 'active',
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
```

#### wcfd_claimed_codes

```sql
CREATE TABLE wcfd_claimed_codes (
    id int(11) NOT NULL AUTO_INCREMENT,
    campaign_id int(11) NOT NULL,
    user_email varchar(255) NOT NULL,
    coupon_code varchar(50) NOT NULL,
    claimed_at datetime DEFAULT CURRENT_TIMESTAMP,
    expires_at datetime NOT NULL,
    email_verified tinyint(1) DEFAULT 0,
    PRIMARY KEY (id)
);
```

#### wcfd_performance_metrics

```sql
CREATE TABLE wcfd_performance_metrics (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    operation varchar(100) NOT NULL,
    duration_ms decimal(10,2) NOT NULL,
    memory_bytes bigint(20) NOT NULL,
    timestamp datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
);
```

## Integration Examples

### Custom Campaign Creation

```php
// Hook into campaign creation
add_action('wcfd_campaign_created', 'my_custom_campaign_handler', 10, 2);

function my_custom_campaign_handler($campaign_id, $campaign_data) {
    // Send notification to admin
    wp_mail(
        get_option('admin_email'),
        'New FOMO Campaign Created',
        "Campaign: {$campaign_data['campaign_name']} has been created."
    );
}
```

### Custom Validation

```php
// Add custom email validation
add_filter('wcfd_disposable_email_domains', 'my_disposable_domains');

function my_disposable_domains($domains) {
    $custom_domains = ['example.com', 'test.com'];
    return array_merge($domains, $custom_domains);
}
```

### Performance Monitoring Integration

```php
// Send performance metrics to external service
add_action('wcfd_performance_metric_recorded', 'send_to_monitoring_service', 10, 3);

function send_to_monitoring_service($operation, $metrics, $context) {
    // Send to New Relic, DataDog, etc.
    wp_remote_post('https://api.monitoring-service.com/metrics', [
        'body' => json_encode([
            'operation' => $operation,
            'duration' => $metrics['duration_ms'],
            'memory' => $metrics['memory_used']
        ])
    ]);
}
```

### Custom Widget Styling

```php
// Add custom widget styles
add_filter('wcfd_widget_css_classes', 'my_custom_widget_classes');

function my_custom_widget_classes($classes, $campaign_id) {
    $classes[] = 'my-custom-style';
    return $classes;
}
```

## Error Handling

### Error Codes

- `validation_error`: Input validation failed
- `campaign_unavailable`: Campaign is not active or out of codes
- `already_claimed`: User has already claimed from this campaign
- `rate_limited`: Too many requests from IP address
- `db_error`: Database operation failed
- `coupon_error`: WooCommerce coupon creation failed

### Custom Error Handling

```php
// Custom error logging
add_action('wcfd_log_entry', 'my_error_handler');

function my_error_handler($log_entry) {
    if ($log_entry['level'] === 'error') {
        // Send to external logging service
        error_log("WCFD Error: " . $log_entry['message']);
    }
}
```

## Security Considerations

- All user inputs are validated and sanitized
- Nonce verification is used for all AJAX requests
- Rate limiting prevents abuse
- SQL injection protection via prepared statements
- XSS prevention through proper output escaping
- CSRF protection on admin forms

## Performance Optimization

- Database queries are optimized with proper indexes
- Caching is used for frequently accessed data
- Transient API is used for temporary data storage
- Performance monitoring helps identify bottlenecks
- Lazy loading for admin components

## Backward Compatibility

The plugin maintains backward compatibility with version 1.x through:
- Legacy function wrappers
- Database migration system
- Gradual deprecation warnings
- Fallback mechanisms for old integrations