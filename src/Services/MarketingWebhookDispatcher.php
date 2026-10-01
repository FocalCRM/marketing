<?php

declare(strict_types=1);

namespace Focal\Marketing\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MarketingWebhookDispatcher
{
    /**
     * Dispatch an outbound webhook event notification.
     *
     * @param  array<string, mixed>  $data
     */
    public static function dispatch(
        string $event,
        array $data,
        ?string $endpointUrl = null,
        ?string $secret = null
    ): bool {
        $url = $endpointUrl ?? config('focal-marketing.webhooks.outbound_url');
        if (empty($url) || ! is_string($url)) {
            return false;
        }

        $signingSecret = $secret ?? (string) config('focal-marketing.webhooks.secret', 'focal-default-secret');
        $timestamp = time();
        $eventId = (string) Str::uuid();

        $payload = [
            'id' => $eventId,
            'event' => $event,
            'timestamp' => $timestamp,
            'data' => $data,
        ];

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($jsonPayload === false) {
            return false;
        }

        $signature = self::generateSignature($timestamp, $jsonPayload, $signingSecret);

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'Focal-Marketing-Webhooks/1.0',
                    'X-Focal-Event' => $event,
                    'X-Focal-Delivery' => $eventId,
                    'X-Focal-Timestamp' => (string) $timestamp,
                    'X-Focal-Signature' => $signature,
                ])
                ->post($url, $payload);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Outbound marketing webhook dispatch failed: '.$e->getMessage(), [
                'event' => $event,
                'url' => $url,
                'delivery_id' => $eventId,
            ]);

            return false;
        }
    }

    /**
     * Generate an HMAC-SHA256 signature matching industry standard v1 signing schemes.
     */
    public static function generateSignature(int $timestamp, string $payload, string $secret): string
    {
        $signedData = "{$timestamp}.{$payload}";
        $hash = hash_hmac('sha256', $signedData, $secret);

        return "t={$timestamp},v1={$hash}";
    }

    /**
     * Verify an inbound or received webhook signature against a secret.
     */
    public static function verifySignature(
        string $payload,
        string $headerSignature,
        string $secret,
        int $tolerance = 300
    ): bool {
        $parts = explode(',', $headerSignature);
        $timestamp = null;
        $v1Hash = null;

        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                if ($kv[0] === 't') {
                    $timestamp = (int) $kv[1];
                } elseif ($kv[0] === 'v1') {
                    $v1Hash = $kv[1];
                }
            }
        }

        if ($timestamp === null || $v1Hash === null) {
            return false;
        }

        // Validate timestamp anti-replay tolerance
        if (abs(time() - $timestamp) > $tolerance) {
            return false;
        }

        $expectedHash = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return hash_equals($expectedHash, $v1Hash);
    }
}
