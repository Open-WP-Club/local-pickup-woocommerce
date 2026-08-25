# Local Pickup Stores for WooCommerce

A WooCommerce extension for zone-aware store pickup locations with individual prices. It is built specifically for the modern Cart and Checkout Blocks.

## Features

- Manage pickup locations under **WooCommerce → Pickup locations**.
- Set an address, phone, opening hours, price, tax status, and active status per location.
- Add **Store pickup** to any WooCommerce shipping zone.
- Limit each shipping-zone instance to selected locations, or leave it empty to use all active locations.
- Choose a default pickup location for each shipping-zone instance.
- Present the matching pickup rates as a single dropdown in the Checkout Block.
- Use WooCommerce-native shipping rates so free/paid pickup, taxes, and checkout totals remain accurate.
- Validate the selected location server-side before the order is placed.
- Show a snapshot of the selected location in order admin, emails, My Account, and the thank-you page.
- Open the selected pickup address directly in Google Maps.
- English source strings and a bundled Bulgarian (`bg_BG`) translation.
- Compatible with WooCommerce HPOS.
- Keeps WooCommerce's Checkout Block pickup UI enabled while the plugin is active.

## Installation

1. Copy this directory to `wp-content/plugins/local-pickup-stores` or upload it as a ZIP from WordPress admin.
2. Activate **Local Pickup Stores for WooCommerce**.
3. Add and publish at least one location at **WooCommerce → Pickup locations**.
4. Go to **WooCommerce → Settings → Shipping → Shipping zones**.
5. Edit a zone, add **Store pickup**, and optionally restrict its available locations.
6. Ensure the checkout page uses the WooCommerce Checkout Block.

## Requirements

- WordPress 6.5+
- WooCommerce 9.0+
- PHP 7.4+
- The block-based checkout (the classic checkout shortcode is intentionally not supported)

## License

[Apache License 2.0](LICENSE)

## Development checks

```sh
composer install
composer test
find . -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/js/checkout.js
```
