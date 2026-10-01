<?php

declare(strict_types=1);

namespace Focal\Marketing\Services;

class DomainHealthCheckService
{
    /**
     * Perform DNS deliverability diagnosis for SPF, DKIM, DMARC, and MX records.
     *
     * @return array{
     *     domain: string,
     *     overall_status: 'pass'|'warning'|'fail',
     *     spf: array{status: 'pass'|'warning'|'fail', label: string, found: ?string, recommendation: string, note: string},
     *     dkim: array{status: 'pass'|'warning'|'fail', label: string, selector: string, found: ?string, recommendation: string, note: string},
     *     dmarc: array{status: 'pass'|'warning'|'fail', label: string, policy: ?string, found: ?string, recommendation: string, note: string},
     *     mx: array{status: 'pass'|'warning'|'fail', label: string, found: list<string>, recommendation: string, note: string}
     * }
     */
    public function diagnose(string $domain, string $dkimSelector = 'focal'): array
    {
        $cleanDomain = mb_strtolower(trim($domain));
        if (str_starts_with($cleanDomain, 'https://') || str_starts_with($cleanDomain, 'http://')) {
            $cleanDomain = (string) parse_url($cleanDomain, PHP_URL_HOST);
        }

        // Local testing mock simulation for '.test' or 'localhost'
        if (str_ends_with($cleanDomain, '.test') || $cleanDomain === 'localhost') {
            return [
                'domain' => $cleanDomain,
                'overall_status' => 'pass',
                'spf' => [
                    'status' => 'pass',
                    'label' => 'SPF Record Configured',
                    'found' => 'v=spf1 include:_spf.focal.test ~all',
                    'recommendation' => 'v=spf1 include:_spf.your-esp.com ~all',
                    'note' => 'Valid SPF declaration authorizes your sending IPs.',
                ],
                'dkim' => [
                    'status' => 'pass',
                    'label' => 'DKIM Cryptographic Signature Active',
                    'selector' => $dkimSelector,
                    'found' => 'v=DKIM1; k=rsa; p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC3...',
                    'recommendation' => "TXT {$dkimSelector}._domainkey.{$cleanDomain} -> v=DKIM1; k=rsa; p=...",
                    'note' => 'DKIM validates email integrity and authenticates sending servers.',
                ],
                'dmarc' => [
                    'status' => 'pass',
                    'label' => 'DMARC Enforcement Active',
                    'policy' => 'quarantine',
                    'found' => "v=DMARC1; p=quarantine; rua=mailto:dmarc@{$cleanDomain}",
                    'recommendation' => "TXT _dmarc.{$cleanDomain} -> v=DMARC1; p=quarantine; rua=mailto:dmarc@{$cleanDomain}",
                    'note' => 'Complies with mandatory Gmail & Yahoo sender requirements (p=quarantine or p=reject).',
                ],
                'mx' => [
                    'status' => 'pass',
                    'label' => 'MX Inbound Routing Configured',
                    'found' => ["mail.{$cleanDomain} (Priority 10)"],
                    'recommendation' => "MX {$cleanDomain} -> mail.your-provider.com (Priority 10)",
                    'note' => 'Enables bounce verification and inbound reply processing.',
                ],
            ];
        }

        // Live DNS Queries for production domains
        $txtRecords = @dns_get_record($cleanDomain, DNS_TXT) ?: [];
        $dmarcRecords = @dns_get_record("_dmarc.{$cleanDomain}", DNS_TXT) ?: [];
        $dkimRecords = @dns_get_record("{$dkimSelector}._domainkey.{$cleanDomain}", DNS_TXT) ?: [];
        $mxRecords = @dns_get_record($cleanDomain, DNS_MX) ?: [];

        // 1. Evaluate SPF
        $spfFound = null;
        foreach ($txtRecords as $record) {
            $txt = $record['txt'] ?? ($record['entries'][0] ?? '');
            if (str_starts_with($txt, 'v=spf1')) {
                $spfFound = $txt;
                break;
            }
        }

        $spfStatus = $spfFound !== null ? 'pass' : 'fail';
        $spfData = [
            'status' => $spfStatus,
            'label' => $spfStatus === 'pass' ? 'SPF Configured' : 'Missing SPF Record',
            'found' => $spfFound,
            'recommendation' => 'v=spf1 include:_spf.your-esp.com ~all',
            'note' => $spfStatus === 'pass' ? 'SPF TXT record is published.' : 'Gmail and Yahoo will reject bulk marketing emails without SPF.',
        ];

        // 2. Evaluate DMARC
        $dmarcFound = null;
        $dmarcPolicy = null;
        foreach ($dmarcRecords as $record) {
            $txt = $record['txt'] ?? ($record['entries'][0] ?? '');
            if (str_starts_with($txt, 'v=DMARC1')) {
                $dmarcFound = $txt;
                if (preg_match('/p=([a-z]+)/i', $txt, $matches)) {
                    $dmarcPolicy = strtolower($matches[1]);
                }
                break;
            }
        }

        $dmarcStatus = 'fail';
        if ($dmarcFound !== null) {
            $dmarcStatus = ($dmarcPolicy === 'reject' || $dmarcPolicy === 'quarantine') ? 'pass' : 'warning';
        }

        $dmarcData = [
            'status' => $dmarcStatus,
            'label' => $dmarcStatus === 'pass' ? 'DMARC Enforced' : ($dmarcStatus === 'warning' ? 'DMARC in Monitor Mode (p=none)' : 'Missing DMARC Policy'),
            'policy' => $dmarcPolicy,
            'found' => $dmarcFound,
            'recommendation' => "TXT _dmarc.{$cleanDomain} -> v=DMARC1; p=quarantine; rua=mailto:dmarc@{$cleanDomain}",
            'note' => $dmarcStatus === 'pass' ? 'Compliant with enterprise inbox placement requirements.' : 'Set p=quarantine or p=reject to prevent spoofing.',
        ];

        // 3. Evaluate DKIM
        $dkimFound = null;
        foreach ($dkimRecords as $record) {
            $txt = $record['txt'] ?? ($record['entries'][0] ?? '');
            if (str_starts_with($txt, 'v=DKIM1') || str_contains($txt, 'p=')) {
                $dkimFound = $txt;
                break;
            }
        }

        $dkimStatus = $dkimFound !== null ? 'pass' : 'warning';
        $dkimData = [
            'status' => $dkimStatus,
            'label' => $dkimStatus === 'pass' ? 'DKIM Key Published' : "DKIM Not Found on '{$dkimSelector}' Selector",
            'selector' => $dkimSelector,
            'found' => $dkimFound,
            'recommendation' => "TXT {$dkimSelector}._domainkey.{$cleanDomain} -> v=DKIM1; k=rsa; p=[PUBLIC_KEY]",
            'note' => 'DKIM signs your outbound broadcasts cryptographically.',
        ];

        // 4. Evaluate MX
        $foundMx = [];
        foreach ($mxRecords as $record) {
            if (! empty($record['target'])) {
                $foundMx[] = $record['target'].' (Priority '.($record['pri'] ?? 10).')';
            }
        }

        $mxStatus = ! empty($foundMx) ? 'pass' : 'fail';
        $mxData = [
            'status' => $mxStatus,
            'label' => $mxStatus === 'pass' ? 'MX Records Configured' : 'Missing Inbound MX Records',
            'found' => $foundMx,
            'recommendation' => "MX {$cleanDomain} -> mail.your-provider.com (Priority 10)",
            'note' => $mxStatus === 'pass' ? 'Domain can receive bounces and unsubscribes.' : 'Mail servers cannot route feedback without MX.',
        ];

        $overallStatus = ($spfStatus === 'pass' && $dmarcStatus === 'pass' && $mxStatus === 'pass') ? 'pass' : (($spfStatus === 'fail' || $mxStatus === 'fail') ? 'fail' : 'warning');

        return [
            'domain' => $cleanDomain,
            'overall_status' => $overallStatus,
            'spf' => $spfData,
            'dkim' => $dkimData,
            'dmarc' => $dmarcData,
            'mx' => $mxData,
        ];
    }
}
