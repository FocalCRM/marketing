<?php

declare(strict_types=1);

namespace Focal\Marketing\Enums;

enum RecipientStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Opened = 'opened';
    case Clicked = 'clicked';
    case Bounced = 'bounced';
    case Unsubscribed = 'unsubscribed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Sent => 'Sent',
            self::Opened => 'Opened',
            self::Clicked => 'Clicked',
            self::Bounced => 'Bounced',
            self::Unsubscribed => 'Unsubscribed',
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
        };
    }
}
