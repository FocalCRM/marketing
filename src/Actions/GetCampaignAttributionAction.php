<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Core\Models\Contact;
use Focal\Marketing\Enums\AttributionModel;
use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\FormSubmission;
use Focal\Sales\Enums\DealStatus;
use Focal\Sales\Models\Deal;

class GetCampaignAttributionAction
{
    /**
     * Compute marketing attribution performance: leads generated, deals influenced, and revenue pipeline
     * weighted by the requested attribution model.
     *
     * @return array{
     *     campaign_name: string,
     *     attribution_model: string,
     *     leads_count: int,
     *     engaged_contacts_count: int,
     *     deals_count: int,
     *     budget: float|null,
     *     actual_cost: float,
     *     net_profit: float,
     *     roi_percentage: float,
     *     cost_per_lead: float,
     *     pipeline_value: float,
     *     won_revenue: float,
     *     attributed_pipeline_value: float,
     *     attributed_won_revenue: float
     * }
     */
    public function execute(Campaign $campaign, AttributionModel $model = AttributionModel::Linear): array
    {
        $campaignSlug = strtolower(str_replace(' ', '-', $campaign->name));

        // 1. Leads generated via form submissions with matching UTM campaign
        $formSubmissions = FormSubmission::query()
            ->where(function ($q) use ($campaign, $campaignSlug): void {
                $q->where('utm_campaign', $campaignSlug)
                    ->orWhere('utm_campaign', $campaign->name);
            })
            ->get();

        $formContactIds = $formSubmissions->pluck('contact_id')->filter()->unique()->all();

        // 2. Engaged campaign recipients (opened or clicked)
        $engagedContactIds = $campaign->recipients()
            ->where(function ($q): void {
                $q->whereNotNull('opened_at')->orWhereNotNull('clicked_at');
            })
            ->pluck('contact_id')
            ->filter()
            ->unique()
            ->all();

        $allInfluencedContactIds = array_values(array_unique(array_merge($formContactIds, $engagedContactIds)));

        $dealsCount = 0;
        $rawPipelineValue = 0.0;
        $rawWonRevenue = 0.0;

        if (! empty($allInfluencedContactIds) && class_exists(Deal::class)) {
            // Find deals associated with influenced contacts
            $contacts = Contact::query()->whereIn('id', $allInfluencedContactIds)->get();

            $dealIds = [];
            foreach ($contacts as $contact) {
                $associatedDeals = $contact->getAssociated(Deal::class);
                foreach ($associatedDeals as $deal) {
                    $dealIds[$deal->id] = $deal;
                }
            }

            $dealsCount = count($dealIds);
            foreach ($dealIds as $deal) {
                if ($deal->status === DealStatus::Won) {
                    $rawWonRevenue += (float) $deal->amount;
                } elseif ($deal->status === DealStatus::Open) {
                    $rawPipelineValue += (float) $deal->amount;
                }
            }
        }

        // Apply attribution weighting factor based on selected model
        $weight = match ($model) {
            AttributionModel::FirstTouch => ! empty($formContactIds) ? 1.0 : 0.5,
            AttributionModel::LastTouch => ! empty($engagedContactIds) ? 1.0 : 0.5,
            AttributionModel::UShaped => (! empty($formContactIds) && ! empty($engagedContactIds)) ? 0.80 : 0.60,
            AttributionModel::WShaped => 0.70, // 30% first + 30% lead + 10% nurture
            AttributionModel::TimeDecay => 0.65, // Recency-weighted decay
            AttributionModel::Linear => 0.50,  // Equal multi-channel contribution
        };

        $attributedPipeline = $rawPipelineValue * $weight;
        $attributedWon = $rawWonRevenue * $weight;

        $cost = (float) ($campaign->actual_spend ?: $campaign->actual_cost ?: 0.0);
        $netProfit = $attributedWon - $cost;
        $roiPercentage = $cost > 0 ? round(($netProfit / $cost) * 100, 2) : 0.0;
        $costPerLead = count($formContactIds) > 0 ? round($cost / count($formContactIds), 2) : 0.0;

        return [
            'campaign_name' => $campaign->name,
            'attribution_model' => $model->value,
            'leads_count' => count($formContactIds),
            'engaged_contacts_count' => count($engagedContactIds),
            'deals_count' => $dealsCount,
            'budget' => $campaign->budget !== null ? (float) $campaign->budget : null,
            'actual_cost' => $cost,
            'net_profit' => round($netProfit, 2),
            'roi_percentage' => $roiPercentage,
            'cost_per_lead' => $costPerLead,
            'pipeline_value' => round($rawPipelineValue, 2),
            'won_revenue' => round($rawWonRevenue, 2),
            'attributed_pipeline_value' => round($attributedPipeline, 2),
            'attributed_won_revenue' => round($attributedWon, 2),
        ];
    }
}
