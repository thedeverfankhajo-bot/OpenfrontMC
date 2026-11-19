<?php

declare(strict_types=1);

namespace OpenFrontMC\Match;

use DomainException;

final class MatchStateMachine{

    /** @var array<string, list<string>> */
    private const TRANSITIONS = [
        "WAITING" => ["COUNTDOWN", "FINISHED"],
        "COUNTDOWN" => ["RUNNING", "PAUSED", "FINISHED"],
        "RUNNING" => ["PAUSED", "FINISHED"],
        "PAUSED" => ["RUNNING", "FINISHED"],
        "FINISHED" => []
    ];

    public function transition(MatchState $from, MatchState $to) : MatchState{
        if(!in_array($to->value, self::TRANSITIONS[$from->value], true)){
            throw new DomainException("Invalid match transition: " . $from->value . " -> " . $to->value);
        }
        return $to;
    }

    public function canTransition(MatchState $from, MatchState $to) : bool{
        return in_array($to->value, self::TRANSITIONS[$from->value], true);
    }
}
