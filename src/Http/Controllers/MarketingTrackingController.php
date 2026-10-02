<?php

declare(strict_types=1);

namespace Focal\Marketing\Http\Controllers;

use Focal\Marketing\Actions\ApplyLeadScoringEventAction;
use Focal\Marketing\Enums\LeadScoringEventType;
use Focal\Marketing\Enums\RecipientStatus;
use Focal\Marketing\Models\CampaignRecipient;
use Focal\Marketing\Models\MarketingSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class MarketingTrackingController extends Controller
{
    /**
     * Record an email open event and return a 1x1 transparent tracking pixel.
     */
    public function trackOpen(string $token): Response
    {
        /** @var CampaignRecipient|null $recipient */
        $recipient = CampaignRecipient::query()->where('tracking_token', $token)->first();

        if ($recipient !== null) {
            $recipient->recordOpen();

            if ($recipient->contact !== null) {
                app(ApplyLeadScoringEventAction::class)->execute(
                    $recipient->contact,
                    LeadScoringEventType::EmailOpened,
                    "Opened email in campaign: {$recipient->campaign->name}"
                );
            }
        }

        // 1x1 transparent GIF binary
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

        return response($gif, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Record a link click event and redirect recipient to destination URL.
     *
     * Only destinations signed for this token when the email was compiled are
     * followed; anything else is a 404, so the endpoint is not an open redirect.
     */
    public function trackClick(Request $request, string $token): RedirectResponse
    {
        $destinationUrl = $request->query('url');

        if (! CampaignRecipient::hasValidClickSignature($token, $destinationUrl, $request->query('sig'))
            || ! is_string($destinationUrl)
            || ! in_array(strtolower((string) parse_url($destinationUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
            || filter_var($destinationUrl, FILTER_VALIDATE_URL) === false) {
            abort(404);
        }

        /** @var CampaignRecipient|null $recipient */
        $recipient = CampaignRecipient::query()->where('tracking_token', $token)->first();

        if ($recipient !== null) {
            $recipient->recordClick();

            if ($recipient->contact !== null) {
                app(ApplyLeadScoringEventAction::class)->execute(
                    $recipient->contact,
                    LeadScoringEventType::EmailClicked,
                    "Clicked link in campaign: {$recipient->campaign->name}"
                );
            }
        }

        return redirect()->away($destinationUrl);
    }

    /**
     * Display the 1-click unsubscribe confirmation page.
     */
    public function showUnsubscribe(string $token): Response
    {
        /** @var CampaignRecipient $recipient */
        $recipient = CampaignRecipient::query()
            ->where('unsubscribe_token', $token)
            ->firstOrFail();

        return response()->view('focal-marketing::unsubscribe.show', [
            'recipient' => $recipient,
        ]);
    }

    /**
     * Process opt-out request for a recipient.
     */
    public function processUnsubscribe(Request $request, string $token): Response
    {
        /** @var CampaignRecipient $recipient */
        $recipient = CampaignRecipient::query()
            ->where('unsubscribe_token', $token)
            ->firstOrFail();

        MarketingSubscription::unsubscribe($recipient->email, $recipient->contact_id);

        if ($recipient->status !== RecipientStatus::Unsubscribed) {
            $recipient->update(['status' => RecipientStatus::Unsubscribed]);
            $recipient->campaign->increment('unsubscribes_count');

            if ($recipient->contact !== null) {
                app(ApplyLeadScoringEventAction::class)->execute(
                    $recipient->contact,
                    LeadScoringEventType::Unsubscribed,
                    'Unsubscribed from marketing communications'
                );
            }
        }

        return response()->view('focal-marketing::unsubscribe.confirmed', [
            'email' => $recipient->email,
        ]);
    }
}
