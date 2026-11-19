<?php

declare(strict_types=1);

namespace OpenFrontMC\Country;

use OpenFrontMC\Security\InputValidator;
use OpenFrontMC\Storage\Database;
use RuntimeException;

final class CountryManager{

    /** @param array<string, int> $startingResources */
    public function __construct(private Database $database, private array $startingResources = []){}

    public function create(string $owner, string $name, string $tag) : string{
        $name = trim($name);
        $tag = strtoupper(trim($tag));
        if(!InputValidator::countryName($name) || preg_match('/^[A-Z0-9]{2,4}$/', $tag) !== 1 || !InputValidator::playerName($owner)){
            throw new RuntimeException("Country name, tag or player name is invalid.");
        }
        $id = bin2hex(random_bytes(8));
        $now = time();
        $this->database->transaction(function() use($id, $owner, $name, $tag, $now) : void{
            if($this->database->fetchOne("SELECT player_name FROM country_members WHERE player_name=?", [$owner]) !== null){
                throw new RuntimeException("You are already a member of a country.");
            }
            $this->database->execute(
                "INSERT INTO countries(id,name,tag,color,owner_name,created_at) VALUES(?,?,?,?,?,?)",
                [$id, $name, $tag, "3366CC", $owner, $now]
            );
            $this->database->execute(
                "INSERT INTO country_members(country_id,player_name,role,joined_at) VALUES(?,?,?,?)",
                [$id, $owner, "LEADER", $now]
            );
            $resources = ["gold", "food", "materials", "energy", "research", "influence"];
            $values = [];
            foreach($resources as $resource){
                $amount = $this->startingResources[$resource] ?? 0;
                $values[] = is_int($amount) ? max(0, min(100000000, $amount)) : 0;
            }
            $this->database->execute(
                "INSERT INTO resources(country_id,gold,food,materials,energy,research,influence,updated_at) VALUES(?,?,?,?,?,?,?,?)",
                array_merge([$id], $values, [$now])
            );
            $this->database->execute(
                "INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES(?,?,?,?,?)",
                [$owner, "COUNTRY_CREATED", $id, json_encode(["name" => $name, "tag" => $tag], JSON_THROW_ON_ERROR), $now]
            );
        });
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function byPlayer(string $player) : ?array{
        return $this->database->fetchOne(
            "SELECT c.* FROM countries c INNER JOIN country_members m ON m.country_id=c.id WHERE m.player_name=?",
            [$player]
        );
    }

    /** @return array<string,mixed>|null */
    public function byTag(string $tag) : ?array{
        return $this->database->fetchOne("SELECT * FROM countries WHERE tag=?", [strtoupper($tag)]);
    }

    /** @return list<array<string,mixed>> */
    public function top(int $limit = 10) : array{
        $limit = max(1, min(50, $limit));
        return $this->database->fetchAll("SELECT name,tag,score,owner_name FROM countries ORDER BY score DESC LIMIT " . $limit);
    }
}
