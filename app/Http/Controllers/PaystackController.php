<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp\Client;
use JsonException;

class PaystackController extends Controller
{

    public function pay()  {
        $url = "https://api.paystack.co/transaction/initialize";

        $fields = [
            'email' => "customer@email.com",
            'amount' => "500000"
        ];

        $fields_string = http_build_query($fields);

        //open connection
        $ch = curl_init();
        
        //set the url, number of POST vars, POST data
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . env('PAYSTACK_SECRET_KEY'),
            "Cache-Control: no-cache",
        ));
        
        //So that curl_exec returns the contents of the cURL; rather than echoing it
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        //execute post
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return $this->paystackJsonResponse($response, $status, $error);
    }

    public function callback(Request $request)
    {
        $curl = curl_init();
  
        curl_setopt_array($curl, array(
            CURLOPT_URL => "https://api.paystack.co/transaction/verify/:reference",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => "GET",
            CURLOPT_HTTPHEADER => array(
            "Authorization: Bearer SECRET_KEY",
            "Cache-Control: no-cache",
            ),
        ));
        
        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);
        
        return $this->paystackJsonResponse($response, 200, $err);
    }


    // Initialize Paystack Transaction
    public function initializeTransaction(Request $request)
    {
        $email = $request->email; // The customer's email address
        $amount = $request->amount; // Amount in Kobo (e.g., 10000 Kobo = 100 NGN)
       
        $url = "https://api.paystack.co/transaction/initialize";

        $fields = [
            'email' => "customer@email.com",
            'amount' => "20000",
        ];

        $fields_string = http_build_query($fields);

        //open connection
        $ch = curl_init();
        
        //set the url, number of POST vars, POST data
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . env('PAYSTACK_SECRET_KEY'),
            "Cache-Control: no-cache",
        ));
        
        //So that curl_exec returns the contents of the cURL; rather than echoing it
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        //execute post
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return $this->paystackJsonResponse($result, $status, $error);
    }

    // Verify Paystack Transaction
    public function verifyTransaction(Request $request)
    {
        $reference = $request->query('reference'); // Paystack transaction reference

        if (!is_string($reference) || !preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $reference)) {
            return response()->json(['message' => 'Invalid transaction reference.'], 422);
        }

        $client = new Client();
        $secretKey = env('PAYSTACK_SECRET_KEY'); // Secret key from .env

        try {
            $response = $client->get('https://api.paystack.co/transaction/verify/' . rawurlencode($reference), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                ],
                'allow_redirects' => false,
                'timeout' => 15,
            ]);

            $body = json_decode($response->getBody(), true);

            if ($body['status'] && $body['data']['status'] === 'success') {
                // Payment was successful
                return response()->json([
                    'message' => 'Payment was successful!',
                    'payment_details' => $body['data'],
                ]);
            }

            return response()->json([
                'message' => 'Payment failed or was not completed.',
                'error' => $body['message'],
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error occurred while verifying payment.',
            ], 502);
        }
    }

    /**
     * Return gateway payloads as JSON so a compromised or malformed upstream
     * response can never be interpreted as executable HTML by the browser.
     */
    private function paystackJsonResponse(string|false $payload, int $status, string $error = '')
    {
        if ($payload === false || $error !== '') {
            return response()->json(['message' => 'Payment gateway request failed.'], 502);
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Payment gateway returned an invalid response.'], 502);
        }

        $safeStatus = $status >= 200 && $status <= 599 ? $status : 502;

        return response()->json($decoded, $safeStatus);
    }
}
