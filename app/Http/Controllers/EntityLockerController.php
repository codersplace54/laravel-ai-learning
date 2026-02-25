<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserDocument;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EntityLockerController extends Controller
{
    private string $base_url = 'https://entity.digilocker.gov.in/public/oauth2';

    public function initiate_auth(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        $code_verifier = Str::random(128);
        $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');
        $state = Str::random(32);

        Cache::put('entity_locker_state_' . $state, [
            'code_verifier' => $code_verifier,
            'user_id' => (int) $request->user_id,
        ], now()->addMinutes(10));

        $params = [
            'client_id' => config('services.entity_locker.client_id'),
            'redirect_uri' => config('services.entity_locker.redirect_uri'),
            'response_type' => 'code',
            'code_challenge' => $code_challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
            // 'consent_valid_till' => now()->addDays(35)->timestamp,
        ];

        return response()->json([
            'status' => 1,
            'message' => 'Authorization URL generated',
            'auth_url' => $this->base_url . '/1/authorize?' . http_build_query($params),
        ]);
    }

    public function handle_callback(Request $request)
    {
        if ($request->filled('error')) {
            return $this->callback_response($request, false, 'Authorization failed: ' . $request->error);
        }

        $request->validate(['code' => 'required', 'state' => 'required']);

        $cached = Cache::pull('entity_locker_state_' . $request->state);
        if (!$cached) {
            return $this->callback_response($request, false, 'Invalid state');
        }

        $token_response = $this->entity_http()
            ->asForm()
            ->withBasicAuth(
                config('services.entity_locker.client_id'),
                config('services.entity_locker.client_secret')
            )
            ->post($this->base_url . '/1/token', [
                'grant_type' => 'authorization_code',
                'code' => $request->code,
                'redirect_uri' => config('services.entity_locker.redirect_uri'),
                'code_verifier' => $cached['code_verifier'],
            ]);

        if (!$token_response->successful()) {
            return $this->callback_response($request, false, 'Token exchange failed');
        }

        $token_data = $token_response->json();
        $access_token = $token_data['access_token'] ?? null;

        if (!$access_token) {
            return $this->callback_response($request, false, 'No access token');
        }

        $user_id = $cached['user_id'];
        Cache::put('entity_locker_token_' . $user_id, $access_token, now()->addSeconds($token_data['expires_in'] ?? 3600));

        $sync = $this->sync_documents($access_token, $user_id);

        return $this->callback_response($request, true, 'Connected successfully', ['synced' => $sync]);
    }

    public function user_documents(Request $request)
    {
        $auth_user = Auth::user();

        $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        $user_id = Auth::user()->id;
        if ($request->filled('user_id')) {

            if (!in_array($auth_user->user_type, ['admin', 'department'])) {
                return response()->json([
                    'status' => 0,
                    'message' => 'Unauthorized access.'
                ], 403);
            }

            $user_id = $request->user_id;
        } else {
            $user_id = $auth_user->id;
        }
        $docs = UserDocument::where('user_id', $user_id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'document_type' => $doc->document_type,
                    'document_name' => $doc->document_name,
                    'issuer' => $doc->issuer,
                    'issued_date' => $doc->issued_date,
                    'file_path' => asset('storage/' . $doc->local_path),
                    'content_type' => $doc->content_type,
                    'downloaded_at' => $doc->downloaded_at,
                    'created_at' => $doc->created_at,
                ];
            });

        return response()->json([
            'status' => 1,
            'data' => $docs
        ]);
    }

    public function download_document(Request $request, int $id)
    {
        $request->validate(['user_id' => 'required']);
        $doc = UserDocument::findOrFail($id);

        if ((int)$request->user_id !== $doc->user_id) {
            return response()->json(['status' => 0, 'message' => 'Forbidden'], 403);
        }

        if ($doc->local_path && Storage::disk('public')->exists($doc->local_path)) {
            return response()->file(
                Storage::disk('public')->path($doc->local_path),
                ['Content-Type' => $doc->content_type ?? 'application/pdf']
            );
        }

        return response()->json(['status' => 0, 'message' => 'File not found'], 404);
    }

    // public function refresh_token(Request $request)
    // {
    //     $request->validate(['user_id' => 'required|exists:users,id']);

    //     $access_token = Cache::get('entity_locker_token_' . $request->user_id);
    //     if (!$access_token) {
    //         return response()->json(['status' => 0, 'message' => 'No token. Please reconnect.'], 401);
    //     }

    //     $sync = $this->sync_documents($access_token, $request->user_id);

    //     return response()->json(['status' => 1, 'message' => 'Synced', 'data' => $sync]);
    // }

    private function sync_documents(string $access_token, int $user_id): array
    {
        $issued = $this->fetch_issued($access_token, $user_id);
        $uploaded = $this->fetch_uploaded($access_token, $user_id);

        return ['issued' => $issued, 'uploaded' => $uploaded];
    }

    private function fetch_issued(string $access_token, int $user_id): int
    {
        $resp = $this->entity_http($access_token)->get($this->base_url . '/2/entity/files/issued');
        if (!$resp->successful()) return 0;

        return $this->store_documents($resp->json()['items'] ?? [], $user_id, 'issued', $access_token);
    }

    private function fetch_uploaded(string $access_token, int $user_id, ?string $folder_id = null): int
    {
        $url = $this->base_url . '/1/entity/files' . ($folder_id ? '/' . $folder_id : '');
        $resp = $this->entity_http($access_token)->get($url);
        if (!$resp->successful()) return 0;

        $items = $resp->json()['items'] ?? [];
        $count = $this->store_documents($items, $user_id, 'uploaded', $access_token);

        foreach ($items as $item) {
            if (($item['type'] ?? null) === 'dir' && !empty($item['id'])) {
                $count += $this->fetch_uploaded($access_token, $user_id, $item['id']);
            }
        }

        return $count;
    }

    private function store_documents(array $documents, int $user_id, string $type, string $access_token): int
    {
        $count = 0;

        foreach ($documents as $doc) {
            if (($doc['type'] ?? null) !== 'file' || empty($doc['uri'])) continue;

            $count++;

            $data = UserDocument::updateOrCreate(
                ['user_id' => $user_id, 'document_id' => $doc['uri']],
                [
                    'document_type' => $type,
                    'document_name' => $doc['name'] ?? '',
                    'issuer' => $doc['issuer'] ?? null,
                    'issued_date' => $this->parse_date($doc['date'] ?? null),
                    'document_data' => $doc,
                ]
            );

            if ($data->local_path && Storage::disk('public')->exists($data->local_path)) {
                continue;
            }

            try {
                $saved = $this->download_file($access_token, $user_id, $doc['uri'], $type, $doc['doctype'] ?? null);
                $data->update($saved);
            } catch (\Throwable $e) {
                Log::warning('File download failed', ['uri' => $doc['uri']]);
            }
        }

        return $count;
    }

    private function download_file(string $access_token, int $user_id, string $uri, string $type, ?string $doctype = null): array
    {
        $url = $this->base_url . '/1/entity/file/' . rawurlencode($uri);
        $resp = $this->entity_http($access_token)->withHeaders(['Accept' => 'application/pdf'])->get($url);

        if (!$resp->successful()) {
            $resp = $this->entity_http($access_token)->get($url);
        }

        if (!$resp->successful()) {
            throw new \RuntimeException('Download failed');
        }

        $content_type = $resp->header('Content-Type') ?: 'application/pdf';
        $ext = match (strtolower(explode(';', $content_type)[0])) {
            'application/pdf' => 'pdf',
            'application/xml', 'text/xml' => 'xml',
            'application/json' => 'json',
            default => 'bin',
        };

        $filename = ($doctype ? $doctype . '-' : '') . sha1($uri) . '.' . $ext;
        $local_path = "uploads/{$user_id}/entity-locker/{$type}/{$filename}";

        Storage::disk('public')->put($local_path, $resp->body());

        return [
            'local_path' => $local_path,
            'content_type' => $content_type,
            'downloaded_at' => now(),
        ];
    }

    /**
     * entity.digilocker.gov.in sometimes resets TLS connections (cURL error 35),
     * especially when clients use HTTP/2, TLS 1.3, IPv6, or re-used keep-alive connections.
     *
     * This helper forces a "maximum compatibility" network profile:
     *  - HTTP/1.1 (avoid HTTP/2 stream resets)
     *  - TLS 1.2  (avoid TLS 1.3 handshake issues on some gateways)
     *  - IPv4     (avoid IPv6 routing issues on some networks)
     *  - Fresh connection / no reuse (avoid keep-alive reuse resets)
     * plus:
     *  - retry + timeout for stability
     */
    private function entity_http(?string $access_token = null)
    {
        $req = Http::withHeaders(['Accept' => 'application/json'])
            ->timeout(60)
            ->retry(2, 1000);

        if ($access_token) {
            $req = $req->withToken($access_token);
        }

        return $req->withOptions([
            'verify' => false,
            'force_ip_resolve' => 'v4',
            'curl' => [
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_FORBID_REUSE => true,
                CURLOPT_FRESH_CONNECT => true,
            ],
        ]);
    }

    private function callback_response(Request $request, bool $success, string $message, array $extra = [])
    {
        $payload = array_merge(['status' => $success ? 1 : 0, 'message' => $message], $extra);

        $frontend = config('services.entity_locker.frontend_redirect_url');
        if (!$request->expectsJson() && $frontend) {
            return redirect($frontend . '?' . http_build_query(['status' => $payload['status'], 'message' => $message]));
        }

        return response()->json($payload, $success ? 200 : 400);
    }

    private function parse_date(?string $date): ?string
    {
        if (!$date) return null;
        try {
            return Carbon::createFromFormat('d-m-Y', $date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
