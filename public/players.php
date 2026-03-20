<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/src/bootstrap.php';

$user = currentUser();
$event = getCurrentEvent();
if (!$event) {
    http_response_code(500);
    exit('No active event.');
}

$players = listPlayersForEvent((int)$event['id']);
$latestSync = getLatestSyncLog((int)$event['id']);
$teamLogoMap = buildTeamLogoMap($players);

renderLayout('Players', static function () use ($players, $latestSync, $teamLogoMap): void {
    ?>
    <section class="card players-intro">
        <h1>Players</h1>
        <?php if ($latestSync): ?>
            <p class="meta">
                Last sync: <?= h(utcDisplay((string)$latestSync['created_at'])) ?>
                (<?= h((string)$latestSync['status']) ?>)
            </p>
        <?php endif; ?>
    </section>

    <section class="card players-board">
        <div class="table-wrap">
        <table class="player-market-table">
            <thead>
                <tr>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="text">Player</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="text">Team</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">Price</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">Rating</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">ACS</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">KD</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">KAST</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">KPR</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">APR</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">FKPR</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">FDPR</button></th>
                    <th aria-sort="none"><button type="button" class="table-sort" data-sort-type="number">CL%</button></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($players as $p): ?>
                    <?php
                    $avatarUrl = trim((string)($p['avatar_url'] ?? ''));
                    $teamName = trim((string)($p['team_name'] ?? ''));
                    if ($teamName === '') {
                        $teamName = (string)($p['short_name'] ?? '--');
                    }
                    $teamSourceId = (int)($p['source_team_id'] ?? 0);
                    $teamLogoUrl = $teamSourceId > 0 ? ($teamLogoMap[$teamSourceId] ?? null) : null;
                    ?>
                    <tr>
                        <td class="player-market-cell" data-sort="<?= h(strtolower((string)$p['alias'])) ?>">
                            <a class="player-link" href="/player.php?id=<?= (int)$p['id'] ?>">
                                <img
                                    class="player-avatar"
                                    <?php if ($avatarUrl !== ''): ?>src="<?= h($avatarUrl) ?>"<?php endif; ?>
                                    alt="<?= h((string)$p['alias']) ?>"
                                    loading="lazy"
                                    onerror="this.hidden=true; if (this.nextElementSibling) { this.nextElementSibling.hidden=false; }"
                                    <?= $avatarUrl === '' ? 'hidden' : '' ?>
                                >
                                <span class="player-avatar player-avatar-fallback" <?= $avatarUrl !== '' ? 'hidden' : '' ?>></span>
                                <span class="player-identity">
                                    <span class="player-handle"><?= h($p['alias']) ?></span>
                                </span>
                            </a>
                        </td>
                        <td data-sort="<?= h(strtolower($teamName)) ?>">
                            <span class="team-inline">
                                <?php if ($teamLogoUrl): ?>
                                    <img class="team-logo" src="<?= h($teamLogoUrl) ?>" alt="<?= h($teamName) ?>">
                                <?php else: ?>
                                    <span class="team-logo-fallback"><?= h(strtoupper(substr((string)($p['short_name'] ?? ''), 0, 1))) ?></span>
                                <?php endif; ?>
                                <span class="team-name"><?= h($teamName) ?></span>
                            </span>
                        </td>
                        <td data-sort="<?= (int)$p['price'] ?>"><?= h(formatMoney((int)$p['price'])) ?></td>
                        <td data-sort="<?= (float)$p['rating'] ?>"><?= h(formatPoints((float)$p['rating'])) ?></td>
                        <td data-sort="<?= (float)$p['acs'] ?>"><?= h(formatPoints((float)$p['acs'])) ?></td>
                        <td data-sort="<?= (float)$p['kd'] ?>"><?= h(formatPoints((float)$p['kd'])) ?></td>
                        <td data-sort="<?= (float)$p['kast'] ?>"><?= h(formatPoints((float)$p['kast'])) ?></td>
                        <td data-sort="<?= (float)$p['kpr'] ?>"><?= h(formatPoints((float)$p['kpr'])) ?></td>
                        <td data-sort="<?= (float)$p['apr'] ?>"><?= h(formatPoints((float)$p['apr'])) ?></td>
                        <td data-sort="<?= (float)$p['fkpr'] ?>"><?= h(formatPoints((float)$p['fkpr'])) ?></td>
                        <td data-sort="<?= (float)$p['fdpr'] ?>"><?= h(formatPoints((float)$p['fdpr'])) ?></td>
                        <td data-sort="<?= (float)$p['cl_pct'] ?>"><?= h(formatPoints((float)$p['cl_pct'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <script>
        (() => {
            const table = document.querySelector('.player-market-table');
            if (!table) return;

            const headerButtons = Array.from(table.querySelectorAll('thead .table-sort'));
            const tbody = table.querySelector('tbody');
            if (!headerButtons.length || !tbody) return;

            let sortedIndex = -1;
            let sortedDir = 'asc';

            const readValue = (row, index, type) => {
                const cell = row.cells[index];
                if (!cell) return type === 'number' ? 0 : '';
                const raw = (cell.getAttribute('data-sort') || cell.textContent || '').trim();
                if (type === 'number') {
                    const num = Number(raw);
                    return Number.isFinite(num) ? num : 0;
                }
                return raw.toLowerCase();
            };

            const setHeaderState = (index, dir) => {
                headerButtons.forEach((btn, i) => {
                    const th = btn.closest('th');
                    if (!th) return;
                    th.setAttribute('aria-sort', i === index ? (dir === 'asc' ? 'ascending' : 'descending') : 'none');
                    const label = btn.textContent.replace(/\s*[▲▼]$/, '');
                    btn.textContent = i === index ? `${label} ${dir === 'asc' ? '▲' : '▼'}` : label;
                });
            };

            const sortBy = (index, type) => {
                const rows = Array.from(tbody.querySelectorAll('tr'));
                if (!rows.length) return;

                if (sortedIndex === index) {
                    sortedDir = sortedDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortedIndex = index;
                    sortedDir = type === 'text' ? 'asc' : 'desc';
                }

                rows.sort((a, b) => {
                    const av = readValue(a, index, type);
                    const bv = readValue(b, index, type);
                    if (type === 'number') {
                        return sortedDir === 'asc' ? av - bv : bv - av;
                    }
                    const cmp = String(av).localeCompare(String(bv), undefined, { sensitivity: 'base' });
                    return sortedDir === 'asc' ? cmp : -cmp;
                });

                rows.forEach((row) => tbody.appendChild(row));
                setHeaderState(index, sortedDir);
            };

            headerButtons.forEach((btn, index) => {
                btn.addEventListener('click', () => {
                    const type = btn.getAttribute('data-sort-type') === 'number' ? 'number' : 'text';
                    sortBy(index, type);
                });
            });
        })();
    </script>
    <?php
}, $user);

function buildTeamLogoMap(array $players): array
{
    $logos = [];
    foreach ($players as $player) {
        $sourceTeamId = (int)($player['source_team_id'] ?? 0);
        if ($sourceTeamId <= 0 || isset($logos[$sourceTeamId])) {
            continue;
        }

        $teamSlug = trim((string)($player['team_slug'] ?? ''));
        $logos[$sourceTeamId] = resolveTeamLogoUrl($sourceTeamId, $teamSlug);
    }

    return $logos;
}

function resolveTeamLogoUrl(int $sourceTeamId, string $teamSlug): ?string
{
    if ($sourceTeamId <= 0 || $teamSlug === '') {
        return null;
    }

    $cacheDir = APP_ROOT . '/public/assets/team-logos';
    if (!is_dir($cacheDir)) {
        return null;
    }

    foreach (['png', 'jpg', 'jpeg', 'webp', 'avif', 'gif', 'svg'] as $extension) {
        $candidate = $cacheDir . '/' . $sourceTeamId . '.' . $extension;
        if (is_file($candidate) && (int)filesize($candidate) > 0) {
            return '/assets/team-logos/' . basename($candidate);
        }
    }

    return null;
}
