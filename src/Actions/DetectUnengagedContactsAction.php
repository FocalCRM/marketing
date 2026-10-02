<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Core\Models\Contact;
use Illuminate\Database\Eloquent\Collection;

class DetectUnengagedContactsAction
{
    /**
     * Identify dormant contacts with no email engagement in the last $daysInactive days.
     *
     * @return Collection<int, Contact>
     */
    public function execute(int $daysInactive = 90): Collection
    {
        $cutoff = now()->subDays($daysInactive);

        /** @var Collection<int, Contact> $contacts */
        $contacts = Contact::query()
            ->where(function ($query) use ($cutoff): void {
                $query->whereNotNull('last_marketing_email_sent_at')
                    ->where('last_marketing_email_sent_at', '<', $cutoff);
            })
            ->where('is_unengaged', false)
            ->get();

        foreach ($contacts as $contact) {
            $contact->update([
                'is_unengaged' => true,
                'unengaged_since' => now(),
                'sunset_stage' => 'flagged',
            ]);
        }

        return $contacts;
    }
}
