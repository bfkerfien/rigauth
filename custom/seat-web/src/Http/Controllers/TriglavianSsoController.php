<?php

namespace Seat\Web\Http\Controllers;

use Illuminate\Http\Request;

class TriglavianSsoController extends Controller
{
    /**
     * Issue a short-lived, signed assertion to the Triglavian Market app.
     * This route is inside SeAT's authenticated web middleware group.
     */
    public function authorize(Request $request)
    {
        $redirectUri = (string) $request->query('redirect_uri', '');
        $expectedRedirect = env(
            'TRIGLAVIAN_MARKET_SSO_CALLBACK',
            'https://market.triglavianconsortium.com/auth/seat/callback'
        );

        if (! hash_equals($expectedRedirect, $redirectUri)) {
            abort(400, 'Unregistered SSO callback URL.');
        }

        $state = (string) $request->query('state', '');
        if (! preg_match('/^[A-Za-z0-9_-]{20,200}$/', $state)) {
            abort(400, 'Invalid SSO state.');
        }

        $secret = (string) env('TRIGLAVIAN_MARKET_SSO_SECRET', '');
        if ($secret === '') {
            abort(503, 'The Triglavian Market SSO bridge is not configured.');
        }

        $user = $request->user();
        if (! $user || ! $user->can('global.superuser')) {
            abort(403, 'A SeAT superuser account is required to manage market fittings.');
        }

        $claims = [
            'iss' => 'https://seat.triglavianconsortium.com',
            'aud' => 'triglavian-market',
            'sub' => (string) $user->getAuthIdentifier(),
            'character_id' => (int) ($user->main_character_id ?? 0),
            'state' => $state,
            'iat' => time(),
            'exp' => time() + 60,
            'admin' => true,
        ];

        $json = json_encode($claims, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            abort(500, 'Could not create SSO assertion.');
        }

        $payload = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $signature = rtrim(strtr(
            base64_encode(hash_hmac('sha256', $payload, $secret, true)),
            '+/',
            '-_'
        ), '=');

        return redirect()->away($expectedRedirect . '?' . http_build_query([
            'assertion' => $payload . '.' . $signature,
            'state' => $state,
        ]));
    }
}
