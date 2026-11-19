<?php

declare(strict_types=1);

namespace OpenFrontMC\Security;

final class RateLimiter{

    /** @var array<string, float> */
    private array $lastActionAt = [];

    public function allow(string $key, float $cooldownSeconds) : bool{
        $now = microtime(true);
        $last = $this->lastActionAt[$key] ?? 0.0;
        if(($now - $last) < $cooldownSeconds){
            return false;
        }
        $this->lastActionAt[$key] = $now;
        if(count($this->lastActionAt) > 2048){
            foreach($this->lastActionAt as $storedKey => $timestamp){
                if(($now - $timestamp) > 300.0){
                    unset($this->lastActionAt[$storedKey]);
                }
            }
        }
        return true;
    }
}
