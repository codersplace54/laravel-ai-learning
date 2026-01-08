<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\UserDocument;

class EntityLockerController extends Controller
{
    private $redirect_uri = 'https://swaagatbackend.tripura.gov.in/entity_locker';
    private $base_url = 'https://entity.digilocker.gov.in/public/oauth2';

    public function initiate_auth(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id'
            ]);

            // Generate PKCE
            $code_verifier = Str::random(128);
            $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
            $state = Str::random(32);

            session([
                'entity_locker_state' => $state,
                'code_verifier' => $code_verifier,
                'user_id' => $request->user_id
            ]);

            $auth_url = $this->base_url . '/1/authorize?' . http_build_query([
                'client_id' => config('services.entity_locker.client_id'),
                'redirect_uri' => $this->redirect_uri,
                'response_type' => 'code',
                'code_challenge' => $code_challenge,
                'code_challenge_method' => 'S256',
                'state' => $state
            ]);

            return response()->json([
                'status' => 1,
                'message' => 'Authorization URL generated successfully',
                'auth_url' => $auth_url
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Failed to generate authorization URL',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function handle_callback(Request $request)
    {
        try {
            if ($request->error) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Authorization failed: ' . $request->error_description
                ], 400);
            }

            if ($request->state !== session('entity_locker_state')) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Invalid state parameter'
                ], 400);
            }

            $client_id     = config('services.entity_locker.client_id') ;
            $client_secret = config('services.entity_locker.client_secret'); 
            $redirect_uri  = $this->redirect_uri;

            // Exchange code for token
            $token_response = Http::asForm()
                ->withBasicAuth($client_id, $client_secret)
                ->post($this->base_url . '/token', [
                    'grant_type'    => 'authorization_code',
                    'code'          => $request->code,
                    'redirect_uri'  => $redirect_uri,
                    'code_verifier' => session('code_verifier'),
                ]);

            if (!$token_response->successful()) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Token exchange failed'
                ], 400);
            }

            $token_data = $token_response->json();
            $access_token = $token_data['access_token'];

            $this->fetch_and_store_documents($access_token, session('user_id'));

            return response()->json([
                'status' => 1,
                'message' => 'Documents fetched and stored successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Callback processing failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function fetch_and_store_documents($access_token, $user_id)
    {
        DB::beginTransaction();

        try {
            // Fetch issued documents
            $issued_response = Http::withToken($access_token)->get($this->base_url . '/issued');
            if ($issued_response->successful()) {
                $this->store_documents($issued_response->json(), $user_id, 'issued');
            }

            // Fetch uploaded documents
            $uploaded_response = Http::withToken($access_token)->get($this->base_url . '/uploaded');
            if ($uploaded_response->successful()) {
                $this->store_documents($uploaded_response->json(), $user_id, 'uploaded');
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function store_documents($documents, $user_id, $type)
    {
        foreach ($documents as $doc) {
            UserDocument::updateOrCreate([
                'user_id' => $user_id,
                'document_id' => $doc['uri']
            ], [
                'document_type' => $type,
                'document_name' => $doc['name'],
                'issuer' => $doc['issuer'] ?? null,
                'issued_date' => $doc['date'] ?? null,
                'document_data' => json_encode($doc)
            ]);
        }
    }

    public function user_documents(Request $request)
    {
        try {
            $request->validate([
                'user_id' => 'required|exists:users,id'
            ]);

            $documents = UserDocument::where('user_id', $request->user_id)
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => 1,
                'message' => 'User documents fetched successfully',
                'data' => $documents
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 0,
                'message' => 'Failed to fetch user documents',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
