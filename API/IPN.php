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
        if (!isset($_REQUEST['wpf_authorizedotnet_listener'])) {
            return;
        }

        // Check the request method is POST
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        // Set initial post data to empty string
        // $post_data = '';

        // // Fallback just in case post_max_size is lower than needed
        // if (ini_get('allow_url_fopen')) {
        //     $post_data = file_get_contents('php://input');
        // } else {
        //     // If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
        //     ini_set('post_max_size', '12M');
        // }

        // $data =  json_decode($post_data);

        // if (!property_exists($data, 'event')) {
        //     $this->handleInvoicePaid($data);
        // } else {
        //     error_log("specific event");
        //     error_log(print_r($data));
        //     $this->handleIpn($data);
        // }

        // exit(200);
    }

    protected function handleIpn($data)
    {
        //handle specific events in the future
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
