# Preview 1 validation

Release tag: `v0.1.0-preview.1`. Plugin header: `0.1.0`. Development preview, not WordPress.org listed or stable certification.

Install ZIP SHA256: `20bfb6241685621a1ea5fa5c7b0dc1c9c39003621cd18502e454aa93a7a31e3f`. `python tools/package.py` rebuilds it byte-for-byte from `release-manifest.json`. Source: bixisoft-plugins `85ce2ccc`.

## What it does

- Adds "Merchant brand" and "GTIN (8, 12, 13 or 14 digits)" fields to a product's General tab, with a nonce and an edit-permission check.
- Adds the saved brand and a valid GTIN (as `gtin8`, `gtin12`, `gtin13` or `gtin14`) to WooCommerce's own Product structured data, only when WooCommerce has no brand or GTIN key of its own.

## Checks run

- PHP syntax (`php -l`, PHP 8.0) and `tests/Product_Module_Test.php` (native data preserved, authored fields added, existing native fields not overwritten, invalid check digit rejected) passed.
- WordPress 7.1.4 with WooCommerce 11.3.0 on PHP 8.3 (Playground), same plugin content as this ZIP (line endings aside):
  - with WooCommerce inactive the add-on activates and stays idle; front page HTTP 200, no PHP errors;
  - with WooCommerce active the fields appear on the General tab, and brand and GTIN save through the product editor;
  - product pages output `brand` and `gtin13`; a product with an invalid GTIN outputs the brand only; WooCommerce `offers` are kept; no PHP errors.

## Not run

- Plugin Check, PHPCS and PHPStan; PHP 7.4 runtime; WooCommerce 8.x–10.x; variable products; WooCommerce's own Brands taxonomy together with this add-on's brand field; multisite.
