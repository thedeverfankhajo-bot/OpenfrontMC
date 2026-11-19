<?php

declare(strict_types=1);

namespace OpenFrontMC\Research;

use InvalidArgumentException;
use OpenFrontMC\Economy\EconomyRules;
use RuntimeException;

final class TechnologyTree{

    /**
     * @var array<string, array{branch:string,cost:array<string,int>,prerequisites:list<string>,effects:array<string,mixed>}>
     */
    private array $technologies = [];

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(array $configuration){
        $source = $configuration["technologies"] ?? $configuration;
        if(!is_array($source)){
            throw new InvalidArgumentException("Research technologies must be a mapping.");
        }

        foreach($source as $id => $raw){
            if(!is_string($id) || preg_match('/^[a-z][a-z0-9_]{1,47}$/', $id) !== 1 || !is_array($raw)){
                throw new InvalidArgumentException("Invalid technology definition.");
            }
            $branch = $raw["branch"] ?? "general";
            $prerequisites = $raw["prerequisites"] ?? [];
            $effects = $raw["effects"] ?? [];
            if(!is_string($branch) || $branch === "" || !is_array($prerequisites) || !is_array($effects)){
                throw new InvalidArgumentException("Invalid technology fields for " . $id . ".");
            }

            $normalizedPrerequisites = [];
            foreach($prerequisites as $prerequisite){
                if(!is_string($prerequisite) || preg_match('/^[a-z][a-z0-9_]{1,47}$/', $prerequisite) !== 1){
                    throw new InvalidArgumentException("Invalid prerequisite for " . $id . ".");
                }
                $normalizedPrerequisites[] = $prerequisite;
            }

            $rawCost = $raw["cost"] ?? [];
            if(!is_array($rawCost)){
                throw new InvalidArgumentException("Invalid technology cost for " . $id . ".");
            }
            $costInput = [];
            foreach($rawCost as $resource => $amount){
                if(!is_string($resource) || (!is_int($amount) && !is_string($amount))){
                    throw new InvalidArgumentException("Invalid technology cost entry for " . $id . ".");
                }
                $costInput[$resource] = $amount;
            }

            $normalizedEffects = [];
            foreach($effects as $effectId => $effectValue){
                if(!is_string($effectId)){
                    throw new InvalidArgumentException("Technology effect keys must be strings.");
                }
                $normalizedEffects[$effectId] = $effectValue;
            }

            $this->technologies[$id] = [
                "branch" => $branch,
                "cost" => EconomyRules::normalizeAmounts($costInput),
                "prerequisites" => array_values(array_unique($normalizedPrerequisites)),
                "effects" => $normalizedEffects
            ];
        }

        foreach($this->technologies as $id => $technology){
            foreach($technology["prerequisites"] as $prerequisite){
                if($prerequisite === $id || !isset($this->technologies[$prerequisite])){
                    throw new InvalidArgumentException("Unknown or self-referencing prerequisite in " . $id . ".");
                }
            }
        }
        $this->assertAcyclic();
    }

    /**
     * @return array<string, array{branch:string,cost:array<string,int>,prerequisites:list<string>,effects:array<string,mixed>}>
     */
    public function all() : array{
        return $this->technologies;
    }

    /**
     * @return array{branch:string,cost:array<string,int>,prerequisites:list<string>,effects:array<string,mixed>}
     */
    public function get(string $id) : array{
        if(!isset($this->technologies[$id])){
            throw new RuntimeException("Unknown technology.");
        }
        return $this->technologies[$id];
    }

    private function assertAcyclic() : void{
        /** @var array<string, int> $state */
        $state = [];
        foreach(array_keys($this->technologies) as $id){
            $this->visit($id, $state);
        }
    }

    /**
     * @param array<string, int> $state
     */
    private function visit(string $id, array &$state) : void{
        if(($state[$id] ?? 0) === 1){
            throw new InvalidArgumentException("Technology prerequisites contain a cycle.");
        }
        if(($state[$id] ?? 0) === 2){
            return;
        }
        $state[$id] = 1;
        foreach($this->technologies[$id]["prerequisites"] as $prerequisite){
            $this->visit($prerequisite, $state);
        }
        $state[$id] = 2;
    }
}
