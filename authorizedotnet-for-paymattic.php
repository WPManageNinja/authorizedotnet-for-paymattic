<?php

/**
 * @package authorizedotnet-for-paymattic
 * 
 */

/** 
 * Plugin Name: AtuhorizeDotNet For Paymattic
 * Plugin URI: https://paymattic.com/
 * Description: AtuhorizeDotNet payment gateway for paymattic. AtuhorizeDotNet is the one of leading payment gateway in United States, Canada, Australia and United Kingdom and Europe.
 * Version: 1.0.2
 * Author: WPManageNinja LLC
 * Author URI: https://paymattic.com/
 * License: GPLv2 or later
 * Text Domain: authorizedotnet-for-paymattic
 * Domain Path: /language
 */

if (!defined('ABSPATH')) {
    exit;
}

defined('ABSPATH') or die;

define('AuthorizeDotNet_FOR_PAYMATTIC', true);
define('AuthorizeDotNet_FOR_PAYMATTIC_DIR', __DIR__);
define('AuthorizeDotNet_FOR_PAYMATTIC_URL', plugin_dir_url(__FILE__));
define('AuthorizeDotNet_FOR_PAYMATTIC_VERSION', '1.0.2');


if (!class_exists('AtuhorizeDotNetForPaymattic')) {
    class AtuhorizeDotNetForPaymattic
    {
        public function boot()
        {
            if (!class_exists('AtuhorizeDotNetPaymentForPaymattic\API\AtuhorizeDotNetProcessor')) {
                $this->init();
            };
        }

        public function init()
        {
            require_once AuthorizeDotNet_FOR_PAYMATTIC_DIR . '/API/AtuhorizeDotNetProcessor.php';
            (new AuthorizeDotNetForPaymattic\API\AuthorizeDotNetProcessor())->init();

            $this->loadTextDomain();
        }

        public function loadTextDomain()
        {
            load_plugin_textdomain('authorizedotnet-for-paymattic', false, dirname(plugin_basename(__FILE__)) . '/language');
        }

        public function hasPro()
        {
            return defined('WPPAYFORMPRO_DIR_PATH') || defined('WPPAYFORMPRO_VERSION');
        }

        public function hasFree()
        {

            return defined('WPPAYFORM_VERSION');
        }

        public function versionCheck()
        {
            $currentFreeVersion = WPPAYFORM_VERSION;
            $currentProVersion = WPPAYFORMPRO_VERSION;

            return version_compare($currentFreeVersion, '4.3.2', '>=') && version_compare($currentProVersion, '4.3.2', '>=');
        }

        public function renderNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please install & Activate Paymattic and Paymattic Pro to use authorizedotnet-for-paymattic plugin.', 'authorizedotnet-for-paymattic');
                    echo '</p></div>';
                }
            });
        }

        public function updateVersionNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please update Paymattic and Paymattic Pro to use authorizedotnet-for-paymattic plugin!', 'authorizedotnet-for-paymattic');
                    echo '</p></div>';
                }
            });
        }
    }

    add_action('init', function () {

        $AtuhorizeDotNet = (new AtuhorizeDotNetForPaymattic);

        if (!$AtuhorizeDotNet->hasFree() || !$AtuhorizeDotNet->hasPro()) {
            $AtuhorizeDotNet->renderNotice();
        } else if (!$AtuhorizeDotNet->versionCheck()) {
            $AtuhorizeDotNet->updateVersionNotice();
        } else {
            $AtuhorizeDotNet->boot();
        }
    });
}
