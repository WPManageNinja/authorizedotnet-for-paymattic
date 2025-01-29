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
        parent::__construct('authorizedotnet_gateway_element', 12);

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
            'editor_title' => 'AuthorizeDotNet Payment',
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
        do_action('wppayform_load_checkout_js_authorizedotnet');

        if (!$this->validateApi()) { ?>
            <p style="color: red">You did not configure AtuhorizeDotNet payment gateway. Please configure authorizedotnet payment
                gateway from <b>Paymattic->Payment Gateway->AtuhorizeDotNet Settings</b> to start accepting payments</p>
<?php return;
        }

        $fieldOptions = Arr::get($element, 'field_options', false);
        if (!$fieldOptions) {
            return;
        }

        $authSettings = new AuthorizeDotNetSettings();

        $settings = $authSettings->getSettings();
        $isLive = $authSettings::isLive();

        $acceptJs = '';
        if ($isLive) {
            $acceptJs =  'https://js.authorize.net/v3/AcceptUI.js';
        } else {
            $acceptJs = 'https://jstest.authorize.net/v3/AcceptUI.js';
        }

        $clientKey = (new AuthorizeDotNetSettings())->getClientKey();
        $apiLoginId = (new AuthorizeDotNetSettings())->getApiLoginId();

        // wp_enqueue_script('wpf_authorize_accept_js', $acceptJs, array('jquery'), '3.0', true);
        $attributes = array(
            'data-apiLoginID' => $apiLoginId,
            'data-clientKey' => $clientKey,
            'data-acceptUIFormBtnTxt' => $settings['button_text'] ?? 'Pay with AtuhorizeDotNet',
            'data-acceptUIFormHeaderTxt' => "Card Information",
            'data-paymentOptions' => '{"showCreditCard": true}',
            'data-responseHandler'=> "responseHandler",
            'class' => 'AcceptUI',
            'style' => 'display:none',
        ); ?>
       <div class="wpf_form_group wpf_item_<?php echo esc_attr($element['id']); ?>">
        <input type="hidden" name="dataValue" id="dataValue" />
        <input type="hidden" name="dataDescriptor" id="dataDescriptor" />
        <button <?php $this->printAttributes($attributes); ?>></button>
        <div class="wpf_authorize-errors" role="alert"></div>
    </div>
<?php

        echo '<input data-wpf_payment_method="authorizedotnet" type="hidden" name="__authorizedotnet_payment_gateway" value="authorizedotnet" />';

        wp_enqueue_script('wpf_authorize_accept_js', $acceptJs , array(), false, array());
    }

    public function renderForMultiple($paymentSettings, $form, $elements)
    {
        do_action('wppayform_load_checkout_js_authorizedotnet');
    
        $component = $this->component();
        $component['id'] = 'authorizedotnet_gateway_element';
        $component['field_options'] = $paymentSettings;
        $this->render($component, $form, $elements);
    }
}
