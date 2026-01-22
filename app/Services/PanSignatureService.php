<?php

namespace App\Services;

use Exception;

class PanSignatureService
{
    private string $pfx_path;
    private string $pfx_password;

    public function __construct(string $pfx_path, string $pfx_password)
    {
        $this->pfx_path = $pfx_path;
        $this->pfx_password = $pfx_password;
    }

    /**
     * Generate PKCS#7 signature from JSON string
     */
    public function sign_from_json_string(string $data_to_sign): string
    {
        $data_file = tempnam(sys_get_temp_dir(), 'panopv_data_');
        $signed_file = tempnam(sys_get_temp_dir(), 'panopv_sign_');

        file_put_contents($data_file, $data_to_sign);

        // Load PFX certificate
        $pfx_content = file_get_contents($this->pfx_path);
        if (!openssl_pkcs12_read($pfx_content, $certs, $this->pfx_password)) {
            throw new Exception("Unable to read PFX file. Check password.");
        }

        // Generate PKCS7 signature
        $result = openssl_pkcs7_sign(
            $data_file,
            $signed_file,
            $certs['cert'],
            $certs['pkey'],
            [],
            PKCS7_BINARY | PKCS7_DETACHED
        );

        if (!$result) {
            throw new Exception("PKCS7 signing failed");
        }

        $smime = file_get_contents($signed_file);

        // Extract Base64 PKCS7 signature
        if (!preg_match(
            '/Content-Type:\s*application\/x-pkcs7-signature.*?\R\R(.*?)\R------/s',
            $smime,
            $matches
        )) {
            throw new Exception("Unable to extract PKCS7 signature");
        }

        $pkcs7_base64 = trim(str_replace(["\r", "\n"], '', $matches[1]));

        // Save debug signature file
        file_put_contents(
            storage_path('logs/debug_signature.p7b'),
            "-----BEGIN PKCS7-----\n" .
            chunk_split($pkcs7_base64, 64, "\n") .
            "-----END PKCS7-----"
        );

        // Cleanup temporary files
        unlink($data_file);
        unlink($signed_file);

        return $pkcs7_base64;
    }
}