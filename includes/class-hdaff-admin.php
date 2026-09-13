<?php

namespace htrxuan\hdaff;

if (!defined('ABSPATH')) {
    exit;
}

class HDAFF_Admin
{

    const NONCE_APPROVE = 'hdaff_approve';
    const NONCE_PAID    = 'hdaff_mark_paid';

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
        require_once HDAFF_PLUGIN_DIR . 'includes/class-hdaff-hub.php';
        add_filter('hdwebmobile_hub_tabs', array($this, 'register_hub_tabs'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_hdaff_approve', array($this, 'handle_approve'));
        add_action('admin_post_hdaff_mark_paid', array($this, 'handle_mark_paid'));
    }

    public function register_hub_tabs($tabs)
    {
        $tabs['affiliate-program'] = array(
            'label'  => __('Affiliate & Referral Program', 'hdwebmobile-affiliate-program'),
            'order'  => 147,
            'render' => array($this, 'render_page'),
        );
        return $tabs;
    }

    public function register_settings()
    {
        register_setting('hdaff_settings_group', 'hdaff_settings', array($this, 'sanitize_settings'));
    }

    public function sanitize_settings($input)
    {
        $current = HDAFF_Repository::get_settings();
        $percent = isset($input['commission_percent']) ? (float) $input['commission_percent'] : $current['commission_percent'];
        if ($percent < 0) {
            $percent = 0;
        }
        if ($percent > 100) {
            $percent = 100;
        }
        return array('commission_percent' => $percent);
    }

    public function render_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-affiliate-program'));
        }

        $settings = HDAFF_Repository::get_settings();
        ?>
        <p><?php esc_html_e('Customers earn a link from their My Account page; a percentage of a completed order goes to whoever referred it. Referrals are always attributed by an opaque code and can never be self-claimed.', 'hdwebmobile-affiliate-program'); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields('hdaff_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="hdaff_commission_percent"><?php esc_html_e('Commission %', 'hdwebmobile-affiliate-program'); ?></label></th>
                    <td>
                        <input type="number" id="hdaff_commission_percent" name="hdaff_settings[commission_percent]" step="any" min="0" max="100" value="<?php echo esc_attr($settings['commission_percent']); ?>" style="width:100px;" />
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Save Settings', 'hdwebmobile-affiliate-program')); ?>
        </form>

        <h2><?php esc_html_e('Commissions', 'hdwebmobile-affiliate-program'); ?></h2>
        <?php $this->render_commissions_table(); ?>
        <?php
    }

    private function render_commissions_table()
    {
        $rows = HDAFF_Repository::get_all(null, 100);
        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Referrer', 'hdwebmobile-affiliate-program'); ?></th>
                    <th><?php esc_html_e('Order', 'hdwebmobile-affiliate-program'); ?></th>
                    <th><?php esc_html_e('Amount', 'hdwebmobile-affiliate-program'); ?></th>
                    <th><?php esc_html_e('Status', 'hdwebmobile-affiliate-program'); ?></th>
                    <th><?php esc_html_e('Actions', 'hdwebmobile-affiliate-program'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="5"><?php esc_html_e('No commissions yet.', 'hdwebmobile-affiliate-program'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) :
                        $user = get_userdata((int) $row->referrer_user_id);
                        $order = wc_get_order((int) $row->order_id);
                        ?>
                        <tr>
                            <td><?php echo $user ? esc_html($user->user_login) : esc_html__('(deleted user)', 'hdwebmobile-affiliate-program'); ?></td>
                            <td>
                                <?php if ($order) : ?>
                                    <a href="<?php echo esc_url($order->get_edit_order_url()); ?>">#<?php echo esc_html($order->get_order_number()); ?></a>
                                <?php else : ?>
                                    #<?php echo (int) $row->order_id; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo wp_kses_post(wc_price((float) $row->amount)); ?></td>
                            <td><?php echo esc_html(ucfirst($row->status)); ?></td>
                            <td>
                                <?php if (HDAFF_Repository::STATUS_PENDING === $row->status) : ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hdaff_approve&id=' . (int) $row->id), self::NONCE_APPROVE . '_' . $row->id)); ?>"><?php esc_html_e('Approve', 'hdwebmobile-affiliate-program'); ?></a>
                                <?php elseif (HDAFF_Repository::STATUS_APPROVED === $row->status) : ?>
                                    <a class="button button-small" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hdaff_mark_paid&id=' . (int) $row->id), self::NONCE_PAID . '_' . $row->id)); ?>"><?php esc_html_e('Mark Paid', 'hdwebmobile-affiliate-program'); ?></a>
                                <?php else : ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    public function handle_approve()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-affiliate-program'));
        }
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::NONCE_APPROVE . '_' . $id);

        HDAFF_Repository::approve($id);
        wp_safe_redirect(admin_url('admin.php?page=hdwebmobile&tab=affiliate-program'));
        exit;
    }

    public function handle_mark_paid()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to do this.', 'hdwebmobile-affiliate-program'));
        }
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        check_admin_referer(self::NONCE_PAID . '_' . $id);

        HDAFF_Repository::mark_paid($id);
        wp_safe_redirect(admin_url('admin.php?page=hdwebmobile&tab=affiliate-program'));
        exit;
    }
}
