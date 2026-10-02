<?php

declare(strict_types=1);

use Odden\Core\Actions\MergeCompaniesAction;
use Odden\Core\Actions\MergeContactsAction;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Enums\SubscriptionStatus;
use Odden\Marketing\Enums\WorkflowEnrollmentStatus;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\CustomBehavioralEvent;
use Odden\Marketing\Models\EspEvent;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\LeadDecayLog;
use Odden\Marketing\Models\LeadScoreLog;
use Odden\Marketing\Models\MarketingAsset;
use Odden\Marketing\Models\MarketingAssetDownload;
use Odden\Marketing\Models\MarketingContactTopic;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingEventRegistration;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Models\MarketingSmsMessage;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\MarketingSubscriptionTopic;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Marketing\Models\NpsResponse;
use Odden\Marketing\Models\NpsSurvey;
use Odden\Marketing\Models\PageView;
use Odden\Marketing\Models\VisitorSession;
use Odden\Marketing\Models\WorkflowEnrollment;
use Odden\Marketing\Models\WorkflowLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{0: Contact, 1: Contact}
 */
function mergeTestContacts(): array
{
    return [
        Contact::create(['first_name' => 'Ada', 'email' => 'ada@example.com']),
        Contact::create(['first_name' => 'Ada', 'email' => 'ada.lovelace@example.org']),
    ];
}

