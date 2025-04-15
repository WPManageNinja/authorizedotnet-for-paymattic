<?php

namespace AuthorizeDotNetForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Submission;
use AuthorizeDotNetForPaymattic\Settings\AuthorizeDotNetSettings;
use WPPayForm\App\Models\Transaction;

use function PHPSTORM_META\type;

class API {

    private static $sandboxEndPoint = 'https://apitest.authorize.net/xml/v1/request.api';
    private static $liveEndPoint = 'https://api.authorize.net/xml/v1/request.api';

    public static function getEndPoint(){
        return AuthorizeDotNetSettings::getPaymentMode() == 'test' ? self::$sandboxEndPoint : self::$liveEndPoint;
    }

    public static function getHeaders(){
        return [
            'Content-Type' => 'application/json'
        ];
    }

    public function makeApiCall($args, $formId, $method = 'GET')
    {
        $endPoint = self::getEndPoint();
        $headers = self::getHeaders();

        if ($method == 'POST') {
            $response = wp_remote_post($endPoint, [
                'headers' => $headers,
                'body' => json_encode($args)
            ]);
        } else {
            $response = wp_remote_get($endPoint, [
                'headers' => $headers,
                'body' => json_encode($args)
            ]);
        }

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'msg' => $response->get_error_message()
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $msg = wp_remote_retrieve_response_message($response);
 
        if ($code >= 400) {            
            return [
                'success' => false,
                'msg' => $msg
            ];
        }

        // remove any BOM or other unwanted characters before decoding the JSON string.
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
        $data = json_decode($body, true);

        // handle response
        if (isset($data['messages']['resultCode']) && $data['messages']['resultCode'] == 'Error') {
            return [
                'success' => false,
                'msg' => $data['messages']['message'][0]['text']
            ];
        }

        return [
            'success' => true,
            'data' => $data
        ];
    }

}