# HDWebmobile Affiliate & Referral Program

Referral links earn commission on completed orders -- payouts are always admin-approved, self-referral is blocked server-side.

- **WordPress.org:** https://wordpress.org/plugins/hdwebmobile-affiliate-program/
- **Requires:** WordPress 6.9+, WooCommerce, PHP 7.4+
- **License:** GPLv2 or later

## Description

Lets any logged-in customer generate their own referral link from My Account. When someone buys through that link, a percentage of the completed order is credited to the referrer as a commission. The store owner reviews and approves commissions, then marks them paid once the payout is actually sent.

## Why this plugin exists

Several competing "Affiliate for WooCommerce" style plugins have shipped commission approve/reject/payout actions that check only whether a request is logged in -- not any specific capability -- letting a Subscriber-level affiliate account approve and mark their own pending commissions as paid (CWE-862, Missing Authorization). Some of the same plugins have also trusted a client-supplied "referred by" user id at checkout, letting a shopper simply declare themselves referred by an account they control.

This plugin is built so neither mistake can happen:

* A referral is only ever attributed through an opaque, randomly-generated code -- there is no method anywhere in the plugin that accepts a referrer's user id directly from a request. The code is captured from a visitor's own click-through link into a cookie, and independently re-resolved to a real user id both when the order is placed and again when the commission is actually awarded.
* Self-referral is blocked in code: the order's own customer id is compared against the resolved referrer's id, and no commission is awarded when they match.
* Every commission is idempotent two different ways -- an order-meta flag and a database UNIQUE constraint on the order id -- so a retried hook can never double-pay the same order.
* Approving a commission or marking it paid only ever happens through an admin-post handler that checks `manage_woocommerce` and a verified nonce; the methods that actually change a commission's status take nothing but its id.

## Features

* Every customer gets their own referral link from My Account, generated automatically
* Configurable store-wide commission percentage
* A simple Pending -> Approved -> Paid workflow with one-click admin actions
* Cancelled or refunded orders automatically reverse an unpaid commission

## Limitations

* One flat commission percentage store-wide; no per-product or per-affiliate rates in this version
* No automated payout integration (PayPal, bank transfer, etc.) -- marking a commission "Paid" is a record of a payment made elsewhere
* Attribution is last-click, 30-day cookie window

## Installation

1. Upload to `/wp-content/plugins/hdwebmobile-affiliate-program`, or install through the WordPress plugins screen.
2. Activate. WooCommerce must already be installed and active.
3. Go to **WooCommerce > HDWebmobile > Affiliate & Referral Program** to set your commission percentage.

## Development

```
includes/
  class-hdaff-activator.php   activation + DB table creation
  class-hdaff-core.php        bootstraps admin + frontend
  class-hdaff-repository.php  the commission ledger -- see its docblock for the security design
  class-hdaff-admin.php       hub tab, settings, commissions table, approve/mark-paid actions
  class-hdaff-frontend.php    referral-link capture, order stamping, My Account summary
  class-hdaff-hub.php         shared HDWebmobile admin hub (see the suite's other plugins)
```

## License

GPLv2 or later — https://www.gnu.org/licenses/gpl-2.0.html
