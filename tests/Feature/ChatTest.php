<?php

namespace Tests\Feature;

use App\Jobs\ChatWithAgent;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ChatTest extends TestCase
{
    public function test_chat_endpoint_rejects_missing_token(): void
    {
        Queue::fake();

        $this->postJson('/api/chat', ['message' => 'Portfolio question'])
            ->assertStatus(403)
            ->assertJson(['error' => 'Unauthorized']);

        Queue::assertNothingPushed();
    }

    public function test_chat_endpoint_dispatches_agent_job_when_authenticated(): void
    {
        Queue::fake();
        $token = $this->signedToken(time() + 60);

        $this->withToken($token)->postJson('/api/chat', [
            'message' => 'Portfolio question',
            'conversation_id' => 'runtime-check',
        ])->assertStatus(202);

        Queue::assertPushed(ChatWithAgent::class);
    }

    public function test_chat_endpoint_rejects_expired_token(): void
    {
        Queue::fake();
        $token = $this->signedToken(time() - 60);

        $this->withToken($token)->postJson('/api/chat', ['message' => 'Portfolio question'])
            ->assertStatus(403)
            ->assertJson(['error' => 'Invalid token']);

        Queue::assertNothingPushed();
    }

    private function signedToken(int $expiresAt): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKey);
        $details = openssl_pkey_get_details($key);
        Cache::put('jwks_public_key', [
            'kty' => 'RSA',
            'alg' => 'RS256',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']),
            'e' => JWT::urlsafeB64Encode($details['rsa']['e']),
        ], 60);

        return JWT::encode(['id' => 123, 'exp' => $expiresAt], $privateKey, 'RS256');
    }
}
