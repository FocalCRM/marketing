<?php

declare(strict_types=1);

namespace Focal\Marketing\Services;

class AbTestSignificanceCalculator
{
    /**
     * Calculate two-tailed statistical significance for an A/B split test.
     *
     * @return array{
     *     sample_a: int,
     *     conversions_a: int,
     *     rate_a: float,
     *     sample_b: int,
     *     conversions_b: int,
     *     rate_b: float,
     *     relative_uplift_percent: float,
     *     z_score: float,
     *     p_value: float,
     *     confidence_percent: float,
     *     is_significant: bool,
     *     winning_variant: ?string,
     *     recommendation: string
     * }
     */
    public static function calculate(
        int $sampleA,
        int $conversionsA,
        int $sampleB,
        int $conversionsB,
        float $confidenceThreshold = 0.95
    ): array {
        if ($sampleA <= 0 || $sampleB <= 0) {
            return self::emptyResult($sampleA, $conversionsA, $sampleB, $conversionsB, 'Insufficient sample size. Both variants require at least 1 observation.');
        }

        $rateA = $conversionsA / $sampleA;
        $rateB = $conversionsB / $sampleB;

        $uplift = $rateA > 0 ? (($rateB - $rateA) / $rateA) * 100 : 0.0;

        $totalConversions = $conversionsA + $conversionsB;
        $totalSample = $sampleA + $sampleB;
        $pooledRate = $totalConversions / $totalSample;

        // If pooledRate is 0 or 1, variance is 0
        if ($pooledRate <= 0.0 || $pooledRate >= 1.0) {
            return self::emptyResult($sampleA, $conversionsA, $sampleB, $conversionsB, 'No variation in observed conversions across variants.');
        }

        $standardError = sqrt($pooledRate * (1 - $pooledRate) * ((1 / $sampleA) + (1 / $sampleB)));
        if ($standardError <= 0.0) {
            return self::emptyResult($sampleA, $conversionsA, $sampleB, $conversionsB, 'Standard error could not be computed.');
        }

        $zScore = ($rateB - $rateA) / $standardError;

        // Two-tailed p-value from normal CDF
        $pValue = 2.0 * (1.0 - self::normalCdf(abs($zScore)));
        $confidence = max(0.0, min(100.0, (1.0 - $pValue) * 100));

        $requiredConfidencePercent = $confidenceThreshold * 100;
        $isSignificant = $confidence >= $requiredConfidencePercent && ($sampleA >= 30 && $sampleB >= 30);

        $winningVariant = null;
        if ($isSignificant) {
            $winningVariant = $rateB > $rateA ? 'B' : 'A';
        }

        $recommendation = self::generateRecommendation(
            $isSignificant,
            $winningVariant,
            $confidence,
            $uplift,
            $sampleA,
            $sampleB
        );

        return [
            'sample_a' => $sampleA,
            'conversions_a' => $conversionsA,
            'rate_a' => round($rateA * 100, 2),
            'sample_b' => $sampleB,
            'conversions_b' => $conversionsB,
            'rate_b' => round($rateB * 100, 2),
            'relative_uplift_percent' => round($uplift, 2),
            'z_score' => round($zScore, 3),
            'p_value' => round($pValue, 4),
            'confidence_percent' => round($confidence, 1),
            'is_significant' => $isSignificant,
            'winning_variant' => $winningVariant,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * Cumulative distribution function (CDF) for standard normal distribution N(0,1).
     */
    protected static function normalCdf(float $x): float
    {
        // Using approximation via error function erf
        return 0.5 * (1.0 + self::erf($x / sqrt(2.0)));
    }

    /**
     * Approximation of error function erf(x).
     */
    protected static function erf(float $x): float
    {
        $sign = $x < 0 ? -1 : 1;
        $x = abs($x);

        // Constants for Abramowitz and Stegun formula 7.1.26
        $a1 = 0.254829592;
        $a2 = -0.284496736;
        $a3 = 1.421413741;
        $a4 = -1.453152027;
        $a5 = 1.061405429;
        $p = 0.3275911;

        $t = 1.0 / (1.0 + $p * $x);
        $y = 1.0 - ((((($a5 * $t + $a4) * $t) + $a3) * $t + $a2) * $t + $a1) * $t * exp(-$x * $x);

        return $sign * $y;
    }

    /**
     * @return array{
     *     sample_a: int,
     *     conversions_a: int,
     *     rate_a: float,
     *     sample_b: int,
     *     conversions_b: int,
     *     rate_b: float,
     *     relative_uplift_percent: float,
     *     z_score: float,
     *     p_value: float,
     *     confidence_percent: float,
     *     is_significant: bool,
     *     winning_variant: ?string,
     *     recommendation: string
     * }
     */
    protected static function emptyResult(
        int $sampleA,
        int $conversionsA,
        int $sampleB,
        int $conversionsB,
        string $reason
    ): array {
        return [
            'sample_a' => $sampleA,
            'conversions_a' => $conversionsA,
            'rate_a' => 0.0,
            'sample_b' => $sampleB,
            'conversions_b' => $conversionsB,
            'rate_b' => 0.0,
            'relative_uplift_percent' => 0.0,
            'z_score' => 0.0,
            'p_value' => 1.0,
            'confidence_percent' => 0.0,
            'is_significant' => false,
            'winning_variant' => null,
            'recommendation' => $reason,
        ];
    }

    protected static function generateRecommendation(
        bool $isSignificant,
        ?string $winningVariant,
        float $confidence,
        float $uplift,
        int $sampleA,
        int $sampleB
    ): string {
        if ($sampleA < 30 || $sampleB < 30) {
            return "Sample size is too small ({$sampleA} vs {$sampleB}). Collect at least 30 observations per variant before drawing conclusions.";
        }

        if ($isSignificant && $winningVariant !== null) {
            $absUplift = abs(round($uplift, 1));

            return "Variant {$winningVariant} is winning with {$confidence}% statistical confidence ({$absUplift}% relative difference). Safe to roll out as the primary template.";
        }

        return "Results are currently inconclusive with {$confidence}% confidence. Continue gathering sends until the 95% significance threshold is reached.";
    }
}
