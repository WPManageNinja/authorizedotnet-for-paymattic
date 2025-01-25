<?php

namespace AuthorizeDotNetForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Services\PlaceholderParser;
use WPPayForm\App\Services\ConfirmationHelper;
use WPPayForm\App\Models\SubmissionActivity;

// can't use namespace as these files are not accessible yet
require_once AuthorizeDotNet_FOR_PAYMATTIC_DIR . '/Settings/AuthorizeDotNetElement.php';
require_once AuthorizeDotNet_FOR_PAYMATTIC_DIR . '/Settings/AuthorizeDotNetSettings.php';
require_once AuthorizeDotNet_FOR_PAYMATTIC_DIR . '/API/IPN.php';
require_once AuthorizeDotNet_FOR_PAYMATTIC_DIR . '/API/API.php';


class AuthorizeDotNetProcessor
{
    public $method = 'authorizedotnet';

    protected $form;

    public function init()
    {
        new  \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetElement();
        (new  \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings())->init();
        (new \AuthorizeDotNetForPaymattic\API\IPN())->init();
        (new \AuthorizeDotNetForPaymattic\API\API()); 

        add_filter('wppayform/choose_payment_method_for_submission', array($this, 'choosePaymentMethod'), 10, 4);
        add_action('wppayform/form_submission_make_payment_authorizedotnet', array($this, 'makeFormPayment'), 10, 6);
        // add_action('wppayform_payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));
        add_filter('wppayform/entry_transactions_' . $this->method, array($this, 'addTransactionUrl'), 10, 2);
        // add_action('wppayform_ipn_AuthorizeDotNet_action_refunded', array($this, 'handleRefund'), 10, 3);
        add_filter('wppayform/submitted_payment_items_' . $this->method, array($this, 'validateSubscription'), 10, 4);
        add_action('wppayform_load_checkout_js_' . $this->method, array($this, 'addCheckoutJs'), 10, 3);
    }



    protected function getPaymentMode($formId = false)
    {
        $isLive = (new \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings())->isLive($formId);

        if ($isLive) {
            return 'live';
        }
        return 'test';
    }

    public function addTransactionUrl($transactions, $submissionId)
    {
        foreach ($transactions as $transaction) {
            if ($transaction->payment_method == 'AuthorizeDotNet' && $transaction->charge_id) {
                $transactionUrl = Arr::get(unserialize($transaction->payment_note), '_links.dashboard.href');
                $transaction->transaction_url =  $transactionUrl;
            }
        }
        return $transactions;
    }

    public function choosePaymentMethod($paymentMethod, $elements, $formId, $form_data)
    {
        if ($paymentMethod) {
            // Already someone choose that it's their payment method
            return $paymentMethod;
        }
        // Now We have to analyze the elements and return our payment method
        foreach ($elements as $element) {
            if ((isset($element['type']) && $element['type'] == 'AuthorizeDotNet_gateway_element')) {
                return 'AuthorizeDotNet';
            }
        }
        return $paymentMethod;
    }

    public function makeFormPayment($transactionId, $submissionId, $form_data, $form, $hasSubscriptions)
    {
        $paymentMode = $this->getPaymentMode();

        $transactionModel = new Transaction();
        if ($transactionId) {
            $transactionModel->updateTransaction($transactionId, array(
                'payment_mode' => $paymentMode
            ));
        }
        $transaction = $transactionModel->getTransaction($transactionId);

        $submission = (new Submission())->getSubmission($submissionId);
        $this->handleRedirect($transaction, $submission, $form, $paymentMode);
    }

