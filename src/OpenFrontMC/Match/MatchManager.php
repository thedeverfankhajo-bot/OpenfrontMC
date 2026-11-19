<?php

declare(strict_types=1);

namespace OpenFrontMC\Match;

use OpenFrontMC\Storage\Database;
use RuntimeException;

final class MatchManager{

    public function __construct(private Database $database){}

    public function create(string $mode, int $capacity) : string{
        if(!in_array($mode, ["FFA", "TEAM", "SOLO", "CUSTOM"], true) || $capacity < 2 || $capacity > 100){
            throw new RuntimeException("Invalid match settings.");
        }
        $id = bin2hex(random_bytes(8));
        $this->database->execute(
            "INSERT INTO matches(id,mode,state,capacity,created_at) VALUES(?,?,'WAITING',?,?)",
            [$id, $mode, $capacity, time()]
        );
        return $id;
    }

    public function join(string $matchId, string $player) : void{
        if($player === "" || strlen($player) > 64){
            throw new RuntimeException("Invalid player name.");
        }
        $this->database->transaction(function() use($matchId, $player) : void{
            $match = $this->database->fetchOne("SELECT * FROM matches WHERE id=?", [$matchId]);
            if($match === null || $match["state"] !== MatchState::WAITING->value){
                throw new RuntimeException("Match is not accepting players.");
            }
            if($this->database->fetchOne(
                "SELECT match_id FROM match_players WHERE player_name=? AND left_at IS NULL LIMIT 1",
                [$player]
            ) !== null){
                throw new RuntimeException("You are already in an active match.");
            }
            $count = $this->database->fetchOne(
                "SELECT COUNT(*) AS c FROM match_players WHERE match_id=? AND left_at IS NULL",
                [$matchId]
            );
            if((int) ($count["c"] ?? 0) >= (int) $match["capacity"]){
                throw new RuntimeException("Match is full.");
            }
            $existing = $this->database->fetchOne(
                "SELECT match_id FROM match_players WHERE match_id=? AND player_name=?",
                [$matchId, $player]
            );
            if($existing === null){
                $this->database->execute(
                    "INSERT INTO match_players(match_id,player_name,ready,joined_at,left_at) VALUES(?,?,0,?,NULL)",
                    [$matchId, $player, time()]
                );
            }else{
                $this->database->execute(
                    "UPDATE match_players SET ready=0,joined_at=?,left_at=NULL WHERE match_id=? AND player_name=?",
                    [time(), $matchId, $player]
                );
            }
            $this->database->execute(
                "INSERT INTO statistics(player_name,matches_joined,matches_won,territories_claimed,updated_at) VALUES(?,1,0,0,?) ON CONFLICT(player_name) DO UPDATE SET matches_joined=matches_joined+1,updated_at=excluded.updated_at",
                [$player, time()]
            );
        });
    }

    public function leave(string $player) : void{
        $this->database->execute(
            "UPDATE match_players SET left_at=? WHERE player_name=? AND left_at IS NULL",
            [time(), $player]
        );
    }

    public function transition(string $id, MatchState $next) : void{
        $this->database->transaction(function() use($id, $next) : void{
            $row = $this->database->fetchOne("SELECT state FROM matches WHERE id=?", [$id]);
            if($row === null){
                throw new RuntimeException("Match not found.");
            }
            (new MatchStateMachine())->transition(MatchState::from((string) $row["state"]), $next);
            $now = time();
            $this->database->execute(
                "UPDATE matches SET state=?,started_at=COALESCE(?,started_at),ended_at=COALESCE(?,ended_at) WHERE id=?",
                [$next->value, $next === MatchState::RUNNING ? $now : null, $next === MatchState::FINISHED ? $now : null, $id]
            );
            if($next === MatchState::FINISHED){
                $this->database->execute("UPDATE match_players SET left_at=? WHERE match_id=? AND left_at IS NULL", [$now, $id]);
            }
        });
    }

