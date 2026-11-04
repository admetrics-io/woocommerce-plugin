# Admetrics Data Studio for WooCommerce

Adds Admetrics tracking to your WooCommerce shop.

## Install and update

Download the [latest release](https://github.com/admetrics-io/woocommerce-plugin/releases/latest)
and upload it in WordPress. Future updates appear under **Plugins → Installed Plugins**.

## Connect tracking settings

1. Open **WooCommerce → Settings → Integration → Admetrics Data Studio**.
2. Select **Connect to Admetrics**, or **Secure connection** for an existing legacy connection.
3. Sign in to Admetrics, select your client and WooCommerce datasource, then return to the shop to approve.

Use an account allowed to manage WooCommerce settings. Both services need HTTPS,
and Admetrics must be able to reach the shop's REST endpoints. Approval links expire after ten minutes.

This connection manages tracking settings, including the tracking script—not orders or products.
Order imports use separate read-only access. **Disconnect tracking settings** revokes the connection
without removing saved settings or stopping order imports. Securing a legacy connection is one-way;
updating the plugin alone does not secure it.

Use **Manage tracking in Admetrics** to change tracking settings. The plugin shows connection status
and the last settings update; these do not confirm that tracking events are arriving.

## Custom thank-you pages

Standard WooCommerce confirmation pages work automatically. For a custom page, enable
**Custom thank-you page** support on the plugin settings page and enter:

| Setting | Example |
| --- | --- |
| URL regex | `^/danke/?(?:\?.*)?$` |
| Order ID parameter | `order_id` |
| Order key parameter | `auth` (or `key`) |

Select **Save custom thank-you page settings**. This optional configuration stays in WordPress,
not Admetrics. Disabling it preserves the settings and standard confirmation tracking.

The case-sensitive regex matches the URL-encoded **path and query string**, excluding the domain
and fragment. Enter it without PHP delimiters or modifiers; use `^` and `$` to anchor it.
Enter parameter names, not actual IDs or keys. An order is tracked only when its ID and key match.
Invalid patterns cannot be saved; regex errors or execution limits produce no custom order match.

**Exclude matching pages from page caches and CDNs.** Place a test order to verify tracking and
reporting, including consent behavior. Historical tracking is not restored.
