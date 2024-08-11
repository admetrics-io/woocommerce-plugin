# WooCommerce Plugin: Admetrics Data Studio

WooCommerce plugin adding the Admetrics Tracking Pixel to your WooCommerce installation.

## Usage

### Installation

Download the latest version from https://github.com/admetrics-io/woocommerce-plugin/releases/latest and upload it to
your WordPress installation.

### Updating

Updates can be done within your WordPress Admin Interface. Go to "Plugins > Installed Plugins", find "Admetrics Data
Studio" and click on "Update now".

## Development

```
# Starting your local WP environment 
make dev

# Stopping ayour local WP environment
make down

# Cleanup your local system (removing all generated/downloaded files and Docker containers)
make clean
```

### Publishing a new release

1. Define new version (major, minor, patch).
2. Set version in `updates/info.json` and `admetrics.php` to latest version.
3. "Draft a new release" on https://github.com/admetrics-io/woocommerce-plugin/releases and use the latest version as
   new tag.
