<?php

declare(strict_types=1);

namespace Focal\Marketing\Enums;

enum SubscriptionStatus: string
{
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
    case Bounced = 'bounced';

    public function getLabel(): string
    {
        return match ($this) {
            self::Subscribed => 'Subscribed',
            self::Unsubscribed => 'Unsubscribed (Opted Out)',
            self::Bounced => 'Bounced (Suppressed)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Subscribed => 'success',
            self::Unsubscribed => 'danger',
            self::Bounced => 'warning',
        };
    }
}
