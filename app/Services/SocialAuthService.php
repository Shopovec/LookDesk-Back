<?php
namespace App\Services;

use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SocialAuthService
{
    public function google(string $token): array
    {
        $res = Http::get('https://www.googleapis.com/oauth2/v3/userinfo', [
            'access_token' => $token
        ])->json();

        if (!isset($res['sub'])) {
            throw new \Exception('Invalid Google token');
        }

        return [
            'provider' => 'google',
            'provider_id' => $res['sub'],
            'email' => $res['email'] ?? null,
            'name' => $res['name'] ?? 'Google User',
            'profile' => $res
        ];
    }

    public function microsoft(string $token): array
    {
        $res = Http::withToken($token)
            ->get('https://graph.microsoft.com/v1.0/me')
            ->json();

        if (!isset($res['id'])) {
            throw new \Exception('Invalid Microsoft token');
        }

        return [
            'provider' => 'microsoft',
            'provider_id' => $res['id'],
            'email' => $res['mail'] ?? $res['userPrincipalName'] ?? null,
            'name' => $res['displayName'] ?? 'Microsoft User',
            'profile' => $res
        ];
    }

    public function resolveUser(array $data): User
    {
        $social = UserSocialAccount::where([
            'provider' => $data['provider'],
            'provider_id' => $data['provider_id'],
        ])->first();

        if ($social) {
            return $social->user;
        }

        $user = User::firstOrCreate(
            ['email' => $data['email']],
            [
                'name' => $data['name'],
                'password' => bcrypt(Str::random(32)),
                'role_id' => 4,
                'email_verified_at' => now(),
            ]
        );

        UserSocialAccount::create([
            'user_id' => $user->id,
            'provider' => $data['provider'],
            'provider_id' => $data['provider_id'],
            'email' => $data['email'],
            'profile' => $data['profile'],
        ]);

        return $user;
    }
}