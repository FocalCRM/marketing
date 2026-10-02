<?php

declare(strict_types=1);

namespace Odden\Marketing\Enums;

enum RecipientStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Unsubscribed = 'unsubscribed';

    /** Skipped at send time: unsubscribed, bounced or suppressed after the campaign was dispatched. */
    case Suppressed = 'suppressed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Opened => 'Opened',
            self::Clicked => 'Clicked',
            self::Bounced => 'Bounced',
            self::Unsubscribed => 'Unsubscribed',
            self::Suppressed => 'Suppressed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Sent => 'info',
            self::Opened => 'primary',
            self::Clicked => 'success',
            self::Bounced => 'danger',
            self::Unsubscribed => 'warning',
            self::Suppressed => 'gray',
        };
    }
}
