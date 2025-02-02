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
        // Check the request method is POST
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        // test if the request have a header with the name 'X-ANET-Signature'
        if (!isset($_SERVER['HTTP_X_ANET_SIGNATURE'])) {
            return;
        }

        // Get all headers
        $headers = getallheaders();

        // verify the reques with the signature key
        $reqSignatureKey = Arr::get($headers, 'X-Anet-Signature', '');
         // remove the prefix
        if (strpos($reqSignatureKey, 'sha512=') === 0) {
            $reqSignatureKey = substr($reqSignatureKey, strlen('sha512='));
        }
        
        $mrchntSignatureKey =  (new \AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings())->getSignatureKey();
  

        // Get the post body
        $post_data = file_get_contents('php://input');

        // Generate the HMAC-SHA512 hash
        $generated_hash = strtoupper(hash_hmac('sha512', $post_data, $mrchntSignatureKey));

        // Compare the signatures
        $reqSignatureKey = strtoupper($reqSignatureKey);
        if (!hash_equals($generated_hash, $reqSignatureKey)) {
            error_log("Signature mismatch: expected $reqSignatureKey but got $generated_hash");
            exit(200);
        }
    
        $data =  json_decode($post_data);
        if (!property_exists($data, 'eventType')) {
            exit(200);
        } else {
            $this->handleIpn($data);
        }

        exit(200);
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
