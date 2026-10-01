<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Core\Models\Company;
use Focal\Core\Models\Contact;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CalculateCompanyIntentScoreAction
{
    /**
     * Calculate and sync the aggregate ABM intent score, buying committee size,
     * and surge status for a company based on all associated contact engagement.
     */
    public function execute(Company $company): Company
    {
        return DB::transaction(function () use ($company): Company {
            /** @var Collection<int, Contact> $contacts */
            $contacts = $company->contacts;

            $totalScore = 0;
            $engagedPersonas = 0;
            $latestActivity = null;

            foreach ($contacts as $contact) {
                // Base lead score contribution
                $totalScore += $contact->lead_score;

                // Check recency: activity within last 30 days
                $hasRecentActivity = false;
                if ($contact->last_contacted_at !== null && $contact->last_contacted_at->greaterThan(now()->subDays(30))) {
                    $hasRecentActivity = true;
                    if ($latestActivity === null || $contact->last_contacted_at->greaterThan($latestActivity)) {
                        $latestActivity = $contact->last_contacted_at;
                    }
                }

                if ($contact->lead_score > 0 || $hasRecentActivity) {
                    $engagedPersonas++;
                }
            }

            // High-intent tier bonus
            $tierBonus = match ($company->account_tier) {
                'tier_1' => 50,
                'tier_2' => 25,
                default => 0,
            };

            $aggregateIntent = $totalScore + $tierBonus;

            // An account is "surging" if intent score >= 75 OR 2+ active buying committee members
            $isSurging = $aggregateIntent >= 75 || $engagedPersonas >= 2;
            $wasSurging = $company->intent_surge;

            $company->update([
                'intent_score' => $aggregateIntent,
                'intent_surge' => $isSurging,
                'buying_committee_size' => $engagedPersonas,
                'last_intent_activity_at' => $latestActivity ?? now(),
            ]);

            // Trigger sales priority task if company just started surging
            if ($isSurging && ! $wasSurging) {
                $company->logTask(
                    title: "ABM Intent Surge: {$company->name}",
                    dueAt: now()->addHours(4),
                    body: "Account {$company->name} entered high-intent surge status (Score: {$aggregateIntent}, Buying Committee: {$engagedPersonas} personas)."
                );
            }

            return $company->fresh() ?? $company;
        });
    }
}
