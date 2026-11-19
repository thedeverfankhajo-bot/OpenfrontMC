<?php

declare(strict_types=1);

namespace OpenFrontMC\Economy;

use InvalidArgumentException;
use LogicException;

final class EconomyRules{

    public const RESOURCES = ["gold", "food", "materials", "energy", "research", "influence"];

    /**
     * @param array<string, int|float> $balance
     * @param array<string, int|float> $delta
     * @return array<string, int>
     */
    public static function apply(array $balance, array $delta, int $capacity) : array{
        if($capacity < 1){
            throw new InvalidArgumentException("Resource capacity must be positive.");
        }
        $result = [];
        foreach(self::RESOURCES as $resource){
            $current = (int) ($balance[$resource] ?? 0);
            $change = (int) ($delta[$resource] ?? 0);
            $next = $current + $change;
            if($next < 0){
                throw new LogicException("Insufficient " . $resource . ".");
            }
            $result[$resource] = min($next, $capacity);
        }
        return $result;
    }

    /**
     * @param array<string, int|float> $balance
     * @param array<string, int|float> $cost
     */
    public static function canAfford(array $balance, array $cost) : bool{
        foreach($cost as $resource => $amount){
            if(!in_array($resource, self::RESOURCES, true) || (int) $amount < 0){
                return false;
            }
            if((int) ($balance[$resource] ?? 0) < (int) $amount){
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string, int|float> $amounts
     * @return array<string, int>
     */
    public static function normalizeAmounts(array $amounts) : array{
        $normalized = [];
        foreach($amounts as $resource => $amount){
            if(!in_array($resource, self::RESOURCES, true) || !is_numeric($amount) || (int) $amount < 0){
                throw new InvalidArgumentException("Invalid resource amount.");
            }
            $normalized[$resource] = (int) $amount;
        }
        return $normalized;
    }
}
