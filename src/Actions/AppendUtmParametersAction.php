<?php

declare(strict_types=1);

namespace Focal\Marketing\Actions;

use Focal\Marketing\Models\Campaign;
use Focal\Marketing\Models\CampaignRecipient;
use Illuminate\Support\Str;

class AppendUtmParametersAction
{
    /**
     * Append Google Analytics / RevOps UTM parameters to a single target URL.
     */
    public function execute(string $url, Campaign $campaign, ?string $variant = null, ?string $term = null): string
    {
        if (
            str_starts_with($url, 'mailto:') ||
            str_starts_with($url, 'tel:') ||
            str_starts_with($url, '#') ||
            str_contains($url, CampaignRecipient::unsubscribePathPrefix())
        ) {
            return $url;
        }

        $campaignSlug = ! empty($campaign->utm_campaign)
            ? Str::slug($campaign->utm_campaign)
            : Str::slug($campaign->name);

        $utmParams = [
            'utm_source' => 'focal',
            'utm_medium' => 'email',
            'utm_campaign' => $campaignSlug,
        ];

        if ($variant !== null && $variant !== '') {
            $utmParams['utm_content'] = 'variant_'.mb_strtolower($variant);
        }

        if ($term !== null && $term !== '') {
            $utmParams['utm_term'] = $term;
        }

        // Split URL into base, query, and fragment
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $existingQuery = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $existingQuery);
        }

        // Only append UTM params if they don't already exist in the target URL
        $mergedQuery = array_merge($utmParams, $existingQuery);
        $queryString = http_build_query($mergedQuery);

        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}://{$host}{$port}{$path}?{$queryString}{$fragment}";
    }

    /**
     * Automatically append UTM parameters to all hyperlinks in an HTML message body.
     */
    public function appendHtmlLinks(string $html, Campaign $campaign, ?string $variant = null): string
    {
        if (! $campaign->utm_auto_tag) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<a\s+([^>]*?)href=(["\'])(.*?)\2([^>]*)>/i',
            function (array $matches) use ($campaign, $variant): string {
                $before = $matches[1];
                $quote = $matches[2];
                $originalUrl = $matches[3];
                $after = $matches[4];

                $taggedUrl = $this->execute($originalUrl, $campaign, $variant);

                return "<a {$before}href={$quote}".htmlspecialchars($taggedUrl, ENT_QUOTES, 'UTF-8')."{$quote}{$after}>";
            },
            $html
        );
    }
}