    private function getSuccessURL($form, $submission)
    {
        // Check If the form settings have success URL
        $confirmation = Form::getConfirmationSettings($form->ID);
        $confirmation = ConfirmationHelper::parseConfirmation($confirmation, $submission);
        if (
            ($confirmation['redirectTo'] == 'customUrl' && $confirmation['customUrl']) ||
            ($confirmation['redirectTo'] == 'customPage' && $confirmation['customPage'])
        ) {
            if ($confirmation['redirectTo'] == 'customUrl') {
                $url = $confirmation['customUrl'];
            } else {
                $url = get_permalink(intval($confirmation['customPage']));
            }
            $url = add_query_arg(array(
                'payment_method' => 'AuthorizeDotNet'
            ), $url);
            return PlaceholderParser::parse($url, $submission);
        }
        // now we have to check for global Success Page
        $globalSettings = get_option('wppayform_confirmation_pages');
        if (isset($globalSettings['confirmation']) && $globalSettings['confirmation']) {
            return add_query_arg(array(
                'wpf_submission' => $submission->submission_hash,
                'payment_method' => 'AuthorizeDotNet'
            ), get_permalink(intval($globalSettings['confirmation'])));
        }
        // In case we don't have global settings
        return add_query_arg(array(
            'wpf_submission' => $submission->submission_hash,
            'payment_method' => 'AuthorizeDotNet'
        ), home_url());
    }

