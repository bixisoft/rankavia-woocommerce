# Rankavia for WooCommerce

Free, GPL-2.0-or-later WooCommerce add-on by Bixisoft.

**Development preview — plugin version 0.2.0; release v0.2.0-preview.1.** Test on a disposable copy before using it on a live store. This repository is public source distribution, not a WordPress.org listing or a stable compatibility certification.

Under WooCommerce → Rankavia SEO it adds:

- **Products and categories:** SEO title and meta description for each product and product category, with counts of gaps, a change history and undo. Values are shared with Rankavia SEO; when Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework is active, this screen is read-only.
- **Product data:** checks WooCommerce's product structured data (price, currency, stock, rating, GTIN, brand, SKU) against what the shop shows, with a live page check.
- **Indexing:** noindex and canonical settings of store pages, products and categories, with a live page check that also reads robots.txt.
- **Images:** product image alt text with writing guidance.
- **Sitemap:** products and categories missing from or wrongly listed in the XML sitemap, a category policy for WordPress' own sitemap, and cart, checkout and account pages left out of it by default.

On the product edit screen it adds a merchant brand and GTIN (used only where WooCommerce has none of its own) and up to ten product questions and answers, shown in a product page tab. FAQ structured data is off by default; Google shows FAQ results only for well-known government and health sites, so it is not a ranking boost.

Nothing is filled in automatically. It does nothing when WooCommerce is not active and does not need the Rankavia plugin.

## Download and install

Download **rankavia-woocommerce.zip** from [the preview release](https://github.com/bixisoft/rankavia-woocommerce/releases/tag/v0.2.0-preview.1). Upload it through WordPress Plugins → Add New → Upload Plugin, then activate; WordPress offers to replace preview 1 if it is installed. Requires WordPress 6.5+, PHP 7.4+ and WooCommerce 8.0+. The GitHub-generated source archives are not the install ZIP. Read [VALIDATION.md](VALIDATION.md) for what was tested.

## Source and building

All plugin code is editable PHP. Build the install ZIP from the verified runtime manifest with Python 3:

```sh
python tools/package.py
```

This checks every packaged file and creates `artifacts/rankavia-woocommerce.zip`. Run the unit checks with `php tests/<name>_Test.php` for each file in `tests/`.

## Privacy and support

The add-on sends no data to Bixisoft or anyone else and includes no analytics or tracking. Its only HTTP requests are the "Check live page", "Check store pages live" and "Check sitemap live" buttons an administrator presses; they load pages, robots.txt and sitemap files from the same site. Uninstalling keeps titles, descriptions, brands, GTINs and product questions, and deletes the change history and sitemap settings. Report reproducible issues in this repository; never include credentials or private customer data.

## License

GPL-2.0-or-later; full GPL v2 text is in [LICENSE](LICENSE).
