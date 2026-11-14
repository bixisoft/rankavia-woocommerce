# Rankavia for WooCommerce

Free, GPL-2.0-or-later WooCommerce add-on by Bixisoft.

**Development preview — plugin version 0.1.0; release v0.1.0-preview.1.** Test on a disposable copy before using it on a live store. This repository is public source distribution, not a WordPress.org listing or a stable compatibility certification.

On a product's General tab, a merchant can enter a brand and a GTIN. The add-on adds them to the Product structured data that WooCommerce already prints, only when WooCommerce has no brand or GTIN of its own. Invalid GTINs (wrong length or GS1 check digit) are not saved. WooCommerce's product, offer, price, availability and review data are left untouched. It does nothing when WooCommerce is not active and does not need the Rankavia plugin.

Category and attribute page SEO, out-of-stock handling and merchant feed checks belong to the planned full version and are not in this preview.

## Download and install

Download **rankavia-woocommerce.zip** from [the preview release](https://github.com/bixisoft/rankavia-woocommerce/releases/tag/v0.1.0-preview.1). Upload it through WordPress Plugins → Add New → Upload Plugin, then activate. Requires WordPress 6.5+, PHP 7.4+ and WooCommerce 8.0+. The GitHub-generated source archives are not the install ZIP. Read [VALIDATION.md](VALIDATION.md) for what was tested.

## Source and building

All plugin code is editable PHP. Build the install ZIP from the verified runtime manifest with Python 3:

```sh
python tools/package.py
```

This checks every packaged file and creates `artifacts/rankavia-woocommerce.zip`. Run the unit checks with `php tests/Product_Module_Test.php`.

## Privacy and support

The add-on makes no HTTP requests and includes no analytics or tracking. Uninstalling keeps the saved brand and GTIN values in product metadata. Report reproducible issues in this repository; never include credentials or private customer data.

## License

GPL-2.0-or-later; full GPL v2 text is in [LICENSE](LICENSE).
