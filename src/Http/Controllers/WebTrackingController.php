<?php

declare(strict_types=1);

namespace Focal\Marketing\Http\Controllers;

use Focal\Core\Enums\ActivityType;
use Focal\Core\Enums\LifecycleStage;
use Focal\Core\Models\Contact;
use Focal\Marketing\Actions\RecordWebVisitAction;
use Focal\Marketing\Models\VisitorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class WebTrackingController extends Controller
{
    /**
     * Ingestion endpoint for first-party website pageview events.
     */
    public function pageview(Request $request, RecordWebVisitAction $action): JsonResponse
    {
        $visitorToken = $request->input('visitor_token') ?: $request->cookie('focal_vid');

        $defaultUrl = (string) config('app.url', 'http://localhost');
        $referer = is_string($ref = $request->header('referer')) ? $ref : $defaultUrl;
        $url = (string) $request->input('url', $referer);

        $result = $action->execute([
            'visitor_token' => $visitorToken,
            'contact_id' => $request->user()?->id !== null ? null : null, // Handled via session/auth if logged in
            'url' => $url,
            'path' => $request->input('path'),
            'title' => $request->input('title'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->input('referer'),
            'utm_source' => $request->input('utm_source'),
            'utm_medium' => $request->input('utm_medium'),
            'utm_campaign' => $request->input('utm_campaign'),
            'duration_seconds' => $request->input('duration_seconds') ? (int) $request->input('duration_seconds') : null,
        ]);

        $token = $result['session']->visitor_token;

        return response()->json([
            'status' => 'success',
            'session_id' => $result['session']->id,
            'visitor_token' => $token,
        ])->cookie('focal_vid', $token, 525600); // 1 year cookie
    }

    /**
     * Ingestion endpoint for external website form auto-capture.
     * Automatically ingests HTML forms submitted on host websites into Focal Leads.
     */
    public function autoCapture(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email')));
        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['status' => 'error', 'message' => 'Valid email address is required.'], 422);
        }

        $firstName = (string) $request->input('first_name');
        $lastName = (string) $request->input('last_name');

        if (empty($firstName) && ! empty($request->input('name'))) {
            $parts = explode(' ', trim((string) $request->input('name')), 2);
            $firstName = $parts[0];
            $lastName = $parts[1] ?? '';
        }

        $phone = $request->input('phone') ? (string) $request->input('phone') : null;
        $pageUrl = (string) $request->input('page_url', 'Website Form');

        /** @var Contact $contact */
        $contact = Contact::query()->firstOrNew(['email' => $email]);
        $isNew = ! $contact->exists;

        if (empty($contact->first_name) && ! empty($firstName)) {
            $contact->first_name = $firstName;
        }
        if (empty($contact->last_name) && ! empty($lastName)) {
            $contact->last_name = $lastName;
        }
        if (empty($contact->phone) && ! empty($phone)) {
            $contact->phone = $phone;
        }

        if ($isNew) {
            $contact->lifecycle_stage = LifecycleStage::MarketingQualifiedLead;
            $contact->lead_score = 15;
        } else {
            $contact->lead_score = (int) $contact->lead_score + 10;
        }

        $contact->save();

        // Link with visitor tracking session if token provided
        $visitorToken = $request->input('visitor_token') ?: $request->cookie('focal_vid');
        if (! empty($visitorToken) && class_exists(VisitorSession::class)) {
            VisitorSession::query()
                ->where('visitor_token', $visitorToken)
                ->whereNull('contact_id')
                ->update(['contact_id' => $contact->id]);
        }

        // Log timeline activity
        $contact->logActivity(
            type: ActivityType::Task,
            title: 'Website Form Auto-Captured',
            body: "Visitor submitted an external form on {$pageUrl}."
        );

        return response()->json([
            'status' => 'success',
            'contact_id' => $contact->id,
            'is_new' => $isNew,
        ]);
    }

    /**
     * Serve lightweight embeddable client tracking JavaScript script with Form Auto-Capture.
     */
    public function clientScript(): Response
    {
        $script = <<<'JS'
(function() {
    var FOCAL_PAGEVIEW_URL = __FOCAL_PAGEVIEW_URL__;
    var FOCAL_AUTO_CAPTURE_URL = __FOCAL_AUTO_CAPTURE_URL__;

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
        return match ? match[2] : null;
    }
    function sendPageView() {
        var vid = getCookie('focal_vid');
        var params = new URLSearchParams(window.location.search);
        var payload = {
            visitor_token: vid,
            url: window.location.href,
            path: window.location.pathname,
            title: document.title,
            referer: document.referrer,
            utm_source: params.get('utm_source'),
            utm_medium: params.get('utm_medium'),
            utm_campaign: params.get('utm_campaign')
        };
        fetch(FOCAL_PAGEVIEW_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify(payload)
        });
    }

    function interceptForms() {
        var forms = document.querySelectorAll('form');
        forms.forEach(function(form) {
            if (form.getAttribute('data-focal-tracked')) return;
            form.setAttribute('data-focal-tracked', 'true');
            form.addEventListener('submit', function() {
                var emailInput = form.querySelector('input[type="email"], input[name*="email"]');
                if (!emailInput || !emailInput.value) return;
                var nameInput = form.querySelector('input[name="name"], input[name*="full_name"]');
                var firstInput = form.querySelector('input[name*="first"]');
                var lastInput = form.querySelector('input[name*="last"]');
                var phoneInput = form.querySelector('input[type="tel"], input[name*="phone"]');
                var params = new URLSearchParams(window.location.search);

                var payload = {
                    visitor_token: getCookie('focal_vid'),
                    email: emailInput.value,
                    name: nameInput ? nameInput.value : null,
                    first_name: firstInput ? firstInput.value : null,
                    last_name: lastInput ? lastInput.value : null,
                    phone: phoneInput ? phoneInput.value : null,
                    page_url: window.location.href,
                    utm_source: params.get('utm_source'),
                    utm_medium: params.get('utm_medium'),
                    utm_campaign: params.get('utm_campaign')
                };

                try {
                    if (navigator.sendBeacon) {
                        navigator.sendBeacon(FOCAL_AUTO_CAPTURE_URL, JSON.stringify(payload));
                    } else {
                        fetch(FOCAL_AUTO_CAPTURE_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload),
                            keepalive: true
                        });
                    }
                } catch(e) {}
            });
        });
    }

    if (document.readyState === 'complete') {
        sendPageView();
        interceptForms();
    } else {
        window.addEventListener('load', function() {
            sendPageView();
            interceptForms();
        });
    }
})();
JS;

        // Absolute URLs so the script works when embedded on other domains and honors route prefixes.
        $script = strtr($script, [
            '__FOCAL_PAGEVIEW_URL__' => json_encode(route('focal.marketing.track.pageview'), JSON_UNESCAPED_SLASHES),
            '__FOCAL_AUTO_CAPTURE_URL__' => json_encode(route('focal.marketing.forms.auto-capture'), JSON_UNESCAPED_SLASHES),
        ]);

        return response($script, 200, [
            'Content-Type' => 'application/javascript',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
