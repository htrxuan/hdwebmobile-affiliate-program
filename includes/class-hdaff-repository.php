<?php

namespace htrxuan\hdaff;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The commission ledger, referral-code generation, and the ONLY place a commission is ever
 * awarded or moved toward being paid.
 *
 * CVE-2025-47771-style bug (CWE-862, Missing Authorization) seen across several competing
 * "Affiliate for WooCommerce" plugins: their commission approve/reject/payout AJAX endpoints
 * checked only is_user_logged_in() (or nothing at all), never a specific capability, letting
 * any registered affiliate approve and mark their OWN pending commissions as paid -- and some
 * of the same plugins additionally trusted a client-supplied "referred_by" user id at
 * checkout, so a shopper could just declare themselves referred by anyone (including an
 * account they control), fabricating commission for orders that were never genuinely
 * referred at all.
 *
 * This class makes both mistakes impossible by construction:
 *
 *  - A referral is only ever attributed via an opaque, server-generated code
 *    (generate_or_get_code()/resolve_referrer_by_code()) -- there is no method anywhere in
 *    this plugin that accepts a referrer user id directly from a caller. The code is captured
 *    from the visitor's own click-through cookie at checkout and re-resolved to a real user
 *    id at BOTH checkout time and commission-award time; a code that doesn't resolve to a
 *    real user is silently ignored.
 *  - award_commission() explicitly compares the resolved referrer's user id to the order's
 *    own customer id and refuses to award anything when they match -- a logged-in user who
 *    somehow orders through their own referral link earns nothing.
 *  - award_commission() is idempotent two different ways: an order-meta flag AND a UNIQUE KEY
 *    on order_id in the ledger table itself, so a retried/duplicated hook can never double-pay.
 *  - approve()/mark_paid() take only a commission id -- they never touch a role, capability,
 *    or the ledger amount itself. The capability check (manage_woocommerce) and nonce
 *    verification live entirely in HDAFF_Admin's admin_post handlers, never in this class,
 *    so there is no code path here that a lower-privileged request could reach at all.
 */
class HDAFF_Repository
{
    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_PAID     = 'paid';
    const STATUS_REVERSED = 'reversed';

