<?php

declare(strict_types=1);

namespace Focal\Marketing\Enums;

enum LeadScoringEventType: string
{
    case FormSubmission = 'form_submission';
    case EmailOpened = 'email_opened';
    case EmailClicked = 'email_clicked';
    case InactivityDecay = 'inactivity_decay';
    case Unsubscribed = 'unsubscribed';
    case PropertyMatch = 'property_match';
    case CustomEvent = 'custom_event';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::FormSubmission => 'Form Submission',
            self::EmailOpened => 'Email Opened',
            self::EmailClicked => 'Link Clicked in Email',
            self::InactivityDecay => 'Inactivity Score Decay',
            self::Unsubscribed => 'Unsubscribed from Marketing',
            self::PropertyMatch => 'Contact Property Match',
            self::CustomEvent => 'Custom In-App Event',
        };
    }
}
