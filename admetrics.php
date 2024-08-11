<?php
/**
 * Admetrics WooCommerce Plugin
 *
 * @package           Admetrics\WooCommercePlugin
 * @author            Admetrics GmbH
 * @copyright         2024 Admetrics GmbH
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Admetrics Data Studio
 * Plugin URI:        https://github.com/admetrics-io/woocommerce-plugin
 * Description:       Connects Admetrics Data Studio with your WooCommerce installation.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Admetrics GmbH
 * Author URI:        https://www.admetrics.io
 * Text Domain:       admetrics
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('wp_head', 'admetrics_wp_head');
function admetrics_wp_head() {
    if (is_wc_endpoint_url( 'order-received' )) {
        return;
    }

    $current_customer = WC()->customer;
    $shop_id = "";
    $customer_id = $current_customer ? $current_customer->get_id() : "";
    if ($customer_id < 1) {
        $customer_id = "";
    }

    echo <<<EOD
<script id="js-app-admq-data" type="application/json">
{
    "sid": "$shop_id",
    "cid": "$customer_id",
    "oid": "",
    "on": ""
}
</script>
<script id="js-app-admq-script"
        type="application/javascript"
        src="https://shopify.admetrics.events/conversion-v1.min.js"
></script>
EOD;
}

add_action('woocommerce_thankyou', 'admetrics_woocommerce_thankyou', 10, 1);
function admetrics_woocommerce_thankyou($order_id) {
    $order = wc_get_order($order_id);
    if (!is_a($order, 'WC_Order')) {
        return;
    }

    $shop_id = "";
    $order_id = $order->get_id();
    $order_number = $order->get_order_number();
    $customer_id = $order->get_customer_id();
    if ($customer_id < 1) {
        $customer_id = md5($order_id) . "@order_id";
    }

    echo <<<EOD
<script id="js-app-admq-data" type="application/json">
{
    "sid": "$shop_id",
    "cid": "$customer_id",
    "oid": "$order_id",
    "on": "$order_number"
}
</script>
<script id="js-app-admq-script"
        type="application/javascript"  
        src="https://shopify.admetrics.events/conversion-v1.min.js"
></script>
EOD;
}
