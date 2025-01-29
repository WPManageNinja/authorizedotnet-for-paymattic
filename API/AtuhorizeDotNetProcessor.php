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
        // foreach ($transactions as $transaction) {
        //     if ($transaction->payment_method == 'authorizedotnet' && $transaction->charge_id) {
        //         $transactionUrl = Arr::get(unserialize($transaction->payment_note), '_links.dashboard.href');
        //         $transaction->transaction_url =  $transactionUrl;
        //     }
        // }
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
            if ((isset($element['type']) && $element['type'] == 'authorizedotnet_gateway_element')) {
                return 'authorizedotnet';
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
                'payment_method' => 'authorizedotnet'
            ), $url);
            return PlaceholderParser::parse($url, $submission);
        }
        // now we have to check for global Success Page
        $globalSettings = get_option('wppayform_confirmation_pages');
        if (isset($globalSettings['confirmation']) && $globalSettings['confirmation']) {
            return add_query_arg(array(
                'wpf_submission' => $submission->submission_hash,
                'payment_method' => 'authorizedotnet'
            ), get_permalink(intval($globalSettings['confirmation'])));
        }
        // In case we don't have global settings
        return add_query_arg(array(
            'wpf_submission' => $submission->submission_hash,
            'payment_method' => 'authorizedotnet'
        ), home_url());
    }

    public function handleRedirect($transaction, $submission, $form, $formData, $methodSettings)
    {
        $authArgs = $this->getAuthArgs($form->ID);
        // get authorizeDataValuye and dataDescriptor from fromData with sanitize_text_field
        $dataValue = sanitize_text_field($formData['dataValue']);
        $dataDescriptor = sanitize_text_field($formData['dataDescriptor']);

        if (!$dataValue) {
            wp_send_json_error(
                array(
                    'message' => 'No authrizeDataValue provide, necessary for payment with authorizeDotNet',
                    'type' => 'error',
                ), 423
            );
        }
       
        // currency validate and get currency code
        $this->validateCurrency($submission);

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

        // truncate submissionhash to 18 characters for refId
        $refId = substr($submission->submission_hash, 0, 20);
        $createTransactionRequest = array(
            'createTransactionRequest' => array(
                'merchantAuthentication' => $authArgs['merchantAuthentication'],
                'refId' => $refId, // truncate to 20 characters
                'transactionRequest' => array(
                    'transactionType' => 'authCaptureTransaction',
                    'amount' => number_format( $submission->payment_total / 100, 2, '.', ''),
                    'payment' => array(
                        'opaqueData' => array(
                            'dataDescriptor' => $dataDescriptor,
                            'dataValue' => $dataValue
                        )
                    ),
                    'lineItems' => $this->getOrderItems($lineItems)
                )
            )
        );

        $tax = $this->getFormattedTax($lineItems);

        if ($tax) {
            $createTransactionRequest['createTransactionRequest']['transactionRequest']['tax'] = $tax;
        }

        // shipping address
        $firstName = '';
        $lastName = '';
        $addressInput = $formData['address_input'] ?? '';
        $address = '';
        $city = '';
        $state = '';
        $zip = '';
        $country = '';

        if ($formData['customer_name']) {
            $customerName = explode(' ', $formData['customer_name']);
            $firstName = $customerName[0];
            $lastName = $customerName[1];
        }
        if ($addressInput) {
            $address = substr($addressInput['address_line_1'] . $addressInput['address_line_2'], 0, 60) ?? '';
            $city = $addressInput['city'] ?? '';
            $state = $addressInput['state'] ?? '';
            $zip = $addressInput['zip_code'] ?? '';
            $country = $addressInput['country'] ?? '';
        }

        $createTransactionRequest['createTransactionRequest']['transactionRequest']['shipTo'] = array(
            'firstName' => $firstName,
            'lastName' => $lastName,
            'address' => $address,
            'city' => $city,
            'state' => $state,
            'zip' => $zip,
            'country' => $country
        );

        $createTransactionRequest['createTransactionRequest']['transactionRequest']['customerIP'] = $_SERVER['REMOTE_ADDR'];

       $response = (new API())->makeApiCall($createTransactionRequest, $form->ID, 'POST');
 
        if (isset($response['success']) && !$response['success']) {
            wp_send_json_error(array('message' => $response['msg']), 423);
        }

        $data = $response['data'];
        $transactionResponse = $data['transactionResponse'];
        $refId = $data['refId'];
        $responseCode = $transactionResponse['responseCode'] ?? 0;
    
        if (1 == intval($responseCode)) {
            // get the last four digits from the accountNumber
            $cardlast4 = substr($transactionResponse['accountNumber'], -4);
            $updateData = array(
                'card_last_4' => $cardlast4,
                'charge_id' => $transactionResponse['transId'],
                'payment_note' => json_encode($data),
                'status' => 'paid'
            );

            // dd('updating data on paid', $updateData, $response);
            $this->markAsPaid('paid', $updateData, $transaction);

        } else if (4 == intval($responseCode)) {
            // error
            $updateData = array(
                'charge_id' => $transactionResponse['transId'],
                'payment_note' => json_encode($data),
                'card_last_4' => substr($transactionResponse['accountNumber'], -4),
                'status' => 'on-hold',
            );

            $transactionModel = new Transaction();
            $transactionModel->where('id', $transaction->id)->update($updateData);

            $submissionModel = new Submission();
            $submission = $submissionModel->getSubmission($transaction->submission_id);
            $submissionData = array(
                'payment_status' => 'on-hold',
                'updated_at' => current_time('Y-m-d H:i:s')
            );

            $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

            SubmissionActivity::createActivity(array(
                'form_id' => $transaction->form_id,
                'submission_id' => $transaction->submission_id,
                'type' => 'info',
                'created_by' => 'PayForm Bot',
                'content' => sprintf(__('Transaction Marked as on-hold as  the ransaction is held for review with Transaction ID: %s', 'AuthorizeDotNet-payment-for-paymattic'), $data['charge_id'])
            ));

            wp_send_json_success(array(
                'message' => "Payment is on hold for review",
                'call_next_method' => 'normalRedirect',
                'redirect_url' => $this->getSuccessURL($form, $submission),
            ), 200);
        } else {
            // error
            $updateData = array(
                'status' => 'failed'
            );

            $transactionModel = new Transaction();
            $transactionModel->where('id', $transaction->id)->update($updateData);

            $submissionModel = new Submission();
            $submission = $submissionModel->getSubmission($transaction->submission_id);
            $submissionData = array(
                'payment_status' => 'failed',
                'updated_at' => current_time('Y-m-d H:i:s')
            );
    
            $submissionModel->where('id', $transaction->submission_id)->update($submissionData);
  
            wp_send_json_error(array(
                'message' => "Payment Failed/Declined with response code: " . $responseCode,
                'type' => 'error',
                'redirect_url' => $this->getSuccessURL($form, $submission)
            ), 423);

        }

        # Tell the client to handle the action
        wp_send_json_success([
            'message' => __('Payment successfull!', 'wp-payment-form-pro'),
            'call_next_method' => 'normalRedirect',
            'redirect_url' => $this->getSuccessURL($form, $submission),
        ], 200);
    }

    public function getOrderItems($items)
    {
        $i = 1;
        $total = 0;
        $name = '';
        $description = '';
        $totalorderItems = 0;
        // count the number of in the items, which are not tax items
        foreach ($items as $item) {
            if ($item->type != 'tax_line') {
                $totalorderItems++;
            }
        }

        foreach ($items as $item) {
            if ($item->type != 'tax_line') {
                $name .= $item->item_name;
                $description .= $item->item_name . ' qty: ' . $item->quantity;
                $total += intval($item->line_total);

               if($totalorderItems > 1 && $i < $totalorderItems) {
                    $name .= ', ';
                    $description .= ', ';
                }
            }
            $i++;
        }

        return array(
            'lineItem' => array(
                'itemId' => 1,
                'name' => 'See the description!',
                'description' => substr($description,0,254),
                'quantity' => 1,
                'unitPrice' => number_format($total / 100, 2, '.', ''),
            )
        );
    }

    public function getFormattedTax($items)
    {
        // count the number of tax items in the items
        $taxItems = 0;
        foreach ($items as $item) {
            if ($item->type == 'tax_line') {
                $taxItems++;
            }
        }

        if (!$taxItems) {
            return null;
        }

        // formatted a tax item with all the tax items, where name will be the concatenated name of all the tax items, amount will be the total amount of all the tax items
        $description = '';
        $total = 0;

        foreach ($items as $item) {
            if ($item->type == 'tax_line') {
                // construct name and amount, add ' ' after each name, if it's not the last item
               $description = $item->item_name;
               $total += intval($item->line_total);

               // add ',' if it's not the last item or items is more than 1
                if ($taxItems > 1 && !end($items)) {
                     $description .= ', ';
                }
            }
        }

        if (!$total) {
            return null;
        }

        return array(
            'amount' => number_format($total / 100, 2, '.', ''),
            'name' => 'See the description!',
            'description' => substr($description, 0, 254)
        );
        
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
            'created_by' => 'Authorizedotnet',
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
        $formDataRaw['authorizedotnet_ipn_data'] = $updateData;
        $submissionData = array(
            'payment_status' => $status,
            'updated_at' => current_time('Y-m-d H:i:s')
        );

        $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

        $transactionModel = new Transaction();
        $data = array(
            'charge_id' => $updateData['charge_id'] ?? '',
            'payment_note' => $updateData['payment_note'] ?? '',
            'card_last_4' => $updateData['card_last_4'] ?? '',
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
