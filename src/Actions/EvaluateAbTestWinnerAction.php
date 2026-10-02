<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Models\Campaign;

class EvaluateAbTestWinnerAction
{
    /**
     * Evaluate A/B test results, pick the winning variant based on engagement,
     * and roll out the winning variant to all remaining staged recipients.
     *
     * @return array{winner: string, metric: string, variant_a_score: float, variant_b_score: float, remaining_sent: int}
     */
    public function execute(Campaign $campaign): array
    {
        if (! $campaign->is_ab_test || $campaign->ab_winner_variant !== null) {
            return [
                'winner' => $campaign->ab_winner_variant ?? 'A',
                'metric' => $campaign->ab_winning_metric,
                'variant_a_score' => 0.0,
                'variant_b_score' => 0.0,
                'remaining_sent' => 0,
            ];
        }

        // Metrics for Variant A
        $sentA = $campaign->recipients()->where('variant', 'A')->count();
        $opensA = $campaign->recipients()->where('variant', 'A')->whereNotNull('opened_at')->count();
        $clicksA = $campaign->recipients()->where('variant', 'A')->whereNotNull('clicked_at')->count();

        $openRateA = $sentA > 0 ? ($opensA / $sentA) * 100 : 0.0;
        $clickRateA = $sentA > 0 ? ($clicksA / $sentA) * 100 : 0.0;

        // Metrics for Variant B
        $sentB = $campaign->recipients()->where('variant', 'B')->count();
        $opensB = $campaign->recipients()->where('variant', 'B')->whereNotNull('opened_at')->count();
        $clicksB = $campaign->recipients()->where('variant', 'B')->whereNotNull('clicked_at')->count();

        $openRateB = $sentB > 0 ? ($opensB / $sentB) * 100 : 0.0;
        $clickRateB = $sentB > 0 ? ($clicksB / $sentB) * 100 : 0.0;

        $isClickMetric = $campaign->ab_winning_metric === 'click_rate';
        $scoreA = $isClickMetric ? $clickRateA : $openRateA;
        $scoreB = $isClickMetric ? $clickRateB : $openRateB;

        $winner = $scoreB > $scoreA ? 'B' : 'A';

        // Roll out winner to remaining pending audience
        $pendingRecipients = $campaign->recipients()
            ->where('status', RecipientStatus::Pending->value)
            ->with('contact')
            ->get();

        $delivery = app(DeliverCampaignMessageAction::class);
        $remainingSent = 0;

        foreach ($pendingRecipients as $recipient) {
            $outcome = $delivery->execute(
                $campaign,
                $recipient,
                variant: $winner,
                activityTitle: "Marketing Campaign: {$campaign->name} (Winning Variant {$winner})",
            );

            if ($outcome === DeliverCampaignMessageAction::QUEUED) {
                $remainingSent++;
            }
        }

        $campaign->update([
            'ab_winner_variant' => $winner,
            'ab_test_evaluated_at' => now(),
            'status' => CampaignStatus::Sent,
            'delivered_count' => $campaign->recipients()->whereNotNull('sent_at')->count(),
        ]);

        return [
            'winner' => $winner,
            'metric' => $campaign->ab_winning_metric,
            'variant_a_score' => round($scoreA, 2),
            'variant_b_score' => round($scoreB, 2),
            'remaining_sent' => $remainingSent,
        ];
    }
}
