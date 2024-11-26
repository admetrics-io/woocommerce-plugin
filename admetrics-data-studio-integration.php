<?php

if (!class_exists('AdmetricsDataStudio_Integration')) {

    class AdmetricsDataStudio_Integration extends WC_Integration
    {
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
        private $ss_scpid = "";

        /**
         * Init and hook in the integration.
         */
        public function __construct()
        {
            global $woocommerce;

            $this->id = 'admetrics_data_studio';
            $this->method_title = "Admetrics Data Studio";

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables.
            $this->enabled = $this->get_option('enabled');
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
            $this->ss_scpid = $this->get_option('ss_scpid');

            // Actions.
            add_action('woocommerce_update_options_integration_' . $this->id, array($this, 'process_admin_options'));
        }

        /**
         * Initialize integration settings form fields.
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
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
                'ss_scpid' => array(
                    'title' => 'SS SCPID',
                    'type' => 'text',
                    'default' => '',
                    'disabled' => true
                ),
            );
        }

        public static function update_settings($request)
        {
            $settings = $request->get_json_params();

            $integration_class = new AdmetricsDataStudio_Integration();
            $option_key = $integration_class->get_option_key();

            // Get all current settings
            $current_settings = get_option($option_key, array());

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
            update_option($option_key, $current_settings);

            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Settings updated successfully.',
                'updated_settings' => $updated_settings,
            ));
        }
    }
}

