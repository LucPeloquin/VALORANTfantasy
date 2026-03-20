<?php
declare(strict_types=1);

function getCurrentEvent(): ?array
{
    $stmt = db()->query("SELECT * FROM events WHERE status IN ('open', 'locked') ORDER BY created_at DESC LIMIT 1");
    $row = $stmt->fetch();
    return $row ?: null;
}

function ensureEventSynced(): void
{
    if ((string)env('AUTO_SYNC_ON_BOOT', '1') !== '1') {
        return;
    }

    $event = getCurrentEvent();
    if (!$event) {
        return;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM players WHERE event_id = :event_id AND is_active = 1');
    $stmt->execute([':event_id' => (int)$event['id']]);
    $count = (int)$stmt->fetchColumn();

    if ($count === 0) {
        try {
            syncEventDataFromSources((int)$event['id']);
        } catch (Throwable $e) {
            // Do not block page load if first sync fails.
        }
    }

    runScheduledSyncIfDue((int)$event['id']);
}

function runScheduledSyncIfDue(int $eventId, bool $force = false): array
{
    $intervalMinutes = max(1, (int)env('AUTO_SYNC_INTERVAL_MINUTES', '30'));

    $latest = getLatestSyncLog($eventId);
    $due = $force || !$latest;
    if (!$due && !empty($latest['created_at'])) {
        $ageSeconds = time() - strtotime((string)$latest['created_at']);
        $due = $ageSeconds >= ($intervalMinutes * 60);
    }

    if (!$due) {
        return ['ran' => false, 'reason' => 'not_due'];
    }

    $lockPath = APP_ROOT . '/data/auto-sync.lock';
    $lockHandle = fopen($lockPath, 'c+');
    if (!$lockHandle) {
        return ['ran' => false, 'reason' => 'lock_open_failed'];
    }

    if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
        fclose($lockHandle);
        return ['ran' => false, 'reason' => 'lock_busy'];
    }

    ftruncate($lockHandle, 0);
    fwrite($lockHandle, (string)time());

    try {
        $summary = syncEventDataFromSources($eventId);
        return [
            'ran' => true,
            'reason' => 'synced',
            'teams' => $summary['teams'] ?? 0,
            'players' => $summary['players'] ?? 0,
        ];
    } catch (Throwable $e) {
        return [
            'ran' => false,
            'reason' => 'sync_error',
            'error' => $e->getMessage(),
        ];
    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function listPlayersForEvent(int $eventId): array
{
    $sql = <<<'SQL'
SELECT p.*, t.name AS team_name, t.short_name, t.slug AS team_slug, t.source_team_id,
       s.rounds_played, s.rating, s.acs, s.kd, s.kast, s.adr, s.kpr, s.apr, s.fkpr, s.fdpr, s.hs_pct, s.cl_pct,
       s.source_primary, s.source_secondary, s.updated_at AS stats_updated_at
FROM players p
INNER JOIN pro_teams t ON t.id = p.team_id
LEFT JOIN player_event_stats s ON s.player_id = p.id AND s.event_id = p.event_id
WHERE p.event_id = :event_id AND p.is_active = 1
ORDER BY p.price DESC, p.alias ASC
SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute([':event_id' => $eventId]);
    return $stmt->fetchAll();
}

function listPlayersForAdmin(int $eventId): array
{
    $sql = <<<'SQL'
SELECT p.*, t.name AS team_name, t.short_name, t.slug AS team_slug, t.source_team_id,
       s.rounds_played, s.rating, s.acs, s.kd, s.kast, s.adr, s.kpr, s.apr, s.fkpr, s.fdpr, s.hs_pct, s.cl_pct,
       s.source_primary, s.source_secondary, s.updated_at AS stats_updated_at
FROM players p
INNER JOIN pro_teams t ON t.id = p.team_id
LEFT JOIN player_event_stats s ON s.player_id = p.id AND s.event_id = p.event_id
WHERE p.event_id = :event_id
ORDER BY p.is_active DESC, p.price DESC, p.alias ASC
SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute([':event_id' => $eventId]);
    return $stmt->fetchAll();
}

function adminUpdatePlayerValues(int $eventId, int $playerId, array $payload): void
{
    $pdo = db();

    $lookup = $pdo->prepare(
        'SELECT p.id, s.id AS stats_id
         FROM players p
         LEFT JOIN player_event_stats s ON s.player_id = p.id AND s.event_id = p.event_id
         WHERE p.id = :player_id AND p.event_id = :event_id
         LIMIT 1'
    );
    $lookup->execute([
        ':player_id' => $playerId,
        ':event_id' => $eventId,
    ]);
    $row = $lookup->fetch();
    if (!$row) {
        throw new RuntimeException('Player not found for this event.');
    }

    $alias = trim((string)($payload['alias'] ?? ''));
    if ($alias === '') {
        throw new InvalidArgumentException('Alias is required.');
    }

    $realName = trim((string)($payload['real_name'] ?? ''));
    $avatarUrl = trim((string)($payload['avatar_url'] ?? ''));
    $price = (int)($payload['price'] ?? 0);
    $isActive = !empty($payload['is_active']) ? 1 : 0;

    $stats = [
        'rating' => $payload['rating'] ?? null,
        'acs' => $payload['acs'] ?? null,
        'kd' => $payload['kd'] ?? null,
        'kast' => $payload['kast'] ?? null,
        'kpr' => $payload['kpr'] ?? null,
        'apr' => $payload['apr'] ?? null,
        'fkpr' => $payload['fkpr'] ?? null,
        'fdpr' => $payload['fdpr'] ?? null,
        'cl_pct' => $payload['cl_pct'] ?? null,
    ];

    $pdo->beginTransaction();
    try {
        $updatePlayer = $pdo->prepare(
            'UPDATE players
             SET alias = :alias,
                 real_name = :real_name,
                 avatar_url = :avatar_url,
                 price = :price,
                 is_active = :is_active
             WHERE id = :player_id AND event_id = :event_id'
        );
        $updatePlayer->execute([
            ':alias' => $alias,
            ':real_name' => $realName !== '' ? $realName : null,
            ':avatar_url' => $avatarUrl !== '' ? $avatarUrl : null,
            ':price' => $price,
            ':is_active' => $isActive,
            ':player_id' => $playerId,
            ':event_id' => $eventId,
        ]);

        if (!empty($row['stats_id'])) {
            $updateStats = $pdo->prepare(
                'UPDATE player_event_stats
                 SET rating = :rating,
                     acs = :acs,
                     kd = :kd,
                     kast = :kast,
                     kpr = :kpr,
                     apr = :apr,
                     fkpr = :fkpr,
                     fdpr = :fdpr,
                     cl_pct = :cl_pct,
                     updated_at = :updated_at
                 WHERE id = :stats_id'
            );
            $updateStats->execute([
                ':rating' => $stats['rating'],
                ':acs' => $stats['acs'],
                ':kd' => $stats['kd'],
                ':kast' => $stats['kast'],
                ':kpr' => $stats['kpr'],
                ':apr' => $stats['apr'],
                ':fkpr' => $stats['fkpr'],
                ':fdpr' => $stats['fdpr'],
                ':cl_pct' => $stats['cl_pct'],
                ':updated_at' => nowUtc(),
                ':stats_id' => (int)$row['stats_id'],
            ]);
        } else {
            $insertStats = $pdo->prepare(
                'INSERT INTO player_event_stats
                (event_id, player_id, rating, acs, kd, kast, kpr, apr, fkpr, fdpr, cl_pct, source_primary, source_secondary, updated_at)
                 VALUES
                (:event_id, :player_id, :rating, :acs, :kd, :kast, :kpr, :apr, :fkpr, :fdpr, :cl_pct, :source_primary, :source_secondary, :updated_at)'
            );
            $insertStats->execute([
                ':event_id' => $eventId,
                ':player_id' => $playerId,
                ':rating' => $stats['rating'],
                ':acs' => $stats['acs'],
                ':kd' => $stats['kd'],
                ':kast' => $stats['kast'],
                ':kpr' => $stats['kpr'],
                ':apr' => $stats['apr'],
                ':fkpr' => $stats['fkpr'],
                ':fdpr' => $stats['fdpr'],
                ':cl_pct' => $stats['cl_pct'],
                ':source_primary' => 'manual',
                ':source_secondary' => 'admin',
                ':updated_at' => nowUtc(),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function getPlayerProfile(int $playerId, int $eventId): ?array
{
    $sql = <<<'SQL'
SELECT p.*, t.name AS team_name, t.short_name, e.name AS event_name,
       s.rounds_played, s.rating, s.acs, s.kd, s.kast, s.adr, s.kpr, s.apr, s.fkpr, s.fdpr, s.hs_pct, s.cl_pct,
       s.source_primary, s.source_secondary, s.raw_json, s.updated_at AS stats_updated_at
FROM players p
INNER JOIN pro_teams t ON t.id = p.team_id
INNER JOIN events e ON e.id = p.event_id
LEFT JOIN player_event_stats s ON s.player_id = p.id AND s.event_id = p.event_id
WHERE p.id = :player_id AND p.event_id = :event_id
LIMIT 1
SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute([':player_id' => $playerId, ':event_id' => $eventId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function listPublicLeaguesForEvent(int $eventId): array
{
    $stmt = db()->prepare('SELECT id, name, description, join_code FROM fantasy_leagues WHERE event_id = :event_id AND is_public = 1 ORDER BY created_at DESC');
    $stmt->execute([':event_id' => $eventId]);
    return $stmt->fetchAll();
}

function listUserLeagues(int $userId): array
{
    $sql = <<<'SQL'
SELECT l.*, e.name AS event_name
FROM fantasy_leagues l
INNER JOIN league_members lm ON lm.league_id = l.id
INNER JOIN events e ON e.id = l.event_id
WHERE lm.user_id = :user_id
ORDER BY l.created_at DESC
SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute([':user_id' => $userId]);
    return $stmt->fetchAll();
}

function getPrimaryLeagueForEvent(int $eventId): ?array
{
    $sql = <<<'SQL'
SELECT l.*, e.name AS event_name, e.lock_at, e.budget, e.max_from_team, e.id AS event_id
FROM fantasy_leagues l
INNER JOIN events e ON e.id = l.event_id
WHERE l.event_id = :event_id
ORDER BY l.created_at ASC, l.id ASC
LIMIT 1
SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute([':event_id' => $eventId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function defaultLeagueOwnerUserId(): int
{
    $admin = db()->query('SELECT id FROM users WHERE is_admin = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($admin) {
        return (int)$admin;
    }

    $firstUser = db()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($firstUser) {
        return (int)$firstUser;
    }

    throw new RuntimeException('No user exists to own the primary league.');
}

function ensurePrimaryLeagueForEvent(int $eventId, ?int $ownerUserId = null): array
{
    $existing = getPrimaryLeagueForEvent($eventId);
    if ($existing) {
        return $existing;
    }

    $eventStmt = db()->prepare('SELECT name FROM events WHERE id = :id');
    $eventStmt->execute([':id' => $eventId]);
    $eventName = (string)($eventStmt->fetchColumn() ?: 'VCT Fantasy');

    $leagueId = createLeague(
        $eventId,
        $ownerUserId ?? defaultLeagueOwnerUserId(),
        $eventName . ' Main League',
        'Single shared league for this event.',
        true
    );

    $league = getLeague($leagueId);
    if (!$league) {
        throw new RuntimeException('Failed to load primary league after creation.');
    }

    return $league;
}

function ensureUserInPrimaryLeague(int $userId, int $eventId): array
{
    $league = ensurePrimaryLeagueForEvent($eventId, $userId);
    addLeagueMember((int)$league['id'], $userId);
    ensureFantasyTeam((int)$league['id'], $userId);
    return $league;
}

function createLeague(int $eventId, int $ownerUserId, string $name, string $description, bool $isPublic): int
{
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('League name is required.');
    }

    $pdo = db();

    $joinCode = null;
    for ($i = 0; $i < 8; $i++) {
        $candidate = randomCode(8);
        $exists = $pdo->prepare('SELECT 1 FROM fantasy_leagues WHERE join_code = :join_code');
        $exists->execute([':join_code' => $candidate]);
        if (!$exists->fetchColumn()) {
            $joinCode = $candidate;
            break;
        }
    }

    if ($joinCode === null) {
        throw new RuntimeException('Unable to generate join code.');
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare(
            'INSERT INTO fantasy_leagues (event_id, name, description, join_code, owner_user_id, is_public, created_at)
             VALUES (:event_id, :name, :description, :join_code, :owner_user_id, :is_public, :created_at)'
        );
        $insert->execute([
            ':event_id' => $eventId,
            ':name' => $name,
            ':description' => trim($description),
            ':join_code' => $joinCode,
            ':owner_user_id' => $ownerUserId,
            ':is_public' => $isPublic ? 1 : 0,
            ':created_at' => nowUtc(),
        ]);

        $leagueId = (int)$pdo->lastInsertId();
        addLeagueMember($leagueId, $ownerUserId);
        ensureFantasyTeam($leagueId, $ownerUserId);

        $pdo->commit();
        return $leagueId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function addLeagueMember(int $leagueId, int $userId): void
{
    $stmt = db()->prepare('INSERT OR IGNORE INTO league_members (league_id, user_id, joined_at) VALUES (:league_id, :user_id, :joined_at)');
    $stmt->execute([
        ':league_id' => $leagueId,
        ':user_id' => $userId,
        ':joined_at' => nowUtc(),
    ]);
}

function joinLeagueByCode(string $code, int $userId): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM fantasy_leagues WHERE join_code = :join_code');
    $stmt->execute([':join_code' => $code]);
    $league = $stmt->fetch();
    if (!$league) {
        return null;
    }

    addLeagueMember((int)$league['id'], $userId);
    ensureFantasyTeam((int)$league['id'], $userId);

    return $league;
}

function getLeague(int $leagueId): ?array
{
    $sql = <<<'SQL'
SELECT l.*, e.name AS event_name, e.lock_at, e.budget, e.max_from_team, e.id AS event_id
FROM fantasy_leagues l
INNER JOIN events e ON e.id = l.event_id
WHERE l.id = :id
SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute([':id' => $leagueId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function isUserInLeague(int $leagueId, int $userId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM league_members WHERE league_id = :league_id AND user_id = :user_id');
    $stmt->execute([':league_id' => $leagueId, ':user_id' => $userId]);
    return (bool)$stmt->fetchColumn();
}

function rosterLocked(array $league): bool
{
    return strtotime(nowUtc()) >= strtotime((string)$league['lock_at']);
}

function ensureFantasyTeam(int $leagueId, int $userId): int
{
    $existing = getFantasyTeamByLeagueAndUser($leagueId, $userId);
    if ($existing) {
        return (int)$existing['id'];
    }

    $slug = null;
    for ($i = 0; $i < 8; $i++) {
        $candidate = randomSlug();
        $check = db()->prepare('SELECT 1 FROM fantasy_teams WHERE share_slug = :share_slug');
        $check->execute([':share_slug' => $candidate]);
        if (!$check->fetchColumn()) {
            $slug = $candidate;
            break;
        }
    }

    if ($slug === null) {
        throw new RuntimeException('Failed to generate share slug.');
    }

    $stmt = db()->prepare(
        'INSERT INTO fantasy_teams (league_id, user_id, team_name, is_public, share_slug, created_at, updated_at)
         VALUES (:league_id, :user_id, :team_name, 0, :share_slug, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':league_id' => $leagueId,
        ':user_id' => $userId,
        ':team_name' => 'My VCT Team',
        ':share_slug' => $slug,
        ':created_at' => nowUtc(),
        ':updated_at' => nowUtc(),
    ]);

    return (int)db()->lastInsertId();
}

function getFantasyTeamByLeagueAndUser(int $leagueId, int $userId): ?array
{
    $stmt = db()->prepare('SELECT * FROM fantasy_teams WHERE league_id = :league_id AND user_id = :user_id');
    $stmt->execute([':league_id' => $leagueId, ':user_id' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function listRosterRows(int $fantasyTeamId): array
{
    $sql = <<<'SQL'
SELECT ftp.*, p.alias, p.real_name, p.price, t.name AS team_name, t.short_name,
       s.rating, s.acs, s.kd, s.kast, s.kpr, s.apr, s.fkpr, s.fdpr, s.cl_pct
FROM fantasy_team_players ftp
INNER JOIN players p ON p.id = ftp.player_id
INNER JOIN pro_teams t ON t.id = p.team_id
LEFT JOIN player_event_stats s ON s.player_id = p.id AND s.event_id = p.event_id
WHERE ftp.fantasy_team_id = :fantasy_team_id
ORDER BY p.price DESC
SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute([':fantasy_team_id' => $fantasyTeamId]);
    return $stmt->fetchAll();
}

function saveFantasyRoster(int $fantasyTeamId, array $league, string $teamName, bool $isPublic, bool $submitNow, array $selection): array
{
    $errors = [];
    if (count($selection) !== 5) {
        $errors[] = 'Pick exactly 5 players.';
        return $errors;
    }

    $playerIds = array_map(static fn(array $s): int => (int)$s['player_id'], $selection);
    if (count(array_unique($playerIds)) !== 5) {
        $errors[] = 'Duplicate players are not allowed.';
    }

    $roles = roleCatalog();
    $powers = powerCatalog();

    $usedPowers = [];
    foreach ($selection as $s) {
        if (!isset($roles[$s['role_key']])) {
            $errors[] = 'Invalid role selected.';
            break;
        }
        if (!isset($powers[$s['power_key']])) {
            $errors[] = 'Invalid power selected.';
            break;
        }

        if ($s['power_key'] !== 'none') {
            if (isset($usedPowers[$s['power_key']])) {
                $errors[] = 'Each superpower can be used only once.';
                break;
            }
            $usedPowers[$s['power_key']] = true;
        }
    }

    $placeholders = implode(',', array_fill(0, count($playerIds), '?'));
    $sql = 'SELECT id, team_id, price FROM players WHERE event_id = ? AND is_active = 1 AND id IN (' . $placeholders . ')';
    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([(int)$league['event_id']], $playerIds));
    $rows = $stmt->fetchAll();

    if (count($rows) !== 5) {
        $errors[] = 'One or more selected players are invalid for this event.';
        return $errors;
    }

    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = $r;
    }

    $spent = 0;
    $teamCounts = [];
    foreach ($selection as $s) {
        $player = $byId[(int)$s['player_id']];
        $spent += (int)$player['price'];
        $teamId = (int)$player['team_id'];
        $teamCounts[$teamId] = ($teamCounts[$teamId] ?? 0) + 1;
    }

    if ($spent > (int)$league['budget']) {
        $errors[] = 'Budget exceeded (' . formatMoney($spent) . ' > ' . formatMoney((int)$league['budget']) . ').';
    }

    foreach ($teamCounts as $count) {
        if ($count > (int)$league['max_from_team']) {
            $errors[] = 'Max ' . (int)$league['max_from_team'] . ' players per real team.';
            break;
        }
    }

    if ($errors) {
        return $errors;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare(
            'UPDATE fantasy_teams
             SET team_name = :team_name,
                 is_public = :is_public,
                 submitted_at = :submitted_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );
        $update->execute([
            ':team_name' => trim($teamName) !== '' ? trim($teamName) : 'My VCT Team',
            ':is_public' => $isPublic ? 1 : 0,
            ':submitted_at' => $submitNow ? nowUtc() : null,
            ':updated_at' => nowUtc(),
            ':id' => $fantasyTeamId,
        ]);

        $pdo->prepare('DELETE FROM fantasy_team_players WHERE fantasy_team_id = :fantasy_team_id')
            ->execute([':fantasy_team_id' => $fantasyTeamId]);

        $insert = $pdo->prepare(
            'INSERT INTO fantasy_team_players (fantasy_team_id, player_id, role_key, power_key, purchase_price)
             VALUES (:fantasy_team_id, :player_id, :role_key, :power_key, :purchase_price)'
        );

        foreach ($selection as $s) {
            $p = $byId[(int)$s['player_id']];
            $insert->execute([
                ':fantasy_team_id' => $fantasyTeamId,
                ':player_id' => (int)$s['player_id'],
                ':role_key' => $s['role_key'],
                ':power_key' => $s['power_key'],
                ':purchase_price' => (int)$p['price'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return [];
}

function fantasyTeamSummary(int $fantasyTeamId): array
{
    $stmt = db()->prepare('SELECT COUNT(*) AS players_count, COALESCE(SUM(purchase_price), 0) AS spent FROM fantasy_team_players WHERE fantasy_team_id = :fantasy_team_id');
    $stmt->execute([':fantasy_team_id' => $fantasyTeamId]);
    $row = $stmt->fetch();

    return [
        'players_count' => (int)($row['players_count'] ?? 0),
        'spent' => (int)($row['spent'] ?? 0),
    ];
}

function computeFantasyTeamScore(int $fantasyTeamId): array
{
    $rows = listRosterRows($fantasyTeamId);

    $lineup = [];
    $total = 0.0;
    foreach ($rows as $row) {
        $score = computePlayerFantasyScore($row, (string)$row['role_key'], (string)$row['power_key']);

        $line = $row;
        $line['score'] = $score;
        $lineup[] = $line;
        $total += (float)$score['total'];
    }

    return [
        'total' => round($total, 2),
        'lineup' => $lineup,
    ];
}

function getLeagueLeaderboard(int $leagueId): array
{
    $sql = <<<'SQL'
SELECT lm.user_id,
       u.username,
       u.display_name,
       ft.id AS fantasy_team_id,
       ft.team_name,
       ft.is_public,
       ft.share_slug,
       ft.submitted_at
FROM league_members lm
INNER JOIN users u ON u.id = lm.user_id
LEFT JOIN fantasy_teams ft ON ft.user_id = lm.user_id AND ft.league_id = lm.league_id
WHERE lm.league_id = :league_id
SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute([':league_id' => $leagueId]);
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $row) {
        $points = 0.0;
        if (!empty($row['fantasy_team_id'])) {
            $score = computeFantasyTeamScore((int)$row['fantasy_team_id']);
            $points = (float)$score['total'];
        }

        $row['points'] = $points;
        $result[] = $row;
    }

    usort($result, static function (array $a, array $b): int {
        $cmp = $b['points'] <=> $a['points'];
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string)$a['username'], (string)$b['username']);
    });

    return $result;
}

function getPublicTeamBySlug(string $slug): ?array
{
    $sql = <<<'SQL'
SELECT ft.*, l.name AS league_name, l.id AS league_id, e.name AS event_name, u.username, u.display_name
FROM fantasy_teams ft
INNER JOIN fantasy_leagues l ON l.id = ft.league_id
INNER JOIN events e ON e.id = l.event_id
INNER JOIN users u ON u.id = ft.user_id
WHERE ft.share_slug = :slug
SQL;

    $stmt = db()->prepare($sql);
    $stmt->execute([':slug' => $slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function getRecentSyncLogs(int $eventId, int $limit = 8): array
{
    $stmt = db()->prepare('SELECT * FROM sync_logs WHERE event_id = :event_id ORDER BY id DESC LIMIT :limit');
    $stmt->bindValue(':event_id', $eventId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getLatestSyncLog(int $eventId): ?array
{
    $stmt = db()->prepare('SELECT * FROM sync_logs WHERE event_id = :event_id ORDER BY id DESC LIMIT 1');
    $stmt->execute([':event_id' => $eventId]);
    $row = $stmt->fetch();
    return $row ?: null;
}
