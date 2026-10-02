<?php

declare(strict_types=1);

use Focal\Core\Http\Middleware\RequireApiToken;
use Focal\Core\Support\CsrfExemption;
use Focal\Core\Support\RouteGroup;
use Focal\Marketing\Http\Controllers\AmpFormController;
use Focal\Marketing\Http\Controllers\CustomBehavioralEventController;
use Focal\Marketing\Http\Controllers\DynamicEmailImageController;
use Focal\Marketing\Http\Controllers\EspWebhookController;
use Focal\Marketing\Http\Controllers\ExternalLeadWebhookController;
use Focal\Marketing\Http\Controllers\LandingPageController;
use Focal\Marketing\Http\Controllers\MarketingAssetController;
use Focal\Marketing\Http\Controllers\MarketingEventController;
use Focal\Marketing\Http\Controllers\MarketingFormController;
use Focal\Marketing\Http\Controllers\MarketingPreferencesController;
use Focal\Marketing\Http\Controllers\MarketingTrackingController;
use Focal\Marketing\Http\Controllers\NpsSurveyController;
use Focal\Marketing\Http\Controllers\TransactionalTemplateController;
use Focal\Marketing\Http\Controllers\WebTrackingController;
use Focal\Marketing\Http\Controllers\WorkflowEnrollmentWebhookController;
use Illuminate\Support\Facades\Route;

// Server-to-server endpoints require the marketing API token; browser-facing
// submissions are rate limited per IP (see focal-core.rate_limits).
$apiToken = RequireApiToken::class.':focal-marketing.api.token';

