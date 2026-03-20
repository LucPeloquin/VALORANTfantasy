<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = requireAuth();
$event = getCurrentEvent();
if (!$event) {
    http_response_code(500);
    exit('No active event.');
}

$leagueId = (int)($_GET['league_id'] ?? 0);
$league = $leagueId > 0 ? getLeague($leagueId) : null;

if (!$league || !isUserInLeague($leagueId, (int)$user['id'])) {
    $league = ensureUserInPrimaryLeague((int)$user['id'], (int)$event['id']);
    $leagueId = (int)$league['id'];
    if ((int)($_GET['league_id'] ?? 0) !== $leagueId) {
        redirect('/team-builder.php?league_id=' . $leagueId);
    }
}

$teamId = ensureFantasyTeam($leagueId, (int)$user['id']);
$team = getFantasyTeamByLeagueAndUser($leagueId, (int)$user['id']);
if (!$team) {
    http_response_code(500);
    exit('Team creation failed.');
}

$locked = rosterLocked($league);

if (isPost()) {
    verifyCsrfOrFail();

    if ($locked) {
        flash('error', 'Roster is locked.');
        redirect('/team-builder.php?league_id=' . $leagueId);
    }

    $selection = [];
    $playerIds = $_POST['player_id'] ?? [];
    $roles = $_POST['role_key'] ?? [];
    $powers = $_POST['power_key'] ?? [];
    $count = min(count($playerIds), count($roles), count($powers));
    for ($i = 0; $i < $count; $i++) {
        $selection[] = [
            'player_id' => (int)$playerIds[$i],
            'role_key' => (string)$roles[$i],
            'power_key' => (string)$powers[$i],
        ];
    }

    $teamName = (string)($_POST['team_name'] ?? 'My VCT Team');
    $isPublic = boolFromPost('is_public');
    $submitNow = boolFromPost('submit_team');

    $errors = saveFantasyRoster((int)$team['id'], $league, $teamName, $isPublic, $submitNow, $selection);
    if ($errors) {
        foreach ($errors as $e) {
            flash('error', $e);
        }
    } else {
        flash('success', $submitNow ? 'Team submitted.' : 'Draft saved.');
        redirect('/team-builder.php?league_id=' . $leagueId);
    }
}

$players = listPlayersForEvent((int)$league['event_id']);
$currentRoster = listRosterRows((int)$team['id']);
$summary = fantasyTeamSummary((int)$team['id']);
$roles = roleCatalog();
$powers = powerCatalog();

$slots = [];
foreach ($currentRoster as $r) {
    $slots[] = [
        'player_id' => (int)$r['player_id'],
        'role_key' => (string)$r['role_key'],
        'power_key' => (string)$r['power_key'],
    ];
}
while (count($slots) < 5) {
    $slots[] = ['player_id' => 0, 'role_key' => 'star', 'power_key' => 'none'];
}

$playersById = [];
$playerJs = [];
foreach ($players as $p) {
    $id = (int)$p['id'];
    $playersById[$id] = $p;
    $playerJs[(string)$id] = [
        'id' => $id,
        'alias' => (string)$p['alias'],
        'real_name' => (string)($p['real_name'] ?? ''),
        'short_name' => (string)$p['short_name'],
        'price' => (int)$p['price'],
        'avatar_url' => (string)($p['avatar_url'] ?? ''),
        'rating' => (float)($p['rating'] ?? 0),
        'acs' => (float)($p['acs'] ?? 0),
        'kd' => (float)($p['kd'] ?? 0),
        'kast' => (float)($p['kast'] ?? 0),
        'kpr' => (float)($p['kpr'] ?? 0),
        'apr' => (float)($p['apr'] ?? 0),
        'fkpr' => (float)($p['fkpr'] ?? 0),
        'fdpr' => (float)($p['fdpr'] ?? 0),
        'cl_pct' => (float)($p['cl_pct'] ?? 0),
    ];
}

$roleLabels = [];
$roleRules = [];
foreach ($roles as $key => $meta) {
    $roleLabels[$key] = (string)$meta['label'];
    $roleRules[$key] = (string)$meta['rule'];
}

$powerLabels = [];
foreach ($powers as $key => $meta) {
    $powerLabels[$key] = (string)$meta['label'];
}

