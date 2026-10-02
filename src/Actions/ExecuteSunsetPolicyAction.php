<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;

class ExecuteSunsetPolicyAction
{
    /**
     * Execute sunset policy progression on an unengaged contact.
     * Transitions: flagged -> reengagement_sent -> suppressed
     */
    public function execute(Contact $contact, bool $forceSuppress = false): Contact
    {
        return DB::transaction(function () use ($contact, $forceSuppress): Contact {
            if ($forceSuppress || $contact->sunset_stage === 'reengagement_sent') {
                $contact->update([
                    'is_unengaged' => true,
                    'sunset_stage' => 'suppressed',
                ]);

                $contact->logTask(
                    title: 'Sunset Policy: Contact Suppressed',
                    dueAt: now(),
                    body: 'Contact automatically suppressed under deliverability sunset policy after prolonged inactivity to protect sender domain reputation.'
                );
            } elseif ($contact->sunset_stage === 'flagged' || $contact->is_unengaged) {
                $contact->update([
                    'sunset_stage' => 'reengagement_sent',
                ]);

                $contact->logTask(
                    title: 'Sunset Policy: Re-engagement Step Triggered',
                    dueAt: now(),
                    body: 'Triggered 14-day re-engagement verification sequence for dormant subscriber.'
                );
            }

            return $contact->fresh() ?? $contact;
        });
    }
}
