<?php

namespace htrxuan\hdaff;

if (!defined('ABSPATH')) {
    exit;
}

class HDAFF_Activator
{

    public static function activate()
    {
        if (!self::is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(HDAFF_PLUGIN_FILE));
            set_transient('hdaff_wc_missing_notice', true, 30);
            return;
        }

        self::maybe_upgrade_db();
    }

    public static function is_woocommerce_active()
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('woocommerce/woocommerce.php') || class_exists('WooCommerce');
    }

    public static function maybe_upgrade_db()
    {
        if (get_option('hdaff_db_version') === HDAFF_DB_VERSION) {
            return;
        }
        require_once HDAFF_PLUGIN_DIR . 'includes/class-hdaff-repository.php';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta(HDAFF_Repository::get_schema_sql());
        update_option('hdaff_db_version', HDAFF_DB_VERSION);
    }
}
