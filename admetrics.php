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
 * Version:           0.1.3
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Admetrics GmbH
 * Author URI:        https://www.admetrics.io
 * Text Domain:       admetrics
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('AdmetricsDataStudio')) {
    class AdmetricsDataStudio
    {
        public string $plugin_slug;
        public string $version;
        public string $cache_key;
        public bool $cache_allowed;

        public function __construct()
        {
            $this->plugin_slug = plugin_basename(__DIR__);
            $this->version = '0.1.3';
            $this->cache_key = 'admetrics_update';
            $this->cache_allowed = false;

            add_filter('plugins_api', array($this, 'info'), 20, 3);
            add_filter('site_transient_update_plugins', array($this, 'update'));
            add_action('upgrader_process_complete', array($this, 'purge'), 10, 2);
            add_action('wp_head', array($this, 'admetrics_wp_head'));
            add_action('woocommerce_thankyou', array($this, 'admetrics_woocommerce_thankyou'), 10, 1);
        }

        public function request()
        {
            $remote = get_transient($this->cache_key);

            if (false === $remote || !$this->cache_allowed) {
                $remote = wp_remote_get(
                    'https://raw.githubusercontent.com/admetrics-io/woocommerce-plugin/main/updates/info.json',
                    array(
                        'timeout' => 10,
                        'headers' => array(
                            'Accept' => 'application/json'
                        )
                    )
                );

                if (
                    is_wp_error($remote)
                    || 200 !== wp_remote_retrieve_response_code($remote)
                    || empty(wp_remote_retrieve_body($remote))
                ) {
                    return false;
                }

                set_transient($this->cache_key, $remote, DAY_IN_SECONDS);
            }

            return json_decode(wp_remote_retrieve_body($remote));
        }


        function info($res, $action, $args)
        {
            // do nothing if you're not getting plugin information right now
            if ('plugin_information' !== $action) {
                return $res;
            }

            // do nothing if it is not our plugin
            if ($this->plugin_slug !== $args->slug) {
                return $res;
            }

            // get updates
            $remote = $this->request();

            if (!$remote) {
                return $res;
            }

            $res = new stdClass();

            $res->name = $remote->name;
            $res->slug = $remote->slug;
            $res->version = $remote->version;
            $res->tested = $remote->tested;
            $res->requires = $remote->requires;
            $res->author = $remote->author;
            $res->author_profile = $remote->author_profile;
            $res->download_link = $remote->download_url;
            $res->trunk = $remote->download_url;
            $res->requires_php = $remote->requires_php;
            $res->last_updated = $remote->last_updated;

            $res->sections = array(
                'description' => $remote->sections->description,
                'installation' => $remote->sections->installation,
                'changelog' => $remote->sections->changelog
            );

            if (!empty($remote->banners)) {
                $res->banners = array(
                    'low' => $remote->banners->low,
                    'high' => $remote->banners->high
                );
            }

            return $res;
        }

        public function update($transient)
        {
            if (empty($transient->checked)) {
                return $transient;
            }

            $remote = $this->request();

            if (
                $remote
                && version_compare($this->version, $remote->version, '<')
                && version_compare($remote->requires, get_bloginfo('version'), '<=')
                && version_compare($remote->requires_php, PHP_VERSION, '<')
            ) {
                $res = new stdClass();
                $res->slug = $this->plugin_slug;
                $res->plugin = plugin_basename(__FILE__);
                $res->new_version = $remote->version;
                $res->tested = $remote->tested;
                $res->package = $remote->download_url;

                $transient->response[$res->plugin] = $res;
            }

            return $transient;
        }

        public function purge($upgrader, $options)
        {
            if (
                $this->cache_allowed
                && 'update' === $options['action']
                && 'plugin' === $options['type']
            ) {
                // just clean the cache when new plugin version is installed
                delete_transient($this->cache_key);
            }
        }

        public function admetrics_wp_head()
        {
            if (is_wc_endpoint_url('order-received')) {
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

        public function admetrics_woocommerce_thankyou($order_id)
        {
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

    }

    new AdmetricsDataStudio();
}
