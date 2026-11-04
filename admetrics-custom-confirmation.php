<?php

/** Shop-local confirmation routing. Never part of the remotely managed settings. */
class AdmetricsDataStudio_Custom_Confirmation
{
    const OPTION = 'admetrics_custom_confirmation';
    const ACTION = 'admetrics_custom_confirmation_save';
    const MAX_PATTERN_BYTES = 2048;
    const MAX_URL_BYTES = 16384;

    /** Pattern bodies have no PHP delimiters or modifiers. Limits can only reduce PCRE's configured limits. */
    private static function match(string $pattern, string $url)
    {
        if ($pattern === '' || strlen($pattern) > self::MAX_PATTERN_BYTES
            || strlen($url) > self::MAX_URL_BYTES || preg_match('/[\x00-\x1f\x7f]/', $pattern)) {
            return false;
        }
        // A forbidden control character is a delimiter that never needs escaping in user patterns.
        // Disable JIT so both the match and depth limits apply to every execution.
        return @preg_match("\x01(*NO_JIT)(*LIMIT_MATCH=10000)(*LIMIT_DEPTH=100)" . $pattern . "\x01", $url);
    }

    public static function init(): void
    {
        add_action('admin_post_' . self::ACTION, [self::class, 'save']);
        add_action('template_redirect', function () {
            if (self::matching_settings() !== null) {
                if (!defined('DONOTCACHEPAGE')) {
                    define('DONOTCACHEPAGE', true);
                }
                nocache_headers();
            }
        });
    }

    /** Return a canonical configuration, or fail closed without changing saved state. */
    public static function validate($input): ?array
    {
        if (!is_array($input)) {
            return null;
        }
        foreach (['enabled', 'pattern', 'id_parameter', 'key_parameter'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                return null;
            }
        }
        if (!in_array($input['enabled'], ['yes', 'no'], true)) {
            return null;
        }
        $pattern = $input['pattern']; // Whitespace and backslashes have regex meaning; preserve them.
        $id = trim($input['id_parameter']);
        $key = trim($input['key_parameter']);
        if ($pattern !== '' && self::match($pattern, '') === false) {
            return null;
        }
        foreach ([$id, $key] as $parameter) {
            if ($parameter !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $parameter)) {
                return null;
            }
        }
        if (($id !== '' && $id === $key) || ($input['enabled'] === 'yes' && ($pattern === '' || $id === '' || $key === ''))) {
            return null;
        }
        return ['enabled' => $input['enabled'], 'pattern' => $pattern, 'id_parameter' => $id, 'key_parameter' => $key];
    }

    private static function matching_settings(): ?array
    {
        $settings = self::validate(get_option(self::OPTION, []));
        if ($settings === null || $settings['enabled'] !== 'yes') {
            return null;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        // Match the raw, URL-encoded path and query exactly as sent, without host or fragment.
        if (!is_string($uri) || !str_starts_with($uri, '/') || str_starts_with($uri, '//')) {
            return null;
        }
        $url = explode('#', wp_unslash($uri), 2)[0];
        return self::match($settings['pattern'], $url) === 1 ? $settings : null;
    }

    public static function order(): ?WC_Order
    {
        $settings = self::matching_settings();
        if ($settings === null) {
            return null;
        }
        $id = $_GET[$settings['id_parameter']] ?? null;
        $key = $_GET[$settings['key_parameter']] ?? null;
        // Do not coerce fractional, signed, zero-padded or overflowing identifiers.
        if (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D', $id) || !is_string($key) || $key === '') {
            return null;
        }
        if (strlen($id) > strlen((string) PHP_INT_MAX)
            || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)) {
            return null;
        }
        $key = wp_unslash($key); // Order keys are opaque: never trim or sanitize them.
        $order = wc_get_order((int) $id);
        return $order instanceof WC_Order && (string) $order->get_id() === $id
            && hash_equals($order->get_order_key(), $key) ? $order : null;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $settings = self::validate(get_option(self::OPTION, []))
            ?? ['enabled' => 'no', 'pattern' => '^/danke/?(?:\?.*)?$', 'id_parameter' => 'order_id', 'key_parameter' => 'key'];
        echo '<section id="admetrics-custom-confirmation"><h3>' . esc_html__('Custom thank-you page', 'admetrics') . '</h3>';
        echo '<p>' . esc_html__('Optional shop-local settings. Standard WooCommerce confirmation tracking remains available. The order ID and order key must match. Exclude this page from all page caches.', 'admetrics') . '</p>';
        echo '<p><label><input form="admetrics-custom-confirmation-form" type="checkbox" name="enabled" value="yes"'
            . checked($settings['enabled'], 'yes', false) . '> ' . esc_html__('Enable custom thank-you page support', 'admetrics') . '</label></p>';
        foreach (['pattern' => __('Thank-you URL regex (path and query string)', 'admetrics'),
            'id_parameter' => __('Order ID parameter', 'admetrics'), 'key_parameter' => __('Order key parameter', 'admetrics')] as $field => $label) {
            echo '<p><label for="admetrics-custom-' . esc_attr($field) . '">' . esc_html($label) . '</label><br>';
            echo '<input form="admetrics-custom-confirmation-form" id="admetrics-custom-' . esc_attr($field)
                . '" name="' . esc_attr($field) . '" type="text" value="' . esc_attr($settings[$field]) . '"></p>';
        }
        echo '<p>' . esc_html__('Enter a case-sensitive PCRE pattern without delimiters or modifiers. Example: ^/danke/?(?:\?.*)?$ . It matches the URL-encoded path and query string, including any shop subdirectory, but not the domain or fragment. Use ^ and $ to anchor the match. Parameter names must be distinct.', 'admetrics') . '</p>';
        echo '<p>' . esc_html__('Patterns are limited to 2048 bytes; URLs to 16384 bytes. Matching has a 10000-step and 100-depth budget. Invalid or over-budget matches never identify an order. Exclude every matching page from caches; do not put real order keys in the pattern.', 'admetrics') . '</p>';
        echo '<button form="admetrics-custom-confirmation-form" type="submit" class="button">'
            . esc_html__('Save custom thank-you page settings', 'admetrics') . '</button></section>';
        // WooCommerce already wraps this screen in a form. Associate controls with a separate footer form.
        add_action('admin_footer', function () {
            echo '<form id="admetrics-custom-confirmation-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            wp_nonce_field(self::ACTION);
            echo '</form>';
        });
    }

    public static function save(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You are not allowed to manage custom thank-you pages.', '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);
        $input = wp_unslash($_POST);
        $input['enabled'] = $input['enabled'] ?? 'no';
        $settings = self::validate($input);
        if ($settings === null) {
            wp_die('Invalid custom thank-you page settings. Saved settings were not changed. Use a valid regex of at most 2048 bytes without delimiters, modifiers or control characters, and distinct parameter names.', '', ['response' => 400]);
        }
        $previous = get_option(self::OPTION, []);
        if ($previous !== $settings && !update_option(self::OPTION, $settings, false)) {
            wp_die('Custom thank-you page settings could not be saved. Please retry.', '', ['response' => 503]);
        }
        wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=integration&section=admetrics_data_studio'));
        exit;
    }
}
