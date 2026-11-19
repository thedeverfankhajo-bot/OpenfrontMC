<?php

declare(strict_types=1);

namespace OpenFrontMC\Match;

use OpenFrontMC\Storage\Database;
use RuntimeException;

/**
 * Evaluates configured, server-authoritative match victory conditions.
 *
 * Campaign data is shared between matches in this release. The evaluator is
 * deliberately isolated from PocketMine classes for deterministic service tests.
 */
final class VictoryManager{

    /** @param array<string, mixed> $configuration */
    public function __construct(
        private Database $database,
        private MatchManager $matches,
        private array $configuration
    ){}

    /**
     * @return array{match_id:string,winner_country_id:string,winner_name:string,winner_tag:string,reason:string,players:list<string>}|null
     */
    public function evaluateActive() : ?array{
        $match = $this->matches->latestActive();
        if($match === null || $match["state"] !== MatchState::RUNNING->value){
            return null;
        }

        $matchId = (string) $match["id"];
        $historicalCountries = $this->database->fetchAll(
            "SELECT DISTINCT cm.country_id
             FROM match_players mp
             INNER JOIN country_members cm ON cm.player_name=mp.player_name
             WHERE mp.match_id=?
             ORDER BY cm.country_id",
            [$matchId]
        );
        $activePlayers = $this->matches->players($matchId);
        if($activePlayers === []){
            return null;
        }

        $activeCountries = $this->database->fetchAll(
            "SELECT DISTINCT c.id,c.name,c.tag,c.score
             FROM match_players mp
             INNER JOIN country_members cm ON cm.player_name=mp.player_name
             INNER JOIN countries c ON c.id=cm.country_id
             WHERE mp.match_id=? AND mp.left_at IS NULL
             ORDER BY c.id",
            [$matchId]
        );
        if($activeCountries === []){
            return null;
        }

        $landRow = $this->database->fetchOne(
            "SELECT COUNT(*) AS amount FROM territories WHERE terrain<>'WATER' AND status<>'PROTECTED'"
        );
        $claimableLand = max(0, (int) ($landRow["amount"] ?? 0));

        /**
         * @var array<string, array{id:string,name:string,tag:string,score:int,territories:int,city_sites:int,technologies:int,resources:int}> $metrics
         */
        $metrics = [];
        foreach($activeCountries as $country){
            $countryId = (string) $country["id"];
            $territoryRow = $this->database->fetchOne(
                "SELECT COUNT(*) AS amount FROM territories WHERE owner_country_id=? AND terrain<>'WATER' AND status<>'PROTECTED'",
                [$countryId]
            );
            $objectiveRow = $this->database->fetchOne(
                "SELECT COUNT(*) AS amount FROM territories WHERE owner_country_id=? AND special='CITY_SITE' AND terrain<>'WATER' AND status<>'PROTECTED'",
                [$countryId]
            );
            $researchRow = $this->database->fetchOne(
                "SELECT COUNT(*) AS amount FROM research WHERE country_id=?",
                [$countryId]
            );
            $resourceRow = $this->database->fetchOne(
                "SELECT gold+food+materials+energy+research+influence AS amount FROM resources WHERE country_id=?",
                [$countryId]
            );
            $metrics[$countryId] = [
                "id" => $countryId,
                "name" => (string) $country["name"],
                "tag" => (string) $country["tag"],
                "score" => (int) $country["score"],
                "territories" => (int) ($territoryRow["amount"] ?? 0),
                "city_sites" => (int) ($objectiveRow["amount"] ?? 0),
                "technologies" => (int) ($researchRow["amount"] ?? 0),
                "resources" => (int) ($resourceRow["amount"] ?? 0)
            ];
        }

        /** @var array<string, string> $qualifying */
        $qualifying = [];
        if(
            ($this->configuration["last-country-standing"] ?? true) === true &&
            count($historicalCountries) >= 2 &&
            count($metrics) === 1
        ){
            $countryId = (string) array_key_first($metrics);
            $qualifying[$countryId] = "LAST_COUNTRY_STANDING";
        }

        $score = $this->section("score-target");
        if(($score["enabled"] ?? false) === true){
            $target = $this->integer($score["points"] ?? null, 500, 1, 1000000000);
            foreach($metrics as $countryId => $metric){
                if($metric["score"] >= $target){
                    $qualifying[$countryId] ??= "SCORE_TARGET";
                }
            }
        }

        $territory = $this->section("territory-control");
        if(($territory["enabled"] ?? false) === true && $claimableLand > 0){
            $percent = $this->integer($territory["percent"] ?? null, 70, 1, 100);
            foreach($metrics as $countryId => $metric){
                if(($metric["territories"] * 100) >= ($claimableLand * $percent)){
                    $qualifying[$countryId] ??= "TERRITORY_CONTROL";
                }
            }
        }

        $objectives = $this->section("special-objectives");
        if(($objectives["enabled"] ?? false) === true){
            $required = $this->integer($objectives["city-sites"] ?? null, 3, 1, 1000000);
            foreach($metrics as $countryId => $metric){
                if($metric["city_sites"] >= $required){
                    $qualifying[$countryId] ??= "SPECIAL_OBJECTIVES";
                }
            }
        }

        $research = $this->section("research-target");
        if(($research["enabled"] ?? false) === true){
            $required = $this->integer($research["technologies"] ?? null, 8, 1, 1000000);
            foreach($metrics as $countryId => $metric){
                if($metric["technologies"] >= $required){
                    $qualifying[$countryId] ??= "RESEARCH_TARGET";
                }
            }
        }

        $economy = $this->section("economic-target");
        if(($economy["enabled"] ?? false) === true){
            $required = $this->integer($economy["total-resources"] ?? null, 5000, 1, 1000000000);
            foreach($metrics as $countryId => $metric){
                if($metric["resources"] >= $required){
                    $qualifying[$countryId] ??= "ECONOMIC_TARGET";
                }
            }
        }

        $winnerId = null;
        $reason = null;
        if($qualifying !== []){
            $winnerId = $this->rankedCountry(array_keys($qualifying), $metrics);
            $reason = $qualifying[$winnerId];
        }else{
            $timeLimit = $this->section("time-limit");
            $startedAt = isset($match["started_at"]) ? (int) $match["started_at"] : 0;
            if(($timeLimit["enabled"] ?? false) === true && $startedAt > 0){
                $seconds = $this->integer($timeLimit["seconds"] ?? null, 1800, 1, 604800);
                if(time() >= $startedAt + $seconds){
                    $winnerId = $this->rankedCountry(array_keys($metrics), $metrics);
                    $reason = "TIME_LIMIT";
                }
            }
        }

        if($winnerId === null || $reason === null){
            return null;
        }

        $winner = $metrics[$winnerId];
        $players = array_values(array_map(
            static fn(array $row) : string => (string) $row["player_name"],
            $activePlayers
        ));
        $this->matches->finishWithWinner($matchId, $winnerId, $reason);

        return [
            "match_id" => $matchId,
            "winner_country_id" => $winnerId,
            "winner_name" => $winner["name"],
            "winner_tag" => $winner["tag"],
            "reason" => $reason,
            "players" => $players
        ];
    }

    /** @return array<string, mixed> */
    private function section(string $name) : array{
        $value = $this->configuration[$name] ?? [];
        return is_array($value) ? $value : [];
    }

    private function integer(mixed $value, int $default, int $minimum, int $maximum) : int{
        if(!is_int($value) && !(is_string($value) && preg_match('/^[0-9]{1,10}$/', $value) === 1)){
            return $default;
        }
        return max($minimum, min($maximum, (int) $value));
    }

    /**
     * @param list<string> $countryIds
     * @param array<string, array{id:string,name:string,tag:string,score:int,territories:int,city_sites:int,technologies:int,resources:int}> $metrics
     */
    private function rankedCountry(array $countryIds, array $metrics) : string{
        if($countryIds === []){
            throw new RuntimeException("No eligible country is available to determine a match winner.");
        }
        usort($countryIds, static function(string $left, string $right) use($metrics) : int{
            $a = $metrics[$left];
            $b = $metrics[$right];
            foreach(["score", "territories", "technologies", "resources"] as $field){
                $comparison = $b[$field] <=> $a[$field];
                if($comparison !== 0){
                    return $comparison;
                }
            }
            return strcmp($left, $right);
        });
        return (string) $countryIds[0];
    }
}