    public function handleRedirect($transaction, $submission, $form, $methodSettings)
    {
        $authArgs = $this->getAuthArgs($form->ID);
        // get MerchantDetails Request 
        $marchentArgs = array(
            'getMerchantDetailsRequest' => array(
                'merchantAuthentication' => $authArgs['merchantAuthentication']
            )
        );

        // $merchantDetails = (new API())->makeApiCall($marchentArgs, $form->ID, 'POST');
        // dd($merchantDetails);

        $name = $submission->customer_name ?? '';

        // getHostedPaymentPageRequest
        $hostedPaymentPageRequest = array(
            'getHostedPaymentPageRequest' => array(
                'merchantAuthentication' => $authArgs['merchantAuthentication'],
                'transactionRequest' => array(
                    'transactionType' => 'authCaptureTransaction',
                    'amount' => number_format($submission->payment_total / 100, 2),
                    'customer' => array(
                        'email' => $submission->customer_email ?? ''
                    ),
                    'billTo' => array(
                        'firstName' => 'dfds',
                        'lastName' => 'dfds fdg',
                    ),
                ),
                'hostedPaymentSettings' => array(
                    'setting' => array(
                        array(
                            'settingName' => 'hostedPaymentReturnOptions',
                            'settingValue' => '{"showReceipt": true, "url": "' . $this->getSuccessURL($form, $submission) . '", "urlText": "Continue", "cancelUrl": "' . $this->getSuccessURL($form, $submission) . '", "cancelUrlText": "Cancel"}'
                        ),
                        array(
                            'settingName' => 'hostedPaymentButtonOptions',
                            'settingValue' => '{"text": "Pay"}'
                        ),
                        // array(
                        //     'settingName' => 'hostedPaymentStyleOptions',
                        //     'settingValue' => '{\"bgColor\": \"#FF0000\"}'
                        // ),
                        // array(
                        //     'settingName' => 'hostedPaymentPaymentOptions',
                        //     'settingValue' => '{\"cardCodeRequired\": true, \"showCreditCard\": true, \"showBankAccount\": true}'
                        // ),
                        // array(
                        //     'settingName' => 'hostedPaymentSecurityOptions',
                        //     'settingValue' => '{\"captcha\": false}'
                        // ),
                        // array(
                        //     'settingName' => 'hostedPaymentShippingAddressOptions',
                        //     'settingValue' => '{\"show\": false, \"required\": false}'
                        // ),
                        // array(
                        //     'settingName' => 'hostedPaymentBillingAddressOptions',
                        //     'settingValue' => '{\"show\": true, \"required\": true}'
                        // ),
                        // array(
                        //     'settingName' => 'hostedPaymentCustomerOptions',
                        //     'settingValue' => '{\"showEmail\": true, \"requiredEmail\": true, \"addPaymentProfile\": true}'
                        // ),
                        // array(
                        //     'settingName' => 'hostedPaymentOrderOptions',
                        //     'settingValue' => '{\"show\": true, \"merchantName\": \"Paymattic\", \"description\": \"Payment for ' . $form->post_title . '\"}'
                        // ),
                    )
                )
            )
        );

        $response = (new API())->makeApiCall($hostedPaymentPageRequest, $form->ID, 'POST');
        
        if (isset($response['success']) && !$response['success']) {
            wp_send_json_error(array('message' => $response['msg']), 423);
        }

        $data = $response['data'];
        $formToken = $data['token'];


        
        
        if (isset($merchantDetails['success']) && !$merchantDetails['success']) {
            wp_send_json_error(array('message' => $merchantDetails['msg']), 423);
        }

        // $marchentDetails = Arr::get($merchantDetails, 'data');
        // $publicClientKey = $marchentDetails['publicClientKey'];

        if (!$formToken) {
            $submissionModel = new Submission();
            $submission = $submissionModel->getSubmission($transaction->submission_id);
            $submissionData = array(
                'payment_status' => 'failed',
                'updated_at' => current_time('Y-m-d H:i:s')
            );
            $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

            do_action('wppayform_log_data', [
                'form_id' => $submission->form_id,
                'submission_id' => $submission->id,
                'type' => 'activity',
                'created_by' => 'Paymattic BOT',
                'title' => 'AuthorizeDotNet Checkout is failed',
                'content' => 'AuthorizeDotNet Modal is failed to initiate, please check the logs for more information.'
            ]);

            do_action('wppayform/form_payment_failed', $submission, $submission->form_id, $transaction, 'moneris');
            wp_send_json([
                'errors'      => __('AuthorizeDotNet payment method failed to initiate', 'wp-payment-form-pro')
            ], 423);
        }

        // $checkoutData = [
        //     'clientKey' => $publicClientKey,
        //     'apiLoginID' => $authArgs['name'],
        //     'transactionKey' => $authArgs['transactionKey'],
        //     'environment' => $paymentMode == 'live' ? 'prod' : 'qa',
        //     'action' => 'receipt',
        //     'email'    => $submission->customer_email ? $submission->customer_email : 'moneris@example.com',
        //     'ref'      => $submission->submission_hash,
        //     'amount'   => $amount,
        //     'currency' => $currency, //
        //     'label'    => $form->post_title,
        //     'metadata' => [
        //         'payment_handler' => 'WPPayForm',
        //         'form_id'         => $form->ID,
        //         'transaction_id'  => $transaction->id,
        //         'submission_id'   => $submission->id,
        //         'form'            => $form->post_title
        //     ]
        // ];

        // dd($checkoutData);

        // $checkoutData = apply_filters('wppayform_moneris_checkout_data', $checkoutData, $submission, $transaction, $form, $form_data);

        do_action('wppayform_log_data', [
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'title' => 'Moneris Modal is initiated',
            'content' => 'Moneris Modal is initiated to complete the payment'
        ]);

        $confirmation = ConfirmationHelper::getFormConfirmation($submission->form_id, $submission);

        $scriptUrl = $this->getActionUrl();
        # Tell the client to handle the action
        wp_send_json_success([
            'nextAction'       => 'authorizedotnet',
            'actionName'       => 'initAuthorizeDotNetCheckout',
            'clientKey'      => '9shHK3NFLwHatCpQxbXF2W27fHE46qR6Ugn7zu3v635zjt722qt7y2VLpgPBF5Rm',
            'apiLoginID'       => $authArgs['merchantAuthentication']['name'],
            'formToken'        => $formToken,
            'scriptUrl'             => self::getAccpetJsUrl(),
            'actionUrl'      => $this->getActionUrl(),
            'submission_id'    => $submission->id,
            // 'checkout_data'       => $checkoutData,
            'transaction_hash' => $submission->submission_hash,
            'message'          => __('Authorize Do net Checkout button is loading. Please wait ....', 'wp-payment-form-pro'),
            'result'           => [
                'insert_id' => $submission->id
            ]
        ], 200);
    }

    public static function getAuthArgs($formId)
    {
        $apiLoginId = (new \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings())->getApiLoginId($formId);
        $transactionKey = (new \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings())->getTransactionKey($formId);

        if (!$apiLoginId || !$transactionKey) {
            return new \WP_Error(423, 'Authorize.Net API credentials are not set');
        }

        return array(
            'merchantAuthentication' => array(
                'name' => $apiLoginId,
                'transactionKey' => $transactionKey
            )
        );
    }

