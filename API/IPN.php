<?php

namespace AuthorizeDotNetForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Submission;
use AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings;
use WPPayForm\App\Models\Transaction;

class IPN
{
    public function init()
    {
        $this->verifyIPN();
    }

    public function verifyIPN()
    {

        // Check if the request method is POST
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }


        // Get all headers in a case-insensitive manner
        $headers = array_change_key_case(getallheaders(), CASE_LOWER);

        // Check if the 'X-ANET-Signature' header exists
        if (!isset($headers['x-anet-signature'])) {
            return;
        }

        // Retrieve the signature from the header
        $reqSignatureKey = $headers['x-anet-signature'];

        // Remove the 'sha512=' prefix if present
        if (strpos($reqSignatureKey, 'sha512=') === 0) {
            $reqSignatureKey = substr($reqSignatureKey, strlen('sha512='));
        }

        // Get the merchant signature key from settings
        $mrchntSignatureKey = (new \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings())->getSignatureKey();
        if (empty($mrchntSignatureKey)) {
            return;
        }

        // Get the raw POST data
        $post_data = file_get_contents('php://input');
        if (empty($post_data)) {
            return;
        }

        // generate the same HMAC-SHA512 hash using the webhook notification's body and the merchant's Signature Key
        $generated_hash = hash_hmac('sha512', $post_data, $mrchntSignatureKey);

        // Compare the signatures securely using hash_equals
        if (!hash_equals(strtolower($generated_hash), strtolower($reqSignatureKey))) {
            return;
        }

        // Decode the JSON payload
        $data = json_decode($post_data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        // Check if the 'eventType' property exists in the payload
        if (!property_exists($data, 'eventType')) {
            return;
        }

        // Handle the IPN data
        $this->handleIpn($data);

        // Return a success response
        http_response_code(200);
        exit('Webhook processed successfully.');
    }

    protected function handleIpn($data)
    {
        $entityName = $data->payload->entityName;
        
        if (has_action('wppayform_handle_authorize_' . $entityName . '_ipn')) {
            do_action('wppayform_handle_authorize_' . $entityName . '_ipn', $data);
        } else {
            exit(200);
        }

    }

    protected function handleInvoicePaid($data)
    {
        // $invoiceId = $data->id;
        // $externalId = $data->external_id;

        // //get transaction from database
        // $transaction = Transaction::where('charge_id', $invoiceId)
        //     ->where('payment_method', 'authorizedotnet')
        //     ->first();

        // if (!$transaction || $transaction->payment_method != 'authorizedotnet') {
        //     return;
        // }

        // $submissionModel = new Submission();
        // $submission = $submissionModel->getSubmission($transaction->submission_id);

        // if ($submission->submission_hash != $externalId) {
        //     // not our invoice
        //     return;
        // }

        // $invoice = $this->makeApiCall('invoices/' . $invoiceId, [], $transaction->form_id, '');

        // if (!$invoice || is_wp_error($invoice)) {
        //     return;
        // }

        // do_action('wppayform/form_submission_activity_start', $transaction->form_id);

        // $status = 'paid';

        // $updateData = [
        //     'payment_note'     => maybe_serialize($data),
        //     'charge_id'        => sanitize_text_field($invoiceId),
        // ];

        // $authorizedotNetProcessor = new AuthorizeDotNetProcessor();
        // $authorizedotNetProcessor->markAsPaid($status, $updateData, $transaction);
    }
}
