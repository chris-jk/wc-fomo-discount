<?php
/**
 * Campaigns List View
 * 
 * @package WC_Fomo_Discount
 * @subpackage Admin\Views
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1 class="wp-heading-inline">
        <?php _e('FOMO Discount Campaigns', 'wc-fomo-discount'); ?>
    </h1>
    
    <a href="<?php echo admin_url('admin.php?page=wcfd-campaigns&action=new'); ?>" class="page-title-action">
        <?php _e('Add New Campaign', 'wc-fomo-discount'); ?>
    </a>
    
    <hr class="wp-header-end">
    
    <div class="wcfd-campaigns-list">
        <?php if (empty($campaigns)): ?>
            <div class="notice notice-info">
                <p><?php _e('No campaigns found. Create your first campaign to get started!', 'wc-fomo-discount'); ?></p>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=wcfd-campaigns&action=new'); ?>" class="button button-primary">
                        <?php _e('Create Your First Campaign', 'wc-fomo-discount'); ?>
                    </a>
                </p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php _e('Campaign', 'wc-fomo-discount'); ?></th>
                        <th scope="col"><?php _e('Discount', 'wc-fomo-discount'); ?></th>
                        <th scope="col"><?php _e('Codes Remaining', 'wc-fomo-discount'); ?></th>
                        <th scope="col"><?php _e('Status', 'wc-fomo-discount'); ?></th>
                        <th scope="col"><?php _e('Shortcode', 'wc-fomo-discount'); ?></th>
                        <th scope="col"><?php _e('Actions', 'wc-fomo-discount'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $campaign): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($campaign->campaign_name); ?></strong>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo admin_url('admin.php?page=wcfd-campaigns&action=edit&campaign_id=' . $campaign->id); ?>">
                                            <?php _e('Edit', 'wc-fomo-discount'); ?>
                                        </a>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <?php 
                                echo $campaign->discount_type == 'percent'
                                    ? $campaign->discount_value . '%'
                                    : get_woocommerce_currency_symbol() . $campaign->discount_value;
                                ?>
                            </td>
                            <td>
                                <span class="codes-remaining"><?php echo $campaign->codes_remaining; ?>/<?php echo $campaign->total_codes; ?></span>
                                <?php if ($campaign->codes_remaining <= 5 && $campaign->codes_remaining > 0): ?>
                                    <span class="dashicons dashicons-warning" title="<?php _e('Running low on codes', 'wc-fomo-discount'); ?>"></span>
                                <?php elseif ($campaign->codes_remaining == 0): ?>
                                    <span class="dashicons dashicons-dismiss" style="color: #dc3232;" title="<?php _e('No codes remaining', 'wc-fomo-discount'); ?>"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-<?php echo $campaign->status; ?>">
                                    <?php echo ucfirst($campaign->status); ?>
                                </span>
                            </td>
                            <td>
                                <code style="background: #f0f0f1; padding: 2px 6px; border-radius: 3px;">
                                    [fomo_discount id="<?php echo $campaign->id; ?>"]
                                </code>
                                <button class="button-link copy-shortcode" data-shortcode='[fomo_discount id="<?php echo $campaign->id; ?>"]' title="<?php _e('Copy to clipboard', 'wc-fomo-discount'); ?>">
                                    <span class="dashicons dashicons-admin-page"></span>
                                </button>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=wcfd-campaigns&action=edit&campaign_id=' . $campaign->id); ?>" class="button button-small">
                                    <?php _e('Edit', 'wc-fomo-discount'); ?>
                                </a>
                                <button class="button button-small toggle-status" data-id="<?php echo $campaign->id; ?>" data-status="<?php echo $campaign->status; ?>">
                                    <?php echo $campaign->status == 'active' ? __('Pause', 'wc-fomo-discount') : __('Activate', 'wc-fomo-discount'); ?>
                                </button>
                                <button class="button button-small button-link-delete delete-campaign" data-id="<?php echo $campaign->id; ?>" data-name="<?php echo esc_attr($campaign->campaign_name); ?>">
                                    <?php _e('Delete', 'wc-fomo-discount'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    
    <!-- Quick Stats -->
    <?php if (!empty($campaigns)): ?>
        <div class="wcfd-stats-grid" style="margin-top: 20px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <div class="wcfd-stat-card">
                <h4><?php _e('Total Campaigns', 'wc-fomo-discount'); ?></h4>
                <span class="stat-number"><?php echo count($campaigns); ?></span>
            </div>
            <div class="wcfd-stat-card">
                <h4><?php _e('Active Campaigns', 'wc-fomo-discount'); ?></h4>
                <span class="stat-number"><?php echo count(array_filter($campaigns, function($c) { return $c->status === 'active'; })); ?></span>
            </div>
            <div class="wcfd-stat-card">
                <h4><?php _e('Total Codes Available', 'wc-fomo-discount'); ?></h4>
                <span class="stat-number"><?php echo array_sum(array_column($campaigns, 'codes_remaining')); ?></span>
            </div>
            <div class="wcfd-stat-card">
                <h4><?php _e('Total Claims', 'wc-fomo-discount'); ?></h4>
                <span class="stat-number"><?php echo array_sum(array_map(function($c) { return $c->total_codes - $c->codes_remaining; }, $campaigns)); ?></span>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.wcfd-stat-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 15px;
    text-align: center;
}
.wcfd-stat-card h4 {
    margin: 0 0 10px 0;
    font-size: 14px;
    color: #646970;
}
.stat-number {
    display: block;
    font-size: 24px;
    font-weight: 600;
    color: #1d2327;
}
.copy-shortcode {
    padding: 2px;
    vertical-align: middle;
}
.status-active {
    color: #00a32a;
    font-weight: 500;
}
.status-paused {
    color: #dba617;
    font-weight: 500;
}
.status-expired {
    color: #dc3232;
    font-weight: 500;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Copy shortcode to clipboard
    $('.copy-shortcode').on('click', function() {
        const shortcode = $(this).data('shortcode');
        navigator.clipboard.writeText(shortcode).then(function() {
            // Provide visual feedback
            const btn = $(this);
            btn.find('.dashicons').removeClass('dashicons-admin-page').addClass('dashicons-yes');
            setTimeout(() => {
                btn.find('.dashicons').removeClass('dashicons-yes').addClass('dashicons-admin-page');
            }, 2000);
        }).catch(function() {
            alert('<?php _e('Failed to copy shortcode to clipboard', 'wc-fomo-discount'); ?>');
        });
    });
    
    // Toggle campaign status
    $('.toggle-status').on('click', function() {
        const btn = $(this);
        const campaignId = btn.data('id');
        const currentStatus = btn.data('status');
        const newStatus = currentStatus === 'active' ? 'paused' : 'active';
        
        btn.prop('disabled', true).text('<?php _e('Processing...', 'wc-fomo-discount'); ?>');
        
        $.post(ajaxurl, {
            action: 'wcfd_toggle_campaign_status',
            campaign_id: campaignId,
            new_status: newStatus,
            nonce: '<?php echo wp_create_nonce('wcfd_toggle_status'); ?>'
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert(response.data || '<?php _e('Error updating campaign status', 'wc-fomo-discount'); ?>');
                btn.prop('disabled', false);
            }
        });
    });
    
    // Delete campaign
    $('.delete-campaign').on('click', function() {
        const btn = $(this);
        const campaignId = btn.data('id');
        const campaignName = btn.data('name');
        
        if (confirm('<?php _e('Are you sure you want to delete', 'wc-fomo-discount'); ?> "' + campaignName + '"? <?php _e('This action cannot be undone.', 'wc-fomo-discount'); ?>')) {
            btn.prop('disabled', true).text('<?php _e('Deleting...', 'wc-fomo-discount'); ?>');
            
            $.post(ajaxurl, {
                action: 'wcfd_delete_campaign',
                campaign_id: campaignId,
                nonce: '<?php echo wp_create_nonce('wcfd_delete_campaign'); ?>'
            }, function(response) {
                if (response.success) {
                    btn.closest('tr').fadeOut(400, function() {
                        $(this).remove();
                        if ($('tbody tr').length === 0) {
                            location.reload();
                        }
                    });
                } else {
                    alert(response.data || '<?php _e('Error deleting campaign', 'wc-fomo-discount'); ?>');
                    btn.prop('disabled', false).text('<?php _e('Delete', 'wc-fomo-discount'); ?>');
                }
            });
        }
    });
});
</script>