<?php

namespace htrxuan\hdaff;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Captures a referral click into a cookie holding only the opaque code (never a user id),
 * stamps that code onto an order at the moment it's created (both checkout flows), and awards
 * or reverses the commission on the order's own real status transitions. See
 * HDAFF_Repository's docblock for why this is safe against the self-referral / forged-referrer
 * class of bug.
 */
final class HDAFF_Frontend
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'maybe_capture_referral'), 20);

        // Stamp the resolved code onto the order at creation time, for both checkout flows.
        add_filter('woocommerce_checkout_create_order', array($this, 'stamp_classic_order'), 10, 1);
        add_action('woocommerce_store_api_checkout_update_order_from_request', array($this, 'stamp_block_order'), 10, 1);

        // These hooks pass the order ID first, not the order object -- resolve it here so the
        // repository keeps its strict \WC_Order signature.
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completed'));
        add_action('woocommerce_order_status_cancelled', array($this, 'handle_order_reversed'));
        add_action('woocommerce_order_status_refunded', array($this, 'handle_order_reversed'));

        add_action('woocommerce_account_dashboard', array($this, 'render_account_summary'));
    }

    public function handle_order_completed($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order instanceof \WC_Order) {
            HDAFF_Repository::award_commission($order);
        }
    }

    public function handle_order_reversed($order_id)
    {
        $order = wc_get_order($order_id);
        if ($order instanceof \WC_Order) {
            HDAFF_Repository::reverse_commission($order);
        }
    }

    /**
     * A code only ever becomes a cookie once it has already been resolved to a real user --
     * so a visitor can never plant an arbitrary/garbage value that later gets trusted.
     */
    public function maybe_capture_referral()
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron() || headers_sent()) {
            return;
        }

        if (empty($_GET['hdaff_ref'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a plain read-only click-through link (like any "?ref=code" affiliate URL); the code only ever becomes a cookie after it independently resolves to a real user below.
            return;
        }

        $code = sanitize_text_field(wp_unslash($_GET['hdaff_ref'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
        if (!HDAFF_Repository::resolve_referrer_by_code($code)) {
            return;
        }

        setcookie(
            HDAFF_Repository::COOKIE_NAME,
            strtolower($code),
            array(
                'expires'  => time() + HDAFF_Repository::COOKIE_DAYS * DAY_IN_SECONDS,
                'path'     => defined('COOKIEPATH') ? COOKIEPATH : '/',
                'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => true,
                'samesite' => 'Lax',
            )
        );
        $_COOKIE[HDAFF_Repository::COOKIE_NAME] = strtolower($code);
    }

    private function cookie_code()
    {
        if (empty($_COOKIE[HDAFF_Repository::COOKIE_NAME])) {
            return '';
        }
        return sanitize_text_field(wp_unslash($_COOKIE[HDAFF_Repository::COOKIE_NAME]));
    }

    public function stamp_classic_order($order)
    {
        $this->stamp($order);
        return $order;
    }

    public function stamp_block_order($order)
    {
        $this->stamp($order);
    }

    private function stamp($order)
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        if ($order->get_meta('_hdaff_ref_code')) {
            return;
        }
        $code = $this->cookie_code();
        if (!$code || !HDAFF_Repository::resolve_referrer_by_code($code)) {
            return;
        }
        $order->update_meta_data('_hdaff_ref_code', $code);
    }

    public function render_account_summary()
    {
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $code = HDAFF_Repository::generate_or_get_code($user_id);
        $link = add_query_arg('hdaff_ref', $code, home_url('/'));

        $pending  = HDAFF_Repository::get_balance($user_id, HDAFF_Repository::STATUS_PENDING);
        $approved = HDAFF_Repository::get_balance($user_id, HDAFF_Repository::STATUS_APPROVED);
        $paid     = HDAFF_Repository::get_balance($user_id, HDAFF_Repository::STATUS_PAID);
        ?>
        <div class="hdaff-account-summary" style="margin-bottom:2em;padding:1em;border:1px solid #ddd;">
            <h2><?php esc_html_e('Your referral link', 'hdwebmobile-affiliate-program'); ?></h2>
            <p><input type="text" readonly value="<?php echo esc_url($link); ?>" style="width:100%;" onclick="this.select();" /></p>
            <p>
                <?php
                printf(
                    /* translators: 1: pending amount, 2: approved amount, 3: paid amount, all pre-formatted as currency */
                    esc_html__('Pending: %1$s -- Approved: %2$s -- Paid out: %3$s', 'hdwebmobile-affiliate-program'),
                    wp_kses_post(wc_price($pending)),
                    wp_kses_post(wc_price($approved)),
                    wp_kses_post(wc_price($paid))
                );
                ?>
            </p>
        </div>
        <?php
    }
}
