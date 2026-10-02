<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Carbon\CarbonInterface;
use DateTimeZone;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Illuminate\Support\Carbon;

class CalculateRecipientOptimalSendTimeAction
{
    /**
     * Map common ISO country codes to primary timezones.
     *
     * @var array<string, string>
     */
    protected array $countryTimezoneMap = [
        'US' => 'America/New_York',
        'USA' => 'America/New_York',
        'GB' => 'Europe/London',
        'UK' => 'Europe/London',
        'DE' => 'Europe/Berlin',
        'FR' => 'Europe/Paris',
        'NL' => 'Europe/Amsterdam',
        'AU' => 'Australia/Sydney',
        'CA' => 'America/Toronto',
        'JP' => 'Asia/Tokyo',
        'IN' => 'Asia/Kolkata',
        'SG' => 'Asia/Singapore',
        'NZ' => 'Pacific/Auckland',
        'BR' => 'America/Sao_Paulo',
    ];

    /**
     * Calculate the optimal UTC send time for a recipient in a given campaign.
     */
    public function execute(Campaign $campaign, ?Contact $contact = null, ?CarbonInterface $baseDate = null): CarbonInterface
    {
        $timezone = $this->resolveTimezone($contact);
        $targetHour = 9;
        $targetMinute = 0;

        // 1. Send Time Optimization (historical behavioral peak)
        if ($campaign->use_sto && $contact !== null) {
            $stoHour = $this->calculateContactPeakOpenHour($contact, $timezone);
            if ($stoHour !== null) {
                $targetHour = $stoHour;
            } else {
                $targetHour = $campaign->recipient_send_hour ?: 9;
            }
        } elseif (! empty($campaign->scheduled_local_time)) {
            $parts = explode(':', $campaign->scheduled_local_time);
            $targetHour = (int) $parts[0];
            $targetMinute = isset($parts[1]) ? (int) $parts[1] : 0;
        } elseif ($campaign->recipient_send_hour > 0) {
            $targetHour = $campaign->recipient_send_hour;
        }

        // 2. Base Date calculation
        $base = $baseDate ?? ($campaign->scheduled_at ?? Carbon::now('UTC'));
        $localTime = Carbon::instance($base)->setTimezone($timezone);

        $targetDate = $localTime->copy()->setTime($targetHour, $targetMinute, 0);

        // If the window has already passed in the local timezone for the target day, schedule for the next day
        if ($targetDate->isPast() && $targetDate->diffInMinutes(now($timezone), false) > 15) {
            $targetDate->addDay();
        }

        return $targetDate->setTimezone('UTC');
    }

    /**
     * Resolve the recipient's timezone using contact properties, country, or fallback.
     */
    public function resolveTimezone(?Contact $contact): string
    {
        if ($contact === null) {
            return (string) config('app.timezone', 'UTC');
        }

        // Direct timezone property
        $tz = $contact->timezone ?? ($contact->properties['timezone'] ?? null);
        if (is_string($tz) && in_array($tz, DateTimeZone::listIdentifiers(), true)) {
            return $tz;
        }

        // Country inference
        $country = $contact->country ?? ($contact->properties['country'] ?? null);
        if (is_string($country)) {
            $countryUpper = mb_strtoupper(trim($country));
            if (isset($this->countryTimezoneMap[$countryUpper])) {
                return $this->countryTimezoneMap[$countryUpper];
            }
        }

        return (string) config('app.timezone', 'UTC');
    }

    /**
     * Compute a contact's historical peak open hour in their local timezone.
     */
    protected function calculateContactPeakOpenHour(Contact $contact, string $timezone): ?int
    {
        /** @var list<string> $openTimestamps */
        $openTimestamps = CampaignRecipient::query()
            ->where('contact_id', $contact->id)
            ->whereNotNull('opened_at')
            ->pluck('opened_at')
            ->map(fn ($val) => (string) $val)
            ->all();

        if (empty($openTimestamps)) {
            return null;
        }

        $hourCounts = [];
        foreach ($openTimestamps as $ts) {
            $hour = Carbon::parse($ts)->setTimezone($timezone)->hour;
            $hourCounts[$hour] = ($hourCounts[$hour] ?? 0) + 1;
        }

        arsort($hourCounts);

        $peakHour = array_key_first($hourCounts);

        return $peakHour;
    }
}
