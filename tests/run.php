<?php
declare(strict_types=1);

use OpenFrontMC\Building\BuildingManager;
use OpenFrontMC\Country\CountryManager;
use OpenFrontMC\Diplomacy\DiplomacyManager;
use OpenFrontMC\Economy\EconomyManager;
use OpenFrontMC\Economy\EconomyRules;
use OpenFrontMC\Match\MatchManager;
use OpenFrontMC\Match\MatchState;
use OpenFrontMC\Match\MatchStateMachine;
use OpenFrontMC\Research\ResearchManager;
use OpenFrontMC\Research\TechnologyTree;
use OpenFrontMC\Security\InputValidator;
use OpenFrontMC\Security\RateLimiter;
use OpenFrontMC\Storage\Database;
use OpenFrontMC\Territory\TerritoryManager;
use OpenFrontMC\World\MapGenerator;

$root = dirname(__DIR__);
$files = [
    "/Security/InputValidator.php", "/Security/RateLimiter.php",
    "/Economy/EconomyRules.php", "/World/MapGenerator.php",
    "/Match/MatchState.php", "/Match/MatchStateMachine.php",
    "/Storage/Database.php", "/Country/CountryManager.php",
    "/Territory/TerritoryManager.php", "/Economy/EconomyManager.php",
    "/Research/TechnologyTree.php", "/Research/ResearchManager.php",
    "/Building/BuildingManager.php", "/Diplomacy/DiplomacyManager.php",
    "/Match/MatchManager.php"
];
foreach($files as $file){
    require_once $root . "/src/OpenFrontMC" . $file;
}


