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
        $delta = self::normalizeDelta($delta);
        $result = [];
        foreach(self::RESOURCES as $resource){
            $current = $balance[$resource] ?? 0;
            if(!is_int($current) && !is_float($current) && !(is_string($current) && preg_match('/^-?[0-9]{1,12}$/', $current) === 1)){
                throw new InvalidArgumentException("Invalid current resource balance.");
            }
            $current = (int) $current;
            if($current < 0){
                throw new InvalidArgumentException("Stored resource balances cannot be negative.");
            }
            $next = $current + ($delta[$resource] ?? 0);
            if($next < 0){
                throw new LogicException("Insufficient " . $resource . ".");
            }
            $result[$resource] = min($next, $capacity);
        }
        return $result;
    }

    /**
     * Signed resource changes used by economy transactions.
     *
     * @param array<string, int|float|string> $changes
     * @return array<string, int>
     */
    public static function normalizeDelta(array $changes) : array{
        $normalized = [];
        foreach($changes as $resource => $amount){
            if(!is_string($resource) || !in_array($resource, self::RESOURCES, true)){
                throw new InvalidArgumentException("Unknown resource.");
            }
            if(is_int($amount)){
                $parsed = $amount;
            }elseif(is_string($amount) && preg_match('/^-?[0-9]{1,9}$/', $amount) === 1){
                $parsed = (int) $amount;
            }else{
                throw new InvalidArgumentException("Resource changes must be whole numbers.");
            }
            if(abs($parsed) > 100000000){
                throw new InvalidArgumentException("Resource change is too large.");
            }
            $normalized[$resource] = $parsed;
        }
        if($normalized === []){
            throw new InvalidArgumentException("At least one resource change is required.");
        }
        return $normalized;
    }

    /**
     * @param array<string, int|float|string> $amounts
     * @return array<string, int>
     */
    public static function normalizeAmounts(array $amounts) : array{
        $normalized = [];
        foreach($amounts as $resource => $amount){
            if(!is_string($resource) || !in_array($resource, self::RESOURCES, true)){
                throw new InvalidArgumentException("Unknown resource.");
            }
            if(is_int($amount)){
                $parsed = $amount;
            }elseif(is_string($amount) && preg_match('/^[0-9]{1,9}$/', $amount) === 1){
                $parsed = (int) $amount;
            }else{
                throw new InvalidArgumentException("Resource costs must be non-negative whole numbers.");
            }
            if($parsed < 0 || $parsed > 100000000){
                throw new InvalidArgumentException("Invalid resource amount.");
            }
            $normalized[$resource] = $parsed;
        }
        return $normalized;
    }

    /**
     * @param array<string, int|float> $balance
     * @param array<string, int|float> $cost
     */
    public static function canAfford(array $balance, array $cost) : bool{
        try{
            $normalized = self::normalizeAmounts($cost);
        }catch(InvalidArgumentException){
            return false;
        }
        foreach($normalized as $resource => $amount){
            if((int) ($balance[$resource] ?? 0) < $amount){
                return false;
            }
        }
        return true;
    }
}
