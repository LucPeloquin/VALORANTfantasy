PRAGMA foreign_keys = ON;

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    display_name TEXT,
    password_hash TEXT NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    last_login_at TEXT
);

CREATE TABLE events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    source TEXT NOT NULL,
    source_event_id INTEGER,
    season TEXT,
    lock_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    budget INTEGER NOT NULL DEFAULT 1000000,
    max_from_team INTEGER NOT NULL DEFAULT 2,
    created_at TEXT NOT NULL
);

CREATE TABLE pro_teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    source_team_id INTEGER,
    name TEXT NOT NULL,
    short_name TEXT NOT NULL,
    slug TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (event_id, source_team_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);

CREATE TABLE players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    source_player_id INTEGER,
    alias TEXT NOT NULL,
    real_name TEXT,
    country_code TEXT,
    price INTEGER NOT NULL,
    pricing_score REAL NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    UNIQUE (event_id, source_player_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES pro_teams(id) ON DELETE CASCADE
);

CREATE TABLE player_event_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    rounds_played INTEGER,
    rating REAL,
    acs REAL,
    kd REAL,
    kast REAL,
    adr REAL,
    kpr REAL,
    apr REAL,
    fkpr REAL,
    fdpr REAL,
    hs_pct REAL,
    cl_pct REAL,
    source_primary TEXT,
    source_secondary TEXT,
    raw_json TEXT,
    updated_at TEXT NOT NULL,
    UNIQUE (event_id, player_id),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
);

CREATE TABLE fantasy_leagues (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    description TEXT,
    join_code TEXT NOT NULL UNIQUE,
    owner_user_id INTEGER NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE league_members (
    league_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    joined_at TEXT NOT NULL,
    PRIMARY KEY (league_id, user_id),
    FOREIGN KEY (league_id) REFERENCES fantasy_leagues(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE fantasy_teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    league_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    team_name TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    share_slug TEXT UNIQUE,
    submitted_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (league_id, user_id),
    FOREIGN KEY (league_id) REFERENCES fantasy_leagues(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE fantasy_team_players (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fantasy_team_id INTEGER NOT NULL,
    player_id INTEGER NOT NULL,
    role_key TEXT NOT NULL,
    power_key TEXT NOT NULL,
    purchase_price INTEGER NOT NULL,
    UNIQUE (fantasy_team_id, player_id),
    FOREIGN KEY (fantasy_team_id) REFERENCES fantasy_teams(id) ON DELETE CASCADE,
    FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
);

CREATE TABLE sync_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id INTEGER,
    source TEXT NOT NULL,
    status TEXT NOT NULL,
    message TEXT,
    created_at TEXT NOT NULL,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
);
