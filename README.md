# WooCommerce Plugin: Admetrics Data Studio

WooCommerce plugin adding the Admetrics Tracking Pixel to your WooCommerce installation.

## Usage

### Installation

Download the latest version from https://github.com/admetrics-io/woocommerce-plugin/releases/latest and upload it to
your WordPress installation.

### Updating

Updates can be done within your WordPress Admin Interface. Go to "Plugins > Installed Plugins", find "Admetrics Data
Studio" and click on "Update now".

### Authorizing tracking-settings updates

WooCommerce order imports use read-only WooCommerce API access. New installations require a separate,
settings-only connection for remote tracking-configuration changes. Existing installations can keep
their legacy connection until the shop administrator enables authorization:

1. Open **WooCommerce → Settings → Integration → Admetrics Data Studio** and select **Secure connection** (existing shops) or **Connect to Admetrics** (new installations).
2. Sign in to Admetrics, select the correct client and choose the existing WooCommerce datasource for this shop.
3. Continue to the shop and approve the tracking-settings connection using an account allowed to manage WooCommerce settings.

The connection allows Admetrics to change its tracking configuration, including the tracking script source.
It does not grant access to change WooCommerce orders, products or other WordPress settings.
The approval link expires after ten minutes; start again if it expires. No API keys need to be copied.

Use **Disconnect tracking settings** on the same plugin page to revoke access immediately. Saved tracking
settings remain in place, and read-only order imports keep their separate authorization. Connecting again
replaces the previous tracking-settings credential. Deleting or removing settings-management permission
from the approving WordPress account also invalidates its credential.

Enabling authorization is a one-way change. Disconnecting, failed authentication or a lost backend
response never restores unauthenticated updates. Legacy connections still accept unauthenticated
tracking-settings changes until they are secured; updating the plugin alone does not secure an existing
connection. New installations never start in legacy mode.

### Settings page

The integration page shows the connection status, the Admetrics client and datasource, and whether
tracking is enabled in the saved shop configuration. **Manage tracking in Admetrics** opens the
datasource configuration in a new tab. Connect/reconnect stays in the same tab and returns to the shop
for approval; the Admetrics connection screen also offers **Back to shop settings** for each matching
datasource. **Technical details** expands the read-only configuration values used for
troubleshooting. Tracking values are managed in Admetrics rather than edited on this page.

- **Legacy connection:** existing unauthenticated updates continue; select **Secure connection** to migrate.
- **Not connected:** authorization is required, but no active tracking-settings credential is available.
- **Awaiting confirmation:** approval is saved; background verification or the first settings delivery
  has not finished. A successfully authenticated backend update confirms the connection.
- **Connected:** Admetrics confirmed the connection. The page separately shows the last settings update
  received for this credential; this is not a live check of tracking-event delivery.
- **Reconnect required:** the approving WordPress account no longer has permission to manage WooCommerce.

Confirmation metadata is tied to the current credential and stored separately from authorization.
Disconnecting revokes authorization immediately, even if a previous callback finishes later.
Duplicate approval submissions reuse the same credential; a delayed approval cannot overwrite a
disconnect or a newer connection. If a callback response is lost, retrying does not reopen legacy access.

Both the shop and Admetrics backend must have valid HTTPS certificates. The plugin defaults to the
production Admetrics service. Server administrators can configure a trusted QA deployment with the
`ADMETRICS_API_ORIGIN` and `ADMETRICS_APP_ORIGIN` constants in `wp-config.php`; these values must
never come from a request or from tracking settings.

The backend must be able to reach the shop's public REST endpoints. Different storefront and WordPress
subdirectory paths are supported on the same HTTPS origin. If a proxy terminates HTTPS, WordPress must
be configured to recognize HTTPS correctly. A connection error does not revoke read-only order imports
or remove previously saved tracking settings.
