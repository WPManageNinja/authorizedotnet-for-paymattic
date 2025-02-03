<?php

namespace AuthorizeDotNetForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Models\SubscriptionTransaction;
use WPPayForm\App\Models\OrderItem;
use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\Subscription;
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
        // add_filter('wppayform/submitted_payment_items_' . $this->method, array($this, 'validateSubscription'), 10, 4);
        add_action('wppayform_load_checkout_js_' . $this->method, array($this, 'addCheckoutJs'), 10, 3);

        // fetch all subscription entry wise
        add_action('wppayform/subscription_settings_sync_authorizedotnet', array($this, 'makeSubscriptionSync'), 10, 2);

         // cancel subscription
         add_action('wppayform/subscription_settings_cancel_authorizedotnet', array($this, 'cancelSubscription'), 10, 3);

         // ipns
         add_action('wppayform_handle_authorize_transaction_ipn', array($this, 'handleTransactionIpn'), 10, 1);
         add_action('wppayform_handle_authorize_subscription_ipn', array($this, 'handleSubscriptionIpn'), 10, 1);

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
        $this->handleRedirect($transaction, $submission, $form, $form_data, $paymentMode, $hasSubscriptions);
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

    public function handleRedirect($transaction, $submission, $form, $formData, $paymentMode, $hasSubscriptions)
    {
        $authArgs = $this->getAuthArgs($form->ID);
        // get authorizeDataValuye and dataDescriptor from fromData with sanitize_text_field
        $dataValue = sanitize_text_field($formData['dataValue']);
        $dataDescriptor = sanitize_text_field($formData['dataDescriptor']);

        $payment = array(
                        'opaqueData' => array(
                            'dataDescriptor' => $dataDescriptor,
                            'dataValue' => $dataValue
                        )
                    );

        if (!$dataValue) {
            wp_send_json_error(
                array(
                    'message' => 'No authrizeDataValue provide, necessary for payment with authorizeDotNet',
                    'type' => 'error',
                ), 423
            );
        }
       
        // currency validate and get currency code
        // $this->validateCurrency($submission);

        $orderItemsModel = new OrderItem();
        $lineItems = $orderItemsModel->getOrderItems($submission->id);
        $hasLineItems = count($lineItems) ? true : false;

        if (!$hasLineItems && !$hasSubscriptions) {
           wp_send_json_error(array(
                'message' => 'AuthorizeDotNet payment method requires at least one line item',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }

        if ($hasLineItems && $hasSubscriptions) {
            wp_send_json_error(array(
                'message' => 'AuthorizeDotNet payment method does not support both one time payment and subscriptions at once',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }

        if ($hasSubscriptions) {
            $this->handleSubscription($submission, $form, $formData, $lineItems, $authArgs, $dataValue, $dataDescriptor);
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
                    'payment' => $payment,
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

        if ($refId != substr($submission->submission_hash, 0, 20)) {
            wp_send_json_error(array('message' => 'Non varified response'), 423);
        }
    
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
                'content' => sprintf(__('Transaction Marked as on-hold as  the ransaction is held for review with Transaction ID: %s', 'authorizedotnet-for-paymattic'), $data['charge_id'])
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

        wp_send_json_success([
            'message' => __('Payment successfull!', 'authorizedotnet-for-paymattic'),
            'call_next_method' => 'normalRedirect',
            'redirect_url' => $this->getSuccessURL($form, $submission),
        ], 200);
    }

    public function handleSubscription($submission, $form, $formData, $lineItems, $authArgs, $dataValue, $dataDescriptor)
    {

        $subscription = $this->getValidSubscription($submission);

        $authArgs = $this->getAuthArgs($form->ID);
        $payment = array(
            'opaqueData' => array(
                'dataDescriptor' => $dataDescriptor,
                'dataValue' => $dataValue
            )
        );

         // billing address
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

        $interval = $subscription->billing_interval;

        if ('month' == $subscription->billing_interval) {
            $interval = 'months';
        } else if ('day' == $subscription->billing_interval) {
            $interval = 'days';
        } else {
            wp_send_json_error(array(
                'message' => 'Authorize dot net payment method does not support the interval: ' . $subscription->billing_interval,
                'call_next_method' => 'normalRedirect',
                'redirect_url' => $this->getSuccessURL($form, $submission)
            ), 423);
        }

        $intervalCount = Arr::get($subscription->original_plan, 'interval_count', 1);

        $trailDays = intval($subscription->trial_days);

        

        // make sure startDate is greater than current date , if no trial days add 5 minutes
        if ($trailDays) {
            $startDate = date('Y-m-d', strtotime('+' . $trailDays . ' days', strtotime(current_time('mysql'))));
        } else {
            $startDate = date('Y-m-d', strtotime('+5 minutes', strtotime(current_time('mysql'))));
        }

        
        $amount = number_format($subscription->recurring_amount / 100, 2, '.', '');
        $totalOccurrences = Arr::get($subscription, 'bill_times', 9999) ? Arr::get($subscription, 'bill_times', 9999) : 9999;

        // add sign up fee to the amount if it's there
        $trialAmount = 0.00;
        if ($subscription->initial_amount) {
            $trialAmount = number_format($subscription->initial_amount / 100, 2, '.', '');
        }

        $trialOccurrences = $trailDays || $trialAmount ? 1 : 0;

        $subscriptionArgs = array(
            'name' => $subscription->item_name,
            'paymentSchedule' => array(
                'interval' => array(
                    'length' => $intervalCount,
                    'unit' => $interval
                ),
                'startDate' => $startDate,
                'totalOccurrences' => $totalOccurrences,
                'trialOccurrences' => $trialOccurrences,
            ),
            'amount' => $amount,
            'trialAmount' => $trialAmount,
            'payment' => $payment,
            'billTo' => array(
                'firstName' => $firstName,
                'lastName' => $lastName,
                'address' => $address,
                'city' => $city,
                'state' => $state,
                'zip' => $zip,
                'country' => $country,
            ),
        );

        $createSubscriptionReq = array(
            'ARBCreateSubscriptionRequest' => array(
                'merchantAuthentication' => $authArgs['merchantAuthentication'],
                'refId' => substr($submission->submission_hash, 0, 20),
                'subscription' => $subscriptionArgs,
            )
        );

        // do the api call
        $response = (new API())->makeApiCall($createSubscriptionReq, $form->ID, 'POST');

        if (isset($response['success']) && !$response['success']) {
            wp_send_json_error(array('message' => $response['msg']), 423);
        }

        $data = $response['data'];

        $refId = $data['refId'];

        if ($refId != substr($submission->submission_hash, 0, 20)) {
            wp_send_json_error(array('message' => 'Non varified response'), 423);
        }

        $vendorSubId = $data['subscriptionId'];
        $customerId = $data['profile']['customerProfileId'];
        
        // update the subscription with status and subsid and customer id
        $updateData = array(
            'status' => 'active',
            'vendor_subscriptipn_id' => $vendorSubId,
            'vendor_customer_id' => $customerId,
            'vendor_response' => json_encode($data)
        );

        $subscriptionModel = new Subscription();
        $subscriptionModel->where('id', $subscription->id)->update($updateData);

        // if there is a sign up fee, we will update the last transaction with the sign up fee as paid
        if ($trialAmount) {
            $transactionModel = new Transaction();
            $transactionModel->where('submission_id', $submission->id)
                ->where('payment_method', 'authorizedotnet')
                ->where('status', 'pending')
                ->update(array(
                    'status' => 'paid',
                    'payment_total' => $trialAmount * 100,
                    'updated_at' => current_time('mysql')
                ));
        }


        wp_send_json_success([
            'message' => __('Subscription created successfully!', 'authorizedotnet-for-paymattic'),
            'call_next_method' => 'normalRedirect',
            'redirect_url' => $this->getSuccessURL($form, $submission),
        ], 200);
        
    }

    public function getValidSubscription($submission)
    {
        $subscriptionModel = new Subscription();
        $subscriptions = $subscriptionModel->getSubscriptions($submission->id);

        $validSubscriptions = [];
        foreach ($subscriptions as $subscriptionItem) {
            if ($subscriptionItem->recurring_amount) {
                $validSubscriptions[] = $subscriptionItem;
            }
        }

        if ($validSubscriptions && count($validSubscriptions) > 1) {
            wp_send_json_error(array(
                'message' => 'Authorize dot net payment method does not support more than 1 subscriptions',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
            // Moneris Standard does not support more than 1 subscriptions
        }

        // We just need the first subscriptipn
        return $validSubscriptions[0];
    }

    public function getSubscriptionFromAuthorize($subscription){
        $subId = $subscription->vendor_subscriptipn_id;

        if (!$subId) {
            return null;
        }

        $authArgs = $this->getAuthArgs($subscription->form_id);
        $getSubscriptionReq = array(
            'ARBGetSubscriptionRequest' => array(
                'merchantAuthentication' => $authArgs['merchantAuthentication'],
                'subscriptionId' => $subId,
                "includeTransactions" => true
            )
        );

        $response = (new API())->makeApiCall($getSubscriptionReq, $subscription->form_id, 'POST');

        if (isset($response['success']) && !$response['success']) {
            return null;
        }

        return $response['data'];
    }

    public function makeSubscriptionSync($formId, $submissionId)
    {
        if (!$submissionId) {
            return;
        }

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($submissionId);

        $subscriptionModel = new Subscription();
        $subscriptions = $subscriptionModel->getSubscriptions($submissionId);

        if (!isset($subscriptions[0])) {
            return 'No subscription Id found!';
        };

        $subscription = $subscriptions[0];
        $vendorSubId = $subscription->vendor_subscriptipn_id;

        if (!$vendorSubId) {
            return 'No subscription Id found!';
        }

        $vendorSubscription = $this->getSubscriptionFromAuthorize($subscription);
        $arbTransactions = $vendorSubscription['subscription']['arbTransactions'];
        $amount = $vendorSubscription['subscription']['amount'];

        if (!$arbTransactions) {
            return 'No transaction found!';
        }

        // arbTransaction wiil have recent 20 transactions at most
        foreach ($arbTransactions as $transaction) {
            $transactionId = $transaction['transId'];
            $paymentNo = $transaction['payNum'];
            $this->maybeHandleSubscriptionPayment($amount, $transactionId, $paymentNo, $subscription, $submission);
        }
      

        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'content' => __('Authorized recurring payments synced from upstream', 'wp-payment-form')
        ));
        
        wp_send_json_success(array(
            'message' => 'Successfully synced!'
        ), 200);


    }

    public function maybeHandleSubscriptionPayment($amount, $chargeId, $paymentNo, $subscription, $submission)
    {
        if ($paymentNo && $subscription->bill_count >= $paymentNo) {
            return;
        }

        // Maybe Insert The transaction Now
        $subscriptionTransaction = new SubscriptionTransaction();
        $totalAmount = $subscription->recurring_amount;
        $paymentMode = $this->getPaymentMode();
        $transactionId = $subscriptionTransaction->maybeInsertCharge([
            'form_id' => $submission->form_id,
            'user_id' => $submission->user_id,
            'submission_id' => $submission->id,
            'subscription_id' => $subscription->id,
            'transaction_type' => 'subscription',
            'payment_method' => 'authorizedotnet',
            'charge_id' => $chargeId,
            'payment_total' => $totalAmount,
            'status' => 'paid',
            'currency' => $submission->currency,
            'payment_mode' => $paymentMode,
            'payment_note' => sanitize_text_field('subscription payment synced from upstream'),
            'created_at' => current_time('mysql'), // current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);

        $transaction = $subscriptionTransaction->getTransaction($transactionId);
        $subscriptionModel = new Subscription();
        $isNewPayment = $paymentNo != $subscription->bill_count;

        // if paymentNo is 1 make the submission status to paid
        if ($paymentNo == 1) {
            $submissionModel = new Submission();
            $submissionModel->where('id', $submission->id)->update([
                'payment_status' => 'paid',
                'updated_at' => current_time('mysql')
            ]);
        }
      
        // Check For Payment EOT
        if ($subscription->bill_times && $paymentNo >= $subscription->bill_times) {
            // we will update the subscription status to completed
            $subscriptionModel->updateSubscription($subscription->id, [
                'status' => 'completed',
                'bill_count' => $paymentNo,
            ]);

            SubmissionActivity::createActivity(array(
                'form_id' => $submission->form_id,
                'submission_id' => $submission->id,
                'type' => 'activity',
                'created_by' => 'Paymattic BOT',
                'content' => __('The Subscription Term Period has been completed', 'wp-payment-form-pro')
            ));

            $updatedSubscription = $subscriptionModel->getSubscription($subscription->id);
            do_action('wppayform/subscription_payment_eot_completed', $submission, $updatedSubscription, $submission->form_id, []);
            do_action('wppayform/subscription_payment_eot_completed_authorizedotnet', $submission, $updatedSubscription, $submission->form_id, []);

        }

        if ($isNewPayment) {
            $subscriptionModel->updateSubscription($subscription->id, [
                'status' => 'active',
                'bill_count' => $paymentNo,
            ]);
            // New Payment Made so we have to fire some events here
            do_action('wppayform/subscription_payment_received', $submission, $transaction, $submission->form_id, $subscription);
            do_action('wppayform/subscription_payment_received_authorizedotnet', $submission, $transaction, $submission->form_id, $subscription);
        }
    }
    

    public function cancelSubscription($formId, $submission, $subscription)
    {

        if (!$subscription) {
            return null;
        }

        $subId = Arr::get($subscription, 'vendor_subscriptipn_id') ;
        $formId = Arr::get($subscription, 'form_id');
        $submissionId = Arr::get($subscription, 'submission_id');
        $id = Arr::get($subscription, 'id');

        if (!$subId) {
            return null;
        }

        $authArgs = $this->getAuthArgs($formId);
        $cancelSubscriptionReq = array(
            'ARBCancelSubscriptionRequest' => array(
                'merchantAuthentication' => $authArgs['merchantAuthentication'],
                'subscriptionId' => $subId,
            )
        );

        $response = (new API())->makeApiCall($cancelSubscriptionReq, $formId, 'POST');

        if (isset($response['success']) && !$response['success']) {
            return null;
        }

        // subscription cancelled
        $updateData = array(
            'status' => 'cancelled'
        );

        $subscriptionModel = new Subscription();
        $subscriptionModel->where('id', $id)->update($updateData);

        // add activity
        SubmissionActivity::createActivity(array(
            'form_id' => $formId,
            'submission_id' => $submissionId,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Subscription Cancelled of Subscription ID: %s', 'authorizedotnet-for-paymattic'), $subId)
        ));

        // trigger cancel event
        do_action('wppayform_subscription_cancelled', $subscription);
        do_action('wppayform_subscription_cancelled_authorizedotnet', $subscription);

        wp_send_json_success(array(
            'message' => 'Subscription cancelled!'
        ), 200);
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
            'content' => sprintf(__('Transaction Marked as paid and AuthorizeDotNet Transaction ID: %s', 'authorizedotnet-for-paymattic'), $data['charge_id'])
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
            'message' => __('Subscription with AuthorizeDotNet is not supported yet!', 'authorizedotnet-for-paymattic'),
            'payment_error' => true
        ), 423);
    }

    // ipn handling

    public function handleTransactionIpn($data)
    {
        if (!$data) {
            return;
        }

        $eventType = $data->eventType;
        $payload = $data->payload;

        $transactionId = $payload->id;

        $transactionModel = new Transaction();
        $transaction = $transactionModel->getTransactionByChargeId($transactionId);

        if (!$transaction) {
            return;
        }

        // net.authorize.payment.fraud.approved get fraud approved from this
        $eventType = str_replace('net.authorize.payment.', '', $eventType);
        $eventType = str_replace('.', '_', $eventType);

        if (method_exists($this, $eventType)) {
            $this->$eventType($transaction, $payload);
        } else {
            return;
        }
    }

    public function handleSubscriptionIpn($data)
    {
        if (!$data) {
            return;
        }

        $eventType = $data->eventType;
        $payload = $data->payload;

        $subscriptionId = $payload->id;

        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->getSubscription($subscriptionId, 'vendor_subscriptipn_id');

        if (!$subscription) {
            return;
        }

        // net.authorize.payment.fraud.approved get fraud approved from this
        $eventType = str_replace('net.authorize.customer.', '', $eventType);
        $eventType = str_replace('.', '_', $eventType);

        if (method_exists($this, $eventType)) {
            $this->$eventType($subscription, $payload);
        } else {
            return;
        }
    }

    // we will use this event only for subscription payment as normal payment is already handled
    public function authcapture_created($data) 
    {
        $vendroSubscriptionData = $data->payload->subscription;
        if (!$vendroSubscriptionData) {
            return;
        }

        $vsubId = $vendroSubscriptionData->id;

        $subscriptionModel = new Subscription();
        $subscription = $subscriptionModel->getSubscription($vsubId, 'vendor_subscriptipn_id');

        if (!$subscription) {
            return;
        }

        $vtransId = $data->payload->id;
        $amount = $data->payload->authAmount;
        $paymentNum = $subscription->bill_count + 1;

        // submission
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($subscription->submission_id);

        $this->maybeHandleSubscriptionPayment($amount, $vtransId, $paymentNum, $subscription, $submission);
    }

    public function fraud_approved($transaction, $payload)
    {
        $updateData = array(
            'status' => 'paid',
            'payment_note' => json_encode($payload)
        );

        $transactionModel = new Transaction();
        $transactionModel->where('id', $transaction->id)->update($updateData);

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);
        $submissionData = array(
            'payment_status' => 'paid',
            'updated_at' => current_time('Y-m-d H:i:s')
        );

        $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as paid as held transaction get approved with Transaction ID: %s', 'authorizedotnet-for-paymattic'), $payload->id)
        ));

        do_action('wppayform/form_payment_success', $submission, $transaction, $transaction->form_id, $payload);
        do_action('wppayform/form_payment_success_authorizedotnet', $submission, $transaction, $transaction->form_id, $payload);
    }

    public function fraud_declined($transaction, $payload)
    {
        $updateData = array(
            'status' => 'failed',
            'payment_note' => json_encode($payload)
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

        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as failed as held transaction get declined with Transaction ID: %s', 'authorizedotnet-for-paymattic'), $payload->id)
        ));

        do_action('wppayform/form_payment_failed', $submission, $transaction, $transaction->form_id, $payload);
    }

    public function void_created($transaction, $payload)
    {
        $updateData = array(
            'status' => 'failed',
            'payment_note' => json_encode($payload)
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

        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as failed as transaction is voided with Transaction ID: %s', 'authorizedotnet-for-paymattic'), $payload->id)
        ));

        do_action('wppayform/form_payment_failed', $submission, $transaction, $transaction->form_id, $payload);
    }

    public function refund_created($transaction, $payload) {
        $updateData = array(
            'status' => 'refunded',
            'payment_note' => json_encode($payload)
        );

        $transactionModel = new Transaction();
        $transactionModel->where('id', $transaction->id)->update($updateData);

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);
        $submissionData = array(
            'payment_status' => 'refunded',
            'updated_at' => current_time('Y-m-d H:i:s')
        );

        $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as refunded with Transaction ID: %s', 'authorizedotnet-for-paymattic'), $payload->id)
        ));

        do_action('wppayform/form_payment_refunded', $submission, $transaction, $transaction->form_id, $payload);
    }

    public function subscription_cancelled($subscription, $payload)
    {
        $updateData = array(
            'status' => 'cancelled',
            'vendor_response' => json_encode($payload)
        );

        $subscriptionModel = new Subscription();
        $subscriptionModel->where('id', $subscription->id)->update($updateData);

        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($subscription->submission_id);

        SubmissionActivity::createActivity(array(
            'form_id' => $subscription->form_id,
            'submission_id' => $subscription->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Subscription Cancelled with Subscription ID: %s', 'authorizedotnet-for-paymattic'), $payload->id)
        ));

        do_action('wppayform/subscription_payment_canceled', $submission, $subscription, $submission->form_id, $payload);
        do_action('wppayform/subscription_payment_canceled_paypal', $submission, $subscription, $submission->form_id, $payload);
    }
}
