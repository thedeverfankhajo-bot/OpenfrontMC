<?php

declare(strict_types=1);

namespace OpenFrontMC\Research;

use OpenFrontMC\Economy\EconomyManager;
use OpenFrontMC\Storage\Database;
use RuntimeException;

final class ResearchManager{

    public function __construct(
        private Database $database,
        private EconomyManager $economy,
        private TechnologyTree $tree
    ){}

    public function unlock(string $countryId, string $actor, string $technologyId) : void{
        $technology = $this->tree->get($technologyId);
        $this->economy->applyAtomic(
            $countryId,
            $actor,
            "RESEARCH_UNLOCK:" . $technologyId,
            $technology["cost"],
            function() use($countryId, $actor, $technologyId, $technology) : void{
                if($this->database->fetchOne(
                    "SELECT technology_id FROM research WHERE country_id=? AND technology_id=?",
                    [$countryId, $technologyId]
                ) !== null){
                    throw new RuntimeException("This technology is already researched.");
                }

                foreach($technology["prerequisites"] as $prerequisite){
                    if($this->database->fetchOne(
                        "SELECT technology_id FROM research WHERE country_id=? AND technology_id=?",
                        [$countryId, $prerequisite]
                    ) === null){
                        throw new RuntimeException("Missing prerequisite: " . $prerequisite . ".");
                    }
                }

                $now = time();
                $this->database->execute(
                    "INSERT INTO research(country_id,technology_id,completed_at) VALUES(?,?,?)",
                    [$countryId, $technologyId, $now]
                );
                $this->database->execute(
                    "INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES(?,?,?,?,?)",
                    [$actor, "RESEARCH_UNLOCKED", $technologyId, json_encode(["country" => $countryId, "branch" => $technology["branch"], "effects" => $technology["effects"]], JSON_THROW_ON_ERROR), $now]
                );
            }
        );
    }

    /**
     * @return list<string>
     */
    public function isCompleted(string $countryId, string $technologyId) : bool{
        return $this->database->fetchOne(
            "SELECT technology_id FROM research WHERE country_id=? AND technology_id=?",
            [$countryId, $technologyId]
        ) !== null;
    }

    public function completed(string $countryId) : array{
        return array_map(
            static fn(array $row) : string => (string) $row["technology_id"],
            $this->database->fetchAll("SELECT technology_id FROM research WHERE country_id=? ORDER BY technology_id", [$countryId])
        );
    }

    /**
     * @return array<string, array{branch:string,cost:array<string,int>,prerequisites:list<string>,effects:array<string,mixed>}>
     */
    public function technologies() : array{
        return $this->tree->all();
    }

    /**
     * @return array<string, float>
     */
    public function productionMultipliers(string $countryId) : array{
        $multipliers = [];
        foreach($this->completed($countryId) as $technologyId){
            $effects = $this->tree->get($technologyId)["effects"];
            foreach($effects as $effect => $value){
                if(is_string($effect) && preg_match('/^(gold|food|materials|energy|research|influence)_multiplier$/', $effect) === 1 && is_numeric($value)){
                    $resource = substr($effect, 0, -11);
                    $multipliers[$resource] = max(0.0, min(5.0, ($multipliers[$resource] ?? 1.0) * (float) $value));
                }
            }
        }
        return $multipliers;
    }
}
