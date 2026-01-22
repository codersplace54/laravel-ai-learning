<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PanVerificationService
{
    private array $config;
    private PanSignatureService $signature_service;

    public function __construct()
    {
        $this->config = config('pan');
        $this->signature_service = new PanSignatureService(
            $this->config['pfx_path'],
            $this->config['pfx_password']
        );
    }

    /**
     * Verify PAN details
     */
    public function verify_pan(array $pan_data): array
    {
        try {
            // Prepare input data for signing
            $input_data_json = json_encode([$pan_data], JSON_UNESCAPED_SLASHES);
            
            // Generate signature
            $signature = $this->signature_service->sign_from_json_string($input_data_json);
            
            // Prepare request payload
            $payload = $this->prepare_request_payload($input_data_json, $signature);
            
            // Make API call
            $response = $this->make_api_call($payload);
            
            // Log request and response for debugging
            $this->log_transaction($payload, $response);
            
            return $response;
            
        } catch (Exception $e) {
            Log::error('PAN verification failed', [
                'error' => $e->getMessage(),
                'pan_data' => $pan_data
            ]);
            throw $e;
        }
    }

    /**
     * Verify single PAN with basic details
     */
    public function verify_single_pan(string $pan, string $name, string $father_name = '', string $dob = ''): array
    {
        $pan_data = [
            'pan' => strtoupper(trim($pan)),
            'name' => strtoupper(trim($name)),
            'fathername' => strtoupper(trim($father_name)),
            'dob' => trim($dob)
        ];

        return $this->verify_pan($pan_data);
    }

    /**
     * Prepare request payload with headers and body structure
     */
    private function prepare_request_payload(string $input_data_json, string $signature): array
    {
        $current_time = Carbon::now('Asia/Kolkata');
        $transaction_id = $this->config['user_id'] . ':' . $current_time->format('YmdHis');
        
        return [
            'headers' => [
                'User_ID' => (string)$this->config['user_id'],
                'Records_count' => '1',
                'Request_time' => $current_time->format('Y-m-d\TH:i:s'),
                'Transaction_ID' => $transaction_id,
                'Version' => $this->config['version'],
                'Content-Type' => 'application/json'
            ],
            'body' => [
                'inputData' => json_decode($input_data_json, true),
                'signature' => $signature
            ]
        ];
    }

    /**
     * Make API call to PAN verification service
     */
    private function make_api_call(array $payload): array
    {
        // Prepare headers array for HTTP request
        $headers = [];
        foreach ($payload['headers'] as $key => $value) {
            if ($key !== 'Content-Type') {
                $headers[] = "{$key}: {$value}";
            }
        }
        $headers[] = 'Content-Type: application/json';
        
        $body_json = json_encode($payload['body'], JSON_UNESCAPED_SLASHES);
        
        // Log request for debugging
        // Log::info('PAN API Request', [
        //     'headers' => $headers,
        //     'body' => $body_json,
        //     'endpoint' => $this->config['api_url']
        // ]);
        
        $response = Http::timeout($this->config['timeout'])
            ->withOptions([
                'verify' => $this->config['ssl_verify']
            ])
            ->withHeaders([
                'User_ID' => $payload['headers']['User_ID'],
                'Records_count' => $payload['headers']['Records_count'],
                'Request_time' => $payload['headers']['Request_time'],
                'Transaction_ID' => $payload['headers']['Transaction_ID'],
                'Version' => $payload['headers']['Version'],
                'Content-Type' => 'application/json'
            ])
            ->post($this->config['api_url'], $payload['body']);

        if ($response->failed()) {
            throw new Exception('PAN API request failed: ' . $response->body());
        }

        $decoded_response = $response->json();
        
        if ($decoded_response === null) {
            // Fallback if response is not JSON
            $decoded_response = ['raw_response' => $response->body()];
        }

        return $decoded_response;
    }

    /**
     * Log transaction for debugging
     */
    private function log_transaction(array $request, array $response): void
    {
        // Save request details to files for debugging (similar to original PHP)
        $headers_text = [];
        foreach ($request['headers'] as $key => $value) {
            $headers_text[] = "{$key}: {$value}";
        }
        
        file_put_contents(storage_path('logs/last_request_headers.txt'), implode("\n", $headers_text));
        file_put_contents(storage_path('logs/last_request_body.json'), json_encode($request['body'], JSON_UNESCAPED_SLASHES));
        
        // Log::info('PAN verification transaction', [
        //     'request_headers' => $request['headers'],
        //     'request_body' => $request['body'],
        //     'response' => $response,
        //     'timestamp' => now()
        // ]);
    }

    /**
     * Parse PAN verification response
     */
    public function parse_response(array $response): array
    {
        $parsed_result = [
            'success' => false,
            'response_code' => $response['response_Code'] ?? null,
            'message' => $this->get_response_message($response['response_Code'] ?? null),
            'data' => []
        ];

        if (isset($response['outputData']) && is_array($response['outputData'])) {
            foreach ($response['outputData'] as $output) {
                $parsed_result['data'][] = [
                    'pan' => $output['pan'] ?? '',
                    'pan_status' => $output['pan_status'] ?? '',
                    'pan_status_description' => $this->get_pan_status_description($output['pan_status'] ?? ''),
                    'name_match' => $output['name'] ?? '',
                    'father_name_match' => $output['fathername'] ?? '',
                    'dob_match' => $output['dob'] ?? '',
                    'seeding_status' => $output['seeding_status'] ?? ''
                ];
            }
            $parsed_result['success'] = ($response['response_Code'] ?? null) == '1';
        }

        return $parsed_result;
    }

    /**
     * Get response message based on response code
     */
    private function get_response_message(?string $response_code): string
    {
        $messages = [
            '1' => 'Success',
            '2' => 'System Error',
            '3' => 'Authentication Failure',
            '4' => 'User not authorized',
            '5' => 'No PANs Entered or Number of PANs exceeds the limit (5)',
            '6' => 'User validity has expired',
            '8' => 'Not enough balance',
            '9' => 'Not an HTTPs request',
            '10' => 'POST method not used',
            '12' => 'Invalid version number entered',
            '15' => 'Valid User ID not sent in Input request',
            '16' => 'Certificate Revocation List issued by the Certifying Authorities is expired',
            '17' => 'User id Deactivated',
            '18' => 'The Certificate used for signing is not matched with the certificate with the Database',
            '19' => 'Signature sent in input request is blank',
            '20' => 'User ID and PAN not sent in Input request',
            '21' => 'No value sent in Input request',
            '22' => 'PAN Number is more than 10 characters or value is Null',
            '23' => 'System Failure or common error message for request',
            '24' => 'Duplicate Transaction ID entered',
            '25' => 'Parse Exception in JSON',
            '26' => 'Records Count Passed from the header value is not matched with the Records Count present in the JSON Input Array',
            '27' => 'Name of Pan holder/Name on card is greater than 85 character or Value is null or contains ~ ^ special characters',
            '28' => 'Father Name field is greater than 75 character or Value is Null or contains ~ ^ special characters',
            '29' => 'Date of Birth format is incorrect it should be separated with slash (/) and in format of (DD/MM/YYYY)',
            '30' => 'Request Time is greater than 30 characters or Value is Null',
            '31' => 'Transaction ID is greater than 50 characters or Value is Null',
            '32' => 'Record count is blank or Record count contains alphabets or special characters',
            '33' => 'Request Time is could not be future date/time and could not be older than last half an hour'
        ];

        return $messages[$response_code] ?? 'Unknown error';
    }

    /**
     * Get PAN status description
     */
    private function get_pan_status_description(string $status): string
    {
        $descriptions = [
            'E' => 'Existing and Valid',
            'F' => 'Marked as Fake',
            'X' => 'Marked as Deactivated',
            'D' => 'Deleted',
            'N' => 'Record (PAN) Not Found in ITD Database/Invalid PAN',
            'EA' => 'Existing and Valid but event marked as "Amalgamation" in ITD database',
            'EC' => 'Existing and Valid but event marked as "Acquisition" in ITD database',
            'ED' => 'Existing and Valid but event marked as "Death" in ITD database',
            'EI' => 'Existing and Valid but event marked as "Dissolution" in ITD database',
            'EL' => 'Existing and Valid but event marked as "Liquidated" in ITD database',
            'EM' => 'Existing and Valid but event marked as "Merger" in ITD database',
            'EP' => 'Existing and Valid but event marked as "Partition" in ITD database',
            'ES' => 'Existing and Valid but event marked as "Split" in ITD database',
            'EU' => 'Existing and Valid but event marked as "Under Liquidation" in ITD database'
        ];

        return $descriptions[$status] ?? 'Unknown status';
    }
}