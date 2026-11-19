<?php

declare(strict_types=1);

namespace OpenFrontMC\Territory;

use OpenFrontMC\Storage\Database;
use RuntimeException;

final class TerritoryManager{

    public function __construct(private Database $database){}

    /**
     * @param list<array{x:int,z:int,terrain:string,special:string,resources:array<string,int>}> $territories
     */
    public function seedNeutralMap(int $seed, int $width, int $height, array $territories) : void{
        if($width < 4 || $height < 4 || $width > 128 || $height > 128 || count($territories) !== $width * $height){
            throw new RuntimeException("Map dimensions or generated tile count are invalid.");
        }
        $this->database->transaction(function() use($seed, $width, $height, $territories) : void{
            $this->database->execute(
                "INSERT OR REPLACE INTO map_metadata(id,seed,width,height,territory_size,rendered,generated_at) VALUES('default',?,?,?,16,0,?)",
                [$seed, $width, $height, time()]
            );
            $this->database->execute("DELETE FROM territories");
            foreach($territories as $tile){
                if($tile["x"] < 0 || $tile["x"] >= $width || $tile["z"] < 0 || $tile["z"] >= $height || !in_array($tile["terrain"], ["WATER", "LAND", "ISLAND"], true)){
                    throw new RuntimeException("Generated map contains an invalid territory.");
                }
                $this->database->execute(
                    "INSERT INTO territories(id,x,z,terrain,special,status,resources_json,updated_at) VALUES(?,?,?,?,?,'NEUTRAL',?,?)",
                    [$tile["x"] . ":" . $tile["z"], $tile["x"], $tile["z"], $tile["terrain"], $tile["special"], json_encode($tile["resources"], JSON_THROW_ON_ERROR), time()]
                );
            }
        });
    }

    public function claim(string $player, string $countryId, int $x, int $z, int $cost) : void{
        if($cost < 0 || $player === "" || strlen($player) > 64){
            throw new RuntimeException("Invalid claim request.");
        }
        $this->database->transaction(function() use($player, $countryId, $x, $z, $cost) : void{
            if($this->database->fetchOne(
                "SELECT role FROM country_members WHERE player_name=? AND country_id=?",
                [$player, $countryId]
            ) === null){
                throw new RuntimeException("You are not a member of this country.");
            }

            $map = $this->database->fetchOne("SELECT width,height FROM map_metadata WHERE id='default'");
            if($map === null || $x < 0 || $z < 0 || $x >= (int) $map["width"] || $z >= (int) $map["height"]){
                throw new RuntimeException("Territory coordinates are outside the map.");
            }

            $target = $this->database->fetchOne("SELECT * FROM territories WHERE x=? AND z=?", [$x, $z]);
            if($target === null || $target["terrain"] === "WATER" || $target["owner_country_id"] !== null || $target["status"] === "PROTECTED"){
                throw new RuntimeException("This territory cannot be claimed.");
            }

            $owned = $this->database->fetchOne("SELECT COUNT(*) AS c FROM territories WHERE owner_country_id=?", [$countryId]);
            $adjacent = $this->database->fetchOne(
                "SELECT id FROM territories WHERE owner_country_id=? AND ((x=? AND ABS(z-?)=1) OR (z=? AND ABS(x-?)=1)) LIMIT 1",
                [$countryId, $x, $z, $z, $x]
            );
            if((int) ($owned["c"] ?? 0) > 0 && $adjacent === null){
                throw new RuntimeException("You may only claim adjacent territory.");
            }

            $resources = $this->database->fetchOne("SELECT gold FROM resources WHERE country_id=?", [$countryId]);
            if($resources === null || (int) $resources["gold"] < $cost){
                throw new RuntimeException("Not enough gold.");
            }

            $now = time();
            $this->database->execute("UPDATE resources SET gold=gold-?,updated_at=? WHERE country_id=?", [$cost, $now, $countryId]);
            $this->database->execute(
                "UPDATE territories SET owner_country_id=?,status='OWNED',updated_at=? WHERE id=? AND owner_country_id IS NULL",
                [$countryId, $now, $target["id"]]
            );
            $this->database->execute("UPDATE countries SET score=score+10 WHERE id=?", [$countryId]);
            $this->database->execute(
                "INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES(?,?,?,?,?)",
                [$player, "TERRITORY_CLAIMED", $target["id"], json_encode(["country" => $countryId, "cost" => $cost], JSON_THROW_ON_ERROR), $now]
            );
            $this->database->execute(
                "INSERT INTO statistics(player_name,matches_joined,matches_won,territories_claimed,updated_at) VALUES(?,0,0,1,?) ON CONFLICT(player_name) DO UPDATE SET territories_claimed=territories_claimed+1,updated_at=excluded.updated_at",
                [$player, $now]
            );
        });
    }

    /** @return list<array<string, mixed>> */
    public function all() : array{
        return $this->database->fetchAll("SELECT * FROM territories ORDER BY z,x");
    }

    /** @return array<string, mixed>|null */
    public function byCoordinates(int $x, int $z) : ?array{
        return $this->database->fetchOne("SELECT * FROM territories WHERE x=? AND z=?", [$x, $z]);
    }

    /** @return array<string, mixed>|null */
    public function metadata() : ?array{
        return $this->database->fetchOne("SELECT * FROM map_metadata WHERE id='default'");
    }

    public function markRendered() : void{
        $this->database->execute("UPDATE map_metadata SET rendered=1 WHERE id='default'");
    }
}
