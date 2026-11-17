# Preview 2 validation

Release tag: `v0.2.0-preview.1`. Plugin header: `0.2.0`. Development preview, not WordPress.org listed or stable certification.

Install ZIP SHA256: `e793358e2b3e883792bef4b447cf2c686333247e608db790c5c193f0549950eb`. `python tools/package.py` rebuilds it byte-for-byte from `release-manifest.json`. Source: bixisoft-plugins `5f17dacf`.

## What it adds since preview 1

- WooCommerce → Rankavia SEO: product and category SEO titles and descriptions with gap counts, change history and conditional undo; read-only when another SEO plugin prints them.
- Product data tab: product structured data price, currency, stock, rating and identifiers checked against the shop, with a live page check.
- Indexing tab: noindex and canonical settings of store pages, products and categories, with a live page check that reads robots meta, X-Robots-Tag, canonical links and robots.txt.
- Images tab: product image alt text with writing guidance.
- Sitemap tab: products and categories missing from or wrongly listed in the XML sitemap, a category policy for WordPress' own sitemap, and cart, checkout and account pages left out of it by default.
- Product questions and answers in a product page tab, with optional FAQ structured data (off by default; not a ranking boost).
- WooCommerce's own GTIN field and WooCommerce Brands are used first; a GTIN saved by preview 1 can be moved into WooCommerce's field.

## Checks run

- Six unit test files in `tests/` pass (PHP 8.0); `php -l` passes on every file; PHPCS (WordPress Coding Standards) passes with one accepted warning (the `$class` autoloader parameter) and one documented `posts_per_page` warning on the sitemap summary.
- Upgrade on WordPress 7.1 with WooCommerce 11.3.0 on PHP 8.3 (Playground): preview 1 was installed from its GitHub release ZIP, a product was given a brand and GTIN in preview 1's fields, then this ZIP was installed over it:
  - the plugin list shows version 0.2.0 active with WooCommerce;
  - the product page still outputs `brand` (Acme Hats) and `gtin13`, keeps WooCommerce's `Offer`, and shows no PHP errors;
  - the Products, Categories, Product data, Indexing, Images and Sitemap tabs load with no PHP errors; the Sitemap tab reads WordPress' sitemap (2 of 2 products listed);
  - the product editor shows the "Questions" tab and offers to move preview 1's GTIN into WooCommerce's own GTIN field.
- Each feature was also tested on its own test shop before merge (product and category editing and undo, live page checks, FAQ output and save checks, image findings, sitemap comparison and policy both ways).

## Not run

- Plugin Check and PHPStan; a PHP 7.4 runtime (WooCommerce would not activate in Playground's PHP 7.4 database layer, so this was not tested); WooCommerce 8.x–10.x; real Yoast SEO, Rank Math, All in One SEO, SEOPress or The SEO Framework installs; Rankavia SEO active; classic themes; variable products; shops with more than 5,000 products; multisite.

This is a development preview, not a stable or WordPress.org release.
