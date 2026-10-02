<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class DynamicEmailImageController extends Controller
{
    /**
     * Render a real-time countdown timer clock as a dynamic vector SVG image.
     */
    public function countdownTimer(Request $request): Response
    {
        $until = $request->query('until', 'now + 3 days');
        $label = htmlspecialchars((string) $request->query('label', 'FLASH SALE ENDS IN'));
        $primaryColor = htmlspecialchars((string) $request->query('color', '#2563EB'));
        $bgColor = htmlspecialchars((string) $request->query('bg', '#0F172A'));
        $textColor = htmlspecialchars((string) $request->query('text', '#FFFFFF'));

        try {
            $target = Carbon::parse((string) $until);
        } catch (\Throwable) {
            $target = Carbon::now()->addDays(2);
        }

        $now = Carbon::now();
        $isExpired = $now->greaterThanOrEqualTo($target);

        if ($isExpired) {
            $days = 0;
            $hours = 0;
            $minutes = 0;
            $seconds = 0;
        } else {
            $diff = $now->diff($target);
            $days = (int) $diff->days;
            $hours = (int) $diff->h;
            $minutes = (int) $diff->i;
            $seconds = (int) $diff->s;
        }

        $daysStr = str_pad((string) $days, 2, '0', STR_PAD_LEFT);
        $hoursStr = str_pad((string) $hours, 2, '0', STR_PAD_LEFT);
        $minutesStr = str_pad((string) $minutes, 2, '0', STR_PAD_LEFT);
        $secondsStr = str_pad((string) $seconds, 2, '0', STR_PAD_LEFT);

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="560" height="130" viewBox="0 0 560 130" fill="none">
    <rect width="560" height="130" rx="12" fill="{$bgColor}" />
    <text x="280" y="32" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="12" font-weight="700" fill="{$primaryColor}" text-anchor="middle" letter-spacing="1.5">{$label}</text>
    
    <!-- Timer Digits -->
    <g transform="translate(60, 48)">
        <!-- Days -->
        <rect x="0" y="0" width="85" height="60" rx="8" fill="#1E293B" />
        <text x="42.5" y="42" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="30" font-weight="800" fill="{$textColor}" text-anchor="middle">{$daysStr}</text>
        <text x="42.5" y="74" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="10" font-weight="600" fill="#94A3B8" text-anchor="middle">DAYS</text>
        
        <!-- Hours -->
        <rect x="115" y="0" width="85" height="60" rx="8" fill="#1E293B" />
        <text x="157.5" y="42" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="30" font-weight="800" fill="{$textColor}" text-anchor="middle">{$hoursStr}</text>
        <text x="157.5" y="74" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="10" font-weight="600" fill="#94A3B8" text-anchor="middle">HOURS</text>
        
        <!-- Minutes -->
        <rect x="230" y="0" width="85" height="60" rx="8" fill="#1E293B" />
        <text x="272.5" y="42" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="30" font-weight="800" fill="{$textColor}" text-anchor="middle">{$minutesStr}</text>
        <text x="272.5" y="74" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="10" font-weight="600" fill="#94A3B8" text-anchor="middle">MINUTES</text>
        
        <!-- Seconds -->
        <rect x="345" y="0" width="85" height="60" rx="8" fill="#1E293B" />
        <text x="387.5" y="42" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="30" font-weight="800" fill="{$textColor}" text-anchor="middle">{$secondsStr}</text>
        <text x="387.5" y="74" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="10" font-weight="600" fill="#94A3B8" text-anchor="middle">SECONDS</text>
    </g>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Render a personalized event badge or VIP pass as an SVG image.
     */
    public function personalizedBadge(Request $request): Response
    {
        $name = htmlspecialchars((string) $request->query('name', 'Valued Guest'));
        $company = htmlspecialchars((string) $request->query('company', 'Acme Corporation'));
        $role = htmlspecialchars((string) $request->query('role', 'VIP Attendee'));
        $themeColor = htmlspecialchars((string) $request->query('color', '#4F46E5'));

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="480" height="240" viewBox="0 0 480 240" fill="none">
    <rect width="480" height="240" rx="16" fill="#0F172A" />
    <circle cx="440" cy="40" r="80" fill="{$themeColor}" fill-opacity="0.15" />
    <circle cx="40" cy="200" r="100" fill="{$themeColor}" fill-opacity="0.1" />
    
    <!-- Header -->
    <rect x="32" y="28" width="84" height="24" rx="6" fill="{$themeColor}" />
    <text x="74" y="44" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="11" font-weight="700" fill="#FFFFFF" text-anchor="middle" letter-spacing="1">{$role}</text>
    
    <!-- Attendee Details -->
    <text x="32" y="105" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="28" font-weight="800" fill="#FFFFFF">{$name}</text>
    <text x="32" y="135" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="16" font-weight="500" fill="#94A3B8">{$company}</text>
    
    <!-- Footer / Barcode decoration -->
    <line x1="32" y1="175" x2="448" y2="175" stroke="#334155" stroke-width="1" stroke-dasharray="4 4" />
    <text x="32" y="205" font-family="-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif" font-size="11" font-weight="600" fill="#64748B" letter-spacing="0.5">OFFICIAL ODDEN SUMMIT ACCESS PASS</text>
</svg>
SVG;

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
