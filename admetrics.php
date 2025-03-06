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
 * Version:           0.2.0
 * Requires at least: 6.3
 * Requires PHP:      7.0
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
        const VERSION = "0.2.0";

        public $plugin_slug;
        public $version;
        public $cache_key;
        public $cache_allowed;

        public function __construct()
        {
            $this->plugin_slug = plugin_basename(__DIR__);
            $this->version = self::VERSION;
            $this->cache_key = 'admetrics_update';
            $this->cache_allowed = false;

            //add_filter('auto_update_plugin', array($this, 'auto_update'), 10, 2);
            add_filter('plugins_api', array($this, 'info'), 20, 3);
            add_filter('site_transient_update_plugins', array($this, 'update'));


            add_action('upgrader_process_complete', array($this, 'purge'), 10, 2);
            add_action('wp_head', array($this, 'admetrics_wp_head'), 20);
            add_action('woocommerce_thankyou', array($this, 'admetrics_woocommerce_thankyou'), 20, 1);
            add_action('plugins_loaded', array($this, 'init'));
        }

        public function init()
        {
            if (class_exists('WC_Integration')) {
                require_once __DIR__ . '/admetrics-data-studio-integration.php';
                add_filter('woocommerce_integrations', array($this, 'add_integration'));
                add_action('rest_api_init', function () {
                    register_rest_route('admetrics-data-studio/v1', '/settings', array(
                        'methods' => 'POST',
                        'callback' => [AdmetricsDataStudio_Integration::class, 'update_settings'],
                        'permission_callback' => function (){
//                            if (!wc_rest_check_manager_permissions('settings', 'read')) {
//                                return new WP_Error(
//                                    'admetrics_data_studio_401',
//                                    __('Not allowed.'),
//                                    array('status' => rest_authorization_required_code())
//                                );
//                            }
                            return true;
                        },
                    ));
                    add_filter('woocommerce_rest_prepare_shop_order_object', [$this, 'add_customer_order_index']);
                }, 20);
//                add_filter('woocommerce_rest_check_permissions', function($permission, $context, $object_id, $post_type) {
//                    if ($post_type === 'shop_order') {
//                        return true; // Allow all requests to the orders endpoint
//                    }
//                    return $permission;
//                }, 10, 4);
            }
        }

        public function add_integration($integrations)
        {
            $integrations[] = 'AdmetricsDataStudio_Integration';
            return $integrations;
        }

        public function add_customer_order_index(WP_REST_Response $response): WP_REST_Response
        {
            $customer_id = $response->data['customer_id'];
            $order_status = $response->data['status'];
            $statuses = ['pending', 'processing', 'on-hold', 'completed', 'refunded'];
            $customer_order_index = null;
            if (in_array($order_status, $statuses)) {
                if ($customer_id) {
                    $customer_orders = wc_get_orders([
                        'customer_id' => $customer_id,
                        'status' => $statuses,
                        'orderby' => 'date',
                        'order' => 'ASC',
                        'return' => 'ids',
                    ]);
                    $order_index = array_search($response->data['id'], $customer_orders);
                    if ($order_index !== false) {
                        $customer_order_index = $order_index + 1;
                    }
                } else {
                    $customer_order_index = 1;
                }
            }
            $response->data['admetrics_customer_order_index'] = $customer_order_index;

            return $response;
        }

        public function auto_update($update, $item)
        {
            if ($this->plugin_slug === $item->slug) {
                return true;
            }
            return $update;
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
            ) {
                $res = new stdClass();
                $res->slug = $this->plugin_slug;
                $res->plugin = plugin_basename(__FILE__);
                $res->new_version = $remote->version;
                $res->tested = $remote->tested;
                $res->package = $remote->download_url;

                if (
                    version_compare($this->version, $remote->version, '<')
                    && version_compare($remote->requires, get_bloginfo('version'), '<=')
                    && version_compare($remote->requires_php, PHP_VERSION, '<')
                ) {
                    $transient->response[$res->plugin] = $res;
                } else {
                    $transient->no_update[$res->plugin] = $res;
                }
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


            $integration_class = new AdmetricsDataStudio_Integration();
            $option_key = $integration_class->get_option_key();
            $current_settings = get_option($option_key, array());

            if (!$current_settings["tracking_enabled"] || $current_settings["tracking_enabled"] == "no") {
                return;
            }

            $current_customer = WC()->customer;
            $sid = $current_settings["sid"] ?? "";
            $src = $current_settings["src"] ?? "";
            $endpoint = $current_settings["endpoint"] ?? "";
            $cn = $current_settings["cn"] ?? "";
            $cv = $current_settings["cv"] ?? "";
            $cv2 = $current_settings["cv2"] ?? "";
            $pa_vendor = $current_settings["pa_vendor"] ?? "";
            $pa_mpid = $current_settings["pa_mpid"] ?? "";
            $ss_mpid = $current_settings["ss_mpid"] ?? "";
            $ss_tkpid = $current_settings["ss_tkpid"] ?? "";
            $ss_scpid = $current_settings["ss_scpid"] ?? "";
            $customer_id = $current_customer ? $current_customer->get_id() : 0;
            if ($customer_id < 1) {
                $customer_id = "";
            }

            $product_id = "";
            $product_type = "";
            $product_title = "";
            $product_size = "";
            $product_price = "";
            if (is_product()) {
                $product_id = get_queried_object_id();
                $product = wc_get_product(get_queried_object_id());

                // Access product properties
                if ($product) {
                    $product_title = $product->get_name();
                    $product_price = $product->get_price();
                }
            }

            $page_title = get_the_title();
            $currency = get_woocommerce_currency();
            $cart_total_price = "";
            $search_string = "";
            $collection_id = "";
            $collection = "";
            $cart = "";

            echo <<<EOD
<script id="js-app-admq-data" type="application/json">
{
    "sid": "$sid",
    "scid": "",
    "cid": "$customer_id",
    "oid": "",
    "on": "",
    "cim": "",
    "et": "woocommerce",
    "en": "",
    "spt": "$product_type",
    "sptt": "$page_title",
    "sppt": "$product_title",
    "spos": "$product_size",
    "scr": "$currency",
    "scpp": "$product_price",
    "sctp": "$cart_total_price",
    "sss": "$search_string",
    "spi": "$product_id",
    "sci": "$collection_id",
    "scc": "$collection",
    "sca": "$cart"
}
</script>
<script id="js-app-admq-script"
        type="application/javascript"
        src="$src"
        data-endpoint="$endpoint"
        data-cn="$cn"
        data-cv="$cv"
        data-cv2="$cv2"
        data-pa-vendor="$pa_vendor"
        data-pa-mpid="$pa_mpid"
        data-ss-mpid="$ss_mpid"
        data-ss-tkpid="$ss_tkpid"
        data-ss-scpid="$ss_scpid"
></script>
EOD;
        }

        public function admetrics_woocommerce_thankyou($order_id)
        {
            $order = wc_get_order($order_id);
            if (!is_a($order, 'WC_Order')) {
                return;
            }

            $integration_class = new AdmetricsDataStudio_Integration();
            $option_key = $integration_class->get_option_key();
            $current_settings = get_option($option_key, array());

            if (!$current_settings["tracking_enabled"] || $current_settings["tracking_enabled"] == "no") {
                return;
            }

            $sid = $current_settings["sid"] ?? "";
            $src = $current_settings["src"] ?? "";
            $endpoint = $current_settings["endpoint"] ?? "";
            $cn = $current_settings["cn"] ?? "";
            $cv = $current_settings["cv"] ?? "";
            $cv2 = $current_settings["cv2"] ?? "";
            $pa_vendor = $current_settings["pa_vendor"] ?? "";
            $pa_mpid = $current_settings["pa_mpid"] ?? "";
            $ss_mpid = $current_settings["ss_mpid"] ?? "";
            $ss_tkpid = $current_settings["ss_tkpid"] ?? "";
            $ss_scpid = $current_settings["ss_scpid"] ?? "";
            $order_id = $order->get_id();
            $order_number = $order->get_order_number();
            $customer_id = $order->get_customer_id();
            if ($customer_id < 1) {
                $customer_id = md5($order_id) . "@order_id";
            }

            $product_id = "";
            $product_type = "";
            $product_title = "";
            $product_size = "";
            $product_price = "";
            $page_title = get_the_title();
            $currency = get_woocommerce_currency();
            $cart_total_price = "";
            $search_string = "";
            $collection_id = "";
            $collection = "";
            $cart = "";

            echo <<<EOD
<script id="js-app-admq-data" type="application/json">
{
    "sid": "$sid",
    "scid": "",
    "cid": "$customer_id",
    "oid": "$order_id",
    "on": "$order_number",
    "cim": "",
    "et": "woocommerce",
    "en": "",
    "spt": "$product_type",
    "sptt": "$page_title",
    "sppt": "$product_title",
    "spos": "$product_size",
    "scr": "$currency",
    "scpp": "$product_price",
    "sctp": "$cart_total_price",
    "sss": "$search_string",
    "spi": "$product_id",
    "sci": "$collection_id",
    "scc": "$collection",
    "sca": "$cart"
}
</script>
<script id="js-app-admq-script"
        type="application/javascript"  
        src="$src"
        data-endpoint="$endpoint"
        data-cn="$cn"
        data-cv="$cv"
        data-cv2="$cv2"
        data-pa-vendor="$pa_vendor"
        data-pa-mpid="$pa_mpid"
        data-ss-mpid="$ss_mpid"
        data-ss-tkpid="$ss_tkpid"
        data-ss-scpid="$ss_scpid"
></script>
EOD;
        }

    }

    new AdmetricsDataStudio();
}
