=== Rankavia for WooCommerce ===
Contributors: bixisoft
Tags: woocommerce, structured data, schema, gtin, brand
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a merchant-entered brand and GTIN to WooCommerce's own product structured data.

== Description ==

This is an early development preview. It does one thing: on a product's General edit panel, a merchant can enter a brand and a GTIN, and the plugin adds them to the Product structured data that WooCommerce already prints.

* The GTIN must be 8, 12, 13 or 14 digits with a valid GS1 check digit. Invalid values are not saved.
* WooCommerce's own product, offer, price, availability and review data are left untouched. If WooCommerce already outputs a brand or GTIN, it is kept and not duplicated.
* Nothing is filled in automatically. Blank fields leave saved values unchanged.
* The plugin does nothing when WooCommerce is not active. It runs on its own and does not need the Rankavia plugin.

Category and attribute page SEO, out-of-stock handling and merchant feed checks are not part of this preview.

== External services ==

This plugin makes no HTTP requests and sends no data to Rankavia, Bixisoft or any third party. It includes no analytics or tracking.

== Installation ==

1. Make sure WooCommerce 8.0 or later is active.
2. Upload the plugin zip through Plugins, Add New, Upload Plugin, then activate it.
3. Edit a product and fill in "Merchant brand" and "GTIN" on the General tab.

== Frequently Asked Questions ==

= How do I remove a saved value? =

Blank fields do not clear saved values. Remove the `_rankavia_wc_brand` or `_rankavia_wc_gtin` product meta with a tool you trust for editing product metadata.

= What happens when I uninstall it? =

The brand and GTIN values stay in your product metadata so that removing the plugin by accident does not lose them. No tables or settings are created.

== Changelog ==

= 0.1.0 =
* First development preview: merchant brand and GTIN fields added to WooCommerce product structured data.
