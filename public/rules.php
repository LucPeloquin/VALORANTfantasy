<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = currentUser();
$tiers = priceRubricTiers();
$roles = roleCatalog();
$powers = powerCatalog();

renderLayout('Rules & Pricing', static function () use ($tiers, $roles, $powers): void {
    ?>
    <section class="card">
        <h1>Rules & Pricing Rubric</h1>
        <p>This rubric is calibrated for a <strong>12-team group stage</strong> pool (~60 players), using HLTV-style fantasy constraints.</p>
        <ul>
            <li>Roster size: <strong>5 players</strong></li>
            <li>Total budget: <strong>$1,000,000</strong></li>
            <li>Max players from one real team: <strong>2</strong></li>
        </ul>
    </section>

    <section class="card">
        <h2>Measurable Pricing Targets (12 teams)</h2>
        <table>
            <thead>
                <tr><th>Tier</th><th>Price Range</th><th>Target Share of Pool</th></tr>
            </thead>
            <tbody>
                <?php foreach ($tiers as $tier): ?>
                    <tr>
                        <td><?= h($tier['tier']) ?></td>
                        <td><?= h($tier['range']) ?></td>
                        <td><?= h($tier['target_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>Operational target: average starter lineup should land around <strong>$980k-$1,000k</strong> when picking mostly A/B-tier players.</p>
    </section>

    <section class="card">
        <h2>Pricing Formula (for manual entry)</h2>
        <p>Use this scoring index, then map to the tier table:</p>
        <pre>pricing_score = 100 * (
  0.40 * norm(rating) +
  0.25 * norm(ACS) +
  0.20 * norm(KPR) +
  0.10 * norm(KD) +
  0.05 * norm(KAST)
)

price ~= $155k + rank_curve(pricing_score) up to $250k
round to nearest $1,000</pre>
        <p>Normalization anchors used in this app:</p>
        <ul>
            <li>Rating: 0.80 to 1.35</li>
            <li>ACS: 165 to 260</li>
            <li>KPR: 0.55 to 0.90</li>
            <li>KD: 0.80 to 1.50</li>
            <li>KAST: 64 to 80</li>
        </ul>
    </section>

    <section class="grid two">
        <div class="card">
            <h2>Role Bonuses</h2>
            <ul>
                <?php foreach ($roles as $role): ?>
                    <li><strong><?= h($role['label']) ?>:</strong> <?= h($role['rule']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card">
            <h2>Superpowers</h2>
            <ul>
                <?php foreach ($powers as $power): ?>
                    <li><strong><?= h($power['label']) ?>:</strong> <?= h($power['rule']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>
    <?php
}, $user);
