<?php

declare(strict_types=1);

namespace Odden\Marketing\Enums;

enum WorkflowTriggerType: string
{
    case FormSubmitted = 'form_submitted';
    case ContactCreated = 'contact_created';
    case ListJoined = 'list_joined';
    case LeadScoreReached = 'lead_score_reached';
    case AssetDownloaded = 'asset_downloaded';
    case EventAttended = 'event_attended';
    case CustomEvent = 'custom_event';
    case InboundWebhook = 'inbound_webhook';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return match ($this) {
            self::FormSubmitted => 'Lead Capture Form Submitted',
            self::ContactCreated => 'New Contact Created',
            self::ListJoined => 'Contact Added to Audience List',
            self::LeadScoreReached => 'Lead Score Threshold Met',
            self::AssetDownloaded => 'Gated Asset Downloaded',
            self::EventAttended => 'Webinar / Event Attended',
            self::CustomEvent => 'Custom In-App / Product Event',
            self::InboundWebhook => 'Inbound Webhook / External API',
            self::Manual => 'Manual Enrollment',
        };
    }
}
