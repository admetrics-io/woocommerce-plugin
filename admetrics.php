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
 * Version:           0.7.0
 * Requires at least: 7.0.7
 * Requires PHP:      8.2
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
        const VERSION = "0.7.0";

        public $plugin_slug;
        public $version;
        public $cache_key;
        public $cache_allowed;

        private $tracking_order = null;
        private $tracking_data_rendered = false;

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
            add_action('woocommerce_before_thankyou', array($this, 'track_confirmation_order'));
            add_action('woocommerce_thankyou', array($this, 'track_confirmation_order'));
            add_action('plugins_loaded', array($this, 'init'));
        }

        public function init()
        {
            if (class_exists('WC_Integration')) {
                require_once __DIR__ . '/admetrics-data-studio-connection.php';
                AdmetricsDataStudio_Connection::init();
                require_once __DIR__ . '/admetrics-custom-confirmation.php';
                AdmetricsDataStudio_Custom_Confirmation::init();
                require_once __DIR__ . '/admetrics-data-studio-integration.php';
                add_filter('woocommerce_integrations', array($this, 'add_integration'));
                add_action('rest_api_init', function () {
                    register_rest_route('admetrics-data-studio/v1', '/settings', array(
                        'methods' => 'POST',
                        'callback' => [AdmetricsDataStudio_Integration::class, 'update_settings'],
                        'permission_callback' => [AdmetricsDataStudio_Connection::class, 'settings_permission'],
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

        private function get_tracking_order(): ?WC_Order
        {
            if ($this->tracking_order) {
                return $this->tracking_order;
            }
            if (!is_order_received_page() && !is_wc_endpoint_url('order-received')) {
                $this->tracking_order = AdmetricsDataStudio_Custom_Confirmation::order();
                return $this->tracking_order;
            }

            $order_key = isset($_GET['key']) && is_string($_GET['key'])
                ? wc_clean(wp_unslash($_GET['key'])) : '';
            $order_key = apply_filters('woocommerce_thankyou_order_key', $order_key);
            if (!is_string($order_key) || $order_key === '') {
                return null;
            }
            $order_id = wc_get_order_id_by_order_key($order_key);
            $order = $order_id ? wc_get_order($order_id) : null;
            if (!is_a($order, 'WC_Order') || !hash_equals($order->get_order_key(), $order_key)) {
                return null;
            }

            $this->tracking_order = $order;
            return $order;
        }

        private function get_order_tracking_data(WC_Order $order): array
        {
            $customer_id = $order->get_customer_id();
            if ($customer_id < 1) {
                $customer_id = md5($order->get_id()) . "@order_id";
            }
            return array(
                'oid' => (string) $order->get_id(),
                'on' => (string) $order->get_order_number(),
                'cid' => (string) $customer_id,
            );
        }

        public function track_confirmation_order($order_id)
        {
            // The two hooks can fire for the same order, including in blocks.
            if ($this->tracking_order || (!is_int($order_id) && !(is_string($order_id) && ctype_digit($order_id)))) {
                return;
            }
            $order = wc_get_order($order_id);
            if (!is_a($order, 'WC_Order')) {
                return;
            }
            $this->tracking_order = $order;
            if (!$this->tracking_data_rendered) {
                return;
            }

            $order_data = wp_json_encode($this->get_order_tracking_data($order), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
            // Update the head's JSON before the deferred tracker executes.
            echo <<<EOD
<script>
(function () {
    var element = document.getElementById('js-app-admq-data');
    if (!element) return;
    var data = JSON.parse(element.textContent);
    var order = $order_data;
    data.oid = order.oid;
    data.on = order.on;
    data.cid = order.cid;
    element.textContent = JSON.stringify(data);
}());
</script>
EOD;
        }

        public function admetrics_wp_head()
        {
            // make sure script is only executed once per page
            if (defined('ADMETRICS_DATA_STUDIO_SCRIPT_INJECTED')) {
                return;
            }
            define('ADMETRICS_DATA_STUDIO_SCRIPT_INJECTED', true);

            $integration_class = new AdmetricsDataStudio_Integration();
            $option_key = $integration_class->get_option_key();
            $current_settings = get_option($option_key, array());

            if (empty($current_settings['tracking_enabled']) || $current_settings['tracking_enabled'] === 'no') {
                return;
            }

            $order_id = "";
            $order_number = "";
            $customer_id = "";
            $order = $this->get_tracking_order();
            if ($order) {
                $order_data = $this->get_order_tracking_data($order);
                $order_id = $order_data['oid'];
                $order_number = $order_data['on'];
                $customer_id = $order_data['cid'];
            } else {
                $current_customer = WC()->customer;
                $customer_id = $current_customer ? $current_customer->get_id() : 0;
                if ($customer_id < 1) {
                    $customer_id = "";
                }
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
            $ss_ob = $current_settings["ss_ob"] ?? "-";
            $ss_ga = $current_settings["ss_ga"] ?? "-";

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

            $version = $this->version;
            $this->tracking_data_rendered = true;

            $payload = [
                'sid' => $sid, 'scid' => '', 'cid' => (string)$customer_id, 'oid' => (string)$order_id,
                'on' => (string)$order_number, 'cim' => '', 'et' => 'woocommerce', 'en' => '',
                'spt' => $product_type, 'sptt' => $page_title, 'sppt' => $product_title,
                'spos' => $product_size, 'scr' => $currency, 'scpp' => $product_price,
                'sctp' => $cart_total_price, 'sss' => $search_string, 'spi' => (string)$product_id,
                'sci' => $collection_id, 'scc' => $collection, 'sca' => $cart,
            ];
            echo '<script id="js-app-admq-data" type="application/json">'
                . wp_json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . '</script>';
            echo '<script id="js-app-admq-script" defer type="application/javascript" src="' . esc_url($src) . '"';
            foreach ([
                'endpoint' => $endpoint, 'cn' => $cn, 'cv' => $cv, 'cv2' => $cv2,
                'pa-vendor' => $pa_vendor, 'pa-mpid' => $pa_mpid, 'ss-mpid' => $ss_mpid,
                'ss-tkpid' => $ss_tkpid, 'ss-ob' => $ss_ob, 'ss-ga' => $ss_ga, 'version' => $version,
            ] as $name => $value) {
                echo ' data-' . $name . '="' . esc_attr($value) . '"';
            }
            echo '></script>';
        }
    }

    new AdmetricsDataStudio();
}
