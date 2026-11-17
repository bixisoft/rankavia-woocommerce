=== Rankavia for WooCommerce ===
Contributors: bixisoft
Tags: woocommerce, seo, structured data, gtin, brand
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Product and category SEO titles and descriptions with undo, plus a merchant-entered brand and GTIN in WooCommerce's own product structured data.

== Description ==

This is an early development preview.

= Product and category titles and descriptions =

WooCommerce, Rankavia SEO opens a list of published products and of product categories with their SEO title and meta description, counts of items without them, and filters for each gap.

* Edit one product or category at a time, with a preview of the saved values.
* Values are stored in the same fields Rankavia SEO uses, so the two plugins never print two sets of tags. With Rankavia SEO active, it prints them and its template variables work. Without any SEO plugin, this add-on prints them as plain text.
* When Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework is active, that plugin prints titles and descriptions and this screen stays read-only.
* Every save is recorded in a history of the last 100 changes. Undo restores only the fields that change touched, and only while they still hold what it saved; if someone changed them again, nothing is overwritten.
* A save is refused when someone changed the values after you opened the form.
* Nothing is filled in automatically. Length notes are guidance only; search engines decide what they show.

= Product data check =

The Product data tab compares the product structured data WooCommerce gives search engines with what your shop shows.

* Price (including sale prices, price ranges and tax display), currency and stock status are checked against the product's own values. A mismatch usually means a currency, pricing or SEO plugin changes one but not the other.
* A rating in the structured data must match the product's approved rated reviews.
* Missing GTIN, brand or SKU are listed, and a GTIN WooCommerce leaves out or with a wrong check digit is flagged.
* Products without a price or reviews get no product structured data from WooCommerce; the check says so instead of inventing a price.
* "Check live page" loads one product page from your own site as a signed-out visitor and checks what it actually prints, including duplicates from other plugins.
* The check only reads. It changes nothing and fills nothing in.

= Canonical and indexing check =

The Indexing tab shows which product, category and store pages ask search engines to stay away (noindex) and which address each one names as its main page (canonical).

* Cart, checkout, account and product search pages should stay out of search results; the shop page should not. The tab says when that is not the case.
* Products and categories are listed with their noindex setting and canonical URL as your SEO plugin keeps them. A canonical URL on another site, conflicting noindex and canonical settings, hidden products that can still be indexed, password-protected products and empty categories are pointed out.
* Out-of-stock products are never set to noindex. If something else noindexes them, "Check live page" says so, because products that come back in stock lose their search traffic that way.
* "Check live page" loads a page from your own site as a signed-out visitor and reads the robots meta tags, X-Robots-Tag header, canonical links and your robots.txt rules.
* The check only reads. It changes no setting.

= Product questions and answers =

A "Questions" tab on the product edit screen holds up to ten questions shoppers ask, answered in your own words.

* They appear in a "Questions and answers" tab on the product page. Answers are plain text.
* FAQ structured data is off by default and can be turned on per product. It is built from the same saved text and printed inside the visible tab, so it never describes answers the page does not show. It is not printed when another SEO plugin is active, when the product is private, password protected or set to noindex, or when the Rankavia SEO FAQ block is already in the description.
* Google shows FAQ results only for well-known government and health sites, so FAQ structured data usually does not change how a shop appears in search. It is not a ranking boost; the answers are there for shoppers.
* A save never overwrites questions someone else changed after you opened the product.

= Image alt text =

The Images tab lists each product's main and gallery images with their alt text, with guidance on what to write. It points out images without alt text, alt text that is a file or camera name, long alt text, and the same text repeated on several images of one product. Each image links to the Media Library, where its alt text is edited. Nothing is filled in automatically.

= Sitemap check and category policy =

The Sitemap tab shows which products and product categories your XML sitemap lists, and which it should.

