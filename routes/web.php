<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Odden\Core\Http\Middleware\RequireApiToken;
use Odden\Core\Support\CsrfExemption;
use Odden\Core\Support\RouteGroup;
use Odden\Marketing\Http\Controllers\AmpFormController;
use Odden\Marketing\Http\Controllers\CustomBehavioralEventController;
use Odden\Marketing\Http\Controllers\DynamicEmailImageController;
use Odden\Marketing\Http\Controllers\EspWebhookController;
use Odden\Marketing\Http\Controllers\ExternalLeadWebhookController;
use Odden\Marketing\Http\Controllers\LandingPageController;
use Odden\Marketing\Http\Controllers\MarketingAssetController;
use Odden\Marketing\Http\Controllers\MarketingEventController;
use Odden\Marketing\Http\Controllers\MarketingFormController;
use Odden\Marketing\Http\Controllers\MarketingPreferencesController;
use Odden\Marketing\Http\Controllers\MarketingTrackingController;
use Odden\Marketing\Http\Controllers\NpsSurveyController;
use Odden\Marketing\Http\Controllers\TransactionalTemplateController;
use Odden\Marketing\Http\Controllers\WebTrackingController;
use Odden\Marketing\Http\Controllers\WorkflowEnrollmentWebhookController;

// Server-to-server endpoints require the marketing API token; browser-facing
// submissions are rate limited per IP (see odden-core.rate_limits).
$apiToken = RequireApiToken::class.':odden-marketing.api.token';

