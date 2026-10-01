<?php

declare(strict_types=1);

namespace Focal\Marketing\Enums;

enum AttributionModel: string
{
    case FirstTouch = 'first_touch';
    case LastTouch = 'last_touch';
    case Linear = 'linear';
    case UShaped = 'u_shaped';
    case WShaped = 'w_shaped';
    case TimeDecay = 'time_decay';

    /**
     * Get a human-readable label for the attribution model.
     */
    public function label(): string
    {
        return match ($this) {
            self::FirstTouch => 'First-Touch Attribution (100% to Acquisition)',
            self::LastTouch => 'Last-Touch Attribution (100% to Final Conversion)',
            self::Linear => 'Linear Attribution (Equal Weighting)',
            self::UShaped => 'U-Shaped Attribution (40/40/20 Weighting)',
            self::WShaped => 'W-Shaped Attribution (30/30/30/10 Weighting)',
            self::TimeDecay => 'Time-Decay Attribution (Recency-Weighted)',
        };
    }
}
