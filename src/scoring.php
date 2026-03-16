<?php
declare(strict_types=1);

function roleCatalog(): array
{
    return [
        'star' => ['label' => 'Star', 'rule' => 'Rating-focused role'],
        'duelist' => ['label' => 'Duelist', 'rule' => 'Rewards high ACS'],
        'initiator' => ['label' => 'Initiator', 'rule' => 'Rewards assist impact'],
        'sentinel' => ['label' => 'Sentinel', 'rule' => 'Rewards low first deaths'],
        'flex' => ['label' => 'Flex', 'rule' => 'Rewards positive opening duel diff'],
    ];
}

function powerCatalog(): array
{
    return [
        'none' => ['label' => 'No Power', 'rule' => 'No extra bonus'],
        'overclock' => ['label' => 'Overclock', 'rule' => '+12 if rating >= 1.20'],
        'firstblood' => ['label' => 'First Blood', 'rule' => '+10 if FKPR >= 0.14'],
        'ironwall' => ['label' => 'Iron Wall', 'rule' => '+10 if FDPR <= 0.10'],
        'assistcore' => ['label' => 'Assist Core', 'rule' => '+9 if APR >= 0.25'],
        'clutchmode' => ['label' => 'Clutch Mode', 'rule' => '+9 if CL% >= 24'],
    ];
}

function priceRubricTiers(): array
{
    return [
        ['tier' => 'S', 'range' => '$235,000-$250,000', 'target_count' => 'Top 5 players'],
        ['tier' => 'A', 'range' => '$215,000-$234,000', 'target_count' => 'Next 10 players'],
        ['tier' => 'B', 'range' => '$195,000-$214,000', 'target_count' => 'Next 15 players'],
        ['tier' => 'C', 'range' => '$175,000-$194,000', 'target_count' => 'Next 15 players'],
        ['tier' => 'D', 'range' => '$155,000-$174,000', 'target_count' => 'Bottom 15 players'],
    ];
}

function computePricingScore(array $stats): float
{
    $rating = toFloat($stats['rating'], 1.0);
    $acs = toFloat($stats['acs'], 200.0);
    $kpr = toFloat($stats['kpr'], 0.70);
    $kd = toFloat($stats['kd'], 1.00);
    $kast = toFloat($stats['kast'], 70.0);

    $ratingN = clamp01(($rating - 0.80) / 0.55);
    $acsN = clamp01(($acs - 165.0) / 95.0);
    $kprN = clamp01(($kpr - 0.55) / 0.35);
    $kdN = clamp01(($kd - 0.80) / 0.70);
    $kastN = clamp01(($kast - 64.0) / 16.0);

    return 100.0 * (0.40 * $ratingN + 0.25 * $acsN + 0.20 * $kprN + 0.10 * $kdN + 0.05 * $kastN);
}

function computePriceFromRank(int $rank, int $total, float $score): int
{
    if ($total <= 1 || $rank < 0) {
        return 200000;
    }

    $countS = max(1, (int)ceil($total * 0.0833));
    $countA = max(1, (int)ceil($total * 0.1667));
    $countB = max(1, (int)ceil($total * 0.25));
    $countC = max(1, (int)ceil($total * 0.25));
    $countD = max(1, $total - ($countS + $countA + $countB + $countC));

    $tierStart = 0;
    $tierCount = $countS;
    $tierMin = 235000;
    $tierMax = 250000;

    if ($rank >= $countS) {
        $tierStart = $countS;
        $tierCount = $countA;
        $tierMin = 215000;
        $tierMax = 234000;
    }
    if ($rank >= $countS + $countA) {
        $tierStart = $countS + $countA;
        $tierCount = $countB;
        $tierMin = 195000;
        $tierMax = 214000;
    }
    if ($rank >= $countS + $countA + $countB) {
        $tierStart = $countS + $countA + $countB;
        $tierCount = $countC;
        $tierMin = 175000;
        $tierMax = 194000;
    }
    if ($rank >= $countS + $countA + $countB + $countC) {
        $tierStart = $countS + $countA + $countB + $countC;
        $tierCount = $countD;
        $tierMin = 155000;
        $tierMax = 174000;
    }

    $pos = $rank - $tierStart;
    $tierPct = $tierCount > 1 ? (1.0 - ($pos / ($tierCount - 1))) : 1.0;
    $scoreAdj = clamp01(($score - 35.0) / 45.0);

    $price = (int)round($tierMin + (($tierMax - $tierMin) * (($tierPct * 0.7) + ($scoreAdj * 0.3))));
    return (int)(round($price / 1000) * 1000);
}