Route::group(RouteGroup::attributes('odden-marketing.routes.web'), function () use ($apiToken): void {
    // Hosted lead capture forms
    Route::get('/forms/{slug}', [MarketingFormController::class, 'show'])->name('odden.marketing.forms.show');
    Route::post('/forms/{slug}', [MarketingFormController::class, 'submit'])
        ->middleware('throttle:odden-public')
        ->name('odden.marketing.forms.submit');

    // Embeddable Form JavaScript Loader & Headless JSON Schema
    Route::get('/marketing/forms/embed.js', [MarketingFormController::class, 'embedScript'])->name('odden.marketing.forms.embed-script');
    Route::get('/marketing/forms/{slug}/embed.js', [MarketingFormController::class, 'embedScript'])->name('odden.marketing.forms.slug-embed-script');
    Route::get('/marketing/forms/{slug}/schema.json', [MarketingFormController::class, 'schema'])->name('odden.marketing.forms.schema');

    // Email Marketing Open & Click Tracking
    Route::get('/marketing/track/open/{token}', [MarketingTrackingController::class, 'trackOpen'])->name('odden.marketing.track.open');
    Route::get('/marketing/track/click/{token}', [MarketingTrackingController::class, 'trackClick'])->name('odden.marketing.track.click');

    // Unsubscribe & Compliance Center
    Route::get('/marketing/unsubscribe/{token}', [MarketingTrackingController::class, 'showUnsubscribe'])->name('odden.marketing.unsubscribe.show');
    // Also the RFC 8058 one-click endpoint (List-Unsubscribe-Post): mailbox providers POST
    // without a session, so it is CSRF-exempt; the unsubscribe token is the credential.
    // Those POSTs come from a few provider IPs, so it uses the server-to-server limit.
    Route::post('/marketing/unsubscribe/{token}', [MarketingTrackingController::class, 'processUnsubscribe'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware('throttle:odden-api')
        ->name('odden.marketing.unsubscribe.process');

    // Inbound Web Tracking & Client Script
    Route::get('/marketing/odden.js', [WebTrackingController::class, 'clientScript'])->name('odden.marketing.track.script');
    Route::post('/marketing/track/pageview', [WebTrackingController::class, 'pageview'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware('throttle:odden-public')
        ->name('odden.marketing.track.pageview');

    // External Form Auto-Capture Endpoint
    Route::post('/marketing/forms/auto-capture', [WebTrackingController::class, 'autoCapture'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware('throttle:odden-public')
        ->name('odden.marketing.forms.auto-capture');

    // Inbound ESP Deliverability Webhooks (bounces, complaints)
    Route::post('/marketing/webhooks/esp/{provider}', [EspWebhookController::class, 'handle'])
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware([$apiToken, 'throttle:odden-api'])
        ->name('odden.marketing.webhooks.esp');

    // Hosted Public Landing Pages
    Route::get('/p/{slug}', [LandingPageController::class, 'show'])->name('odden.marketing.landing-pages.show');
    Route::post('/p/{slug}/submit', [LandingPageController::class, 'submit'])
        ->middleware('throttle:odden-public')
        ->name('odden.marketing.landing-pages.submit');

    // Self-Service Preference Center & Topic Subscriptions
    Route::get('/marketing/preferences/{token}', [MarketingPreferencesController::class, 'showPreferences'])->name('odden.marketing.preferences.show');
    Route::post('/marketing/preferences/{token}', [MarketingPreferencesController::class, 'updatePreferences'])
        ->middleware('throttle:odden-public')
        ->name('odden.marketing.preferences.update');

    // Double Opt-In Email Verification
    Route::get('/marketing/confirm/{token}', [MarketingPreferencesController::class, 'confirmEmail'])->name('odden.marketing.confirm');

    // Net Promoter Score (NPS) 1-Click Rating & Feedback
    Route::get('/marketing/nps/{token}/{score}', [NpsSurveyController::class, 'recordScore'])->name('odden.marketing.nps.rate');
    Route::post('/marketing/nps/{token}/feedback', [NpsSurveyController::class, 'submitFeedback'])
        ->middleware('throttle:odden-public')
        ->name('odden.marketing.nps.feedback');

    // Gated Marketing Assets / Lead Magnet Downloads
    Route::get('/marketing/assets/{slug}/download', [MarketingAssetController::class, 'download'])->name('odden.marketing.assets.download');

    // Real-Time Dynamic Email Images (Countdown Timers & Personalized Badges)
    Route::get('/marketing/images/countdown-timer.svg', [DynamicEmailImageController::class, 'countdownTimer'])
        ->name('odden.marketing.images.countdown-timer');
    Route::get('/marketing/images/badge.svg', [DynamicEmailImageController::class, 'personalizedBadge'])
        ->name('odden.marketing.images.badge');
});

Route::group(RouteGroup::attributes('odden-marketing.routes.api'), function () use ($apiToken): void {
    Route::withoutMiddleware(CsrfExemption::middleware())->group(function () use ($apiToken): void {
        // Public, browser-facing endpoints (embedded forms, event sign-ups, in-email AMP forms).
        Route::middleware('throttle:odden-public')->group(function (): void {
            // External / Embed form submission endpoint (CSRF-exempt for cross-site landing pages)
            Route::post('/forms/{slug}', [MarketingFormController::class, 'submit'])->name('odden.marketing.forms.api-submit');

            // Marketing Events & Webinar Registrations
            Route::post('/events/{slug}/register', [MarketingEventController::class, 'register'])->name('odden.marketing.events.register');

            // Interactive In-Email AMP Form Handlers (NPS feedback & event RSVPs)
            Route::post('/amp/feedback', [AmpFormController::class, 'feedback'])->name('odden.marketing.amp.feedback');
            Route::post('/amp/rsvp', [AmpFormController::class, 'rsvp'])->name('odden.marketing.amp.rsvp');
        });

        // Server-to-server endpoints: require the marketing API token.
        Route::middleware([$apiToken, 'throttle:odden-api'])->group(function (): void {
            // Inbound External Webhook Lead Ingestion (Zapier, LinkedIn Lead Gen, Zoom Webinars)
            Route::post('/leads/webhook/{source?}', [ExternalLeadWebhookController::class, 'handle'])->name('odden.marketing.leads.webhook');

            // Inbound ESP Deliverability Webhooks
            Route::post('/webhooks/deliverability', [EspWebhookController::class, 'deliverability'])->name('odden.marketing.webhooks.deliverability');

            // Webinar attendance webhooks
            Route::post('/events/{slug}/attendance-webhook', [MarketingEventController::class, 'attendanceWebhook'])->name('odden.marketing.events.attendance-webhook');

            // In-App Custom Behavioral Events (Product-Led Growth / Custom Tracking API)
            Route::post('/events/track', [CustomBehavioralEventController::class, 'track'])->name('odden.marketing.events.track');

            // Inbound Webhook Workflow Enrollment (Zapier, Segment, Stripe, telemetry)
            Route::post('/workflows/{workflow}/enroll', [WorkflowEnrollmentWebhookController::class, 'enroll'])->name('odden.marketing.workflows.enroll-webhook');

            // Headless Transactional Email API (trigger template send programmatically via API)
            Route::post('/templates/{template}/send', [TransactionalTemplateController::class, 'send'])->name('odden.marketing.templates.send');
            Route::post('/templates/{template}/send-batch', [TransactionalTemplateController::class, 'sendBatch'])->name('odden.marketing.templates.send-batch');
        });
    });
});
