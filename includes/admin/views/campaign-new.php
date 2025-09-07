<?php
/**
 * New Campaign View
 * 
 * @package WC_Fomo_Discount
 * @subpackage Admin\Views
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php _e('Add New FOMO Campaign', 'wc-fomo-discount'); ?></h1>
    
    <form method="post" action="<?php echo admin_url('admin.php?page=wcfd-campaigns'); ?>">
        <?php wp_nonce_field('wcfd_create_campaign', 'wcfd_nonce'); ?>
        
        <table class="form-table">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="campaign_name"><?php _e('Campaign Name', 'wc-fomo-discount'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="campaign_name" name="campaign_name" class="regular-text" required />
                        <p class="description"><?php _e('Internal name for this campaign.', 'wc-fomo-discount'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="discount_type"><?php _e('Discount Type', 'wc-fomo-discount'); ?></label>
                    </th>
                    <td>
                        <select id="discount_type" name="discount_type">
                            <option value="percent"><?php _e('Percentage', 'wc-fomo-discount'); ?></option>
                            <option value="fixed"><?php _e('Fixed Amount', 'wc-fomo-discount'); ?></option>
                        </select>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="discount_value"><?php _e('Discount Value', 'wc-fomo-discount'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="discount_value" name="discount_value" class="small-text" step="0.01" min="0" required />
                        <p class="description"><?php _e('Enter the discount amount (percentage or fixed value).', 'wc-fomo-discount'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="total_codes"><?php _e('Total Codes', 'wc-fomo-discount'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="total_codes" name="total_codes" class="small-text" min="1" value="100" required />
                        <p class="description"><?php _e('Number of discount codes to generate.', 'wc-fomo-discount'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="expiry_hours"><?php _e('Code Expiry (Hours)', 'wc-fomo-discount'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="expiry_hours" name="expiry_hours" class="small-text" min="1" value="24" required />
                        <p class="description"><?php _e('How long each claimed code is valid for.', 'wc-fomo-discount'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="scope_type"><?php _e('Scope', 'wc-fomo-discount'); ?></label>
                    </th>
                    <td>
                        <select id="scope_type" name="scope_type">
                            <option value="all"><?php _e('All Products', 'wc-fomo-discount'); ?></option>
                            <option value="products"><?php _e('Specific Products', 'wc-fomo-discount'); ?></option>
                            <option value="categories"><?php _e('Specific Categories', 'wc-fomo-discount'); ?></option>
                        </select>
                        <p class="description"><?php _e('Choose what the discount applies to.', 'wc-fomo-discount'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <p class="submit">
            <input type="submit" name="wcfd_create_campaign" class="button-primary" value="<?php _e('Create Campaign', 'wc-fomo-discount'); ?>" />
            <a href="<?php echo admin_url('admin.php?page=wcfd-campaigns'); ?>" class="button"><?php _e('Cancel', 'wc-fomo-discount'); ?></a>
        </p>
    </form>
</div>