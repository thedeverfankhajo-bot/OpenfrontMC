<?php

declare(strict_types=1);

namespace OpenFrontMC\Match;

enum MatchState: string{
    case WAITING = "WAITING";
    case COUNTDOWN = "COUNTDOWN";
    case RUNNING = "RUNNING";
    case PAUSED = "PAUSED";
    case FINISHED = "FINISHED";
}