function computePlayerFantasyScore(array $stats, string $roleKey, string $powerKey): array
{
    $rating = toFloat($stats['rating'], 1.0);
    $acs = toFloat($stats['acs'], 200.0);
    $kpr = toFloat($stats['kpr'], 0.70);
    $kast = toFloat($stats['kast'], 70.0);
    $kd = toFloat($stats['kd'], 1.0);
    $apr = toFloat($stats['apr'], 0.20);
    $fkpr = toFloat($stats['fkpr'], 0.10);
    $fdpr = toFloat($stats['fdpr'], 0.12);
    $clPct = toFloat($stats['cl_pct'], 20.0);

    $base = (($rating - 1.0) * 100)
        + (($acs - 200.0) * 0.25)
        + (($kpr - 0.70) * 120)
        + (($kast - 70.0) * 1.0)
        + (($kd - 1.0) * 40);

    $roleBonus = 0.0;
    switch ($roleKey) {
        case 'star':
            $roleBonus = $rating >= 1.15 ? 12.0 : ($rating >= 1.05 ? 6.0 : -4.0);
            break;
        case 'duelist':
            $roleBonus = $acs >= 230 ? 10.0 : ($acs >= 210 ? 5.0 : -3.0);
            break;
        case 'initiator':
            $roleBonus = $apr >= 0.24 ? 9.0 : ($apr >= 0.20 ? 4.0 : -2.0);
            break;
        case 'sentinel':
            $roleBonus = $fdpr <= 0.11 ? 9.0 : ($fdpr <= 0.14 ? 4.0 : -3.0);
            break;
        case 'flex':
            $delta = $fkpr - $fdpr;
            $roleBonus = $delta >= 0.02 ? 8.0 : ($delta >= -0.01 ? 3.0 : -3.0);
            break;
    }

    $powerBonus = 0.0;
    $powerTriggered = false;
    if ($powerKey !== 'none') {
        switch ($powerKey) {
            case 'overclock':
                $powerTriggered = $rating >= 1.20;
                $powerBonus = $powerTriggered ? 12.0 : 0.0;
                break;
            case 'firstblood':
                $powerTriggered = $fkpr >= 0.14;
                $powerBonus = $powerTriggered ? 10.0 : 0.0;
                break;
            case 'ironwall':
                $powerTriggered = $fdpr <= 0.10;
                $powerBonus = $powerTriggered ? 10.0 : 0.0;
                break;
            case 'assistcore':
                $powerTriggered = $apr >= 0.25;
                $powerBonus = $powerTriggered ? 9.0 : 0.0;
                break;
            case 'clutchmode':
                $powerTriggered = $clPct >= 24.0;
                $powerBonus = $powerTriggered ? 9.0 : 0.0;
                break;
        }
    }

    $total = $base + $roleBonus + $powerBonus;

    return [
        'base' => round($base, 2),
        'role_bonus' => round($roleBonus, 2),
        'power_bonus' => round($powerBonus, 2),
        'power_triggered' => $powerTriggered,
        'total' => round($total, 2),
    ];
}

function clamp01(float $value): float
{
    if ($value < 0.0) {
        return 0.0;
    }
    if ($value > 1.0) {
        return 1.0;
    }
    return $value;
}
