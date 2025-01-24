<?php

namespace AuthorizeDotNetForPaymattic\Settings;

use WPPayForm\App\Modules\FormComponents\BaseComponent;
use WPPayForm\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

class AuthorizeDotNetElement extends BaseComponent
{
    public $gateWayName = 'authorizedotnet';

    public function __construct()
    {
        parent::__construct('authorizedotnet_gateway_element', 8);

        add_action('wppayform/validate_gateway_api_' . $this->gateWayName, array($this, 'validateApi'));
        add_filter('wppayform/validate_gateway_api_' . $this->gateWayName, function ($data, $form) {
            return $this->validateApi();
        }, 2, 10);
        add_action('wppayform/payment_method_choose_element_render_authorizedotnet', array($this, 'renderForMultiple'), 10, 3);
        add_filter('wppayform/available_payment_methods', array($this, 'pushPaymentMethod'), 2, 1);
    }

    public function pushPaymentMethod($methods)
    {
        $methods['authorizedotnet'] = array(
            'label' => 'authorizedotnet',
            'isActive' => true,
            'logo' => AuthorizeDotNet_FOR_PAYMATTIC_URL . 'assets/authorizedotnet.svg',
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Payment Option Label',
                    'type' => 'text',
                    'default' => 'Pay with authorizedotnet'
                )
            )
        );
        return $methods;
    }


    public function component()
    {
        return array(
            'type' => 'authorizedotnet_gateway_element',
            'editor_title' => 'AtuhorizeDotNet Payment',
            'editor_icon' => '',
            'conditional_hide' => true,
            'group' => 'payment_method_element',
            'method_handler' => $this->gateWayName,
            'postion_group' => 'payment_method',
            'single_only' => true,
            'editor_elements' => array(
                'label' => array(
                    'label' => 'Field Label',
                    'type' => 'text'
                )
            ),
            'field_options' => array(
                'label' => __('AtuhorizeDotNet Payment Gateway', 'authorizedotnet-payment-for-paymattic')
            )
        );
    }

    public function validateApi()
    {
        $authorizedotnet = new AuthorizeDotNetSettings();
        return $authorizedotnet->getApiLoginId();
    }

    public function render($element, $form, $elements)
    {
        if (!$this->validateApi()) { ?>
            <p style="color: red">You did not configure AtuhorizeDotNet payment gateway. Please configure authorizedotnet payment
                gateway from <b>Paymattic->Payment Gateway->AtuhorizeDotNet Settings</b> to start accepting payments</p>
<?php return;
        }

        echo '<input data-wpf_payment_method="authorizedotnet" type="hidden" name="__authorizedotnet_payment_gateway" value="authorizedotnet" />';
    }

    public function renderForMultiple($paymentSettings, $form, $elements)
    {
        $component = $this->component();
        $component['id'] = 'authorizedotnet_gateway_element';
        $component['field_options'] = $paymentSettings;
        $this->render($component, $form, $elements);
    }
}