function mergeTestCampaign(string $name = 'Newsletter'): Campaign
{
    return Campaign::create([
        'name' => $name,
        'subject' => 'News',
        'sender_name' => 'Odden',
        'sender_email' => 'news@example.com',
        'status' => CampaignStatus::Sent,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mergeTestRecipient(Campaign $campaign, Contact $contact, array $attributes = []): CampaignRecipient
{
    return CampaignRecipient::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'email' => $contact->email,
        'status' => RecipientStatus::Sent,
        'sent_at' => now()->subDay(),
        ...$attributes,
    ]);
}

test('merging contacts moves every contact-keyed marketing row to the primary', function () {
    [$primary, $secondary] = mergeTestContacts();
    $id = $secondary->id;

    $form = MarketingForm::create(['title' => 'Demo', 'slug' => 'demo', 'fields_schema' => []]);
    $campaign = mergeTestCampaign();
    $workflow = MarketingWorkflow::create(['name' => 'Nurture', 'trigger_type' => WorkflowTriggerType::Manual, 'is_active' => true]);
    $session = VisitorSession::create(['visitor_token' => Str::random(40), 'contact_id' => $id]);
    $survey = NpsSurvey::create(['name' => 'Q3']);
    $asset = MarketingAsset::create(['name' => 'Guide', 'slug' => 'guide']);
    $event = MarketingEvent::create(['title' => 'Webinar', 'slug' => 'webinar']);
    $topic = MarketingSubscriptionTopic::create(['name' => 'Product', 'slug' => 'product']);

    FormSubmission::create(['form_id' => $form->id, 'contact_id' => $id, 'form_data' => []]);
    mergeTestRecipient($campaign, $secondary);
    MarketingSubscription::create(['contact_id' => $id, 'email' => $secondary->email, 'status' => SubscriptionStatus::Subscribed]);
    MarketingContactTopic::create(['email' => $secondary->email, 'contact_id' => $id, 'topic_id' => $topic->id, 'is_subscribed' => true]);
    DB::table((new LeadScoreLog)->getTable())->insert(['contact_id' => $id, 'event_type' => 'form_submission', 'event_description' => 'Form', 'score_change' => 5, 'score_after' => 5]);
    DB::table((new LeadDecayLog)->getTable())->insert(['contact_id' => $id, 'score_before' => 10, 'score_after' => 5, 'score_decayed' => 5, 'days_inactive' => 30]);
    $enrollment = WorkflowEnrollment::create(['workflow_id' => $workflow->id, 'contact_id' => $id, 'status' => WorkflowEnrollmentStatus::Completed]);
    DB::table((new WorkflowLog)->getTable())->insert(['enrollment_id' => $enrollment->id, 'contact_id' => $id, 'action_taken' => 'sent']);
    DB::table((new PageView)->getTable())->insert(['session_id' => $session->id, 'contact_id' => $id, 'url' => 'https://example.com/pricing', 'path' => '/pricing']);
    DB::table((new MarketingSmsMessage)->getTable())->insert(['contact_id' => $id, 'phone_number' => '+15550100', 'message_body' => 'Hi']);
    DB::table((new NpsResponse)->getTable())->insert(['survey_id' => $survey->id, 'contact_id' => $id, 'score' => 9, 'category' => 'promoter', 'token' => Str::random(40)]);
    DB::table((new MarketingAssetDownload)->getTable())->insert(['asset_id' => $asset->id, 'contact_id' => $id, 'downloaded_at' => now()]);
    MarketingEventRegistration::create(['event_id' => $event->id, 'contact_id' => $id, 'status' => 'registered', 'registered_at' => now()]);
    CustomBehavioralEvent::create(['contact_id' => $id, 'event_name' => 'workspace_upgraded', 'occurred_at' => now()]);

    $models = [
        FormSubmission::class, CampaignRecipient::class, MarketingSubscription::class, MarketingContactTopic::class,
        LeadScoreLog::class, LeadDecayLog::class, WorkflowEnrollment::class, WorkflowLog::class, VisitorSession::class,
        PageView::class, MarketingSmsMessage::class, NpsResponse::class, MarketingAssetDownload::class,
        MarketingEventRegistration::class, CustomBehavioralEvent::class,
    ];

    app(MergeContactsAction::class)->execute($primary, $secondary);

    foreach ($models as $model) {
        $table = (new $model)->getTable();

        expect(DB::table($table)->where('contact_id', $secondary->id)->count())->toBe(0, "{$table} still has rows for the secondary")
            ->and(DB::table($table)->where('contact_id', $primary->id)->count())->toBe(1, "{$table} has no row for the primary");
    }
});

test('a campaign both contacts received keeps the most engaged recipient and detaches the other sent one', function () {
    [$primary, $secondary] = mergeTestContacts();
    $campaign = mergeTestCampaign();
    $sentOnly = mergeTestRecipient($campaign, $primary);
    $clicked = mergeTestRecipient($campaign, $secondary, ['status' => RecipientStatus::Clicked, 'opened_at' => now(), 'clicked_at' => now()]);
    $delivered = EspEvent::create(['provider' => 'ses', 'event_type' => 'delivered', 'email' => $primary->email, 'campaign_id' => $campaign->id, 'recipient_id' => $sentOnly->id]);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect(CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('contact_id', $primary->id)->pluck('id')->all())->toBe([$clicked->id])
        ->and($sentOnly->fresh()->contact_id)->toBeNull()
        ->and($delivered->fresh()->recipient_id)->toBe($sentOnly->id);
});

test('the detached recipient\'s unsubscribe link still works after a merge', function () {
    [$primary, $secondary] = mergeTestContacts();
    $campaign = mergeTestCampaign();
    $sentOnly = mergeTestRecipient($campaign, $primary);
    mergeTestRecipient($campaign, $secondary, ['status' => RecipientStatus::Clicked, 'opened_at' => now(), 'clicked_at' => now()]);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    $this->post(route('odden.marketing.unsubscribe.process', $sentOnly->unsubscribe_token))->assertOk();

    expect(MarketingSubscription::isSuppressed($primary->email))->toBeTrue();
});

test('an unsent duplicate recipient is deleted and its ESP events move to the kept one', function () {
    [$primary, $secondary] = mergeTestContacts();
    $campaign = mergeTestCampaign();
    $pending = mergeTestRecipient($campaign, $primary, ['status' => RecipientStatus::Pending, 'sent_at' => null]);
    $sent = mergeTestRecipient($campaign, $secondary);
    $event = EspEvent::create(['provider' => 'ses', 'event_type' => 'delivered', 'email' => $primary->email, 'campaign_id' => $campaign->id, 'recipient_id' => $pending->id]);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect(CampaignRecipient::query()->where('campaign_id', $campaign->id)->pluck('id')->all())->toBe([$sent->id])
        ->and($sent->fresh()->contact_id)->toBe($primary->id)
        ->and($event->fresh()->recipient_id)->toBe($sent->id);
});

test('a campaign both contacts received keeps an unsubscribed recipient, with the other\'s engagement', function () {
    [$primary, $secondary] = mergeTestContacts();
    $campaign = mergeTestCampaign();
    $clicked = mergeTestRecipient($campaign, $primary, ['status' => RecipientStatus::Clicked, 'opened_at' => now()->subHour(), 'clicked_at' => now()->subHour()]);
    $unsubscribed = mergeTestRecipient($campaign, $secondary, ['status' => RecipientStatus::Unsubscribed]);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    $kept = CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('contact_id', $primary->id)->sole();

    expect($kept->id)->toBe($unsubscribed->id)
        ->and($kept->contact_id)->toBe($primary->id)
        ->and($kept->status)->toBe(RecipientStatus::Unsubscribed)
        ->and($kept->clicked_at)->not->toBeNull()
        ->and($kept->opened_at)->not->toBeNull()
        ->and($clicked->fresh()->contact_id)->toBeNull();
});

test('a campaign only one contact received moves without conflict', function () {
    [$primary, $secondary] = mergeTestContacts();
    $mine = mergeTestRecipient(mergeTestCampaign('A'), $primary);
    $theirs = mergeTestRecipient(mergeTestCampaign('B'), $secondary);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect($mine->fresh()->contact_id)->toBe($primary->id)
        ->and($theirs->fresh()->contact_id)->toBe($primary->id);
});

test('an unsubscribe on the secondary carries over to the primary\'s address', function () {
    [$primary, $secondary] = mergeTestContacts();
    MarketingSubscription::create(['contact_id' => $primary->id, 'email' => $primary->email, 'status' => SubscriptionStatus::Subscribed]);
    $optOut = MarketingSubscription::unsubscribe($secondary->email, $secondary->id);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect(MarketingSubscription::isSuppressed($primary->email))->toBeTrue()
        ->and(MarketingSubscription::isSuppressed($secondary->email))->toBeTrue()
        ->and($optOut->fresh()->contact_id)->toBe($primary->id)
        ->and(MarketingSubscription::query()->where('email', $primary->email)->sole()->status)->toBe(SubscriptionStatus::Unsubscribed);
});

test('an unsubscribed primary is never resubscribed by a subscribed secondary', function () {
    [$primary, $secondary] = mergeTestContacts();
    MarketingSubscription::unsubscribe($primary->email, $primary->id);
    MarketingSubscription::create(['contact_id' => $secondary->id, 'email' => $secondary->email, 'status' => SubscriptionStatus::Subscribed]);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect(MarketingSubscription::isSuppressed($primary->email))->toBeTrue()
        ->and(MarketingSubscription::query()->where('contact_id', $primary->id)->count())->toBe(2);
});

test('an unsubscribe on a shared address stays in place and moves to the primary', function () {
    $primary = Contact::create(['first_name' => 'Ada', 'email' => 'ada@example.com']);
    $secondary = Contact::create(['first_name' => 'Ada', 'email' => 'ada@example.com']);
    MarketingSubscription::unsubscribe('ada@example.com', $secondary->id);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    $subscription = MarketingSubscription::query()->where('email', 'ada@example.com')->sole();

    expect($subscription->contact_id)->toBe($primary->id)
        ->and($subscription->status)->toBe(SubscriptionStatus::Unsubscribed);
});

test('a topic opt-out on the secondary carries over to the primary\'s address', function () {
    [$primary, $secondary] = mergeTestContacts();
    $topic = MarketingSubscriptionTopic::create(['name' => 'Product', 'slug' => 'product', 'is_default' => true]);
    MarketingSubscriptionTopic::setSubscription($primary->email, $topic->id, true, $primary->id);
    MarketingSubscriptionTopic::setSubscription($secondary->email, $topic->id, false, $secondary->id);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect(MarketingSubscriptionTopic::isSubscribed($primary->email, $topic->id))->toBeFalse()
        ->and(MarketingSubscriptionTopic::isSubscribed($secondary->email, $topic->id))->toBeFalse()
        ->and(MarketingContactTopic::query()->where('contact_id', $secondary->id)->count())->toBe(0);
});

test('an event both contacts registered for keeps the attended registration and fixes the counters', function () {
    [$primary, $secondary] = mergeTestContacts();
    $event = MarketingEvent::create(['title' => 'Webinar', 'slug' => 'webinar', 'registrations_count' => 2, 'attendees_count' => 1]);
    $registered = MarketingEventRegistration::create(['event_id' => $event->id, 'contact_id' => $primary->id, 'status' => 'registered', 'registered_at' => now()->subDays(2), 'utm_source' => 'linkedin']);
    $attended = MarketingEventRegistration::create(['event_id' => $event->id, 'contact_id' => $secondary->id, 'status' => 'attended', 'registered_at' => now()->subDay(), 'attended_at' => now()]);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    $kept = MarketingEventRegistration::query()->where('event_id', $event->id)->sole();

    expect($kept->id)->toBe($attended->id)
        ->and($kept->contact_id)->toBe($primary->id)
        ->and($kept->status)->toBe('attended')
        ->and($kept->utm_source)->toBe('linkedin')
        ->and($registered->fresh())->toBeNull()
        ->and($event->fresh()->registrations_count)->toBe(1)
        ->and($event->fresh()->attendees_count)->toBe(1);
});

test('a workflow both contacts are active in keeps one active enrollment', function () {
    [$primary, $secondary] = mergeTestContacts();
    $workflow = MarketingWorkflow::create(['name' => 'Nurture', 'trigger_type' => WorkflowTriggerType::Manual, 'is_active' => true]);
    $older = WorkflowEnrollment::create(['workflow_id' => $workflow->id, 'contact_id' => $primary->id, 'status' => WorkflowEnrollmentStatus::Active, 'enrolled_at' => now()->subWeek(), 'next_run_at' => now()]);
    $newer = WorkflowEnrollment::create(['workflow_id' => $workflow->id, 'contact_id' => $secondary->id, 'status' => WorkflowEnrollmentStatus::Active, 'enrolled_at' => now()->subDay(), 'next_run_at' => now()]);

    app(MergeContactsAction::class)->execute($primary, $secondary);

    expect($older->fresh()->status)->toBe(WorkflowEnrollmentStatus::Active)
        ->and($newer->fresh()->status)->toBe(WorkflowEnrollmentStatus::Exited)
        ->and($newer->fresh()->next_run_at)->toBeNull()
        ->and($newer->fresh()->contact_id)->toBe($primary->id);
});

test('merging companies moves the secondary company\'s behavioural events to the primary', function () {
    [$primary, $secondary] = Company::factory()->count(2)->create();
    $event = CustomBehavioralEvent::create(['company_id' => $secondary->id, 'event_name' => 'seat_added', 'occurred_at' => now()]);

    app(MergeCompaniesAction::class)->execute($primary, $secondary);

    expect($event->fresh()->company_id)->toBe($primary->id);
});
