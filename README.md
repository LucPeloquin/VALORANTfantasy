# VCT Fantasy (HLTV-style clone)

Multi-page fantasy site for **VALORANT / VCT Americas Stage 1** with HLTV-inspired format and a green-tinted UI.

## Features implemented

- Multi-page app:
  - `/index.php` hero/frontpage
  - `/register.php` account creation
  - `/login.php` username/password sign-in
  - `/dashboard.php` league create/join + user hub
  - `/team-builder.php` roster builder + submit
  - `/players.php` player values/stats
  - `/player.php?id=...` per-player stats + fantasy projections
  - `/leaderboard.php` standings
  - `/team.php` public/private team profile
  - `/rules.php` scoring + pricing rubric
  - `/admin.php` manual data sync
- Authentication: username/password (session-based)
- League invite codes, public/private leagues
- 5-player roster rules:
  - Budget cap
  - Max 2 players per real team
  - Roles
  - Superpowers (unique non-`none`)
- Public team profiles + share slug
- Discord-friendly embeds:
  - `/share.php?slug=...` OG metadata endpoint
  - `/share-image.php?slug=...` generated lineup card image
- VLR scraping pipeline for VCT Americas Stage 1 teams/rosters and baseline stats

## Data source behavior

- Primary and only built-in source: **VLR.gg**
- Stage 1 stats are used when available.
- If Stage 1 stats are not populated yet, fallback uses VCT 2026 Americas Kickoff stats.
- Automatic scheduled sync support:
  - Web requests trigger due-sync checks (interval-based).
  - CLI runner available for cron: `php scripts/sync-if-due.php`.

## Setup

1. Create `.env` in project root:

```env
APP_BASE_URL=http://127.0.0.1:8080
AUTO_SYNC_ON_BOOT=1
AUTO_SYNC_INTERVAL_MINUTES=30
```

2. Run server:

```bash
cd hltv-fantasy-clone
php -S 127.0.0.1:8080 -t public
```

3. Open:

- http://127.0.0.1:8080/index.php

## Admin

Seeded admin account:

- username: `admin`
- password: `test`

Admin page:

- `/admin.php`
- Trigger manual VLR sync
- View sync logs

## Scripts

Reset DB + snapshot:

```bash
php scripts/reset-db.php
```

Manual data sync:

```bash
php scripts/sync-data.php
```

Scheduled sync (safe to run from cron every 5 minutes):

```bash
php scripts/sync-if-due.php
```

## Pricing rubric

Targets for a 12-team group stage (~60 players):

- S tier: $235k-$250k (top ~5)
- A tier: $215k-$234k (next ~10)
- B tier: $195k-$214k (next ~15)
- C tier: $175k-$194k (next ~15)
- D tier: $155k-$174k (bottom ~15)

Roster budget remains `$1,000,000` for 5 players.
