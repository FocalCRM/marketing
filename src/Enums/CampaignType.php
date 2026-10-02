<?php

declare(strict_types=1);

namespace Odden\Marketing\Enums;

enum CampaignType: string
{
    case Regular = 'regular';
    case Automated = 'automated';

    public function getLabel(): string
    {
        return match ($this) {
            self::Regular => 'Regular Broadcast',
            self::Automated => 'Drip Sequence',
        };
    }
}