* Products and categories that search engines may index should be listed; noindex, password-protected and cart, checkout and account pages should not. The tab names products missing from the sitemap, including out-of-stock products that something leaves out, and pages listed that should not be.
* With no SEO plugin, or with Rankavia SEO, the list comes straight from WordPress' own sitemap, with no request. With another SEO plugin, "Check sitemap live" reads its sitemap files from your own site.
* Category policy for WordPress' sitemap: list categories that have products (the WordPress default) or none, and leave out chosen categories. With Rankavia SEO, its own sitemap settings decide whether categories are listed; single categories can still be left out here. Another SEO plugin's sitemap is not changed.
* Cart, checkout and account pages are left out of WordPress' sitemap by default, because they are noindex. You can turn this off.
* Everything runs on your own site and needs no Rankavia account.

= Brand and GTIN =

On a product's General edit panel, a merchant can enter a brand and a GTIN, and the plugin adds them to the Product structured data that WooCommerce already prints.

* The GTIN must be 8, 12, 13 or 14 digits with a valid GS1 check digit; spaces and hyphens are ignored. Invalid values are not saved.
* WooCommerce's own fields come first. On WooCommerce 9.2 and later, which has its own GTIN field, this add-on does not ask for a GTIN and offers to move a GTIN it saved earlier into WooCommerce's field. When WooCommerce Brands assigns a brand, that brand is used and the text here only covers products without one.
* WooCommerce's own product, offer, price, availability and review data are left untouched. If WooCommerce already outputs a brand or GTIN, it is kept and not duplicated.
* Nothing is filled in automatically. Blank fields leave saved values unchanged; tick "Clear" to remove a value.
* If someone else changes the brand or GTIN while you edit the product, your save does not overwrite their change.
* The plugin does nothing when WooCommerce is not active. It runs on its own and does not need the Rankavia plugin.

Attribute page SEO, bulk edits, out-of-stock handling and merchant feed checks are not part of this preview.

== External services ==

This plugin sends no data to Rankavia, Bixisoft or any third party. It includes no analytics or tracking. Its only HTTP requests are the "Check live page", "Check store pages live" and "Check sitemap live" buttons, which an administrator starts: they load pages, robots.txt and sitemap files from your own site, and nothing leaves your site.

== Installation ==

1. Make sure WooCommerce 8.0 or later is active.
2. Upload the plugin zip through Plugins, Add New, Upload Plugin, then activate it.
3. Edit a product and fill in "Merchant brand" and "GTIN" on the General tab.

== Frequently Asked Questions ==

= How do I remove a saved value? =

Tick "Clear" next to the field and update the product. Blank fields alone do not clear saved values.

= What happens when I uninstall it? =

Titles, descriptions, brands, GTINs and product questions stay with your products and categories so that removing the plugin by accident does not lose them, and Rankavia SEO keeps using the titles and descriptions. The change history and sitemap settings are deleted. No tables are created.

== Changelog ==

= 0.2.0 =
* Sitemap tab: products and categories missing from or wrongly listed in the XML sitemap, a product category policy, and cart, checkout and account pages left out of WordPress' sitemap by default.
* Product questions and answers: up to ten per product in a product page tab, with optional matching FAQ structured data (off by default).
* Images tab: alt text of product images with writing guidance and links to the Media Library.
* Indexing tab: noindex and canonical settings of store pages, products and categories, with a live page check that also reads robots.txt. Out-of-stock products are never noindexed.
* Product data tab: structured data price, currency, stock, reviews and identifiers checked against what the shop shows, with a live page check.
* WooCommerce, Rankavia SEO: product and category title and description list, health counts, single-item editor, change history and conditional undo.
* Shares the title and description fields with Rankavia SEO; read-only when another SEO plugin prints them.
* Use WooCommerce's own GTIN field when available, with a one-click move of a GTIN saved earlier.
* Point to WooCommerce Brands, which wins over the text brand; brand output is a schema.org Brand.
* Clear checkboxes, spaces and hyphens in GTINs accepted, and protection against overwriting a newer value.

= 0.1.0 =
* First development preview: merchant brand and GTIN fields added to WooCommerce product structured data.
