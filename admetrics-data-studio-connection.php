<?php

if (!defined('ABSPATH')) {
    exit;
}

class AdmetricsDataStudio_Connection
{
    const OPTION = 'admetrics_settings_connection';
    const CONFIRMATION_OPTION = 'admetrics_settings_connection_confirmation';
    const AUTH_MODE_OPTION = 'admetrics_settings_auth_mode';
    const HEADER = 'X-Admetrics-Settings-Token';
    private static int $lock_depth = 0;
    private static ?array $active_lock = null;
    private static bool $shutdown_registered = false;

    public static function init(): void
    {
        self::initialize_auth_mode();
        add_action('admin_post_admetrics_settings_connect', [self::class, 'approve']);
        add_action('admin_post_admetrics_settings_disconnect', [self::class, 'disconnect']);
        add_action('admin_init', function () {
            if (isset($_GET['admetrics_pairing'])) {
                header('Referrer-Policy: no-referrer');
                nocache_headers();
            }
        });
        add_filter('admin_referrer_policy', static function ($policy) {
            return isset($_GET['admetrics_pairing']) ? 'no-referrer' : $policy;
        });
        add_action('rest_api_init', function () {
            register_rest_route('admetrics-data-studio/v1', '/connection/info', [
                'methods' => 'GET',
                'permission_callback' => '__return_true',
                'callback' => [self::class, 'info'],
            ]);
            register_rest_route('admetrics-data-studio/v1', '/connection/verify', [
                'methods' => 'POST',
                'permission_callback' => [self::class, 'token_permission'],
                'callback' => [self::class, 'verify'],
            ]);
        });
    }

    public static function initialize_auth_mode(): void
    {
        $connection = get_option(self::OPTION, null);
        if (self::valid_state($connection)) {
            return;
        }
        // One durable state record makes mode, credentials and revocation a single atomic change.
        // The separate mode option is read only when migrating an earlier development installation.
        $record = self::state_record();
        if (self::valid_state($record['state'])) {
            wp_cache_delete(self::OPTION, 'options');
            return;
        }
        $old_mode = get_option(self::AUTH_MODE_OPTION, null);
        $existing = get_option('woocommerce_admetrics_data_studio_settings', null);
        $mode = $record['raw'] === null && $old_mode !== 'required' && is_array($existing) ? 'legacy' : 'required';
        $state = $record['state'];
        $state['mode'] = $mode;
        $state['revision'] = bin2hex(random_bytes(32));
        if (!self::replace_state($record['raw'], $state)) {
            wp_cache_delete(self::OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
            wp_cache_delete('alloptions', 'options');
        }
    }

    private static function valid_state($state): bool
    {
        return is_array($state) && in_array($state['mode'] ?? null, ['legacy', 'required'], true)
            && ($state['mode'] === 'required' || empty($state['token_hash']))
            && is_string($state['revision'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $state['revision']) === 1;
    }

    public static function authentication_required(): bool
    {
        $state = get_option(self::OPTION, []);
        return !self::valid_state($state) || $state['mode'] !== 'legacy' || !empty($state['token_hash']);
    }

    private static function state_record(): array
    {
        global $wpdb;
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));
        $state = $raw === null ? [] : maybe_unserialize($raw);
        return ['raw' => $raw, 'state' => is_array($state) ? $state : []];
    }

