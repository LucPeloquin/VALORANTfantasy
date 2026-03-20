<?php
declare(strict_types=1);

function syncEventDataFromSources(int $eventId): array
{
    $pdo = db();

    $eventStmt = $pdo->prepare('SELECT * FROM events WHERE id = :id');
    $eventStmt->execute([':id' => $eventId]);
    $event = $eventStmt->fetch();
    if (!$event) {
        throw new RuntimeException('Event not found');
    }

    $sourceEventId = (int)($event['source_event_id'] ?? 0);
    if ($sourceEventId <= 0) {
        throw new RuntimeException('Event source ID is missing');
    }

    $statsSnapshot = scrapeVctAmericasStatsSnapshot($sourceEventId, 2682);
    if (!$statsSnapshot['stage'] && !$statsSnapshot['fallback']) {
        throw new RuntimeException('No stats data returned from scraper');
    }

    $playersStmt = $pdo->prepare(
        'SELECT id, source_player_id, alias FROM players WHERE event_id = :event_id AND is_active = 1 ORDER BY id ASC'
    );
    $playersStmt->execute([':event_id' => $eventId]);
    $players = $playersStmt->fetchAll();
    if (!$players) {
        throw new RuntimeException('No active players found. Add or sync players first, then run stats sync.');
    }

    $teamsCountStmt = $pdo->prepare('SELECT COUNT(*) FROM pro_teams WHERE event_id = :event_id');
    $teamsCountStmt->execute([':event_id' => $eventId]);
    $teamsCount = (int)$teamsCountStmt->fetchColumn();

    $now = nowUtc();
    $updated = 0;
    $defaults = 0;

    $pdo->beginTransaction();
    try {
        foreach ($players as $player) {
            $playerId = (int)$player['id'];
            $sourcePlayerId = (int)($player['source_player_id'] ?? 0);
            $alias = strtolower(trim((string)($player['alias'] ?? '')));

            $stage = $sourcePlayerId > 0 ? ($statsSnapshot['stage']['by_id'][$sourcePlayerId] ?? null) : null;
            $fallbackById = $sourcePlayerId > 0 ? ($statsSnapshot['fallback']['by_id'][$sourcePlayerId] ?? null) : null;
            $fallbackByAlias = $alias !== '' ? ($statsSnapshot['fallback']['by_alias'][$alias] ?? null) : null;
            $fallback = $fallbackById ?: $fallbackByAlias;

            $stats = $stage ?: $fallback ?: defaultPlayerStats();
            $sourcePrimary = $stage ? 'vlr_stage1' : ($fallback ? 'vlr_kickoff' : 'default');
            upsertEventPlayerStats($pdo, $eventId, $playerId, $stats, $sourcePrimary, null, $now);

            $updated++;
            if (!$stage && !$fallback) {
                $defaults++;
            }
        }

        $pdo->prepare('INSERT INTO sync_logs (event_id, source, status, message, created_at) VALUES (:event_id, :source, :status, :message, :created_at)')
            ->execute([
                ':event_id' => $eventId,
                ':source' => 'vlr',
                ':status' => 'ok',
                ':message' => 'Stats-only sync. Teams: ' . $teamsCount . ', Players updated: ' . $updated . ', Defaults used: ' . $defaults,
                ':created_at' => $now,
            ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $pdo->prepare('INSERT INTO sync_logs (event_id, source, status, message, created_at) VALUES (:event_id, :source, :status, :message, :created_at)')
            ->execute([
                ':event_id' => $eventId,
                ':source' => 'vlr',
                ':status' => 'error',
                ':message' => $e->getMessage(),
                ':created_at' => nowUtc(),
            ]);

        throw $e;
    }

    file_put_contents(APP_ROOT . '/data/vct_americas_stage1_snapshot.json', json_encode([
        'generated_at' => $now,
        'event_id' => $sourceEventId,
        'sync_mode' => 'stats_only',
        'teams_count' => $teamsCount,
        'players_updated' => $updated,
        'defaults_used' => $defaults,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return [
        'teams' => $teamsCount,
        'players' => $updated,
    ];
}

function scrapeVctAmericasStatsSnapshot(int $stageEventId, int $fallbackEventId): array
{
    return [
        'stage' => scrapeVlrEventStats($stageEventId),
        'fallback' => scrapeVlrEventStats($fallbackEventId),
    ];
}

function defaultPlayerStats(): array
{
    return [
        'rounds_played' => 0,
        'rating' => 1.00,
        'acs' => 200.0,
        'kd' => 1.00,
        'kast' => 70.0,
        'adr' => 130.0,
        'kpr' => 0.70,
        'apr' => 0.20,
        'fkpr' => 0.10,
        'fdpr' => 0.12,
        'hs_pct' => 23.0,
        'cl_pct' => 20.0,
    ];
}

function scrapeVctAmericasSnapshot(int $stageEventId, int $fallbackEventId): array
{
    $teams = scrapeVlrEventTeams($stageEventId);
    $stageStats = scrapeVlrEventStats($stageEventId);
    $fallbackStats = scrapeVlrEventStats($fallbackEventId);

    $players = [];
    foreach ($teams as $team) {
        foreach ($team['players'] as $p) {
            $sourcePlayerId = (int)$p['source_player_id'];

            $stage = $stageStats['by_id'][$sourcePlayerId] ?? null;
            $fallback = $fallbackStats['by_id'][$sourcePlayerId]
                ?? ($fallbackStats['by_alias'][strtolower($p['alias'])] ?? null);

            $baseStats = $stage ?: $fallback;
            if (!$baseStats) {
                $baseStats = [
                    'rounds_played' => 0,
                    'rating' => 1.00,
                    'acs' => 200.0,
                    'kd' => 1.00,
                    'kast' => 70.0,
                    'adr' => 130.0,
                    'kpr' => 0.70,
                    'apr' => 0.20,
                    'fkpr' => 0.10,
                    'fdpr' => 0.12,
                    'hs_pct' => 23.0,
                    'cl_pct' => 20.0,
                ];
            }

            $stats = $baseStats;

            $players[] = [
                'source_player_id' => $sourcePlayerId,
                'source_team_id' => (int)$team['source_team_id'],
                'alias' => $p['alias'],
                'real_name' => $p['real_name'],
                'country_code' => $p['country_code'],
                'avatar_url' => resolvePlayerAvatarUrl($sourcePlayerId, $p['avatar_url'] ?? null),
                'price' => 180000,
                'pricing_score' => 0.0,
                'stats' => $stats,
                'source_primary' => $stage ? 'vlr_stage1' : ($fallback ? 'vlr_kickoff' : 'default'),
                'source_secondary' => null,
            ];
        }
    }

    return [
        'teams' => $teams,
        'players' => $players,
    ];
}

function scrapeVlrEventTeams(int $eventId): array
{
    $html = httpGet('https://www.vlr.gg/event/' . $eventId . '/');
    $xpath = htmlToXpath($html);

    $anchors = $xpath->query('//a[contains(@class,"event-team-name")]');
    if (!$anchors) {
        return [];
    }

    $seen = [];
    $teams = [];

    foreach ($anchors as $anchor) {
        $href = trim((string)$anchor->getAttribute('href'));
        if (!preg_match('#^/team/(\d+)/([^/]+)$#', $href, $m)) {
            continue;
        }

        $sourceTeamId = (int)$m[1];
        if (isset($seen[$sourceTeamId])) {
            continue;
        }
        $seen[$sourceTeamId] = true;

        $teamPage = scrapeVlrTeamPage($sourceTeamId, $m[2]);

        $teams[] = [
            'source_team_id' => $sourceTeamId,
            'slug' => $m[2],
            'name' => $teamPage['name'] ?: trim($anchor->textContent),
            'short_name' => $teamPage['short_name'] ?: strtoupper(substr(trim($anchor->textContent), 0, 3)),
            'players' => $teamPage['players'],
        ];
    }

    return $teams;
}

function scrapeVlrTeamPage(int $teamId, string $slug): array
{
    $html = httpGet('https://www.vlr.gg/team/' . $teamId . '/' . $slug);
    $xpath = htmlToXpath($html);

    $nameNode = $xpath->query('//div[contains(@class,"team-header-name")]//h1')?->item(0);
    $tagNode = $xpath->query('//h2[contains(@class,"team-header-tag")]')?->item(0);

    $name = trim((string)($nameNode?->textContent ?? ''));
    $short = strtoupper(trim((string)($tagNode?->textContent ?? '')));

    $playerNodes = $xpath->query(
        '//h2[contains(normalize-space(.),"Current") and contains(normalize-space(.),"Roster")]
          /following::div[contains(@class,"wf-card")][1]
          //div[contains(@class,"wf-module-label") and contains(translate(normalize-space(.),"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"players")]
          /following-sibling::div[1]
          //div[contains(@class,"team-roster-item")]/a'
    );

    $players = [];
    if ($playerNodes) {
        foreach ($playerNodes as $a) {
            $href = trim((string)$a->getAttribute('href'));
            if (!preg_match('#^/player/(\d+)/([^/]+)$#', $href, $m)) {
                continue;
            }

            $aliasNode = $xpath->query('.//div[contains(@class,"team-roster-item-name-alias")]', $a)?->item(0);
            $realNode = $xpath->query('.//div[contains(@class,"team-roster-item-name-real")]', $a)?->item(0);
            $flagNode = $xpath->query('.//i[contains(@class,"flag")]', $a)?->item(0);
            $avatarNode = $xpath->query('.//div[contains(@class,"team-roster-item-img")]//img', $a)?->item(0);

            $alias = trim(preg_replace('/\s+/', ' ', (string)($aliasNode?->textContent ?? $m[2])));
            $alias = trim((string)preg_replace('/\x{2605}/u', '', $alias));
            $alias = preg_replace('/\s+/', ' ', $alias ?? '');

            $countryCode = null;
            if ($flagNode) {
                $class = (string)$flagNode->getAttribute('class');
                if (preg_match('/\bmod-([a-z]{2})\b/i', $class, $cm)) {
                    $countryCode = strtolower($cm[1]);
                }
            }

            $avatarUrl = null;
            if ($avatarNode) {
                $avatarUrl = normalizeHttpUrl((string)$avatarNode->getAttribute('src'));
            }

            $players[] = [
                'source_player_id' => (int)$m[1],
                'alias' => $alias !== '' ? $alias : $m[2],
                'real_name' => trim((string)($realNode?->textContent ?? '')),
                'country_code' => $countryCode,
                'avatar_url' => $avatarUrl,
            ];
        }
    }

    return [
        'name' => $name,
        'short_name' => $short,
        'players' => $players,
    ];
}

function scrapeVlrEventStats(int $eventId): array
{
    $html = httpGet('https://www.vlr.gg/event/stats/' . $eventId . '/');
    $xpath = htmlToXpath($html);

    $rows = $xpath->query('//table[contains(@class,"wf-table") and contains(@class,"mod-stats")]//tbody/tr');
    if (!$rows || $rows->length === 0) {
        return ['by_id' => [], 'by_alias' => []];
    }

    $byId = [];
    $byAlias = [];

    foreach ($rows as $row) {
        $playerAnchor = $xpath->query('.//td[contains(@class,"mod-player")]/a', $row)?->item(0);
        if (!$playerAnchor) {
            continue;
        }

        $href = (string)$playerAnchor->getAttribute('href');
        if (!preg_match('#^/player/(\d+)/([^/]+)$#', $href, $m)) {
            continue;
        }
        $playerId = (int)$m[1];

        $aliasNode = $xpath->query('.//div[contains(@class,"text-of")]', $row)?->item(0);
        $alias = strtolower(trim((string)($aliasNode?->textContent ?? $m[2])));

        $cells = $xpath->query('./td', $row);
        if (!$cells || $cells->length < 14) {
            continue;
        }

        $stats = [
            'rounds_played' => toIntCell($cells->item(2)?->textContent),
            'rating' => toFloatCell($cells->item(3)?->textContent),
            'acs' => toFloatCell($cells->item(4)?->textContent),
            'kd' => toFloatCell($cells->item(5)?->textContent),
            'kast' => toFloatCell($cells->item(6)?->textContent),
            'adr' => toFloatCell($cells->item(7)?->textContent),
            'kpr' => toFloatCell($cells->item(8)?->textContent),
            'apr' => toFloatCell($cells->item(9)?->textContent),
            'fkpr' => toFloatCell($cells->item(10)?->textContent),
            'fdpr' => toFloatCell($cells->item(11)?->textContent),
            'hs_pct' => toFloatCell($cells->item(12)?->textContent),
            'cl_pct' => toFloatCell($cells->item(13)?->textContent),
        ];

        $byId[$playerId] = $stats;
        $byAlias[$alias] = $stats;
    }

    return ['by_id' => $byId, 'by_alias' => $byAlias];
}

function upsertEventTeam(PDO $pdo, int $eventId, array $team, string $now): int
{
    $select = $pdo->prepare('SELECT id FROM pro_teams WHERE event_id = :event_id AND source_team_id = :source_team_id');
    $select->execute([':event_id' => $eventId, ':source_team_id' => (int)$team['source_team_id']]);
    $existingId = $select->fetchColumn();

    if ($existingId) {
        $pdo->prepare('UPDATE pro_teams SET name = :name, short_name = :short_name, slug = :slug WHERE id = :id')
            ->execute([
                ':name' => $team['name'],
                ':short_name' => $team['short_name'],
                ':slug' => $team['slug'],
                ':id' => (int)$existingId,
            ]);
        return (int)$existingId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO pro_teams (event_id, source_team_id, name, short_name, slug, created_at)
         VALUES (:event_id, :source_team_id, :name, :short_name, :slug, :created_at)'
    );
    $insert->execute([
        ':event_id' => $eventId,
        ':source_team_id' => (int)$team['source_team_id'],
        ':name' => $team['name'],
        ':short_name' => $team['short_name'],
        ':slug' => $team['slug'],
        ':created_at' => $now,
    ]);

    return (int)$pdo->lastInsertId();
}

function upsertEventPlayer(PDO $pdo, int $eventId, int $teamId, array $player, string $now): int
{
    $select = $pdo->prepare('SELECT id FROM players WHERE event_id = :event_id AND source_player_id = :source_player_id');
    $select->execute([':event_id' => $eventId, ':source_player_id' => (int)$player['source_player_id']]);
    $existingId = $select->fetchColumn();

    if ($existingId) {
        $pdo->prepare(
            'UPDATE players
             SET team_id = :team_id, alias = :alias, real_name = :real_name, country_code = :country_code,
                 avatar_url = COALESCE(:avatar_url, avatar_url),
                 price = :price, pricing_score = :pricing_score, is_active = 1
             WHERE id = :id'
        )->execute([
            ':team_id' => $teamId,
            ':alias' => $player['alias'],
            ':real_name' => $player['real_name'],
            ':country_code' => $player['country_code'],
            ':avatar_url' => $player['avatar_url'] ?? null,
            ':price' => (int)$player['price'],
            ':pricing_score' => (float)$player['pricing_score'],
            ':id' => (int)$existingId,
        ]);
        return (int)$existingId;
    }

    $insert = $pdo->prepare(
        'INSERT INTO players (event_id, team_id, source_player_id, alias, real_name, country_code, avatar_url, price, pricing_score, is_active, created_at)
         VALUES (:event_id, :team_id, :source_player_id, :alias, :real_name, :country_code, :avatar_url, :price, :pricing_score, 1, :created_at)'
    );
    $insert->execute([
        ':event_id' => $eventId,
        ':team_id' => $teamId,
        ':source_player_id' => (int)$player['source_player_id'],
        ':alias' => $player['alias'],
        ':real_name' => $player['real_name'],
        ':country_code' => $player['country_code'],
        ':avatar_url' => $player['avatar_url'] ?? null,
        ':price' => (int)$player['price'],
        ':pricing_score' => (float)$player['pricing_score'],
        ':created_at' => $now,
    ]);

    return (int)$pdo->lastInsertId();
}

function upsertEventPlayerStats(PDO $pdo, int $eventId, int $playerId, array $stats, string $sourcePrimary, ?string $sourceSecondary, string $now): void
{
    $insert = $pdo->prepare(
        'INSERT INTO player_event_stats
            (event_id, player_id, rounds_played, rating, acs, kd, kast, adr, kpr, apr, fkpr, fdpr, hs_pct, cl_pct,
             source_primary, source_secondary, raw_json, updated_at)
         VALUES
            (:event_id, :player_id, :rounds_played, :rating, :acs, :kd, :kast, :adr, :kpr, :apr, :fkpr, :fdpr, :hs_pct, :cl_pct,
             :source_primary, :source_secondary, :raw_json, :updated_at)
         ON CONFLICT(event_id, player_id) DO UPDATE SET
             rounds_played = excluded.rounds_played,
             rating = excluded.rating,
             acs = excluded.acs,
             kd = excluded.kd,
             kast = excluded.kast,
             adr = excluded.adr,
             kpr = excluded.kpr,
             apr = excluded.apr,
             fkpr = excluded.fkpr,
             fdpr = excluded.fdpr,
             hs_pct = excluded.hs_pct,
             cl_pct = excluded.cl_pct,
             source_primary = excluded.source_primary,
             source_secondary = excluded.source_secondary,
             raw_json = excluded.raw_json,
             updated_at = excluded.updated_at'
    );

    $insert->execute([
        ':event_id' => $eventId,
        ':player_id' => $playerId,
        ':rounds_played' => (int)($stats['rounds_played'] ?? 0),
        ':rating' => toFloat($stats['rating'], 1.0),
        ':acs' => toFloat($stats['acs'], 200.0),
        ':kd' => toFloat($stats['kd'], 1.0),
        ':kast' => toFloat($stats['kast'], 70.0),
        ':adr' => toFloat($stats['adr'], 130.0),
        ':kpr' => toFloat($stats['kpr'], 0.70),
        ':apr' => toFloat($stats['apr'], 0.20),
        ':fkpr' => toFloat($stats['fkpr'], 0.10),
        ':fdpr' => toFloat($stats['fdpr'], 0.12),
        ':hs_pct' => toFloat($stats['hs_pct'], 23.0),
        ':cl_pct' => toFloat($stats['cl_pct'], 20.0),
        ':source_primary' => $sourcePrimary,
        ':source_secondary' => $sourceSecondary,
        ':raw_json' => json_encode($stats, JSON_UNESCAPED_SLASHES),
        ':updated_at' => $now,
    ]);
}

function markInactivePlayers(PDO $pdo, int $eventId, array $activeSourcePlayerIds): void
{
    if (!$activeSourcePlayerIds) {
        $pdo->prepare('UPDATE players SET is_active = 0 WHERE event_id = :event_id')->execute([':event_id' => $eventId]);
        return;
    }

    $activeSourcePlayerIds = array_values(array_unique(array_map('intval', $activeSourcePlayerIds)));
    $ph = implode(',', array_fill(0, count($activeSourcePlayerIds), '?'));

    $sql = 'UPDATE players SET is_active = 0 WHERE event_id = ? AND source_player_id NOT IN (' . $ph . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$eventId], $activeSourcePlayerIds));
}

function resolvePlayerAvatarUrl(int $sourcePlayerId, ?string $sourceUrl): ?string
{
    $indexBase = trim((string)env('PLAYER_IMAGE_INDEX_BASE_URL', ''));
    if ($indexBase !== '') {
        $ext = trim((string)env('PLAYER_IMAGE_INDEX_EXTENSION', '.png'));
        if ($ext === '') {
            $ext = '.png';
        }
        if ($ext[0] !== '.') {
            $ext = '.' . $ext;
        }
        return rtrim($indexBase, '/') . '/' . $sourcePlayerId . $ext;
    }

    return cachePlayerAvatarLocally($sourcePlayerId, $sourceUrl);
}

function cachePlayerAvatarLocally(int $sourcePlayerId, ?string $sourceUrl): ?string
{
    $sourceUrl = normalizeHttpUrl($sourceUrl);
    if ($sourceUrl === null) {
        return null;
    }

    $cacheDir = APP_ROOT . '/public/assets/player-images';
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }

    $ext = avatarExtensionFromUrl($sourceUrl);
    $filename = $sourcePlayerId . '.' . $ext;
    $absolutePath = $cacheDir . '/' . $filename;
    $publicPath = '/assets/player-images/' . $filename;

    if (is_file($absolutePath) && (int)filesize($absolutePath) > 0) {
        return $publicPath;
    }

    if (downloadAvatarImage($sourceUrl, $absolutePath)) {
        return $publicPath;
    }

    return $sourceUrl;
}

function avatarExtensionFromUrl(string $url): string
{
    $path = (string)parse_url($url, PHP_URL_PATH);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];
    if (in_array($ext, $allowed, true)) {
        return $ext;
    }
    return 'png';
}

