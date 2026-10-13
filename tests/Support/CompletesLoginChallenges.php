<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

trait CompletesLoginChallenges
{
    /** Complete the real two-step API for existing role/token/domain tests. */
    private function completeLogin(array $credentials, array $verification = []): TestResponse
    {
        $before = DB::table('personal_access_tokens')->count();
        $challenge = $this->loginRequest('/api/login', $credentials)->assertStatus(202)
            ->assertJsonMissingPath('access_token')->assertJsonMissingPath('user');
        $this->assertSame($before, DB::table('personal_access_tokens')->count(), 'Password acceptance alone must never issue a bearer token.');
        $row = DB::table('login_email_verifications')->where('challenge_id', $challenge->json('challenge_id'))->sole();

        return $this->loginRequest('/api/login/email-verifications/'.$row->challenge_id.'/verify',
            ['code' => Crypt::decryptString($row->encrypted_code)] + $verification)->assertOk();
    }

    private function loginRequest(string $uri, array $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('POST', $uri, [], [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode((object) $body, JSON_THROW_ON_ERROR));
    }
}
