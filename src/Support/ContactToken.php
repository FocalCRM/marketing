<?php

declare(strict_types=1);

namespace Odden\Marketing\Support;

use Odden\Core\Models\Contact;

/**
 * Signed contact identity for public, unauthenticated links (hosted forms, schema lookups).
 *
 * A bare contact id from the request must never identify a contact: anyone could
 * enumerate ids to read names or attach data to other people's records. Links sent
 * to a known contact carry "{id}.{hmac}" instead, scoped so a token issued for one
 * purpose cannot be replayed for another.
 */
final class ContactToken
{
    public static function make(Contact $contact, string $scope): string
    {
        return $contact->getKey().'.'.self::signature((string) $contact->getKey(), $scope);
    }

    public static function resolve(mixed $token, string $scope): ?Contact
    {
        if (! is_string($token) || preg_match('/^(\d+)\.([a-f0-9]{64})$/', $token, $matches) !== 1) {
            return null;
        }

        if (! hash_equals(self::signature($matches[1], $scope), $matches[2])) {
            return null;
        }

        /** @var Contact|null */
        return Contact::query()->find((int) $matches[1]);
    }

    public static function forForm(int|string $formId): string
    {
        return "form:{$formId}";
    }

    public static function forEvent(int|string $eventId): string
    {
        return "event:{$eventId}";
    }

    private static function signature(string $contactId, string $scope): string
    {
        return hash_hmac('sha256', "odden-contact|{$scope}|{$contactId}", (string) config('app.key'));
    }
}
