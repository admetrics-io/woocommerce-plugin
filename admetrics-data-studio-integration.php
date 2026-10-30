<?php

if (!class_exists('AdmetricsDataStudio_Integration')) {

    class AdmetricsDataStudio_Integration extends WC_Integration
    {
        private $tracking_enabled = "";
        private $sid = "";
        private $src = "";
        private $endpoint = "";
        private $cn = "";
        private $cv = "";
        private $cv2 = "";
        private $pa_vendor = "";
        private $pa_mpid = "";
        private $ss_mpid = "";
        private $ss_tkpid = "";
        private $ss_ob = "-";
        private $ss_ga = "-";

        /**
         * Init and hook in the integration.
         */
        public function __construct()
        {
            $this->id = 'admetrics_data_studio';
            $this->method_title = "Admetrics Data Studio";

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables.
            $this->tracking_enabled = $this->get_option('tracking_enabled');
            $this->sid = $this->get_option('sid');
            $this->src = $this->get_option('src');
            $this->endpoint = $this->get_option('endpoint');
            $this->cn = $this->get_option('cn');
            $this->cv = $this->get_option('cv');
            $this->cv2 = $this->get_option('cv2');
            $this->pa_vendor = $this->get_option('pa_vendor');
            $this->pa_mpid = $this->get_option('pa_mpid');
            $this->ss_mpid = $this->get_option('ss_mpid');
            $this->ss_tkpid = $this->get_option('ss_tkpid');
            $this->ss_ob = $this->get_option('ss_ob');
            $this->ss_ga = $this->get_option('ss_ga');

            // Tracking settings are managed through the authorized REST endpoint, not the
            // read-only WooCommerce screen. Do not register the generic settings-save action.
        }

        /**
         * Initialize integration settings form fields.
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'tracking_enabled' => array(
                    'title' => 'Enabled',
                    'type' => 'checkbox',
                    'default' => 'no',
                    'disabled' => true
                ),
                'sid' => array(
                    'title' => 'Shop ID',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'src' => array(
                    'title' => 'Source',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'endpoint' => array(
                    'title' => 'Endpoint',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'cn' => array(
                    'title' => 'CN',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'cv' => array(
                    'title' => 'CV',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'cv2' => array(
                    'title' => 'CV2',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'pa_vendor' => array(
                    'title' => 'PA Vendor',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'pa_mpid' => array(
                    'title' => 'PA MPID',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'ss_mpid' => array(
                    'title' => 'SS MPID',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'ss_tkpid' => array(
                    'title' => 'SS TKPID',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
                'ss_ob' => array(
                    'title' => 'SS OB',
                    'type' => 'text',
                    'default' => '-',
                    'disabled' => true
                ),
                'ss_ga' => array(
                    'title' => 'SS GA',
                    'type' => 'text',
                    'default' => '-',
                    'disabled' => true
                ),
            );
        }

        public function admin_options()
        {
            $GLOBALS['hide_save_button'] = true;
            echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';
            AdmetricsDataStudio_Connection::render();
            $settings = get_option($this->get_option_key(), []);
            $connection = get_option(AdmetricsDataStudio_Connection::OPTION, []);
            $confirmation = AdmetricsDataStudio_Connection::confirmation($connection);
            $tracking_enabled = !empty($settings['tracking_enabled']) && $settings['tracking_enabled'] !== 'no';
            echo '<section id="admetrics-tracking-status" aria-labelledby="admetrics-tracking-heading">';
            echo '<h3 id="admetrics-tracking-heading">' . esc_html__('Tracking', 'admetrics') . '</h3>';
            echo '<p><strong>' . esc_html($tracking_enabled ? __('Enabled', 'admetrics') : __('Disabled', 'admetrics')) . '</strong></p>';
            echo '<p>' . esc_html__('Tracking uses the configuration saved in this shop. Manage changes in Admetrics.', 'admetrics') . '</p>';
            if (!empty($connection['token_hash'])) {
                echo '<p>' . esc_html__('Last settings update:', 'admetrics') . ' ';
                echo !empty($confirmation['last_sync_at'])
                    ? esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $confirmation['last_sync_at']))
                    : esc_html__('Not yet received', 'admetrics');
                echo '</p>';
            }
            $manage_url = !empty($connection['ds_id'])
                ? AdmetricsDataStudio_Connection::dashboard_url('/auth/success', ['ds_id' => $connection['ds_id']])
                : AdmetricsDataStudio_Connection::dashboard_url('/');
            echo '<p><a href="' . esc_url($manage_url) . '" target="_blank" rel="noopener noreferrer" title="'
                . esc_attr__('Opens in a new tab', 'admetrics') . '">'
                . esc_html__('Manage tracking in Admetrics', 'admetrics') . '</a></p></section>';
            echo '<details id="admetrics-technical-details"><summary>' . esc_html__('Technical details', 'admetrics') . '</summary>';
            echo '<p>' . esc_html__('Read-only tracking configuration for troubleshooting.', 'admetrics') . '</p>';
            echo '<table class="widefat striped"><tbody>';
            if (!empty($connection['ds_id'])) {
                echo '<tr><th scope="row">' . esc_html__('Datasource ID', 'admetrics') . '</th><td><code>' . esc_html($connection['ds_id']) . '</code></td></tr>';
            }
            foreach ($this->get_form_fields() as $name => $field) {
                $value = $settings[$name] ?? $field['default'] ?? '';
                echo '<tr><th scope="row">' . esc_html($field['title']) . '</th><td><code>' . esc_html((string)$value) . '</code></td></tr>';
            }
            echo '</tbody></table></details>';
        }

        public static function update_settings($request)
        {
            $settings = $request->get_json_params();
            if (!is_array($settings) || !is_object(json_decode($request->get_body()))) {
                return new WP_Error('admetrics_settings_invalid', 'Settings must be a JSON object.', ['status' => 400]);
            }
            foreach ($settings as $value) {
                if (!is_scalar($value) && $value !== null) {
                    return new WP_Error('admetrics_settings_invalid', 'Settings values must be scalar.', ['status' => 400]);
                }
            }

            return AdmetricsDataStudio_Connection::with_state_lock(static function () use ($request, $settings) {
                return self::persist_settings($request, $settings);
            });
        }

        private static function persist_settings(WP_REST_Request $request, array $settings)
        {
            if (!AdmetricsDataStudio_Connection::authorized_generation_is_current($request)) {
                return new WP_Error('admetrics_settings_revoked', 'The tracking connection changed. Reconnect before updating settings.', ['status' => 401]);
            }
            $integration_class = new AdmetricsDataStudio_Integration();
            $option_key = $integration_class->get_option_key();
            // A previous request may have populated the options cache before this write acquired
            // the lock. Refresh it so concurrent partial updates cannot overwrite each other.
            wp_cache_delete($option_key, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');

            // Get all current settings
            $current_settings = get_option($option_key, array());
            $original_settings = $current_settings;

            $updated_settings = array();
            foreach ($settings as $setting_key => $setting_value) {
                // Sanitize and validate each field
                $sanitized_key = sanitize_text_field($setting_key);
                $sanitized_value = sanitize_text_field($setting_value);

                // Update the specific setting
                $current_settings[$sanitized_key] = $sanitized_value;
                $updated_settings[$sanitized_key] = $sanitized_value;
            }

            // Save the updated settings
            if ($current_settings !== $original_settings && !update_option($option_key, $current_settings)) {
                return new WP_Error('admetrics_settings_persistence_failed', 'Tracking settings could not be saved. Please retry.', ['status' => 503]);
            }
            $token = $request->get_header(AdmetricsDataStudio_Connection::HEADER);
            if (is_string($token)) {
                AdmetricsDataStudio_Connection::record_confirmation($token, true);
            }

            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Settings updated successfully.',
                'updated_settings' => $updated_settings,
            ));
        }
    }
}
