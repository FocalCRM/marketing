<?php

declare(strict_types=1);

namespace Focal\Marketing\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The anonymous visitor id shared by focal.js, embed.js and the hosted pages.
 *
 * The browser scripts generate a random id, keep it in first-party storage on the
 * host site (localStorage and a readable "_focal_vid" cookie) and send it as
 * "visitor_token" with every pageview, auto-capture and embedded form submission.
 * Nothing depends on the app's own cookies, so it works when the scripts are
 * embedded on another domain. Hosted pages served by the app itself (landing pages)
 * also carry the server-issued "focal_vid" cookie, which is used when a request
 * has no explicit token.
 */
final class VisitorToken
{
    /** Server-issued, encrypted cookie set by the pageview endpoint (same-site flows). */
    public const COOKIE = 'focal_vid';

    /** localStorage key and first-party cookie name the browser scripts use on the host site. */
    public const CLIENT_KEY = '_focal_vid';

    /** Letters, digits, "-" and "_", 16 to 64 characters. Covers ids from the scripts and Str::random(40). */
    public const PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

    public static function isValid(mixed $token): bool
    {
        return is_string($token) && preg_match(self::PATTERN, $token) === 1;
    }

    public static function generate(): string
    {
        return Str::random(40);
    }

    /**
     * The visitor token of a request: an explicit, well-formed "visitor_token" field
     * first, then the "focal_vid" cookie. Malformed values are ignored.
     */
    public static function fromRequest(Request $request): ?string
    {
        foreach ([$request->input('visitor_token'), $request->cookie(self::COOKIE)] as $candidate) {
            if (self::isValid($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * JavaScript helper embedded in focal.js and embed.js so both read and write the same id.
     * Defines focalVisitorId(), which returns the stored id or creates and stores a new one.
     */
    public static function javascript(): string
    {
        $key = self::CLIENT_KEY;

        return <<<JS
    function focalVisitorId() {
        var key = '{$key}';
        var pattern = /^[A-Za-z0-9_-]{16,64}$/;
        var vid = null;
        try { vid = window.localStorage.getItem(key); } catch (e) {}
        if (!vid || !pattern.test(vid)) {
            var match = document.cookie.match(/(?:^|;\\s*){$key}=([^;]+)/);
            vid = match ? decodeURIComponent(match[1]) : null;
        }
        if (!vid || !pattern.test(vid)) {
            var bytes = new Uint8Array(16);
            if (window.crypto && window.crypto.getRandomValues) {
                window.crypto.getRandomValues(bytes);
            } else {
                for (var i = 0; i < bytes.length; i++) { bytes[i] = Math.floor(Math.random() * 256); }
            }
            vid = '';
            for (var j = 0; j < bytes.length; j++) { vid += ('0' + bytes[j].toString(16)).slice(-2); }
        }
        try { window.localStorage.setItem(key, vid); } catch (e) {}
        document.cookie = key + '=' + vid + '; path=/; max-age=31536000; SameSite=Lax' + (window.location.protocol === 'https:' ? '; Secure' : '');
        return vid;
    }

JS;
    }
}
