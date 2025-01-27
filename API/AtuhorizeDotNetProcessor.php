<?php

namespace AuthorizeDotNetForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Models\OrderItem;
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
        $this->handleRedirect($transaction, $submission, $form, $form_data, $paymentMode);
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

    public function handleRedirect($transaction, $submission, $form, $formData, $methodSettings)
    {
        $authArgs = $this->getAuthArgs($form->ID);
        // get authorizeDataValuye and authorizeDataDescriptor from fromData with sanitize_text_field
        $authorizeDataValue = sanitize_text_field($formData['authorizeDataValue']);
        $authorizeDataDescriptor = sanitize_text_field($formData['authorizeDataDescriptor']);
       
        // currency validate and get currency code
        $this->validateCurrency($submission);

        // truncate submissionhash to 18 characters for refId
        $refId = substr($submission->submission_hash, 0, 20);
        $createTransactionRequest = array(
            'createTransactionRequest' => array(
                'merchantAuthentication' => $authArgs['merchantAuthentication'],
                'refId' => $refId, // truncate to 20 characters
                'transactionRequest' => array(
                    'transactionType' => 'authCaptureTransaction',
                    'amount' => number_format( $submission->payment_total / 100, 2),
                    'payment' => array(
                        'opaqueData' => array(
                            'dataDescriptor' => $authorizeDataDescriptor,
                            'dataValue' => $authorizeDataValue
                        )
                    ),
                    'lineItems' => $this->getLineItems($submission),
                    'customerIP' => $_SERVER['REMOTE_ADDR'],
                )
            )
        );

       $response = (new API())->makeApiCall($createTransactionRequest, $form->ID, 'POST');
        
        if (isset($response['success']) && !$response['success']) {
            wp_send_json_error(array('message' => $response['msg']), 423);
        }

        $data = $response['data'];
        $transactionResponse = $data['transactionResponse'];
        $refId = $data['refId'];
        $responseCode = $transactionResponse['responseCode'];
        if (1 == intval($responseCode)) {
            // get the last four digits from the accountNumber
            $cardlast4 = substr($transactionResponse['accountNumber'], -4);

            $updateData = array(
                'card_last_4' => $cardlast4,
                'charge_id' => $transactionResponse['transHash'],
                'payment_note' => json_encode($data),
                'payment_status' => 'paid'
            );

            $this->markAsPaid('paid', $updateData, $transaction);

        }

        # Tell the client to handle the action
        wp_send_json_success([
            'message' => __('You are redirecting to Billplz.com to complete the purchase. Please wait while you are redirecting....', 'wp-payment-form-pro'),
            'call_next_method' => 'normalRedirect',
            'redirect_url' => $this->getSuccessURL($form, $submission),
        ], 200);
    }

    public function getLineItems($submission)
    {
        $orderItemsModel = new OrderItem();
        $lineItems = $orderItemsModel->getOrderItems($submission->id);
        $hasLineItems = count($lineItems) ? true : false;

        if (!$hasLineItems) {
           wp_send_json_error(array(
                'message' => 'AuthorizeDotNet payment method requires at least one line item',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }
        $lineItems = array();
        $submissionItems = $submission->items;
        $i = 1;
        foreach ($submissionItems as $item) {
            $lineItems[] = array(
                'itemId' => $i,
                'name' => $item['name'],
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unitPrice' => number_format($item['unitPrice'] / 100, 2)
            );
            $i++;
        }
        return $lineItems;
    }

    public function validateCurrency($submission)
    {
        $merchantDetailsReq = array(
            'getMerchantDetailsRequest' => array(
                'merchantAuthentication' => $this->getAuthArgs($submission->form_id)['merchantAuthentication']
            )
        );

        $response = (new API())->makeApiCall($merchantDetailsReq, $submission->form_id, 'POST');
        if (isset($response['success']) && !$response['success']) {
            wp_send_json_error(array('message' => $response['msg']), 423);
        }

        $currencies = $response['data']['currencies'];
        $currency = $submission->currency;

        if (!in_array($currency, $currencies)) {
            wp_send_json_error(array('message' => __('Currency is not supported by The merchant Account', 'authorize-dotnet-for-paymattic')), 423);
        }
        return;
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
                'transactionKey' => $transactionKey,
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

        do_action('wppayform/form_payment_success_authorizedotnet', $submission, $transaction, $transaction->form_id, $updateData);
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
        // wp_enqueue_script('wppayform_authorizedotnet_handler', AuthorizeDotNet_FOR_PAYMATTIC_URL . 'assets/js/authorizedotnet-handler.js', ['jquery'], AuthorizeDotNet_FOR_PAYMATTIC_VERSION);
    }

    public function validateSubscription($paymentItems)
    {
        wp_send_json_error(array(
            'message' => __('Subscription with AuthorizeDotNet is not supported yet!', 'AuthorizeDotNet-payment-for-paymattic'),
            'payment_error' => true
        ), 423);
    }
}