    /**
     * Finishes a match, records its result and closes active player seats atomically.
     */
    public function finishWithWinner(string $matchId, ?string $countryId, string $reason) : void{
        if(preg_match('/^[A-Z0-9_:-]{1,64}$/', $reason) !== 1){
            throw new RuntimeException("Invalid match end reason.");
        }

        $this->database->transaction(function() use($matchId, $countryId, $reason) : void{
            $match = $this->database->fetchOne("SELECT state FROM matches WHERE id=?", [$matchId]);
            if($match === null){
                throw new RuntimeException("Match not found.");
            }
            (new MatchStateMachine())->transition(MatchState::from((string) $match["state"]), MatchState::FINISHED);

            if($countryId !== null && $this->database->fetchOne(
                "SELECT cm.country_id
                 FROM match_players mp
                 INNER JOIN country_members cm ON cm.player_name=mp.player_name
                 WHERE mp.match_id=? AND cm.country_id=?
                 LIMIT 1",
                [$matchId, $countryId]
            ) === null){
                throw new RuntimeException("The winning country did not participate in this match.");
            }

            $now = time();
            $this->database->execute(
                "UPDATE matches SET state='FINISHED',winner_country_id=?,end_reason=?,ended_at=? WHERE id=?",
                [$countryId, $reason, $now, $matchId]
            );
            $this->database->execute(
                "UPDATE match_players SET left_at=? WHERE match_id=? AND left_at IS NULL",
                [$now, $matchId]
            );

            if($countryId !== null){
                $this->database->execute(
                    "UPDATE statistics SET matches_won=matches_won+1,updated_at=?
                     WHERE player_name IN (
                         SELECT mp.player_name FROM match_players mp
                         INNER JOIN country_members cm ON cm.player_name=mp.player_name
                         WHERE mp.match_id=? AND cm.country_id=?
                     )",
                    [$now, $matchId, $countryId]
                );
            }

            $this->database->execute(
                "INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES('SYSTEM','MATCH_FINISHED',?,?,?)",
                [$matchId, json_encode(["winner_country_id" => $countryId, "reason" => $reason], JSON_THROW_ON_ERROR), $now]
            );
        });
    }

    public function setWinner(string $matchId, ?string $countryId) : void{
        $this->database->execute("UPDATE matches SET winner_country_id=? WHERE id=? AND state='FINISHED'", [$countryId, $matchId]);
    }

    /** @return array<string,mixed>|null */
    public function get(string $id) : ?array{
        return $this->database->fetchOne("SELECT * FROM matches WHERE id=?", [$id]);
    }

    /** @return array<string,mixed>|null */
    public function latestWaiting() : ?array{
        return $this->database->fetchOne("SELECT m.* FROM matches m WHERE m.state='WAITING' AND (SELECT COUNT(*) FROM match_players mp WHERE mp.match_id=m.id AND mp.left_at IS NULL)<m.capacity ORDER BY m.created_at DESC LIMIT 1");
    }

    /** @return array<string,mixed>|null */
    public function latestActive() : ?array{
        return $this->database->fetchOne("SELECT * FROM matches WHERE state IN ('WAITING','COUNTDOWN','RUNNING','PAUSED') ORDER BY created_at DESC LIMIT 1");
    }

    /** @return list<array<string,mixed>> */
    public function players(string $matchId) : array{
        return $this->database->fetchAll(
            "SELECT player_name,ready,joined_at FROM match_players WHERE match_id=? AND left_at IS NULL ORDER BY joined_at",
            [$matchId]
        );
    }

    /**
     * Returns the persisted active match membership for a player.
     * Disconnected players retain their seat until they explicitly leave or the match finishes.
     *
     * @return array<string, mixed>|null
     */
    public function forPlayer(string $player) : ?array{
        return $this->database->fetchOne(
            "SELECT m.*,mp.ready AS player_ready,mp.joined_at AS player_joined_at
             FROM match_players mp
             INNER JOIN matches m ON m.id=mp.match_id
             WHERE mp.player_name=? AND mp.left_at IS NULL
               AND m.state IN ('WAITING','COUNTDOWN','RUNNING','PAUSED')
             ORDER BY mp.joined_at DESC
             LIMIT 1",
            [$player]
        );
    }

    public function readyCount(string $matchId) : int{
        $row = $this->database->fetchOne(
            "SELECT COUNT(*) AS amount FROM match_players WHERE match_id=? AND left_at IS NULL AND ready=1",
            [$matchId]
        );
        return (int) ($row["amount"] ?? 0);
    }

    public function markReady(string $matchId, string $player, bool $ready) : void{
        $this->database->transaction(function() use($matchId, $player, $ready) : void{
            $match = $this->database->fetchOne("SELECT state FROM matches WHERE id=?", [$matchId]);
            if($match === null || $match["state"] !== MatchState::WAITING->value){
                throw new RuntimeException("This match is not accepting ready changes.");
            }
            $member = $this->database->fetchOne(
                "SELECT player_name FROM match_players WHERE match_id=? AND player_name=? AND left_at IS NULL",
                [$matchId, $player]
            );
            if($member === null){
                throw new RuntimeException("You are not in this match.");
            }
            $this->database->execute(
                "UPDATE match_players SET ready=? WHERE match_id=? AND player_name=? AND left_at IS NULL",
                [$ready ? 1 : 0, $matchId, $player]
            );
        });
    }
}
