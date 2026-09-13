<?php

/**
 * Plugin Name: HDWebmobile Affiliate & Referral Program
 * Plugin URI: https://hdwebmobile.com/plugins/hdwebmobile-affiliate-program/
 * Description: Referral links earn commission on completed orders -- payouts are always admin-approved, self-referral is blocked server-side.
 * Version: 1.0.0
 * Author: htrxuan - Han Tran
 * Author URI: https://hdwebmobile.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: hdwebmobile-affiliate-program
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * Requires at least: 6.9
 */

namespace htrxuan\hdaff;

if (!defined('ABSPATH')) {
    exit;
}

define('HDAFF_VERSION', '1.0.0');
define('HDAFF_DB_VERSION', '1');
define('HDAFF_PLUGIN_FILE', __FILE__);
define('HDAFF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HDAFF_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once HDAFF_PLUGIN_DIR . 'includes/class-hdaff-activator.php';

register_activation_hook(__FILE__, array(HDAFF_Activator::class, 'activate'));

add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', HDAFF_PLUGIN_FILE, true);
    }
});

add_action('plugins_loaded', function () {
    require_once HDAFF_PLUGIN_DIR . 'includes/class-hdaff-core.php';
    HDAFF_Core::get_instance();
});

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $donate_link = '<a href="https://paypal.me/htrxuan/20" target="_blank" rel="noopener noreferrer">' . esc_html__('Donate', 'hdwebmobile-affiliate-program') . '</a>';
    array_unshift($links, $donate_link);
    return $links;
});