function downloadAvatarImage(string $url, string $targetPath): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            'Referer: https://www.vlr.gg/',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));

    if ($body === false || $status >= 400) {
        return false;
    }

    if ($contentType !== '' && !str_starts_with($contentType, 'image/')) {
        return false;
    }

    if ($body === '') {
        return false;
    }

    return file_put_contents($targetPath, $body) !== false;
}

function httpGet(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/json;q=0.9,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ],
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    if ($response === false || $status >= 400) {
        throw new RuntimeException('HTTP fetch failed for ' . $url . ' (' . ($err ?: 'HTTP ' . $status) . ')');
    }

    return (string)$response;
}

function htmlToXpath(string $html): DOMXPath
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();
    return new DOMXPath($doc);
}

function toFloatCell(?string $text): float
{
    if ($text === null) {
        return 0.0;
    }
    $v = trim(str_replace('%', '', $text));
    if ($v === '' || $v === '-') {
        return 0.0;
    }
    return (float)$v;
}

function toIntCell(?string $text): int
{
    if ($text === null) {
        return 0;
    }
    $v = preg_replace('/[^0-9]/', '', $text);
    return $v === '' ? 0 : (int)$v;
}

function normalizeHttpUrl(?string $url): ?string
{
    if ($url === null) {
        return null;
    }

    $url = trim($url);
    if ($url === '') {
        return null;
    }

    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }

    if (str_starts_with($url, '/')) {
        return 'https://www.vlr.gg' . $url;
    }

    if (!preg_match('#^https?://#i', $url)) {
        return null;
    }

    return $url;
}
