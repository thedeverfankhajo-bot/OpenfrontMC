<?php

declare(strict_types=1);

namespace OpenFrontMC\Building;

use OpenFrontMC\Economy\EconomyManager;
use OpenFrontMC\Storage\Database;
use RuntimeException;

final class BuildingManager{

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(
        private Database $database,
        private EconomyManager $economy,
        private array $configuration
    ){}

    public function build(string $countryId, string $territoryId, string $type, string $actor) : string{
        $type = strtolower(trim($type));
        if(preg_match('/^[a-z][a-z0-9_]{1,47}$/', $type) !== 1){
            throw new RuntimeException("Invalid building type.");
        }
        $definition = $this->definition($type);
        $territory = $this->database->fetchOne(
            "SELECT owner_country_id,terrain,status FROM territories WHERE id=?",
            [$territoryId]
        );
        if($territory === null || $territory["owner_country_id"] !== $countryId || $territory["terrain"] === "WATER" || $territory["status"] === "PROTECTED"){
            throw new RuntimeException("Buildings may only be placed in your own accessible territory.");
        }

        $maxPerTerritory = max(1, min(32, (int) ($definition["max-per-territory"] ?? 1)));
        $maxPerCountry = max(1, min(256, (int) ($definition["max-per-country"] ?? 64)));
        $countTerritory = $this->database->fetchOne(
            "SELECT COUNT(*) AS amount FROM buildings WHERE country_id=? AND territory_id=?",
            [$countryId, $territoryId]
        );
        $countCountry = $this->database->fetchOne(
            "SELECT COUNT(*) AS amount FROM buildings WHERE country_id=?",
            [$countryId]
        );
        if((int) ($countTerritory["amount"] ?? 0) >= $maxPerTerritory || (int) ($countCountry["amount"] ?? 0) >= $maxPerCountry){
            throw new RuntimeException("Building placement limit reached.");
        }
        if($type === "capital" && $this->database->fetchOne("SELECT id FROM buildings WHERE country_id=? AND type='capital'", [$countryId]) !== null){
            throw new RuntimeException("Your country already has a capital building.");
        }

        $id = bin2hex(random_bytes(8));
        $cost = $this->cost($definition["cost"] ?? []);
        $this->economy->applyAtomic($countryId, $actor, "BUILD:" . strtoupper($type), $cost, function() use($id, $countryId, $territoryId, $type, $actor) : void{
            $now = time();
            $this->database->execute(
                "INSERT INTO buildings(id,country_id,territory_id,type,level,created_at) VALUES(?,?,?,?,1,?)",
                [$id, $countryId, $territoryId, $type, $now]
            );
            $this->database->execute(
                "INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES(?,?,?,?,?)",
                [$actor, "BUILDING_CREATED", $id, json_encode(["country" => $countryId, "territory" => $territoryId, "type" => $type], JSON_THROW_ON_ERROR), $now]
            );
        });

        return $id;
    }

    public function upgrade(string $countryId, string $buildingId, string $actor) : int{
        $building = $this->database->fetchOne(
            "SELECT * FROM buildings WHERE id=? AND country_id=?",
            [$buildingId, $countryId]
        );
        if($building === null){
            throw new RuntimeException("Building not found in your country.");
        }
        $definition = $this->definition((string) $building["type"]);
        $level = (int) $building["level"];
        $maxLevel = max(1, min(20, (int) ($definition["max-level"] ?? 5)));
        if($level >= $maxLevel){
            throw new RuntimeException("This building is already at maximum level.");
        }

        $baseCost = $this->cost($definition["upgrade-cost"] ?? $definition["cost"] ?? []);
        $scaledCost = [];
        foreach($baseCost as $resource => $amount){
            $scaledCost[$resource] = $amount * max(1, $level);
        }

        $this->economy->applyAtomic($countryId, $actor, "UPGRADE:" . strtoupper((string) $building["type"]), $scaledCost, function() use($countryId, $buildingId, $level, $actor) : void{
            $this->database->execute(
                "UPDATE buildings SET level=level+1 WHERE id=? AND country_id=? AND level=?",
                [$buildingId, $countryId, $level]
            );
            $this->database->execute(
                "INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES(?,?,?,?,?)",
                [$actor, "BUILDING_UPGRADED", $buildingId, json_encode(["from" => $level, "to" => $level + 1], JSON_THROW_ON_ERROR), time()]
            );
        });

        return $level + 1;
    }

    public function demolish(string $countryId, string $buildingId, string $actor) : void{
        $this->database->transaction(function() use($countryId, $buildingId, $actor) : void{
            $building = $this->database->fetchOne(
                "SELECT id,type,level FROM buildings WHERE id=? AND country_id=?",
                [$buildingId, $countryId]
            );
            if($building === null){
                throw new RuntimeException("Building not found in your country.");
            }
            if($building["type"] === "capital"){
                throw new RuntimeException("The capital cannot be demolished.");
            }
            $this->database->execute("DELETE FROM buildings WHERE id=? AND country_id=?", [$buildingId, $countryId]);
            $this->database->execute(
                "INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES(?,?,?,?,?)",
                [$actor, "BUILDING_DEMOLISHED", $buildingId, json_encode(["type" => $building["type"], "level" => (int) $building["level"]], JSON_THROW_ON_ERROR), time()]
            );
        });
    }

    /**
     * @param array<string, float> $multipliers Resource-specific research multipliers.
     * @return array<string, int>
     */
    public function production(string $countryId, array $multipliers = []) : array{
        $result = [];
        foreach($this->database->fetchAll("SELECT type,level FROM buildings WHERE country_id=?", [$countryId]) as $building){
            $definition = $this->definition((string) $building["type"]);
            $production = $definition["production"] ?? [];
            if(!is_array($production)){
                continue;
            }
            foreach($production as $resource => $amount){
                if(!is_string($resource) || !in_array($resource, ["gold", "food", "materials", "energy", "research", "influence"], true) || !is_numeric($amount)){
                    continue;
                }
                $multiplier = max(0.0, min(10.0, (float) ($multipliers[$resource] ?? 1.0)));
                $result[$resource] = ($result[$resource] ?? 0) + (int) floor((float) $amount * (int) $building["level"] * $multiplier);
            }
        }
        return array_filter($result, static fn(int $amount) : bool => $amount !== 0);
    }

    /** @return array<string, mixed> */
    private function definition(string $type) : array{
        $buildings = $this->configuration["buildings"] ?? [];
        if(!is_array($buildings) || !isset($buildings[$type]) || !is_array($buildings[$type])){
            throw new RuntimeException("Unknown building type.");
        }
        return $buildings[$type];
    }

    /**
     * @param mixed $raw
     * @return array<string, int>
     */
    private function cost(mixed $raw) : array{
        if(!is_array($raw)){
            throw new RuntimeException("Building cost configuration is invalid.");
        }
        $cost = [];
        foreach($raw as $resource => $amount){
            if(!is_string($resource) || !is_int($amount) || $amount < 0 || $amount > 1000000){
                throw new RuntimeException("Building cost configuration is invalid.");
            }
            $cost[$resource] = $amount;
        }
        if($cost === []){
            $cost["gold"] = 0;
        }
        return $cost;
    }
}
