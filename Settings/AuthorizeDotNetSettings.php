<?php

namespace  AuthorizeDotNetForPaymattic\Settings;

use \WPPayForm\Framework\Support\Arr;
use \WPPayForm\App\Services\AccessControl;
use \WPPayFormPro\GateWays\BasePaymentMethod;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class AuthorizeDotNetSettings extends BasePaymentMethod
{
    /**
     * Automatically create global payment settings page
     * @param  String: key, title, routes_query, 'logo')
     */
    public function __construct()
    {
        parent::__construct(
            'authorizedotnet',
            'AuthorizeDotNet',
            [],
            AuthorizeDotNet_FOR_PAYMATTIC_URL . 'assets/authorizedotnet.svg' // follow naming convention of logo with lowercase exactly as payment key to avoid logo rendering hassle
        );
    }

    /**
     * @function mapperSettings, To map key => value before store
     * @function validateSettings, To validate before save settings
     */

    public function init()
    {
        add_filter('wppayform_payment_method_settings_mapper_' . $this->key, array($this, 'mapperSettings'));
        add_filter('wppayform_payment_method_settings_validation_' . $this->key, array($this, 'validateSettings'), 10, 2);
    }

    public function mapperSettings($settings)
    {
        return $this->mapper(
            static::settingsKeys(),
            $settings,
            false
        );
    }

    /**
     * @return Array of default fields
     */
    public static function settingsKeys() : array
    {
        $slug = 'authorizedotnet-payment-for-paymattic';

        $updateAvailable = static::checkForUpdate($slug);
        return array(
            'payment_mode' => 'test',
            'test_api_key' => '',
            'live_api_key' => '',
            'invoice_duration' => 'none',
            'customer_notification_preference' => ['email'],
            'update_available' => $updateAvailable
        );
    }

    public static function checkForUpdate($slug)
    {
        $githubApi = "https://api.github.com/repos/WPManageNinja/{$slug}/releases";
        $result = array(
            'available' => 'no',
            'url' => '',
            'slug' => 'authorizedotnet-payment-for-paymattic'
        );

        $response = wp_remote_get($githubApi);
        $releases = json_decode($response['body']);
        if (isset($releases->documentation_url)) {
            return $result;
        }

        $latestRelease = $releases[0];
        $latestVersion = $latestRelease->tag_name;
        $zipUrl = $latestRelease->zipball_url;

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    
        $plugins = get_plugins();
        $currentVersion = '';

        // Check if the plugin is present
        foreach ($plugins as $plugin_file => $plugin_data) {
            // Check if the plugin slug or name matches
            if ($slug === $plugin_data['TextDomain'] || $slug === $plugin_data['Name']) {
                $currentVersion = $plugin_data['Version'];
            }
        }

        if (version_compare( $latestVersion, $currentVersion, '>')) {
            $result['available'] = 'yes';
            $result['url'] = $zipUrl;
        }

        return $result;
    }

    public static function getSettings()
    {
        $setting = get_option('wppayform_payment_settings_authorizedotnet', []);

        // Check especially only for addons
        $tempSettings = static::settingsKeys();
        if (isset($tempSettings['update_available'])) {
            $setting['update_available'] = $tempSettings['update_available'];
        }
        return wp_parse_args($setting, static::settingsKeys());
    }

    public function getPaymentSettings()
    {
        $settings = $this->mapper(
            $this->globalFields(),
            static::getSettings()
        );
        return array(
            'settings' => $settings
        );
    }

    /**
     * @return Array of global fields
     */
    public function globalFields() : array
    {
        return array(
            'payment_mode' => array(
                'value' => 'test',
                'label' => __('Payment Mode', 'authorizedotnet-payment-for-paymattic'),
                'options' => array(
                    'test' => __('Test Mode', 'authorizedotnet-payment-for-paymattic'),
                    'live' => __('Live Mode', 'authorizedotnet-payment-for-paymattic')
                ),
                'type' => 'payment_mode'
            ),
            'test_api_key' => array(
                'value' => '',
                'label' => __('Test Secret Key', 'authorizedotnet-payment-for-paymattic'),
                'type' => 'test_secret_key',
                'placeholder' => __('Test Secret Key', 'authorizedotnet-payment-for-paymattic')
            ),
            'live_api_key' => array(
                'value' => '',
                'label' => __('Live Secret Key', 'authorizedotnet-payment-for-paymattic'),
                'type' => 'live_secret_key',
                'placeholder' => __('Live Secret Key', 'authorizedotnet-payment-for-paymattic')
            ),
            'invoice_duration' => array(
                'value' => '' ,
                'label' => __('Invoice Duration in', 'authorizedotnet-payment-for-paymattic'),
                'type' => 'dropdown',
                'options' => array(
                    'none' => __('None', 'authorizedotnet-payment-for-paymattic'),
                    '2' => __('2 Hours', 'authorizedotnet-payment-for-paymattic'),
                    '4' => __('4 Hours', 'authorizedotnet-payment-for-paymattic'),
                    '6' => __('6 Hours', 'authorizedotnet-payment-for-paymattic'),
                    '8' => __('8 Hours', 'authorizedotnet-payment-for-paymattic'),
                    '12' => __('12 Hours', 'authorizedotnet-payment-for-paymattic'),
                    '24' => __('24 Hours (Default)', 'authorizedotnet-payment-for-paymattic'),
                ),
                'tooltip' => "<span>Select the duration of the invoice (Optional), </br> Select 'None' If you want/have configuration in authorizedotnet dashboard for invoice duration</span>",
            ),
            'customer_notification_preference' => array(
                'value' => ['email'],
                'label' => __('Customer Notification Preference', 'authorizedotnet-payment-for-paymattic'),
                'options' => array(
                    'whatsapp' => __('Whatsapp', 'authorizedotnet-payment-for-paymattic'),
                    'email' => __('Email', 'authorizedotnet-payment-for-paymattic'),
                    'viber' => __('Viber', 'authorizedotnet-payment-for-paymattic')
                ),
                'tooltip' => "<span>Select the notification preference of your choice for the customer </span>",
                'type' => 'multiple_checkbox',
            ),
            'desc' => array(
                'value' => '<p>See our <a href="https://paymattic.com/docs/how-to-integrate-authorizedotnet-in-wordpress" target="_blank" rel="noopener">documentation</a> to get more information about authorizedotnet setup.</p>',
                'type' => 'html_attr',
                'placeholder' => __('Description', 'authorizedotnet-payment-for-paymattic')
            ),
            'webhook_desc' => array(
                'value' => "<h3><span style='color: #ef680e; margin-right: 2px'>*</span>Requred authorizedotnet Webhook Setup </h3> <p>In order for authorizedotnet to function completely for payments, you must configure your authorizedotnet webhooks. Visit your <a href='https://dashboard.authorizedotnet.co/settings/developers#callbacks' target='_blank' rel='noopener'>account dashboard</a> to configure them. Please add a webhook endpoint for the URL below. </p> <p><b>Webhook URL: </b><code> " . site_url('?wpf_authorizedotnet_listener=1') . "</code></p> <p>See <a href='https://paymattic.com/docs/how-to-integrate-authorizedotnet-in-wordpress#webhook' target='_blank' rel='noopener'>our documentation</a> for more information.</p> <div> <p><b>Please subscribe to these following Webhook events for this URL:</b></p> <ul> <li><code>Invoices paid</code></li></ul> </div>",
                'label' => __('Webhook URL', 'authorizedotnet-payment-for-paymattic'),
                'type' => 'html_attr',
            ),
            'is_pro_item' => array(
                'value' => 'yes',
                'label' => __('authorizedotnet', 'authorizedotnet-payment-for-paymattic'),
            ),
            'update_available' => array(
                'value' => array(
                    'available' => 'no',
                    'url' => '',
                    'slug' => 'authorizedotnet-payment-for-paymattic'
                ),
                'type' => 'update_check',
                'label' => __('Update to new version avaiable', 'authorizedotnet-payment-for-paymattic'),
            )
        );
    }

    public function validateSettings($errors, $settings)
    {
        AccessControl::checkAndPresponseError('set_payment_settings', 'global');
        $mode = Arr::get($settings, 'payment_mode');

        if ($mode == 'test') {
            if (empty(Arr::get($settings, 'test_api_key'))) {
                $errors['test_api_key'] = __('Please provide Test Secret Key', 'authorizedotnet-payment-for-paymattic');
            }
        }

        if ($mode == 'live') {
            if (empty(Arr::get($settings, 'live_api_key'))) {
                $errors['live_api_key'] = __('Please provide Live Secret Key', 'authorizedotnet-payment-for-paymattic');
            }
        }
        return $errors;
    }

    public function isLive($formId = false)
    {
        $settings = $this->getSettings();
        return $settings['payment_mode'] == 'live';
    }

    public function getApiKey($formId = false)
    {
        $isLive = $this->isLive($formId);
        $settings = $this->getSettings();

        if ($isLive) {
            return $settings['live_api_key'];
        }

        return $settings['test_api_key'];
    }
}