Route::group(RouteGroup::attributes('focal-marketing.routes.web'), function () use ($apiToken): void {
    // Hosted lead capture forms
    Route::get('/forms/{slug}', [MarketingFormController::class, 'show'])->name('focal.marketing.forms.show');
    Route::post('/forms/{slug}', [MarketingFormController::class, 'submit'])
        ->middleware('throttle:focal-public')
        ->name('focal.marketing.forms.submit');

    // Embeddable Form JavaScript Loader & Headless JSON Schema
    Route::get('/marketing/forms/embed.js', [MarketingFormController::class, 'embedScript'])->name('focal.marketing.forms.embed-script');
    Route::get('/marketing/forms/{slug}/embed.js', [MarketingFormController::class, 'embedScript'])->name('focal.marketing.forms.slug-embed-script');
    Route::get('/marketing/forms/{slug}/schema.json', [MarketingFormController::class, 'schema'])->name('focal.marketing.forms.schema');

    // Email Marketing Open & Click Tracking
    Route::get('/marketing/track/open/{token}', [MarketingTrackingController::class, 'trackOpen'])->name('focal.marketing.track.open');
    Route::get('/marketing/track/click/{token}', [MarketingTrackingController::class, 'trackClick'])->name('focal.marketing.track.click');

    // Unsubscribe & Compliance Center
    Route::get('/marketing/unsubscribe/{token}', [MarketingTrackingController::class, 'showUnsubscribe'])->name('focal.marketing.unsubscribe.show');
    // Also the RFC 8058 one-click endpoint (List-Unsubscribe-Post): mailbox providers POST
    // without a session, so it is CSRF-exempt; the unsubscribe token is the credential.
    // Those POSTs come from a few provider IPs, so it uses the server-to-server limit.
    Route::post('/marketing/unsubscribe/{token}', [MarketingTrackingController::class, 'processUnsubscribe'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware('throttle:focal-api')
        ->name('focal.marketing.unsubscribe.process');

    // Inbound Web Tracking & Client Script
    Route::get('/marketing/focal.js', [WebTrackingController::class, 'clientScript'])->name('focal.marketing.track.script');
    Route::post('/marketing/track/pageview', [WebTrackingController::class, 'pageview'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware('throttle:focal-public')
        ->name('focal.marketing.track.pageview');

    // External Form Auto-Capture Endpoint
    Route::post('/marketing/forms/auto-capture', [WebTrackingController::class, 'autoCapture'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware('throttle:focal-public')
        ->name('focal.marketing.forms.auto-capture');

    // Inbound ESP Deliverability Webhooks (bounces, complaints)
    Route::post('/marketing/webhooks/esp/{provider}', [EspWebhookController::class, 'handle'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware([$apiToken, 'throttle:focal-api'])
        ->name('focal.marketing.webhooks.esp');

    // Hosted Public Landing Pages
    Route::get('/p/{slug}', [LandingPageController::class, 'show'])->name('focal.marketing.landing-pages.show');
    Route::post('/p/{slug}/submit', [LandingPageController::class, 'submit'])
        ->middleware('throttle:focal-public')
        ->name('focal.marketing.landing-pages.submit');

    // Self-Service Preference Center & Topic Subscriptions
    Route::get('/marketing/preferences/{token}', [MarketingPreferencesController::class, 'showPreferences'])->name('focal.marketing.preferences.show');
    Route::post('/marketing/preferences/{token}', [MarketingPreferencesController::class, 'updatePreferences'])
        ->middleware('throttle:focal-public')
        ->name('focal.marketing.preferences.update');

    // Double Opt-In Email Verification
    Route::get('/marketing/confirm/{token}', [MarketingPreferencesController::class, 'confirmEmail'])->name('focal.marketing.confirm');

    // Net Promoter Score (NPS) 1-Click Rating & Feedback
    Route::get('/marketing/nps/{token}/{score}', [NpsSurveyController::class, 'recordScore'])->name('focal.marketing.nps.rate');
    Route::post('/marketing/nps/{token}/feedback', [NpsSurveyController::class, 'submitFeedback'])
        ->middleware('throttle:focal-public')
        ->name('focal.marketing.nps.feedback');

    // Gated Marketing Assets / Lead Magnet Downloads
    Route::get('/marketing/assets/{slug}/download', [MarketingAssetController::class, 'download'])->name('focal.marketing.assets.download');

    // Real-Time Dynamic Email Images (Countdown Timers & Personalized Badges)
    Route::get('/marketing/images/countdown-timer.svg', [DynamicEmailImageController::class, 'countdownTimer'])
        ->name('focal.marketing.images.countdown-timer');
    Route::get('/marketing/images/badge.svg', [DynamicEmailImageController::class, 'personalizedBadge'])
        ->name('focal.marketing.images.badge');
});

Route::group(RouteGroup::attributes('focal-marketing.routes.api'), function () use ($apiToken): void {
    Route::withoutMiddleware(CsrfExemption::middleware())->group(function () use ($apiToken): void {
        // Public, browser-facing endpoints (embedded forms, event sign-ups, in-email AMP forms).
        Route::middleware('throttle:focal-public')->group(function (): void {
            // External / Embed form submission endpoint (CSRF-exempt for cross-site landing pages)
            Route::post('/forms/{slug}', [MarketingFormController::class, 'submit'])->name('focal.marketing.forms.api-submit');

            // Marketing Events & Webinar Registrations
            Route::post('/events/{slug}/register', [MarketingEventController::class, 'register'])->name('focal.marketing.events.register');

            // Interactive In-Email AMP Form Handlers (NPS feedback & event RSVPs)
            Route::post('/amp/feedback', [AmpFormController::class, 'feedback'])->name('focal.marketing.amp.feedback');
            Route::post('/amp/rsvp', [AmpFormController::class, 'rsvp'])->name('focal.marketing.amp.rsvp');
        });

        // Server-to-server endpoints: require the marketing API token.
        Route::middleware([$apiToken, 'throttle:focal-api'])->group(function (): void {
            // Inbound External Webhook Lead Ingestion (Zapier, LinkedIn Lead Gen, Zoom Webinars)
            Route::post('/leads/webhook/{source?}', [ExternalLeadWebhookController::class, 'handle'])->name('focal.marketing.leads.webhook');

            // Inbound ESP Deliverability Webhooks
            Route::post('/webhooks/deliverability', [EspWebhookController::class, 'deliverability'])->name('focal.marketing.webhooks.deliverability');

            // Webinar attendance webhooks
            Route::post('/events/{slug}/attendance-webhook', [MarketingEventController::class, 'attendanceWebhook'])->name('focal.marketing.events.attendance-webhook');

            // In-App Custom Behavioral Events (Product-Led Growth / Custom Tracking API)
            Route::post('/events/track', [CustomBehavioralEventController::class, 'track'])->name('focal.marketing.events.track');

            // Inbound Webhook Workflow Enrollment (Zapier, Segment, Stripe, telemetry)
            Route::post('/workflows/{workflow}/enroll', [WorkflowEnrollmentWebhookController::class, 'enroll'])->name('focal.marketing.workflows.enroll-webhook');

            // Headless Transactional Email API (trigger template send programmatically via API)
            Route::post('/templates/{template}/send', [TransactionalTemplateController::class, 'send'])->name('focal.marketing.templates.send');
            Route::post('/templates/{template}/send-batch', [TransactionalTemplateController::class, 'sendBatch'])->name('focal.marketing.templates.send-batch');
        });
    });
});