    const CODE_LENGTH = 10;
    const COOKIE_NAME = 'hdaff_ref';
    const COOKIE_DAYS = 30;

    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'hdaff_commissions';
    }

    public static function get_schema_sql()
    {
        global $wpdb;
        $table   = self::table();
        $collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            referrer_user_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(19,4) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            paid_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_id (order_id),
            KEY referrer_status (referrer_user_id, status)
        ) {$collate};";
    }

    /**
     * A referral code is a random, opaque, unguessable-enough token -- never a user id, an
     * email, or anything else that could be forged into pointing at a different account.
     */
    public static function generate_or_get_code($user_id)
    {
        $user_id = absint($user_id);
        if (!$user_id) {
            return '';
        }

        $existing = get_user_meta($user_id, '_hdaff_ref_code', true);
        if (is_string($existing) && '' !== $existing) {
            return $existing;
        }

        do {
            $code = bin2hex(random_bytes((int) ceil(self::CODE_LENGTH / 2)));
            $code = substr($code, 0, self::CODE_LENGTH);
        } while (self::resolve_referrer_by_code($code));

        update_user_meta($user_id, '_hdaff_ref_code', $code);
        return $code;
    }

    /**
     * Resolves a code to a real, currently-existing user id -- or 0 if the code is malformed,
     * unknown, or belongs to a since-deleted account. Never trusts the caller's own idea of
     * who a code belongs to.
     */
    public static function resolve_referrer_by_code($code)
    {
        $code = is_string($code) ? strtolower(sanitize_text_field($code)) : '';
        if (!preg_match('/^[a-f0-9]{' . self::CODE_LENGTH . '}$/', $code)) {
            return 0;
        }

        // A per-user unique code, looked up rarely (referral capture / commission award) --
        // not a hot path worth a dedicated index/table.
        $users = get_users(array(
            'meta_key'   => '_hdaff_ref_code', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_value' => $code, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'number'     => 1,
            'fields'     => 'ID',
        ));

        return $users ? (int) $users[0] : 0;
    }

    /**
     * The only write path that creates a ledger row. $order is a real WC_Order the caller
     * already has (never an id trusted from a request), and every value used to compute the
     * commission is re-derived from that order and from server-side settings -- nothing here
     * ever reads a request parameter.
     */
    public static function award_commission(\WC_Order $order)
    {
        if ('yes' === $order->get_meta('_hdaff_awarded')) {
            return false;
        }

        $code = $order->get_meta('_hdaff_ref_code');
        $referrer_id = self::resolve_referrer_by_code(is_string($code) ? $code : '');
        if (!$referrer_id) {
            $order->update_meta_data('_hdaff_awarded', 'yes');
            $order->save();
            return false;
        }

        // Block self-referral: a logged-in user ordering through their own link earns nothing.
        $customer_id = (int) $order->get_customer_id();
        if ($customer_id && $customer_id === $referrer_id) {
            $order->update_meta_data('_hdaff_awarded', 'yes');
            $order->save();
            return false;
        }

        $settings = self::get_settings();
        $percent  = $settings['commission_percent'];
        $amount   = round((float) $order->get_total() * $percent / 100, 2);

        if ($amount <= 0) {
            $order->update_meta_data('_hdaff_awarded', 'yes');
            $order->save();
            return false;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a custom ledger table has no WP core caching layer to use.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO %i (referrer_user_id, order_id, amount, status, created_at) VALUES (%d, %d, %f, %s, %s)",
            self::table(),
            $referrer_id,
            $order->get_id(),
            $amount,
            self::STATUS_PENDING,
            current_time('mysql')
        ));

        $order->update_meta_data('_hdaff_awarded', 'yes');
        $order->save();

        return (bool) $inserted;
    }

    /**
     * Cancelling/refunding an order after its commission was already awarded reverses it
     * (rather than deleting the row, so the ledger keeps an honest audit trail) -- only if it
     * hasn't already been paid out. A paid commission is left alone; that's a manual
     * store-credit-style decision for the admin, not something this plugin auto-reverses.
     */
    public static function reverse_commission(\WC_Order $order)
    {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a custom ledger table has no WP core caching layer to use.
        $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = %s WHERE order_id = %d AND status IN (%s, %s)",
            $table,
            self::STATUS_REVERSED,
            $order->get_id(),
            self::STATUS_PENDING,
            self::STATUS_APPROVED
        ));
    }

    public static function approve($id)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a custom ledger table has no WP core caching layer to use.
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = %s WHERE id = %d AND status = %s",
            self::table(),
            self::STATUS_APPROVED,
            absint($id),
            self::STATUS_PENDING
        ));
    }

    public static function mark_paid($id)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a custom ledger table has no WP core caching layer to use.
        return (bool) $wpdb->query($wpdb->prepare(
            "UPDATE %i SET status = %s, paid_at = %s WHERE id = %d AND status = %s",
            self::table(),
            self::STATUS_PAID,
            current_time('mysql'),
            absint($id),
            self::STATUS_APPROVED
        ));
    }

    public static function get_all($status = null, $limit = 50)
    {
        global $wpdb;
        $table = self::table();
        if ($status) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a custom ledger table has no WP core caching layer to use.
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d",
                $table,
                $status,
                absint($limit)
            ));
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a custom ledger table has no WP core caching layer to use.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i ORDER BY created_at DESC LIMIT %d",
            $table,
            absint($limit)
        ));
    }

    public static function get_for_user($user_id)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a custom ledger table has no WP core caching layer to use.
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM %i WHERE referrer_user_id = %d ORDER BY created_at DESC",
            self::table(),
            absint($user_id)
        ));
    }

    public static function get_balance($user_id, $status)
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a custom ledger table has no WP core caching layer to use.
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(amount) FROM %i WHERE referrer_user_id = %d AND status = %s",
            self::table(),
            absint($user_id),
            $status
        ));
        return $value ? (float) $value : 0.0;
    }

    public static function get_settings()
    {
        $defaults = array('commission_percent' => 10.0);
        $settings = get_option('hdaff_settings', array());
        return wp_parse_args(is_array($settings) ? $settings : array(), $defaults);
    }

    public static function save_settings($percent)
    {
        $percent = (float) $percent;
        if ($percent < 0) {
            $percent = 0;
        }
        if ($percent > 100) {
            $percent = 100;
        }
        update_option('hdaff_settings', array('commission_percent' => $percent));
        return $percent;
    }
}