$playersByTeam = [];
foreach ($players as $p) {
    $teamId = (int)$p['team_id'];
    if (!isset($playersByTeam[$teamId])) {
        $playersByTeam[$teamId] = [
            'team_id' => $teamId,
            'team_name' => (string)$p['team_name'],
            'short_name' => (string)$p['short_name'],
            'players' => [],
        ];
    }
    $playersByTeam[$teamId]['players'][] = $p;
}
foreach ($playersByTeam as &$teamBucket) {
    usort($teamBucket['players'], static function (array $a, array $b): int {
        return (int)$b['price'] <=> (int)$a['price'];
    });
}
unset($teamBucket);
usort($playersByTeam, static function (array $a, array $b): int {
    return strcmp((string)$a['short_name'], (string)$b['short_name']);
});

$priceTierClass = static function (?int $price): string {
    if ($price === null) {
        return '';
    }
    if ($price >= 215000) {
        return 'tb-tier-gold';
    }
    if ($price >= 185000) {
        return 'tb-tier-silver';
    }
    return 'tb-tier-bronze';
};

renderLayout('Team Builder', static function () use ($user, $league, $team, $locked, $players, $playersById, $playersByTeam, $slots, $summary, $roles, $powers, $playerJs, $roleLabels, $roleRules, $powerLabels, $priceTierClass): void {
    ?>
    <section class="card">
        <h1>Team Builder</h1>
        <p><strong><?= h($league['name']) ?></strong> - <?= h($league['event_name']) ?></p>
        <p>Lock: <strong><?= h(utcDisplay((string)$league['lock_at'])) ?></strong></p>
        <p>Budget: <strong><?= h(formatMoney((int)$league['budget'])) ?></strong> | Spent: <strong><?= h(formatMoney((int)$summary['spent'])) ?></strong> | Players: <strong><?= (int)$summary['players_count'] ?>/5</strong></p>
        <?php if ($locked): ?><p class="danger">Roster is locked.</p><?php endif; ?>
    </section>

    <section class="card">
        <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
            <label>Fantasy team name <input name="team_name" maxlength="60" value="<?= h((string)$team['team_name']) ?>" <?= $locked ? 'disabled' : '' ?>></label>
            <label class="inline-check"><input type="checkbox" name="is_public" value="1" <?= (int)$team['is_public'] === 1 ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>> Public team profile</label>

            <div class="tb-draft-shell">
                <div class="tb-draft-head">
                    <strong>Remaining budget: <span data-budget-remaining><?= h(formatMoney((int)$league['budget'] - (int)$summary['spent'])) ?></span></strong>
                    <span class="meta">Spent: <span data-budget-spent><?= h(formatMoney((int)$summary['spent'])) ?></span></span>
                    <span class="meta" data-active-slot-label>Picking for slot 1</span>
                </div>

                <div class="tb-slots">
                    <?php foreach ($slots as $i => $slot): ?>
                        <?php
                        $selected = ((int)$slot['player_id'] > 0 && isset($playersById[(int)$slot['player_id']]))
                            ? $playersById[(int)$slot['player_id']]
                            : null;
                        $avatarUrl = (string)($selected['avatar_url'] ?? '');
                        $alias = (string)($selected['alias'] ?? 'Pick player');
                        $realName = (string)($selected['real_name'] ?? '');
                        $teamShort = (string)($selected['short_name'] ?? '--');
                        $priceText = $selected ? formatMoney((int)$selected['price']) : '--';
                        $initial = strtoupper(substr($alias, 0, 1));
                        $slotTierClass = $selected ? $priceTierClass((int)$selected['price']) : '';
                        ?>
                        <article class="tb-slot <?= h($slotTierClass) ?>" data-slot-index="<?= $i ?>">
                            <div class="tb-slot-head">
                                <button type="button" class="tb-slot-focus" data-set-active-slot="<?= $i ?>" <?= $locked ? 'disabled' : '' ?>>
                                    Slot <?= $i + 1 ?>
                                </button>
                            </div>

                            <div class="visually-hidden">
                                <select class="tb-player-select" name="player_id[]" <?= $locked ? 'disabled' : '' ?>>
                                    <option value="">Select player</option>
                                    <?php foreach ($players as $p): ?>
                                        <option value="<?= (int)$p['id'] ?>" <?= (int)$slot['player_id'] === (int)$p['id'] ? 'selected' : '' ?>>
                                            <?= h($p['alias']) ?> (<?= h($p['short_name']) ?>) - <?= h(formatMoney((int)$p['price'])) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <input type="hidden" name="role_key[]" value="<?= h($slot['role_key']) ?>" class="tb-role-input">
                            <div class="visually-hidden">
                                <select class="tb-role-select" <?= $locked ? 'disabled' : '' ?>>
                                    <?php foreach ($roles as $key => $meta): ?>
                                        <option value="<?= h($key) ?>" <?= $slot['role_key'] === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="tb-card" data-card tabindex="0" role="button" aria-label="Flip player stats card">
                                <div class="tb-face tb-face-front">
                                    <div class="tb-player-main">
                                        <img
                                            class="tb-avatar"
                                            data-avatar-img
                                            src="<?= h($avatarUrl) ?>"
                                            alt="<?= h($alias) ?>"
                                            loading="lazy"
                                            onerror="this.hidden=true; var fallback=this.parentElement && this.parentElement.querySelector('[data-avatar-fallback]'); if (fallback) { fallback.hidden=false; }"
                                            <?= $avatarUrl === '' ? 'hidden' : '' ?>
                                        >
                                        <span class="tb-avatar-fallback" data-avatar-fallback <?= $avatarUrl !== '' ? 'hidden' : '' ?>><?= h($initial !== '' ? $initial : '?') ?></span>
                                        <div>
                                            <div class="tb-player-name" data-field="alias"><?= h($alias) ?></div>
                                            <div class="tb-player-real" data-field="real_name"><?= h($realName) ?></div>
                                        </div>
                                    </div>
                                    <div class="tb-chip-row">
                                        <span class="tb-chip tb-role-badge" data-role-label><?= h($roles[$slot['role_key']]['label'] ?? 'Role') ?></span>
                                        <span class="tb-chip tb-power-badge" data-power-label><?= h($powers[$slot['power_key']]['label'] ?? 'No Power') ?></span>
                                    </div>
                                    <div class="tb-meta-row">
                                        <span data-field="team"><?= h($teamShort) ?></span>
                                        <span data-field="price"><?= h($priceText) ?></span>
                                    </div>
                                    <div class="tb-card-note">Click player to flip stats</div>
                                </div>
                                <div class="tb-face tb-face-back">
                                    <dl class="tb-stats-grid">
                                        <div><dt>Rating</dt><dd data-stat="rating"><?= $selected ? h(formatPoints((float)$selected['rating'])) : '--' ?></dd></div>
                                        <div><dt>ACS</dt><dd data-stat="acs"><?= $selected ? h(formatPoints((float)$selected['acs'])) : '--' ?></dd></div>
                                        <div><dt>KD</dt><dd data-stat="kd"><?= $selected ? h(formatPoints((float)$selected['kd'])) : '--' ?></dd></div>
                                        <div><dt>KAST</dt><dd data-stat="kast"><?= $selected ? h(formatPoints((float)$selected['kast'])) : '--' ?></dd></div>
                                        <div><dt>KPR</dt><dd data-stat="kpr"><?= $selected ? h(formatPoints((float)$selected['kpr'])) : '--' ?></dd></div>
                                        <div><dt>APR</dt><dd data-stat="apr"><?= $selected ? h(formatPoints((float)$selected['apr'])) : '--' ?></dd></div>
                                        <div><dt>FKPR</dt><dd data-stat="fkpr"><?= $selected ? h(formatPoints((float)$selected['fkpr'])) : '--' ?></dd></div>
                                        <div><dt>FDPR</dt><dd data-stat="fdpr"><?= $selected ? h(formatPoints((float)$selected['fdpr'])) : '--' ?></dd></div>
                                        <div><dt>CL%</dt><dd data-stat="cl_pct"><?= $selected ? h(formatPoints((float)$selected['cl_pct'])) : '--' ?></dd></div>
                                    </dl>
                                </div>
                            </div>

                            <div class="tb-slot-actions">
                                <button type="button" class="button ghost tb-role-open" data-open-role="<?= $i ?>" <?= $locked ? 'disabled' : '' ?>>Assign role</button>
                                <button type="button" class="button ghost tb-power-open" data-open-power="<?= $i ?>" <?= $locked ? 'disabled' : '' ?>>Add booster</button>
                                <button type="button" class="button ghost tb-flip-button">Flip stats</button>
                                <button type="button" class="button ghost tb-clear-slot" data-clear-slot <?= $locked ? 'disabled' : '' ?>>Clear</button>
                            </div>

                            <input type="hidden" name="power_key[]" value="<?= h($slot['power_key']) ?>" class="tb-power-input">
                            <div class="visually-hidden">
                                <select class="tb-power-select" <?= $locked ? 'disabled' : '' ?>>
                                    <?php foreach ($powers as $key => $meta): ?>
                                        <option value="<?= h($key) ?>" <?= $slot['power_key'] === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="tb-power-helper" data-power-helper>
                                <?= $slot['power_key'] === 'none' ? 'No booster selected.' : 'Booster selected.' ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="tb-market">
                <?php foreach ($playersByTeam as $teamBucket): ?>
                    <section class="tb-market-team">
                        <header class="tb-market-team-head">
                            <strong><?= h((string)$teamBucket['team_name']) ?></strong>
                            <span><?= h((string)$teamBucket['short_name']) ?></span>
                        </header>
                        <div class="tb-market-grid">
                            <?php foreach ($teamBucket['players'] as $p): ?>
                                <?php
                                $marketAvatar = (string)($p['avatar_url'] ?? '');
                                $marketInitial = strtoupper(substr((string)$p['alias'], 0, 1));
                                ?>
                                <?php $tierClass = $priceTierClass((int)$p['price']); ?>
                                <button
                                    type="button"
                                    class="tb-market-card <?= h($tierClass) ?>"
                                    data-market-player-id="<?= (int)$p['id'] ?>"
                                    data-market-team="<?= h((string)$p['short_name']) ?>"
                                    <?= $locked ? 'disabled' : '' ?>
                                >
                                    <div class="tb-market-image">
                                        <img
                                            src="<?= h($marketAvatar) ?>"
                                            alt="<?= h((string)$p['alias']) ?>"
                                            loading="lazy"
                                            onerror="this.hidden=true; var fallback=this.parentElement && this.parentElement.querySelector('.tb-market-fallback'); if (fallback) { fallback.hidden=false; }"
                                            <?= $marketAvatar === '' ? 'hidden' : '' ?>
                                        >
                                        <span class="tb-market-fallback" <?= $marketAvatar !== '' ? 'hidden' : '' ?>><?= h($marketInitial !== '' ? $marketInitial : '?') ?></span>
                                    </div>
                                    <div class="tb-market-meta">
                                        <div class="tb-market-name"><?= h((string)$p['alias']) ?></div>
                                        <div class="tb-market-price"><?= h(formatMoney((int)$p['price'])) ?></div>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>

            <div class="tb-role-modal" data-role-modal hidden>
                <div class="tb-role-backdrop" data-role-close></div>
                <div class="tb-role-panel card" role="dialog" aria-modal="true" aria-label="Assign player role">
                    <div class="tb-role-panel-head">
                        <h2>Assign player role</h2>
                        <button type="button" class="linklike" data-role-close>Close</button>
                    </div>
                    <p class="meta">Select a role</p>
                    <div class="tb-role-player-strip" data-role-player-strip></div>
                    <div class="tb-role-options">
                        <?php foreach ($roles as $key => $meta): ?>
                            <button type="button" class="tb-role-option" data-role-value="<?= h($key) ?>">
                                <span><?= h($meta['label']) ?></span>
                                <small><?= h($meta['rule']) ?></small>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="tb-role-nav">
                        <button type="button" class="button ghost" data-role-prev>Previous player</button>
                        <button type="button" class="button ghost" data-role-next>Next player</button>
                    </div>
                </div>
            </div>

            <div class="tb-power-modal" data-power-modal hidden>
                <div class="tb-role-backdrop" data-power-close></div>
                <div class="tb-role-panel card" role="dialog" aria-modal="true" aria-label="Assign booster">
                    <div class="tb-role-panel-head">
                        <h2>Assign booster</h2>
                        <button type="button" class="linklike" data-power-close>Close</button>
                    </div>
                    <div class="tb-power-controls">
                        <label>
                            <span class="meta">Mode</span>
                            <select data-power-mode>
                                <option value="assign">Assign booster</option>
                                <option value="breakdown">Booster breakdown</option>
                            </select>
                        </label>
                    </div>
                    <div data-power-assign-view>
                        <p class="meta">Pick a booster for this player</p>
                        <div class="tb-role-player-strip" data-power-player-strip></div>
                        <div class="tb-power-options">
                            <?php foreach ($powers as $key => $meta): ?>
                                <button type="button" class="tb-role-option tb-power-option" data-power-value="<?= h($key) ?>">
                                    <span><?= h($meta['label']) ?></span>
                                    <small><?= h($meta['rule']) ?></small>
                                </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="tb-role-nav">
                            <button type="button" class="button ghost" data-power-prev>Previous player</button>
                            <button type="button" class="button ghost" data-power-next>Next player</button>
                        </div>
                    </div>
                    <div data-power-breakdown-view hidden>
                        <p class="meta">Booster breakdown</p>
                        <div class="tb-breakdown-grid">
                            <?php foreach ($powers as $key => $meta): ?>
                                <article class="tb-breakdown-item">
                                    <strong><?= h($meta['label']) ?></strong>
                                    <small><?= h($meta['rule']) ?></small>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$locked): ?>
                <div class="button-row">
                    <button type="submit">Save draft</button>
                    <button type="submit" name="submit_team" value="1">Submit team</button>
                </div>
            <?php endif; ?>
        </form>
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

    <section class="card">
        <p><a href="/leaderboard.php?league_id=<?= (int)$league['id'] ?>">Open leaderboard</a></p>
    </section>

    <script>
        (() => {
            const slots = Array.from(document.querySelectorAll('.tb-slot'));
            if (!slots.length) return;

            const playersById = <?= json_encode($playerJs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const roleLabels = <?= json_encode($roleLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const roleRules = <?= json_encode($roleRules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const powerLabels = <?= json_encode($powerLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const locked = <?= $locked ? 'true' : 'false' ?>;
            const budgetCap = <?= (int)$league['budget'] ?>;

            const toMoney = (value) => '$' + Number(value || 0).toLocaleString();
            const toNumber = (value) => Number(value || 0).toFixed(2);
            const toPercent = (value) => Number(value || 0).toFixed(2);

            const budgetRemainingEl = document.querySelector('[data-budget-remaining]');
            const budgetSpentEl = document.querySelector('[data-budget-spent]');
            const activeSlotLabelEl = document.querySelector('[data-active-slot-label]');
            const marketCards = Array.from(document.querySelectorAll('[data-market-player-id]'));
            let activeSlotIndex = 0;

            let refreshRoleModal = () => {};
            let refreshPowerModal = () => {};

            const getSelectedPlayer = (slotEl) => {
                const id = slotEl.querySelector('.tb-player-select')?.value || '';
                return playersById[id] || null;
            };

            const applyTierClass = (el, price) => {
                el.classList.remove('tb-tier-gold', 'tb-tier-silver', 'tb-tier-bronze');
                if (!Number.isFinite(price)) return;
                if (price >= 215000) {
                    el.classList.add('tb-tier-gold');
                    return;
                }
                if (price >= 185000) {
                    el.classList.add('tb-tier-silver');
                    return;
                }
                el.classList.add('tb-tier-bronze');
            };

            const syncActiveSlotState = () => {
                slots.forEach((slotEl, idx) => slotEl.classList.toggle('is-active-slot', idx === activeSlotIndex));
                if (activeSlotLabelEl) {
                    activeSlotLabelEl.textContent = `Picking for slot ${activeSlotIndex + 1}`;
                }
            };

            const syncBudget = () => {
                let spent = 0;
                slots.forEach((slotEl) => {
                    const player = getSelectedPlayer(slotEl);
                    if (player) spent += Number(player.price || 0);
                });
                if (budgetSpentEl) budgetSpentEl.textContent = toMoney(spent);
                if (budgetRemainingEl) {
                    const remaining = budgetCap - spent;
                    budgetRemainingEl.textContent = toMoney(remaining);
                    budgetRemainingEl.classList.toggle('danger', remaining < 0);
                }
            };

            const syncMarketCards = () => {
                const selectedOwners = new Map();
                slots.forEach((slotEl, idx) => {
                    const playerId = slotEl.querySelector('.tb-player-select')?.value || '';
                    if (playerId !== '') selectedOwners.set(playerId, idx);
                });
                marketCards.forEach((card) => {
                    const playerId = card.getAttribute('data-market-player-id') || '';
                    const owner = selectedOwners.get(playerId);
                    card.classList.toggle('is-picked', owner !== undefined);
                    card.classList.toggle('is-picked-active', owner === activeSlotIndex);
                });
            };

            const setActiveSlot = (index) => {
                activeSlotIndex = Math.max(0, Math.min(slots.length - 1, index));
                syncActiveSlotState();
                syncMarketCards();
            };

            const renderSlot = (slotEl) => {
                const player = getSelectedPlayer(slotEl);
                const roleSelect = slotEl.querySelector('.tb-role-select');
                const roleInput = slotEl.querySelector('.tb-role-input');
                const powerSelect = slotEl.querySelector('.tb-power-select');
                const powerInput = slotEl.querySelector('.tb-power-input');
                const alias = player ? player.alias : 'Select player';
                const realName = player && player.real_name ? player.real_name : '';

                if (roleInput && roleSelect) roleInput.value = roleSelect.value;
                if (powerInput && powerSelect) powerInput.value = powerSelect.value;
                const roleKey = roleSelect?.value || 'star';
                const powerKey = powerSelect?.value || 'none';

                slotEl.querySelector('[data-role-label]').textContent = roleLabels[roleKey] || roleKey;
                slotEl.querySelector('[data-power-label]').textContent = powerLabels[powerKey] || powerKey;
                slotEl.querySelector('[data-field="alias"]').textContent = alias;
                slotEl.querySelector('[data-field="real_name"]').textContent = realName;
                slotEl.querySelector('[data-field="team"]').textContent = player ? player.short_name : '--';
                slotEl.querySelector('[data-field="price"]').textContent = player ? toMoney(player.price) : '--';

                const avatar = slotEl.querySelector('[data-avatar-img]');
                const fallback = slotEl.querySelector('[data-avatar-fallback]');
                if (player && player.avatar_url) {
                    avatar.src = player.avatar_url;
                    avatar.alt = alias;
                    avatar.hidden = false;
                    fallback.hidden = true;
                } else {
                    avatar.hidden = true;
                    fallback.textContent = alias.charAt(0).toUpperCase() || '?';
                    fallback.hidden = false;
                }

                const statMap = {
                    rating: player ? toNumber(player.rating) : '--',
                    acs: player ? toNumber(player.acs) : '--',
                    kd: player ? toNumber(player.kd) : '--',
                    kast: player ? toNumber(player.kast) : '--',
                    kpr: player ? toNumber(player.kpr) : '--',
                    apr: player ? toNumber(player.apr) : '--',
                    fkpr: player ? toNumber(player.fkpr) : '--',
                    fdpr: player ? toNumber(player.fdpr) : '--',
                    cl_pct: player ? toPercent(player.cl_pct) : '--',
                };
                slotEl.querySelectorAll('[data-stat]').forEach((el) => {
                    const key = el.getAttribute('data-stat');
                    el.textContent = statMap[key] ?? '--';
                });

                const powerHelper = slotEl.querySelector('[data-power-helper]');
                const powerButton = slotEl.querySelector('.tb-power-open');
                if (powerHelper) {
                    powerHelper.textContent = powerKey === 'none'
                        ? 'No booster selected.'
                        : `${powerLabels[powerKey] || 'Booster'} selected.`;
                }
                if (powerButton) {
                    powerButton.textContent = powerKey === 'none' ? 'Add booster' : 'Change booster';
                }

                applyTierClass(slotEl, player ? Number(player.price || 0) : NaN);
                syncBudget();
                syncMarketCards();
                refreshRoleModal();
                refreshPowerModal();
            };

            slots.forEach((slotEl, idx) => {
                slotEl.querySelector('.tb-player-select')?.addEventListener('change', () => renderSlot(slotEl));
                slotEl.querySelector('.tb-role-select')?.addEventListener('change', () => renderSlot(slotEl));
                slotEl.querySelector('.tb-power-select')?.addEventListener('change', () => renderSlot(slotEl));
                slotEl.querySelector('[data-set-active-slot]')?.addEventListener('click', () => setActiveSlot(idx));
                slotEl.querySelector('[data-clear-slot]')?.addEventListener('click', () => {
                    const playerSelect = slotEl.querySelector('.tb-player-select');
                    if (!playerSelect) return;
                    playerSelect.value = '';
                    playerSelect.dispatchEvent(new Event('change', { bubbles: true }));
                    setActiveSlot(idx);
                });

                const card = slotEl.querySelector('.tb-card');
                const flipButton = slotEl.querySelector('.tb-flip-button');
                const flip = () => card.classList.toggle('is-flipped');

                card?.addEventListener('click', (event) => {
                    if (event.target.closest('a,button,select,input,label')) return;
                    setActiveSlot(idx);
                    flip();
                });
                flipButton?.addEventListener('click', flip);
                card?.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        setActiveSlot(idx);
                        flip();
                    }
                });

                renderSlot(slotEl);
            });

            marketCards.forEach((card) => {
                card.addEventListener('click', () => {
                    const playerId = card.getAttribute('data-market-player-id') || '';
                    if (playerId === '') return;

                    const existingOwner = slots.findIndex((slotEl) => {
                        const current = slotEl.querySelector('.tb-player-select');
                        return (current?.value || '') === playerId;
                    });

                    if (existingOwner === activeSlotIndex) {
                        const currentSelect = slots[activeSlotIndex].querySelector('.tb-player-select');
                        if (!currentSelect) return;
                        currentSelect.value = '';
                        currentSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        return;
                    }

                    if (existingOwner >= 0 && existingOwner !== activeSlotIndex) {
                        const otherSelect = slots[existingOwner].querySelector('.tb-player-select');
                        if (otherSelect) {
                            otherSelect.value = '';
                            otherSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }

                    const activeSelect = slots[activeSlotIndex].querySelector('.tb-player-select');
                    if (!activeSelect) return;
                    activeSelect.value = playerId;
                    activeSelect.dispatchEvent(new Event('change', { bubbles: true }));

                    const nextEmpty = slots.findIndex((slotEl) => (slotEl.querySelector('.tb-player-select')?.value || '') === '');
                    if (nextEmpty >= 0) {
                        setActiveSlot(nextEmpty);
                    }
                });
            });

            setActiveSlot(0);

            const roleModal = document.querySelector('[data-role-modal]');
            const powerModal = document.querySelector('[data-power-modal]');
            if (locked) return;

            const syncBodyModalLock = () => {
                const anyOpen = (roleModal && !roleModal.hidden) || (powerModal && !powerModal.hidden);
                document.body.classList.toggle('modal-open', Boolean(anyOpen));
            };

            if (roleModal) {
                const rolePlayerStrip = roleModal.querySelector('[data-role-player-strip]');
                const roleOptions = Array.from(roleModal.querySelectorAll('[data-role-value]'));
                let activeRoleIndex = 0;

                const syncRoleOptions = () => {
                    const slot = slots[activeRoleIndex];
                    const selectedKey = slot.querySelector('.tb-role-select')?.value || 'star';
                    roleOptions.forEach((btn) => {
                        const key = btn.getAttribute('data-role-value');
                        btn.classList.toggle('is-selected', key === selectedKey);
                        btn.setAttribute('aria-pressed', key === selectedKey ? 'true' : 'false');
                    });
                };

                const renderRoleStrip = () => {
                    if (!rolePlayerStrip) return;
                    rolePlayerStrip.innerHTML = '';
                    slots.forEach((slotEl, idx) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'tb-role-player';
                        if (idx === activeRoleIndex) btn.classList.add('is-active');

                        const player = getSelectedPlayer(slotEl);
                        btn.textContent = player ? player.alias : `Slot ${idx + 1}`;
                        btn.addEventListener('click', () => {
                            activeRoleIndex = idx;
                            renderRoleStrip();
                            syncRoleOptions();
                        });
                        rolePlayerStrip.appendChild(btn);
                    });
                };

                const openRoleModal = (index) => {
                    activeRoleIndex = index;
                    if (powerModal) powerModal.hidden = true;
                    roleModal.hidden = false;
                    syncBodyModalLock();
                    renderRoleStrip();
                    syncRoleOptions();
                };

                const closeRoleModal = () => {
                    roleModal.hidden = true;
                    syncBodyModalLock();
                };

                document.querySelectorAll('[data-open-role]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const idx = Number(btn.getAttribute('data-open-role') || '0');
                        setActiveSlot(idx);
                        openRoleModal(idx);
                    });
                });

                roleModal.querySelectorAll('[data-role-close]').forEach((el) => el.addEventListener('click', closeRoleModal));
                roleModal.querySelector('[data-role-prev]')?.addEventListener('click', () => {
                    activeRoleIndex = (activeRoleIndex - 1 + slots.length) % slots.length;
                    renderRoleStrip();
                    syncRoleOptions();
                });
                roleModal.querySelector('[data-role-next]')?.addEventListener('click', () => {
                    activeRoleIndex = (activeRoleIndex + 1) % slots.length;
                    renderRoleStrip();
                    syncRoleOptions();
                });

                roleOptions.forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const slot = slots[activeRoleIndex];
                        const roleSelect = slot.querySelector('.tb-role-select');
                        if (!roleSelect) return;
                        roleSelect.value = btn.getAttribute('data-role-value');
                        roleSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        syncRoleOptions();
                        renderRoleStrip();
                    });
                });

                refreshRoleModal = () => {
                    if (roleModal.hidden) return;
                    renderRoleStrip();
                    syncRoleOptions();
                };
            }

            if (powerModal) {
                const powerPlayerStrip = powerModal.querySelector('[data-power-player-strip]');
                const powerMode = powerModal.querySelector('[data-power-mode]');
                const powerAssignView = powerModal.querySelector('[data-power-assign-view]');
                const powerBreakdownView = powerModal.querySelector('[data-power-breakdown-view]');
                const powerOptions = Array.from(powerModal.querySelectorAll('[data-power-value]'));
                let activePowerIndex = 0;

                const syncPowerViews = () => {
                    const breakdown = powerMode && powerMode.value === 'breakdown';
                    if (powerAssignView) powerAssignView.hidden = Boolean(breakdown);
                    if (powerBreakdownView) powerBreakdownView.hidden = !breakdown;
                };

                const renderPowerStrip = () => {
                    if (!powerPlayerStrip) return;
                    powerPlayerStrip.innerHTML = '';
                    slots.forEach((slotEl, idx) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'tb-role-player';
                        if (idx === activePowerIndex) btn.classList.add('is-active');

                        const player = getSelectedPlayer(slotEl);
                        btn.textContent = player ? player.alias : `Slot ${idx + 1}`;
                        btn.addEventListener('click', () => {
                            activePowerIndex = idx;
                            renderPowerStrip();
                            syncPowerOptions();
                        });
                        powerPlayerStrip.appendChild(btn);
                    });
                };

                const syncPowerOptions = () => {
                    const slot = slots[activePowerIndex];
                    const current = slot.querySelector('.tb-power-select')?.value || 'none';
                    const takenByOthers = new Set();
                    slots.forEach((slotEl, idx) => {
                        if (idx === activePowerIndex) return;
                        const key = slotEl.querySelector('.tb-power-select')?.value || 'none';
                        if (key !== 'none') takenByOthers.add(key);
                    });

                    powerOptions.forEach((btn) => {
                        const key = btn.getAttribute('data-power-value') || 'none';
                        const selected = key === current;
                        const disabled = key !== 'none' && takenByOthers.has(key) && !selected;
                        btn.classList.toggle('is-selected', selected);
                        btn.classList.toggle('is-disabled', disabled);
                        btn.disabled = disabled;
                        btn.setAttribute('aria-pressed', selected ? 'true' : 'false');
                    });
                };

                const openPowerModal = (index) => {
                    activePowerIndex = index;
                    if (roleModal) roleModal.hidden = true;
                    if (powerMode) powerMode.value = 'assign';
                    powerModal.hidden = false;
                    syncBodyModalLock();
                    syncPowerViews();
                    renderPowerStrip();
                    syncPowerOptions();
                };

                const closePowerModal = () => {
                    powerModal.hidden = true;
                    syncBodyModalLock();
                };

                document.querySelectorAll('[data-open-power]').forEach((btn) => {
                    btn.addEventListener('click', () => {
                        const idx = Number(btn.getAttribute('data-open-power') || '0');
                        setActiveSlot(idx);
                        openPowerModal(idx);
                    });
                });

                powerModal.querySelectorAll('[data-power-close]').forEach((el) => el.addEventListener('click', closePowerModal));
                powerModal.querySelector('[data-power-prev]')?.addEventListener('click', () => {
                    activePowerIndex = (activePowerIndex - 1 + slots.length) % slots.length;
                    renderPowerStrip();
                    syncPowerOptions();
                });
                powerModal.querySelector('[data-power-next]')?.addEventListener('click', () => {
                    activePowerIndex = (activePowerIndex + 1) % slots.length;
                    renderPowerStrip();
                    syncPowerOptions();
                });

                powerMode?.addEventListener('change', syncPowerViews);

                powerOptions.forEach((btn) => {
                    btn.addEventListener('click', () => {
                        if (btn.disabled) return;
                        const slot = slots[activePowerIndex];
                        const powerSelect = slot.querySelector('.tb-power-select');
                        if (!powerSelect) return;
                        powerSelect.value = btn.getAttribute('data-power-value');
                        powerSelect.dispatchEvent(new Event('change', { bubbles: true }));
                        syncPowerOptions();
                        renderPowerStrip();
                    });
                });

                refreshPowerModal = () => {
                    if (powerModal.hidden) return;
                    renderPowerStrip();
                    syncPowerOptions();
                };
            }

            document.addEventListener('keydown', (event) => {
                if (event.key !== 'Escape') return;
                if (roleModal && !roleModal.hidden) roleModal.hidden = true;
                if (powerModal && !powerModal.hidden) powerModal.hidden = true;
                syncBodyModalLock();
            });

            void roleRules;
        })();
    </script>
    <?php
}, $user);
