=== HDWebmobile Affiliate Program ===
Contributors: htrxuan
Donate link: https://paypal.me/htrxuan/20
Tags: woocommerce, affiliate, referral, commission, marketing
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Referral links earn commission on completed orders -- payouts are always admin-approved, self-referral is blocked server-side.

== Description ==

HDWebmobile Affiliate Program lets any logged-in customer generate their own referral link from My Account. When someone buys through that link, a percentage of the completed order is credited to the referrer as a commission. The store owner reviews and approves commissions, then marks them paid once the payout is actually sent.

= Security by design =
A common weakness in affiliate and referral tools is commission approve/reject/payout actions that check only whether a request is logged in -- not any specific capability -- which can let a Subscriber-level affiliate account approve and mark its own pending commissions as paid (CWE-862, Missing Authorization). Another is trusting a client-supplied "referred by" user id at checkout, letting a shopper simply declare themselves referred by an account they control.

This plugin is built so neither mistake can happen:

* A referral is only ever attributed through an opaque, randomly-generated code -- there is no method anywhere in the plugin that accepts a referrer's user id directly from a request. The code is captured from a visitor's own click-through link into a cookie, and independently re-resolved to a real user id both when the order is placed and again when the commission is actually awarded.
* Self-referral is blocked in code: the order's own customer id is compared against the resolved referrer's id, and no commission is awarded when they match.
* Every commission is idempotent two different ways -- an order-meta flag and a database UNIQUE constraint on the order id -- so a retried hook can never double-pay the same order.
* Approving a commission or marking it paid only ever happens through an admin-post handler that checks `manage_woocommerce` and a verified nonce; the methods that actually change a commission's status take nothing but its id.

= Key Features =
* Every customer gets their own referral link from My Account, generated automatically
* Configurable store-wide commission percentage
* A simple Pending -> Approved -> Paid workflow with one-click admin actions
* Cancelled or refunded orders automatically reverse an unpaid commission

= Limitations (please read before installing) =
* One flat commission percentage store-wide; no per-product or per-affiliate rates in this version
* No automated payout integration (PayPal, bank transfer, etc.) -- marking a commission "Paid" is a record of a payment made elsewhere
* Attribution is last-click, 30-day cookie window

= Enjoying this plugin? =
If it helps your site, please consider [leaving an honest review on WordPress.org](https://wordpress.org/support/plugin/hdwebmobile-affiliate-program/reviews/#new-post) -- it helps other WordPress users find it. Found a problem or have an idea? Please [open a support topic](https://wordpress.org/support/plugin/hdwebmobile-affiliate-program/) first so it can be fixed.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/hdwebmobile-affiliate-program` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress. WooCommerce must already be installed and active.
3. Go to **WooCommerce > HDWebmobile > Affiliate & Referral Program** to set your commission percentage.

== How to Use ==

= 1. Customers get a link =
Once logged in, a customer sees their own referral link on the My Account dashboard.

= 2. Orders earn commission =
When someone completes an order after clicking that link, a pending commission is created automatically.

= 3. Review and pay =
Approve pending commissions and mark them paid from the same screen once you've actually sent the payout.

== Screenshots ==

1. The referral link and earnings summary on My Account.
2. The commissions table under WooCommerce > HDWebmobile.

== Changelog ==

= 1.0.0 =
* Initial release: referral links, commission ledger, and an admin-approved payout workflow, with self-referral blocked server-side.
