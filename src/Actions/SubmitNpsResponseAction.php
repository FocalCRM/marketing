<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\NpsResponse;

class SubmitNpsResponseAction
{
    public function __construct(
        public ApplyLeadScoringEventAction $scoringAction,
    ) {}

    /**
     * Submit an NPS rating score, categorize customer sentiment, update CRM contact properties,
     * and apply lead scoring impact.
     */
    public function execute(
        NpsResponse $response,
        int $score,
        ?string $feedback = null
    ): NpsResponse {
        $clampedScore = max(0, min(10, $score));
        $category = NpsResponse::categorizeScore($clampedScore);

        return DB::transaction(function () use ($response, $clampedScore, $category, $feedback): NpsResponse {
            $isFirstSubmission = $response->responded_at === null;

            $response->update([
                'score' => $clampedScore,
                'category' => $category,
                'feedback' => $feedback ?? $response->feedback,
                'responded_at' => now(),
            ]);

            /** @var Contact|null $contact */
            $contact = $response->contact;

            if ($contact !== null) {
                $props = $contact->properties ?? [];
                $props['latest_nps_score'] = $clampedScore;
                $props['nps_sentiment'] = $category;
                $props['nps_responded_at'] = now()->toIso8601String();
                $contact->updateQuietly(['properties' => $props]);

                // Apply lead scoring bonus or penalty
                if ($isFirstSubmission) {
                    if ($category === 'promoter') {
                        $this->scoringAction->execute(
                            contact: $contact,
                            eventType: LeadScoringEventType::PropertyMatch,
                            description: 'NPS Promoter Feedback (+10 pts)',
                            points: 10,
                        );
                    } elseif ($category === 'detractor') {
                        $this->scoringAction->execute(
                            contact: $contact,
                            eventType: LeadScoringEventType::PropertyMatch,
                            description: 'NPS Detractor Alert (-10 pts)',
                            points: -10,
                        );
                    }
                }
            }

            return $response->fresh() ?? $response;
        });
    }
}
