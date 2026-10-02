<?php

declare(strict_types=1);

namespace Focal\Marketing\Exceptions;

use Focal\Marketing\Models\Campaign;
use RuntimeException;

/**
 * Thrown when a campaign is dispatched without a list and without explicit contacts.
 * Dispatch never falls back to every contact in the CRM.
 */
class CampaignHasNoAudienceException extends RuntimeException
{
    public static function for(Campaign $campaign): self
    {
        return new self("Campaign [{$campaign->name}] (#{$campaign->id}) has no audience: assign a list (list_id or crm_list_id) or pass the contacts to dispatch.");
    }
}
