<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Odden\Core\Http\Middleware\RequireApiToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates inbound ESP deliverability webhooks.
 *
 * Mailgun cannot send custom headers, so when a Mailgun signing key is configured the
 * request is authenticated by Mailgun's own HMAC signature instead of the API token:
 * HMAC-SHA256 of "{timestamp}{token}" with the signing key, within a time window, with
 * each signature token accepted once. Every other provider, and Mailgun without a
 * signing key, keeps using the API token.
 */
final class AuthenticateEspWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $signingKey = config('odden-marketing.esp.mailgun.signing_key');

        if (strtolower((string) $request->route('provider')) === 'mailgun' && is_string($signingKey) && $signingKey !== '') {
            abort_unless($this->hasValidMailgunSignature($request, $signingKey), 401, 'Invalid or missing Mailgun webhook signature.');

            return $next($request);
        }

        return (new RequireApiToken)->handle($request, $next, 'odden-marketing.api.token');
    }

    private function hasValidMailgunSignature(Request $request, string $signingKey): bool
    {
        $signature = $request->input('signature');

        if (! is_array($signature)) {
            return false;
        }

        $timestamp = $signature['timestamp'] ?? null;
        $token = $signature['token'] ?? null;
        $given = $signature['signature'] ?? null;

        if (! is_scalar($timestamp) || ! is_string($token) || $token === '' || ! is_string($given)) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.$token, $signingKey);

        if (! hash_equals($expected, $given)) {
            return false;
        }

        $tolerance = max(1, (int) config('odden-marketing.esp.mailgun.tolerance', 900));

        if (! ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        // A signature token is single-use inside the window, which stops a captured request being replayed.
        return Cache::add('odden-marketing:mailgun-webhook:'.$token, true, $tolerance);
    }
}
