<?php

declare(strict_types=1);

namespace OpenFrontMC\Storage;

use Closure;
use RuntimeException;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;
use Throwable;

final class Database{

    private SQLite3 $sqlite;

    public function __construct(string $path){
        $directory = dirname($path);
        if(!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)){
            throw new RuntimeException("Unable to create the plugin data directory.");
        }
        $this->sqlite = new SQLite3($path, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
        $this->sqlite->busyTimeout(5000);
        $this->sqlite->exec("PRAGMA foreign_keys = ON");
        $this->sqlite->exec("PRAGMA journal_mode = WAL");
        $this->sqlite->exec("PRAGMA synchronous = NORMAL");
    }

    public function migrate() : void{
        $versionRow = $this->fetchOne("PRAGMA user_version");
        $version = (int) ($versionRow["user_version"] ?? 0);
        if($version > 2){
            throw new RuntimeException("The database schema is newer than this plugin version supports.");
        }
        if($version === 2){
            return;
        }
        if($version === 1){
            $this->transaction(function() : void{
                $this->runExec(<<<SQL
CREATE TABLE IF NOT EXISTS rendered_territories (
    territory_id TEXT PRIMARY KEY,
    rendered_at INTEGER NOT NULL,
    FOREIGN KEY(territory_id) REFERENCES territories(id) ON DELETE CASCADE
);
PRAGMA user_version = 2;
SQL);
            });
            return;
        }

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS players (
    player_name TEXT PRIMARY KEY,
    first_seen INTEGER NOT NULL,
    last_seen INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS countries (
    id TEXT PRIMARY KEY,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE,
    tag TEXT NOT NULL UNIQUE,
    color TEXT NOT NULL,
    owner_name TEXT NOT NULL UNIQUE,
    score INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS country_members (
    country_id TEXT NOT NULL,
    player_name TEXT NOT NULL UNIQUE,
    role TEXT NOT NULL,
    joined_at INTEGER NOT NULL,
    PRIMARY KEY(country_id, player_name),
    FOREIGN KEY(country_id) REFERENCES countries(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS resources (
    country_id TEXT PRIMARY KEY,
    gold INTEGER NOT NULL DEFAULT 0,
    food INTEGER NOT NULL DEFAULT 0,
    materials INTEGER NOT NULL DEFAULT 0,
    energy INTEGER NOT NULL DEFAULT 0,
    research INTEGER NOT NULL DEFAULT 0,
    influence INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY(country_id) REFERENCES countries(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS economy_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    country_id TEXT NOT NULL,
    actor_name TEXT NOT NULL,
    reason TEXT NOT NULL,
    changes_json TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(country_id) REFERENCES countries(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS territories (
    id TEXT PRIMARY KEY,
    x INTEGER NOT NULL,
    z INTEGER NOT NULL,
    terrain TEXT NOT NULL,
    special TEXT NOT NULL DEFAULT 'NONE',
    status TEXT NOT NULL DEFAULT 'NEUTRAL',
    owner_country_id TEXT NULL,
    development INTEGER NOT NULL DEFAULT 0,
    resources_json TEXT NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE(x, z),
    FOREIGN KEY(owner_country_id) REFERENCES countries(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_territories_owner ON territories(owner_country_id);
CREATE TABLE IF NOT EXISTS map_metadata (
    id TEXT PRIMARY KEY,
    seed INTEGER NOT NULL,
    width INTEGER NOT NULL,
    height INTEGER NOT NULL,
    territory_size INTEGER NOT NULL,
    rendered INTEGER NOT NULL DEFAULT 0,
    generated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS rendered_territories (
    territory_id TEXT PRIMARY KEY,
    rendered_at INTEGER NOT NULL,
    FOREIGN KEY(territory_id) REFERENCES territories(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS matches (
    id TEXT PRIMARY KEY,
    mode TEXT NOT NULL,
    state TEXT NOT NULL,
    capacity INTEGER NOT NULL,
    created_at INTEGER NOT NULL,
    started_at INTEGER NULL,
    ended_at INTEGER NULL,
    winner_country_id TEXT NULL,
    FOREIGN KEY(winner_country_id) REFERENCES countries(id) ON DELETE SET NULL
);
CREATE INDEX IF NOT EXISTS idx_matches_state ON matches(state);
CREATE TABLE IF NOT EXISTS match_players (
    match_id TEXT NOT NULL,
    player_name TEXT NOT NULL,
    ready INTEGER NOT NULL DEFAULT 0,
    joined_at INTEGER NOT NULL,
    left_at INTEGER NULL,
    PRIMARY KEY(match_id, player_name),
    FOREIGN KEY(match_id) REFERENCES matches(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_match_players_player ON match_players(player_name, left_at);
CREATE TABLE IF NOT EXISTS treaties (
    id TEXT PRIMARY KEY,
    from_country_id TEXT NOT NULL,
    to_country_id TEXT NOT NULL,
    type TEXT NOT NULL,
    status TEXT NOT NULL,
    expires_at INTEGER NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY(from_country_id) REFERENCES countries(id) ON DELETE CASCADE,
    FOREIGN KEY(to_country_id) REFERENCES countries(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS buildings (
    id TEXT PRIMARY KEY,
    country_id TEXT NOT NULL,
    territory_id TEXT NOT NULL,
    type TEXT NOT NULL,
    level INTEGER NOT NULL DEFAULT 1,
    created_at INTEGER NOT NULL,
    FOREIGN KEY(country_id) REFERENCES countries(id) ON DELETE CASCADE,
    FOREIGN KEY(territory_id) REFERENCES territories(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS research (
    country_id TEXT NOT NULL,
    technology_id TEXT NOT NULL,
    completed_at INTEGER NOT NULL,
    PRIMARY KEY(country_id, technology_id),
    FOREIGN KEY(country_id) REFERENCES countries(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS game_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    actor_name TEXT NOT NULL,
    action TEXT NOT NULL,
    subject_id TEXT NOT NULL,
    details_json TEXT NOT NULL,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS statistics (
    player_name TEXT PRIMARY KEY,
    matches_joined INTEGER NOT NULL DEFAULT 0,
    matches_won INTEGER NOT NULL DEFAULT 0,
    territories_claimed INTEGER NOT NULL DEFAULT 0,
    updated_at INTEGER NOT NULL
);
PRAGMA user_version = 2;
SQL;
        $this->transaction(function() use($sql) : void{
            $this->runExec($sql);
        });
    }

    /**
     * @param array<int, mixed> $parameters
     */
    public function execute(string $sql, array $parameters = []) : void{
        $statement = $this->prepare($sql, $parameters);
        $result = $statement->execute();
        if($result === false){
            $statement->close();
            throw new RuntimeException("Database statement failed: " . $this->sqlite->lastErrorMsg());
        }
        $result->finalize();
        $statement->close();
    }

    /**
     * @param array<int, mixed> $parameters
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $parameters = []) : ?array{
        $statement = $this->prepare($sql, $parameters);
        $result = $statement->execute();
        if(!$result instanceof SQLite3Result){
            $statement->close();
            throw new RuntimeException("Database query failed: " . $this->sqlite->lastErrorMsg());
        }
        $row = $result->fetchArray(SQLITE3_ASSOC);
        $result->finalize();
        $statement->close();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int, mixed> $parameters
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $parameters = []) : array{
        $statement = $this->prepare($sql, $parameters);
        $result = $statement->execute();
        if(!$result instanceof SQLite3Result){
            $statement->close();
            throw new RuntimeException("Database query failed: " . $this->sqlite->lastErrorMsg());
        }
        $rows = [];
        while(($row = $result->fetchArray(SQLITE3_ASSOC)) !== false){
            $rows[] = $row;
        }
        $result->finalize();
        $statement->close();
        return $rows;
    }

    /**
     * @template T
     * @param Closure():T $callback
     * @return T
     */
    public function transaction(Closure $callback) : mixed{
        $this->runExec("BEGIN IMMEDIATE TRANSACTION");
        try{
            $result = $callback();
            $this->runExec("COMMIT");
            return $result;
        }catch(Throwable $e){
            try{
                $this->runExec("ROLLBACK");
            }catch(Throwable){
            }
            throw $e;
        }
    }

    public function close() : void{
        $this->sqlite->close();
    }

    /**
     * @param array<int, mixed> $parameters
     */
    private function prepare(string $sql, array $parameters) : SQLite3Stmt{
        $statement = $this->sqlite->prepare($sql);
        if(!$statement instanceof SQLite3Stmt){
            throw new RuntimeException("Unable to prepare database statement: " . $this->sqlite->lastErrorMsg());
        }
        foreach($parameters as $index => $value){
            $type = match(true){
                $value === null => SQLITE3_NULL,
                is_int($value), is_bool($value) => SQLITE3_INTEGER,
                is_float($value) => SQLITE3_FLOAT,
                default => SQLITE3_TEXT
            };
            $statement->bindValue($index + 1, $value, $type);
        }
        return $statement;
    }

    private function runExec(string $sql) : void{
        if(!$this->sqlite->exec($sql)){
            throw new RuntimeException("Database operation failed: " . $this->sqlite->lastErrorMsg());
        }
    }
}