$assertions = 0;
$assert = static function(bool $condition, string $message) use (&$assertions) : void{
    ++$assertions;
    if(!$condition){
        throw new RuntimeException("Assertion failed: " . $message);
    }
};
$expectException = static function(callable $callback, string $message) use ($assert) : void{
    try{
        $callback();
    }catch(Throwable){
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

$assert(InputValidator::countryName("New Horizon"), "valid country name");
$assert(InputValidator::countryName("آینده نو"), "unicode country name");
$assert(!InputValidator::countryName("<script>"), "reject markup");
$assert(InputValidator::coordinate(15, 16), "valid coordinate");
$assert(!InputValidator::coordinate(16, 16), "reject out-of-range coordinate");
$assert(InputValidator::playerName("Player_01"), "valid player name");
$assert(!InputValidator::playerName("../bad"), "reject invalid player name");

$balance = ["gold" => 10, "food" => 5];
$next = EconomyRules::apply($balance, ["gold" => -3, "food" => 2], 100);
$assert($next["gold"] === 7 && $next["food"] === 7, "economy debit/credit");
$assert(!EconomyRules::canAfford($balance, ["gold" => 11]), "insufficient funds");
$assert(EconomyRules::apply(["gold" => 99], ["gold" => 9], 100)["gold"] === 100, "capacity cap");
$expectException(static fn() => EconomyRules::apply($balance, ["gold" => -11], 100), "no negative resource balance");
$expectException(static fn() => EconomyRules::normalizeDelta(["diamonds" => 1]), "unknown resource rejected");
$expectException(static fn() => EconomyRules::normalizeAmounts(["gold" => -1]), "negative cost rejected");

$generator = new MapGenerator();
$mapA = $generator->generate(12345, 8, 8, 2, 4, 0.55);
$mapB = $generator->generate(12345, 8, 8, 2, 4, 0.55);
$mapC = $generator->generate(54321, 8, 8, 2, 4, 0.55);
$assert($mapA === $mapB, "seed reproducibility");
$assert($mapA !== $mapC, "different seeds vary map");
$assert(count($mapA) === 64, "map dimensions");
$assert(count(array_filter($mapA, static fn(array $tile) : bool => $tile["terrain"] !== "WATER")) > 0, "land exists");
$assert($mapA[4 * 8 + 4]["terrain"] !== "WATER", "central spawn territory is land");
$expectException(static fn() => $generator->generate(1, 2, 2, 1, 1, 0.5), "map bounds validated");

$machine = new MatchStateMachine();
$assert($machine->transition(MatchState::WAITING, MatchState::COUNTDOWN) === MatchState::COUNTDOWN, "valid transition");
$assert(!$machine->canTransition(MatchState::FINISHED, MatchState::RUNNING), "finished is terminal");
$expectException(static fn() => $machine->transition(MatchState::WAITING, MatchState::RUNNING), "invalid transition rejected");

$limiter = new RateLimiter();
$assert($limiter->allow("unit-test", 10.0), "first rate-limited action allowed");
$assert(!$limiter->allow("unit-test", 10.0), "repeated action throttled");

$tree = new TechnologyTree([
    "technologies" => [
        "basic_science" => [
            "branch" => "science", "cost" => ["research" => 10],
            "prerequisites" => [], "effects" => ["research_multiplier" => 1.1]
        ],
        "advanced_science" => [
            "branch" => "science", "cost" => ["research" => 20],
            "prerequisites" => ["basic_science"], "effects" => []
        ]
    ]
]);
$assert(count($tree->all()) === 2, "technology definitions loaded");
$expectException(static fn() => new TechnologyTree([
    "technologies" => [
        "alpha_tech" => ["branch" => "science", "cost" => [], "prerequisites" => ["beta_tech"], "effects" => []],
        "beta_tech" => ["branch" => "science", "cost" => [], "prerequisites" => ["alpha_tech"], "effects" => []]
    ]
]), "technology cycles rejected");
$expectException(static fn() => new TechnologyTree([
    "technologies" => [
        "alpha_tech" => ["branch" => "science", "cost" => [], "prerequisites" => ["unknown_tech"], "effects" => []]
    ]
]), "unknown technology prerequisite rejected");

if(!extension_loaded("sqlite3")){
    throw new RuntimeException("SQLite3 is required by the project test suite.");
}

$databasePath = sys_get_temp_dir() . "/openfrontmc-tests-" . bin2hex(random_bytes(6)) . ".sqlite3";
$database = null;
try{
    $database = new Database($databasePath);
    $database->migrate();
    $assert((int) ($database->fetchOne("PRAGMA user_version")["user_version"] ?? 0) === 2, "database migration version");
    $backupPath = $databasePath . ".backup";
    $database->backup($backupPath);
    $backupDatabase = new SQLite3($backupPath, SQLITE3_OPEN_READONLY);
    $assert($backupDatabase->querySingle("PRAGMA integrity_check") === "ok", "online database backup integrity");
    $backupDatabase->close();
    if(is_file($backupPath)){
        @unlink($backupPath);
    }
    $database->migrate();
    $assert((int) ($database->fetchOne("PRAGMA user_version")["user_version"] ?? 0) === 2, "database migration is idempotent");
    $renderTable = $database->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='rendered_territories'");
    $assert($renderTable !== null, "terrain render progress migration");

    $countries = new CountryManager($database, [
        "gold" => 200, "food" => 80, "materials" => 80,
        "energy" => 30, "research" => 100, "influence" => 20
    ]);
    $countryId = $countries->create("PlayerOne", "North Horizon", "NHR");
    $secondCountryId = $countries->create("PlayerTwo", "South Horizon", "SHR");
    $assert($countries->byPlayer("PlayerOne")["id"] === $countryId, "country lookup by player");
    $assert($countries->byTag("SHR")["id"] === $secondCountryId, "country lookup by tag");

    $territories = new TerritoryManager($database);
    $territories->seedNeutralMap(12345, 8, 8, $mapA);
    $assert(count($territories->all()) === 64, "territory map persisted");
    $assert((int) $territories->metadata()["seed"] === 12345, "map seed persisted");
    $territories->claim("PlayerOne", $countryId, 4, 4, 25);
    $assert($territories->byCoordinates(4, 4)["owner_country_id"] === $countryId, "territory ownership");
    $expectException(static fn() => $territories->claim("PlayerOne", $countryId, 4, 4, 25), "duplicate territory claim rejected");
    $expectException(static fn() => $territories->claim("PlayerOne", $countryId, 999, 999, 25), "out-of-map claim rejected");
    $assert((int) $database->fetchOne("SELECT gold FROM resources WHERE country_id=?", [$countryId])["gold"] === 175, "territory claim cost debited");

    $database->execute("INSERT INTO rendered_territories(territory_id,rendered_at) VALUES(?,?)", ["4:4", time()]);
    $assert((int) ($database->fetchOne("SELECT COUNT(*) AS c FROM rendered_territories")["c"] ?? 0) === 1, "rendered territory checkpoint stored");
    $territories->markRendered();
    $assert((int) $territories->metadata()["rendered"] === 1, "map marked rendered");

    $economy = new EconomyManager($database, ["capacity" => 1000, "income_per_period" => ["gold" => 5]]);
    $economy->change($countryId, "PlayerOne", "UNIT_TEST_CREDIT", ["gold" => 10]);
    $assert($economy->balance($countryId)["gold"] === 185, "economy transaction persisted");
    $expectException(
        static fn() => $economy->applyAtomic($countryId, "PlayerOne", "UNIT_TEST_ROLLBACK", ["gold" => 30], static function() : void{
            throw new RuntimeException("rollback test");
        }),
        "atomic economy callback rolls back"
    );
    $assert($economy->balance($countryId)["gold"] === 185, "failed atomic action refunded");

    $buildingManager = new BuildingManager($database, $economy, [
        "buildings" => [
            "capital" => ["cost" => ["gold" => 0], "production" => ["influence" => 1], "max-level" => 3, "max-per-country" => 1],
            "farm" => ["cost" => ["gold" => 15, "materials" => 5], "upgrade-cost" => ["gold" => 10], "production" => ["food" => 5], "max-level" => 3, "max-per-country" => 5]
        ]
    ]);
    $farmId = $buildingManager->build($countryId, "4:4", "farm", "PlayerOne");
    $assert($buildingManager->production($countryId)["food"] === 5, "building production");
    $assert($buildingManager->upgrade($countryId, $farmId, "PlayerOne") === 2, "building upgrade");
    $assert($buildingManager->production($countryId)["food"] === 10, "upgraded building production");
    $buildingManager->demolish($countryId, $farmId, "PlayerOne");
    $assert($buildingManager->production($countryId) === [], "building demolition");

    $research = new ResearchManager($database, $economy, $tree);
    $expectException(static fn() => $research->unlock($countryId, "PlayerOne", "advanced_science"), "research prerequisite enforced before prerequisite unlock");
    $research->unlock($countryId, "PlayerOne", "basic_science");
    $assert($research->isCompleted($countryId, "basic_science"), "research unlocked");
    $assert($research->productionMultipliers($countryId)["research"] > 1.0, "research effect applied");
    $research->unlock($countryId, "PlayerOne", "advanced_science");
    $assert($research->isCompleted($countryId, "advanced_science"), "dependent research unlocks after prerequisite");

    $diplomacy = new DiplomacyManager($database, [
        "request-expiry-seconds" => 3600, "max-pending-per-hour" => 4,
        "treaty-duration-seconds" => ["TRADE" => 3600]
    ]);
    $treatyId = $diplomacy->offer($countryId, "SHR", "TRADE", "PlayerOne");
    $diplomacy->accept($secondCountryId, "PlayerTwo", $treatyId);
    $treaties = $diplomacy->forCountry($countryId);
    $assert(count($treaties) === 1 && $treaties[0]["status"] === "ACTIVE", "diplomacy offer and accept");

    $matches = new MatchManager($database);
    $matchId = $matches->create("FFA", 8);
    $matches->join($matchId, "PlayerOne");
    $matches->markReady($matchId, "PlayerOne", true);
    $assert($matches->readyCount($matchId) === 1, "match ready state");
    $matches->leave("PlayerOne");
    $matches->join($matchId, "PlayerOne");
    $assert(count($matches->players($matchId)) === 1, "player can rejoin the same match");
    $matches->transition($matchId, MatchState::COUNTDOWN);
    $matches->transition($matchId, MatchState::RUNNING);
    $matches->transition($matchId, MatchState::FINISHED);
    $assert($matches->get($matchId)["state"] === MatchState::FINISHED->value, "match finish persisted");
}finally{
    if($database instanceof Database){
        $database->close();
    }
    foreach(["", "-wal", "-shm"] as $suffix){
        $path = $databasePath . $suffix;
        if(is_file($path)){
            @unlink($path);
        }
    }
}


$legacyDatabasePath = sys_get_temp_dir() . "/openfrontmc-legacy-" . bin2hex(random_bytes(6)) . ".sqlite3";
$legacyDatabase = null;
try{
    $legacyDatabase = new Database($legacyDatabasePath);
    $legacyDatabase->migrate();
    $legacyDatabase->execute("DROP TABLE rendered_territories");
    $legacyDatabase->execute("PRAGMA user_version = 1");
    $legacyDatabase->migrate();
    $assert((int) ($legacyDatabase->fetchOne("PRAGMA user_version")["user_version"] ?? 0) === 2, "version 1 database migrates forward");
    $assert($legacyDatabase->fetchOne("SELECT name FROM sqlite_master WHERE type='table' AND name='rendered_territories'") !== null, "version 1 migration adds terrain checkpoints");
}finally{
    if($legacyDatabase instanceof Database){
        $legacyDatabase->close();
    }
    foreach(["", "-wal", "-shm"] as $suffix){
        $path = $legacyDatabasePath . $suffix;
        if(is_file($path)){
            @unlink($path);
        }
    }
}

echo "OK: " . $assertions . " assertions\n";