    public static function getAccpetJsUrl()
    {
        $isLive = (new \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings())::isLive();
        if ($isLive) {
            return 'https://js.authorize.net/v3/AcceptUI.js';
        } else {
            return 'https://jstest.authorize.net/v3/AcceptUI.js';
        }
    }

    public static function getActionUrl()
    {
        $isLive = (new \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings())::isLive();
        if ($isLive) {
            return 'https://accept.authorize.net/payment/payment';
        } else {
            return 'https://test.authorize.net/payment/payment';
        }
    }

    public function handleRefund($refundAmount, $submission, $vendorTransaction)
    {
        $transaction = $this->getLastTransaction($submission->id);
        $this->updateRefund($vendorTransaction['status'], $refundAmount, $transaction, $submission);
    }

    public function updateRefund($newStatus, $refundAmount, $transaction, $submission)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($submission->id);
        if ($submission->payment_status == $newStatus) {
            return;
        }

        $submissionModel->updateSubmission($submission->id, array(
            'payment_status' => $newStatus
        ));

        Transaction::where('submission_id', $submission->id)->update(array(
            'status' => $newStatus,
            'updated_at' => current_time('mysql')
        ));

        do_action('wppayform/after_payment_status_change', $submission->id, $newStatus);

        $activityContent = 'Payment status changed from <b>' . $submission->payment_status . '</b> to <b>' . $newStatus . '</b>';
        $note = wp_kses_post('Status updated by AuthorizeDotNet.');
        $activityContent .= '<br />Note: ' . $note;
        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'info',
            'created_by' => 'AuthorizeDotNet',
            'content' => $activityContent
        ));
    }

    public function getLastTransaction($submissionId)
    {
        $transactionModel = new Transaction();
        $transaction = $transactionModel->where('submission_id', $submissionId)
            ->first();
        return $transaction;
    }

    public function markAsPaid($status, $updateData, $transaction)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);

        $formDataRaw = $submission->form_data_raw;
        $formDataRaw['AuthorizeDotNet_ipn_data'] = $updateData;
        $submissionData = array(
            'payment_status' => $status,
            'form_data_raw' => maybe_serialize($formDataRaw),
            'updated_at' => current_time('Y-m-d H:i:s')
        );

        $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

        $transactionModel = new Transaction();
        $data = array(
            'charge_id' => $updateData['charge_id'],
            'payment_note' => $updateData['payment_note'],
            'status' => $status,
            'updated_at' => current_time('Y-m-d H:i:s')
        );
        $transactionModel->where('id', $transaction->id)->update($data);

        $transaction = $transactionModel->getTransaction($transaction->id);
        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as paid and AuthorizeDotNet Transaction ID: %s', 'AuthorizeDotNet-payment-for-paymattic'), $data['charge_id'])
        ));

        do_action('wppayform/form_payment_success_AuthorizeDotNet', $submission, $transaction, $transaction->form_id, $updateData);
        do_action('wppayform/form_payment_success', $submission, $transaction, $transaction->form_id, $updateData);
    }

    public function addCheckoutJs($settings)
    {
        // $isLive = (new  \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings())::isLive();
        // if ($isLive) {
        //     wp_enqueue_script('wppayform_authorizedotnet', 'https://js.authorize.net/v3/AcceptUI.js', ['jquery'], AuthorizeDotNet_FOR_PAYMATTIC_VERSION);
        // } else {
        //     wp_enqueue_script('wppayform_authorizedotnet', 'https://jstest.authorize.net/v3/AcceptUI.js', ['jquery'], AuthorizeDotNet_FOR_PAYMATTIC_VERSION);
        // }
        wp_enqueue_script('wppayform_authorizedotnet_handler', AuthorizeDotNet_FOR_PAYMATTIC_URL . 'assets/js/authorizedotnet-handler.js', ['jquery'], AuthorizeDotNet_FOR_PAYMATTIC_VERSION);
    }

    public function validateSubscription($paymentItems)
    {
        wp_send_json_error(array(
            'message' => __('Subscription with AuthorizeDotNet is not supported yet!', 'AuthorizeDotNet-payment-for-paymattic'),
            'payment_error' => true
        ), 423);
    }
}