    private static function replace_state(?string $expected, array $state): bool
    {
        global $wpdb;
        $result = self::with_state_lock(static function () use ($wpdb, $expected, $state): bool {
            if ($expected === null) { return add_option(self::OPTION, $state, '', false); }
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s",
                maybe_serialize($state), self::OPTION, $expected
            ));
            if ($changed === 1) {
                wp_cache_delete(self::OPTION, 'options');
                wp_cache_delete('alloptions', 'options');
                return true;
            }
            return false;
        });
        return $result === true;
    }

    public static function with_state_lock(callable $callback)
    {
        global $wpdb;
        $name = 'admetrics_settings_' . substr(hash('sha256', DB_NAME . ':' . $wpdb->options), 0, 40);
        if (self::$lock_depth === 0) {
            if (!($wpdb->dbh instanceof mysqli)) {
                return new WP_Error('admetrics_settings_database', 'The connection requires the standard WordPress mysqli database driver.', ['status' => 503]);
            }
            if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $name)) !== '1') {
                return new WP_Error('admetrics_settings_busy', 'The tracking connection is busy. Please retry.', ['status' => 503]);
            }
            $dbh = $wpdb->dbh;
            $thread = $dbh->thread_id;
            $report_mode = (new mysqli_driver())->report_mode;
            $fence = static function ($query) use ($wpdb, $dbh, $thread) {
                if ($wpdb->dbh !== $dbh || $dbh->thread_id !== $thread) {
                    throw new RuntimeException('The database connection changed during a tracking update.');
                }
                // wpdb otherwise retries a failed query on a new connection, without its lock.
                mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                return $query;
            };
            self::$active_lock = ['dbh' => $dbh, 'name' => $name, 'report_mode' => $report_mode, 'fence' => $fence];
            add_filter('query', $fence, PHP_INT_MAX);
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            if (!self::$shutdown_registered) {
                $cleanup = static function (): void { self::release_active_lock(); };
                add_action('shutdown', $cleanup, PHP_INT_MIN);
                register_shutdown_function($cleanup);
                self::$shutdown_registered = true;
            }
        } elseif ((self::$active_lock['name'] ?? '') !== $name) {
            return new WP_Error('admetrics_settings_database', 'Nested updates across different shops are not supported.', ['status' => 503]);
        }
        self::$lock_depth++;
        try { return $callback(); }
        catch (Throwable $error) {
            error_log('Admetrics tracking database operation failed (' . get_class($error) . ').');
            return new WP_Error('admetrics_settings_database', 'Tracking settings could not be saved. Please retry.', ['status' => 503]);
        }
        finally {
            self::$lock_depth--;
            if (self::$lock_depth === 0) { self::release_active_lock(); }
        }
    }

    private static function release_active_lock(): void
    {
        $lock = self::$active_lock;
        if ($lock === null) { return; }
        self::$active_lock = null;
        self::$lock_depth = 0;
        remove_filter('query', $lock['fence'], PHP_INT_MAX);
        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            // Release on the original handle, never an automatically reconnected handle.
            mysqli_query($lock['dbh'], "SELECT RELEASE_LOCK('" . $lock['name'] . "')");
        } catch (Throwable $error) {
            // A closed connection has already lost its server-side locks.
        } finally { mysqli_report($lock['report_mode']); }
    }

    private static function authorize(WP_REST_Request $request, string $kind, ?string $raw = null): bool
    {
        $attributes = $request->get_attributes();
        $attributes['admetrics_auth_kind'] = $kind;
        $attributes['admetrics_authority'] = $raw;
        $request->set_attributes($attributes);
        return true;
    }

    public static function authorized_generation_is_current(WP_REST_Request $request): bool
    {
        $attributes = $request->get_attributes();
        if (($attributes['admetrics_auth_kind'] ?? '') === 'administrator') { return true; }
        $record = self::state_record();
        return is_string($attributes['admetrics_authority'] ?? null) && is_string($record['raw'])
            && hash_equals($attributes['admetrics_authority'], $record['raw']);
    }

    private static function token_for_state(array $state): string
    {
        return hash_hmac('sha256', $state['revision'] . ':' . $state['pairing_hash'], wp_salt('auth'));
    }

    private static function valid_credential(array $state): bool
    {
        return self::valid_state($state) && $state['mode'] === 'required'
            && is_string($state['token_hash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $state['token_hash']) === 1
            && is_string($state['pairing_hash'] ?? null) && preg_match('/\A[a-f0-9]{64}\z/', $state['pairing_hash']) === 1
            && is_string($state['ds_id'] ?? null) && $state['ds_id'] !== '';
    }

    public static function info(): array
    {
        return [
            'protocol' => 2, 'home_url' => untrailingslashit(home_url()),
            'site_url' => untrailingslashit(site_url()), 'settings_url' => self::settings_url(),
            'rest_url' => untrailingslashit(rest_url('admetrics-data-studio/v1')),
        ];
    }

    // Overrides are server configuration, never accepted from a request or tracking settings.
    public static function api_origin(): string
    {
        return defined('ADMETRICS_API_ORIGIN') ? rtrim(ADMETRICS_API_ORIGIN, '/') : 'https://datasource-api.app.admetrics.io';
    }

    public static function app_origin(): string
    {
        return defined('ADMETRICS_APP_ORIGIN') ? rtrim(ADMETRICS_APP_ORIGIN, '/') : 'https://app.admetrics.io';
    }

    public static function settings_url(): string
    {
        return admin_url('admin.php?page=wc-settings&tab=integration&section=admetrics_data_studio');
    }

    public static function dashboard_url(string $location, array $params = []): string
    {
        // The dashboard decodes `loc` and forwards only `q_` parameters to its iframe.
        $query = ['loc' => rawurlencode($location)];
        foreach ($params as $name => $value) {
            $query['q_' . $name] = rawurlencode($value);
        }
        return self::app_origin() . '/apps/datasource/?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function owner_can_manage(array $connection): bool
    {
        $id = $connection['user_id'] ?? 0;
        if (!is_int($id) || $id < 1) { return false; }
        $user = get_user_by('id', $id);
        return $user && user_can($user, 'manage_woocommerce');
    }

    public static function confirmation(array $connection): array
    {
        $confirmation = get_option(self::CONFIRMATION_OPTION, []);
        if (!is_array($confirmation) || !is_string($connection['token_hash'] ?? null)
            || ($confirmation['token_hash'] ?? '') !== $connection['token_hash']
            || !is_int($confirmation['confirmed_at'] ?? null) || $confirmation['confirmed_at'] < 1) {
            return [];
        }
        if (isset($confirmation['last_sync_at']) && (!is_int($confirmation['last_sync_at']) || $confirmation['last_sync_at'] < 1)) {
            unset($confirmation['last_sync_at']);
        }
        return $confirmation;
    }

    public static function record_confirmation(string $token, bool $settings_synced = false): void
    {
        global $wpdb;
        $record = self::state_record();
        $connection = $record['state'];
        if (!preg_match('/\A[a-f0-9]{64}\z/', $token)
            || ($connection['token_hash'] ?? '') !== hash('sha256', $token)) {
            return;
        }
        $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::CONFIRMATION_OPTION));
        $previous = $raw === null ? [] : maybe_unserialize($raw);
        $confirmation = is_array($previous) && ($previous['token_hash'] ?? '') === $connection['token_hash'] ? $previous : [];
        $confirmation['token_hash'] = $connection['token_hash'];
        $confirmation['confirmed_at'] = $confirmation['confirmed_at'] ?? time();
        if ($settings_synced) {
            $confirmation['last_sync_at'] = time();
        }
        // Fence display metadata against both the authority generation and a concurrent newer
        // confirmation. A late response can neither resurrect access nor hide a newer success.
        if ($raw === null) {
            $changed = $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) SELECT %s,%s,'off' FROM {$wpdb->options} WHERE option_name=%s AND BINARY option_value=BINARY %s",
                self::CONFIRMATION_OPTION, maybe_serialize($confirmation), self::OPTION, $record['raw']
            ));
        } else {
            $changed = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} AS metadata JOIN {$wpdb->options} AS authority ON authority.option_name=%s SET metadata.option_value=%s WHERE metadata.option_name=%s AND BINARY authority.option_value=BINARY %s AND BINARY metadata.option_value=BINARY %s",
                self::OPTION, maybe_serialize($confirmation), self::CONFIRMATION_OPTION, $record['raw'], $raw
            ));
        }
        if ($changed === 1) {
            wp_cache_delete(self::CONFIRMATION_OPTION, 'options');
            wp_cache_delete('notoptions', 'options');
        }
    }

    public static function settings_permission(WP_REST_Request $request)
    {
        if ($request->get_header(self::HEADER) !== null) {
            return self::token_permission($request);
        }
        if (current_user_can('manage_woocommerce')) {
            return self::authorize($request, 'administrator');
        }
        $record = self::state_record();
        if (!is_user_logged_in() && self::valid_state($record['state']) && $record['state']['mode'] === 'legacy' && empty($record['state']['token_hash'])) {
            return self::authorize($request, 'legacy', $record['raw']);
        }
        return new WP_Error('admetrics_settings_forbidden', __('You are not allowed to update tracking settings.', 'admetrics'),
            ['status' => rest_authorization_required_code()]);
    }

    public static function token_permission(WP_REST_Request $request)
    {
        $token = $request->get_header(self::HEADER);
        $record = self::state_record();
        $connection = $record['state'];
        if (is_ssl()
            && self::valid_credential($connection)
            && is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token)
            && is_string($connection['token_hash'] ?? null)
            && hash_equals($connection['token_hash'], hash('sha256', $token))
            && self::owner_can_manage($connection)) {
            return self::authorize($request, 'token', $record['raw']);
        }
        return new WP_Error('admetrics_settings_connection_required',
            __('Connect the Admetrics plugin to authorize tracking-settings updates.', 'admetrics'), ['status' => 401]);
    }

    public static function verify(WP_REST_Request $request)
    {
        if (!self::authorized_generation_is_current($request)) {
            return new WP_Error('admetrics_settings_revoked', 'The tracking connection changed.', ['status' => 401]);
        }
        $connection = maybe_unserialize($request->get_attributes()['admetrics_authority']);
        return rest_ensure_response([
            'shop_url' => untrailingslashit(home_url()),
            'site_url' => untrailingslashit(site_url()),
            'ds_id' => $connection['ds_id'],
            'pairing_hash' => $connection['pairing_hash'],
        ]);
    }

    private static function api_request(string $path, array $body)
    {
        $url = self::api_origin() . '/api/1/dsa/woocommerce/settings-connection/' . $path;
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https') {
            return new WP_Error('admetrics_connection_https', 'The Admetrics connection requires HTTPS.');
        }
        $response = wp_remote_post($url, [
            'timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('admetrics_connection_failed', 'Admetrics could not be reached. Please try connecting again.');
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) !== 200 || !is_array($data) || ($data['success'] ?? false) !== true) {
            return new WP_Error('admetrics_connection_failed', 'The connection request is invalid or expired. Start again in Admetrics.');
        }
        return $data;
    }

    private static function pairing_details(string $ds_id, string $code)
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/', $code) || $ds_id === '') {
            return new WP_Error('admetrics_pairing_invalid', 'Invalid connection request.');
        }
        $details = self::api_request('details', ['ds_id' => $ds_id, 'code' => $code]);
        $identity = static function (string $url): string {
            $parts = wp_parse_url($url);
            return strtolower($parts['host'] ?? '')
                . (!empty($parts['port']) && !in_array($parts['port'], [80, 443], true) ? ':' . $parts['port'] : '')
                . untrailingslashit($parts['path'] ?? '');
        };
        if (!is_wp_error($details) && !in_array($identity($details['shop_url'] ?? ''), [
            $identity(home_url()), $identity(site_url()),
        ], true)) {
            return new WP_Error('admetrics_pairing_shop', 'This connection request belongs to a different shop.');
        }
        return $details;
    }

    public static function render(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $connection = get_option(self::OPTION, []);
        $connection = is_array($connection) ? $connection : [];
        $has_approval = self::valid_credential($connection);
        $confirmation = self::confirmation($connection);
        echo '<section id="admetrics-connection-status" aria-labelledby="admetrics-connection-heading">';
        echo '<h3 id="admetrics-connection-heading">' . esc_html__('Connection', 'admetrics') . '</h3>';
        if (!$has_approval && !self::authentication_required()) {
            $status = __('Legacy connection', 'admetrics');
            $description = __('Settings updates do not require authentication yet. Secure the connection to enable authorization.', 'admetrics');
        } elseif (!$has_approval) {
            $status = __('Not connected', 'admetrics');
            $description = __('Connect this shop to manage its tracking settings in Admetrics. Your saved tracking configuration remains in place.', 'admetrics');
        } elseif (!self::owner_can_manage($connection)) {
            $status = __('Reconnect required', 'admetrics');
            $description = __('The approving WordPress account can no longer manage WooCommerce settings. Reconnect using an authorized shop account.', 'admetrics');
        } elseif (empty($confirmation['confirmed_at'])) {
            $status = __('Awaiting confirmation', 'admetrics');
            $description = __('Approval saved. Waiting for verification and settings delivery. Reconnect if this stays pending.', 'admetrics');
        } else {
            $status = __('Connected', 'admetrics');
            $description = '';
        }
        echo '<p><strong>' . esc_html($status) . '</strong></p>';
        if ($description !== '') {
            echo '<p>' . esc_html($description) . '</p>';
        }
        if ($has_approval) {
            echo '<dl><dt><strong>' . esc_html__('Admetrics client', 'admetrics') . '</strong></dt><dd>'
                . esc_html($connection['client_name'] ?? __('Not recorded', 'admetrics')) . '</dd>';
            echo '<dt><strong>' . esc_html__('Datasource', 'admetrics') . '</strong></dt><dd>'
                . esc_html($connection['data_source_name'] ?? $connection['ds_id'] ?? __('Not recorded', 'admetrics')) . '</dd></dl>';
            echo '<button type="submit" class="button" form="admetrics-disconnect">' . esc_html__('Disconnect tracking settings', 'admetrics') . '</button>';
            // WooCommerce wraps this screen in a settings form. Emit separate action forms in the footer.
            add_action('admin_footer', function () use ($connection) {
                echo '<form id="admetrics-disconnect" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                echo '<input type="hidden" name="action" value="admetrics_settings_disconnect">';
                echo '<input type="hidden" name="revision" value="' . esc_attr($connection['revision'] ?? '') . '">';
                wp_nonce_field('admetrics_settings_disconnect:' . ($connection['revision'] ?? ''));
                echo '</form>';
            });
        }
        $code = isset($_GET['admetrics_pairing']) && is_string($_GET['admetrics_pairing']) ? wp_unslash($_GET['admetrics_pairing']) : '';
        $ds_id = isset($_GET['admetrics_ds_id']) && is_string($_GET['admetrics_ds_id']) ? sanitize_text_field(wp_unslash($_GET['admetrics_ds_id'])) : '';
        $details = $code !== '' ? self::pairing_details($ds_id, $code) : null;
        // A valid approval request already completed shop selection in Admetrics.
        // Keep one primary next step instead of offering to restart the same flow.
        if ($details === null || is_wp_error($details)) {
            $url = self::dashboard_url('/woocommerce-settings', [
                'shop' => untrailingslashit(home_url()), 'wordpress' => untrailingslashit(site_url()),
            ]);
            $label = !self::authentication_required() ? __('Secure connection', 'admetrics')
                : ($has_approval ? __('Reconnect to Admetrics', 'admetrics') : __('Connect to Admetrics', 'admetrics'));
            echo '<p><a class="button button-primary" href="' . esc_url($url) . '">'
                . esc_html($label) . '</a></p>';
        }
        echo '<p>' . esc_html__('This connection lets Admetrics update its tracking configuration, including the tracking script. Order imports keep their separate read-only WooCommerce access.', 'admetrics') . '</p>';
        echo '</section>';

        if ($code === '') {
            return;
        }
        if (is_wp_error($details)) {
            echo '<p role="alert">' . esc_html($details->get_error_message()) . '</p>';
            return;
        }
        echo '<p>' . esc_html(sprintf(__('Allow %s (%s) to update Admetrics tracking settings, including the tracking script?', 'admetrics'), $details['client_name'], $details['data_source_name'] ?? $ds_id)) . '</p>';
        echo '<button type="submit" class="button button-primary" form="admetrics-connect">' . esc_html__('Approve tracking-settings connection', 'admetrics') . '</button>';
        $revision = $connection['revision'] ?? '';
        add_action('admin_footer', function () use ($ds_id, $code, $revision) {
            echo '<form id="admetrics-connect" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="admetrics_settings_connect">';
            echo '<input type="hidden" name="ds_id" value="' . esc_attr($ds_id) . '">';
            echo '<input type="hidden" name="code" value="' . esc_attr($code) . '">';
            echo '<input type="hidden" name="revision" value="' . esc_attr($revision) . '">';
            wp_nonce_field('admetrics_settings_connect:' . $ds_id . ':' . $code . ':' . $revision);
            echo '</form>';
        });
    }

    public static function approve(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You are not allowed to connect tracking settings.', '', ['response' => 403]);
        }
        $ds_id = isset($_POST['ds_id']) && is_string($_POST['ds_id']) ? sanitize_text_field(wp_unslash($_POST['ds_id'])) : '';
        $code = isset($_POST['code']) && is_string($_POST['code']) ? wp_unslash($_POST['code']) : '';
        $revision = isset($_POST['revision']) && is_string($_POST['revision']) ? wp_unslash($_POST['revision']) : '';
        check_admin_referer('admetrics_settings_connect:' . $ds_id . ':' . $code . ':' . $revision);
        $record = self::state_record();
        $same = static function (array $state) use ($ds_id, $code): bool {
            return self::valid_credential($state) && ($state['ds_id'] ?? '') === $ds_id && ($state['pairing_hash'] ?? '') === hash('sha256', $code)
                && ($state['user_id'] ?? 0) === get_current_user_id() && !empty($state['token_hash']);
        };
        if (!$same($record['state'])) {
            if (($record['state']['revision'] ?? '') !== $revision) {
                wp_die('The connection changed. Reload settings and start again.', '', ['response' => 409]);
            }
            $details = self::pairing_details($ds_id, $code);
            if (is_wp_error($details)) {
                wp_die(esc_html($details->get_error_message()), '', ['response' => 400]);
            }
            $state = [
                'mode' => 'required', 'revision' => bin2hex(random_bytes(32)), 'ds_id' => $ds_id,
                'pairing_hash' => hash('sha256', $code), 'user_id' => get_current_user_id(),
                'client_name' => sanitize_text_field($details['client_name']),
                'data_source_name' => sanitize_text_field($details['data_source_name'] ?? $ds_id),
            ];
            $state['token_hash'] = hash('sha256', self::token_for_state($state));
            if (!self::replace_state($record['raw'], $state)) {
                $record = self::state_record();
                if (!$same($record['state'])) {
                    wp_die('The connection changed. Reload settings and start again.', '', ['response' => 409]);
                }
            }
            $record = self::state_record();
        }
        if (!$same($record['state'])) {
            wp_die('The connection changed. Reload settings and start again.', '', ['response' => 409]);
        }
        $token = self::token_for_state($record['state']);
        if (!hash_equals($record['state']['token_hash'], hash('sha256', $token))) {
            wp_die('The shop security keys changed. Start a new connection.', '', ['response' => 409]);
        }
        $result = self::api_request('complete', ['ds_id' => $ds_id, 'code' => $code, 'token' => $token]);
        if (!is_wp_error($result) && ($result['confirmed'] ?? false) === true) {
            self::record_confirmation($token);
        }
        wp_safe_redirect(self::settings_url());
        exit;
    }

    public static function disconnect(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You are not allowed to disconnect tracking settings.', '', ['response' => 403]);
        }
        $revision = isset($_POST['revision']) && is_string($_POST['revision']) ? wp_unslash($_POST['revision']) : '';
        check_admin_referer('admetrics_settings_disconnect:' . $revision);
        $record = self::state_record();
        if (($record['state']['revision'] ?? '') !== $revision || !self::replace_state($record['raw'], [
            'mode' => 'required', 'revision' => bin2hex(random_bytes(32)),
        ])) {
            wp_die('The connection changed. Reload settings and try disconnecting again.', '', ['response' => 409]);
        }
        delete_option(self::CONFIRMATION_OPTION);
        wp_safe_redirect(self::settings_url());
        exit;
    }
}
