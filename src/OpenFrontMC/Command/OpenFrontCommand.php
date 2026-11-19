<?php

declare(strict_types=1);

namespace OpenFrontMC\Command;

use OpenFrontMC\Building\BuildingManager;
use OpenFrontMC\Country\CountryManager;
use OpenFrontMC\Diplomacy\DiplomacyManager;
use OpenFrontMC\Economy\EconomyManager;
use OpenFrontMC\Language\LanguageManager;
use OpenFrontMC\Match\MatchManager;
use OpenFrontMC\Match\MatchState;
use OpenFrontMC\Research\ResearchManager;
use OpenFrontMC\Security\RateLimiter;
use OpenFrontMC\Territory\TerritoryManager;
use OpenFrontMC\World\TerrainRenderer;
use OpenFrontMC\World\WorldManager;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use RuntimeException;
use Throwable;

final class OpenFrontCommand{

    private RateLimiter $rateLimiter;

    public function __construct(
        private PluginBase $plugin,
        private CountryManager $countries,
        private TerritoryManager $territories,
        private EconomyManager $economy,
        private MatchManager $matches,
        private WorldManager $worlds,
        private BuildingManager $buildings,
        private ResearchManager $research,
        private DiplomacyManager $diplomacy,
        private LanguageManager $language,
        private TerrainRenderer $terrainRenderer
    ){
        $this->rateLimiter = new RateLimiter();
    }

    /** @param string[] $args */
    public function execute(CommandSender $sender, array $args) : void{
        try{
            $sub = strtolower($args[0] ?? "help");
            switch($sub){
                case "help":
                    $sender->sendMessage($this->language->get("help"));
                    break;
                case "country":
                    $this->country($sender, array_slice($args, 1));
                    break;
                case "claim":
                    $this->requirePermission($sender, "openfront.territory");
                    $this->claim($sender, $args[1] ?? null, $args[2] ?? null);
                    break;
                case "map":
                    $this->requirePermission($sender, "openfront.map");
                    $this->map($sender);
                    break;
                case "economy":
                    $this->requirePermission($sender, "openfront.economy");
                    $this->economyInfo($sender);
                    break;
                case "build":
                    $this->requirePermission($sender, "openfront.building");
                    $this->build($sender, $args[1] ?? null, $args[2] ?? null, $args[3] ?? null);
                    break;
                case "upgrade":
                    $this->requirePermission($sender, "openfront.building");
                    $this->upgrade($sender, $args[1] ?? null);
                    break;
                case "demolish":
                    $this->requirePermission($sender, "openfront.building");
                    $this->demolish($sender, $args[1] ?? null);
                    break;
                case "research":
                    $this->requirePermission($sender, "openfront.research");
                    $this->researchCommand($sender, array_slice($args, 1));
                    break;
                case "diplomacy":
                    $this->requirePermission($sender, "openfront.diplomacy");
                    $this->diplomacyCommand($sender, array_slice($args, 1));
                    break;
                case "join":
                    $this->requirePermission($sender, "openfront.match");
                    $this->join($sender);
                    break;
                case "leave":
                    $this->requirePermission($sender, "openfront.match");
                    $this->leave($sender);
                    break;
                case "ready":
                    $this->requirePermission($sender, "openfront.match");
                    $this->ready($sender);
                    break;
                case "stats":
                    $this->requirePermission($sender, "openfront.stats");
                    $this->stats($sender);
                    break;
                case "top":
                    $this->requirePermission($sender, "openfront.stats");
                    foreach($this->countries->top() as $index => $country){
                        $sender->sendMessage("§e" . ($index + 1) . ". §f" . $country["name"] . " §7[" . $country["tag"] . "] §fScore: " . $country["score"]);
                    }
                    break;
                case "admin":
                    $this->admin($sender, array_slice($args, 1));
                    break;
                default:
                    $sender->sendMessage($this->language->get("unknown-command"));
            }
        }catch(RuntimeException $e){
            $sender->sendMessage("§c" . $e->getMessage());
        }catch(Throwable $e){
            $this->plugin->getLogger()->error("Command failed: " . $e->getMessage());
            $sender->sendMessage("§cAn internal error occurred. Please contact a server administrator.");
        }
    }

    /** @param string[] $args */
    private function country(CommandSender $sender, array $args) : void{
        $this->requirePermission($sender, "openfront.country");
        if(!$sender instanceof Player){
            throw new RuntimeException("This command is available to players only.");
        }
        if(($args[0] ?? "") === "create"){
            if(count($args) < 3){
                throw new RuntimeException("Usage: /openfront country create <name> <TAG>");
            }
            if(!$this->rateLimiter->allow($sender->getName() . ":country-create", 2.0)){
                throw new RuntimeException("Please wait before creating another country.");
            }
            $tag = strtoupper((string) array_pop($args));
            array_shift($args);
            $name = implode(" ", $args);
            $this->countries->create($sender->getName(), $name, $tag);
            $sender->sendMessage($this->language->get("country-created"));
            return;
        }
        if(($args[0] ?? "") === "info" && isset($args[1])){
            $country = $this->countries->byTag($args[1]);
            if($country === null){
                throw new RuntimeException("Country not found.");
            }
            $sender->sendMessage("§a" . $country["name"] . " §7[" . $country["tag"] . "] §fScore: " . $country["score"] . " §7Leader: " . $country["owner_name"]);
            return;
        }
        $country = $this->countries->byPlayer($sender->getName());
        if($country === null){
            $sender->sendMessage("§eNo country. Use /openfront country create <name> <TAG>.");
            return;
        }
        $sender->sendMessage("§a" . $country["name"] . " §7[" . $country["tag"] . "] §fScore: " . $country["score"]);
    }

    private function claim(CommandSender $sender, ?string $rawX, ?string $rawZ) : void{
        if(!$sender instanceof Player){
            throw new RuntimeException("Players only.");
        }
        if(!$this->rateLimiter->allow($sender->getName() . ":claim", 1.0)){
            throw new RuntimeException("Please wait before making another claim.");
        }
        if($rawX === null || $rawZ === null || preg_match('/^[0-9]{1,3}$/', $rawX) !== 1 || preg_match('/^[0-9]{1,3}$/', $rawZ) !== 1){
            throw new RuntimeException("Usage: /openfront claim <x> <z>");
        }
        $country = $this->requireCountry($sender);
        $cost = max(0, (int) $this->plugin->getConfig()->getNested("territory.claim-cost", 25));
        $this->territories->claim($sender->getName(), (string) $country["id"], (int) $rawX, (int) $rawZ, $cost);
        $sender->sendMessage("§aTerritory claimed.");
    }

    private function map(CommandSender $sender) : void{
        $metadata = $this->territories->metadata();
        if($metadata === null){
            throw new RuntimeException("Map metadata is not available.");
        }
        $tiles = $this->territories->all();
        $width = (int) $metadata["width"];
        $height = (int) $metadata["height"];
        $land = 0;
        $water = 0;
        $claimed = 0;
        $rows = array_fill(0, $height, array_fill(0, $width, "?"));
        foreach($tiles as $tile){
            $x = (int) $tile["x"];
            $z = (int) $tile["z"];
            if($tile["terrain"] === "WATER"){
                ++$water;
                $symbol = "~";
            }else{
                ++$land;
                $symbol = $tile["special"] === "CITY_SITE" ? "C" : ".";
            }
            if($tile["owner_country_id"] !== null){
                ++$claimed;
                $symbol = "#";
            }
            $rows[$z][$x] = $symbol;
        }
        $sender->sendMessage($this->language->get("map-header", ["land" => $land, "water" => $water, "claimed" => $claimed]));
        foreach($rows as $row){
            $sender->sendMessage("§7" . implode("", $row));
        }
        $sender->sendMessage("§7Legend: ~ water, . land, C city site, # claimed");
    }

    private function economyInfo(CommandSender $sender) : void{
        if(!$sender instanceof Player){
            throw new RuntimeException("Players only.");
        }
        $country = $this->requireCountry($sender);
        foreach($this->economy->balance((string) $country["id"]) as $resource => $amount){
            $sender->sendMessage("§e" . ucfirst($resource) . ": §f" . $amount);
        }
    }

    private function build(CommandSender $sender, ?string $type, ?string $rawX, ?string $rawZ) : void{
        if(!$sender instanceof Player || $type === null || $rawX === null || $rawZ === null || preg_match('/^[0-9]{1,3}$/', $rawX) !== 1 || preg_match('/^[0-9]{1,3}$/', $rawZ) !== 1){
            throw new RuntimeException("Usage: /openfront build <type> <territory-x> <territory-z>");
        }
        if(!$this->rateLimiter->allow($sender->getName() . ":build", 1.0)){
            throw new RuntimeException("Please wait before building again.");
        }
        $country = $this->requireCountry($sender);
        $territory = $this->territories->byCoordinates((int) $rawX, (int) $rawZ);
        if($territory === null){
            throw new RuntimeException("Territory not found.");
        }
        $id = $this->buildings->build((string) $country["id"], (string) $territory["id"], $type, $sender->getName());
        $sender->sendMessage($this->language->get("building-created", ["id" => $id]));
    }

    private function upgrade(CommandSender $sender, ?string $buildingId) : void{
        if(!$sender instanceof Player || $buildingId === null || preg_match('/^[a-f0-9]{16}$/', $buildingId) !== 1){
            throw new RuntimeException("Usage: /openfront upgrade <building-id>");
        }
        if(!$this->rateLimiter->allow($sender->getName() . ":upgrade", 1.0)){
            throw new RuntimeException("Please wait before upgrading again.");
        }
        $country = $this->requireCountry($sender);
        $level = $this->buildings->upgrade((string) $country["id"], $buildingId, $sender->getName());
        $sender->sendMessage("§aBuilding upgraded to level " . $level . ".");
    }

    private function demolish(CommandSender $sender, ?string $buildingId) : void{
        if(!$sender instanceof Player || $buildingId === null || preg_match('/^[a-f0-9]{16}$/', $buildingId) !== 1){
            throw new RuntimeException("Usage: /openfront demolish <building-id>");
        }
        if(!$this->rateLimiter->allow($sender->getName() . ":demolish", 1.0)){
            throw new RuntimeException("Please wait before demolishing again.");
        }
        $country = $this->requireCountry($sender);
        $this->buildings->demolish((string) $country["id"], $buildingId, $sender->getName());
        $sender->sendMessage("§aBuilding demolished.");
    }

    /** @param string[] $args */
    private function researchCommand(CommandSender $sender, array $args) : void{
        if(!$sender instanceof Player){
            throw new RuntimeException("Players only.");
        }
        $country = $this->requireCountry($sender);
        $action = strtolower($args[0] ?? "list");
        if($action === "list"){
            $completed = $this->research->completed((string) $country["id"]);
            foreach($this->research->technologies() as $id => $technology){
                $state = in_array($id, $completed, true) ? "§aRESEARCHED" : "§eAVAILABLE";
                $sender->sendMessage($state . " §f" . $id . " §7[" . $technology["branch"] . "] Cost: " . json_encode($technology["cost"]));
            }
            return;
        }
        if($action === "unlock" && isset($args[1])){
            if(!$this->rateLimiter->allow($sender->getName() . ":research", 1.0)){
                throw new RuntimeException("Please wait before starting another research.");
            }
            $this->research->unlock((string) $country["id"], $sender->getName(), $args[1]);
            $sender->sendMessage($this->language->get("research-complete", ["id" => $args[1]]));
            return;
        }
        throw new RuntimeException("Usage: /openfront research list|unlock <technology-id>");
    }

    /** @param string[] $args */
    private function diplomacyCommand(CommandSender $sender, array $args) : void{
        if(!$sender instanceof Player){
            throw new RuntimeException("Players only.");
        }
        $country = $this->requireCountry($sender);
        $action = strtolower($args[0] ?? "list");
        $countryId = (string) $country["id"];
        if($action === "list"){
            foreach($this->diplomacy->forCountry($countryId) as $treaty){
                $sender->sendMessage("§e" . $treaty["id"] . " §f" . $treaty["type"] . " §7" . $treaty["status"] . " §f" . $treaty["from_name"] . " -> " . $treaty["to_name"]);
            }
            return;
        }
        if($action === "offer" && isset($args[1], $args[2])){
            if(!$this->rateLimiter->allow($sender->getName() . ":diplomacy", 2.0)){
                throw new RuntimeException("Please wait before sending another diplomacy request.");
            }
            $id = $this->diplomacy->offer($countryId, $args[1], strtoupper($args[2]), $sender->getName());
            $sender->sendMessage($this->language->get("treaty-created", ["id" => $id]));
            return;
        }
        if(in_array($action, ["accept", "reject", "end"], true) && isset($args[1])){
            if(preg_match('/^[a-f0-9]{16}$/', $args[1]) !== 1){
                throw new RuntimeException("Invalid treaty identifier.");
            }
            if($action === "accept"){
                $this->diplomacy->accept($countryId, $sender->getName(), $args[1]);
                $sender->sendMessage($this->language->get("treaty-accepted"));
            }elseif($action === "reject"){
                $this->diplomacy->reject($countryId, $sender->getName(), $args[1]);
                $sender->sendMessage("§aDiplomacy request rejected.");
            }else{
                $this->diplomacy->end($countryId, $sender->getName(), $args[1]);
                $sender->sendMessage("§aAgreement ended.");
            }
            return;
        }
        throw new RuntimeException("Usage: /openfront diplomacy list|offer <TAG> <ALLIANCE|TRADE|NON_AGGRESSION|PEACE>|accept|reject|end <id>");
    }

    private function join(CommandSender $sender) : void{
        if(!$sender instanceof Player){
            throw new RuntimeException("Players only.");
        }
        if(!$this->terrainRenderer->isReady()){
            $failure = $this->terrainRenderer->getFailure();
            throw new RuntimeException($failure ?? "The strategy map is still being generated. Please try again shortly.");
        }
        if(!$this->rateLimiter->allow($sender->getName() . ":join", 2.0)){
            throw new RuntimeException("Please wait before joining a match again.");
        }
        $match = $this->matches->latestWaiting();
        if($match === null){
            $capacity = (int) $this->plugin->getConfig()->getNested("match.capacity", 16);
            $id = $this->matches->create("FFA", $capacity);
            $match = ["id" => $id];
        }
        $this->matches->join((string) $match["id"], $sender->getName());
        $sender->teleport($this->worlds->getMatchWorld()->getSpawnLocation());
        $sender->sendMessage($this->language->get("joined-match", ["id" => $match["id"]]));
    }

    private function leave(CommandSender $sender) : void{
        if(!$sender instanceof Player){
            throw new RuntimeException("Players only.");
        }
        $this->matches->leave($sender->getName());
        $sender->teleport($this->worlds->getLobby()->getSpawnLocation());
        $sender->sendMessage($this->language->get("left-match"));
    }

    private function ready(CommandSender $sender) : void{
        if(!$sender instanceof Player){
            throw new RuntimeException("Players only.");
        }
        $match = $this->matches->latestActive();
        if($match === null){
            throw new RuntimeException("There is no active match lobby.");
        }
        $this->matches->markReady((string) $match["id"], $sender->getName(), true);
        $players = count($this->matches->players((string) $match["id"]));
        $sender->sendMessage($this->language->get("match-status", [
            "id" => $match["id"],
            "state" => $match["state"],
            "players" => $players,
            "capacity" => $match["capacity"]
        ]) . " §aReady: " . $this->matches->readyCount((string) $match["id"]));
    }

    private function stats(CommandSender $sender) : void{
        if(!$sender instanceof Player){
            throw new RuntimeException("Players only.");
        }
        $country = $this->countries->byPlayer($sender->getName());
        if($country === null){
            $sender->sendMessage($this->language->get("not-in-country"));
            return;
        }
        $sender->sendMessage("§a" . $country["name"] . " §7[" . $country["tag"] . "] §fScore: " . $country["score"]);
        $balance = $this->economy->balance((string) $country["id"]);
        $sender->sendMessage("§7Gold " . $balance["gold"] . " | Food " . $balance["food"] . " | Research " . $balance["research"]);
    }

    /** @param string[] $args */
    private function admin(CommandSender $sender, array $args) : void{
        $action = strtolower($args[0] ?? "status");
        $permission = match($action){
            "setup" => "openfront.admin.setup",
            "start", "pause", "resume", "stop" => "openfront.admin.match",
            "reload" => "openfront.admin.reload",
            "debug" => "openfront.admin.debug",
            default => "openfront.admin.status"
        };
        if(!$sender->hasPermission("openfront.admin") && !$sender->hasPermission($permission)){
            throw new RuntimeException($this->language->get("no-permission"));
        }
        if($action === "status"){
            $match = $this->matches->latestActive();
            $mapStatus = $this->terrainRenderer->isReady() ? "§aReady" : ($this->terrainRenderer->getFailure() !== null ? "§cFailed: " . $this->terrainRenderer->getFailure() : "§eGenerating");
            $sender->sendMessage($this->language->get("admin-status") . " §7Map: " . $mapStatus);
            if($match !== null){
                $sender->sendMessage($this->language->get("match-status", [
                    "id" => $match["id"],
                    "state" => $match["state"],
                    "players" => count($this->matches->players((string) $match["id"])),
                    "capacity" => $match["capacity"]
                ]));
            }
            return;
        }
        if($action === "reload"){
            $this->plugin->reloadConfig();
            $sender->sendMessage("§aMain configuration file reloaded. Restart is required for services that cache settings.");
            return;
        }
        if($action === "setup"){
            $mapStatus = $this->terrainRenderer->isReady() ? "§aReady" : ($this->terrainRenderer->getFailure() !== null ? "§cFailed: " . $this->terrainRenderer->getFailure() : "§eGenerating");
            $sender->sendMessage("§aManaged worlds are loaded: " . $this->worlds->getLobby()->getFolderName() . ", " . $this->worlds->getMatchWorld()->getFolderName() . " §7Map: " . $mapStatus);
            return;
        }
        if($action === "start"){
            $match = $this->matches->latestWaiting();
            if($match === null){
                throw new RuntimeException("No waiting match exists. Players can create one with /openfront join.");
            }
            $id = (string) $match["id"];
            $minimumPlayers = max(1, min((int) $match["capacity"], (int) $this->plugin->getConfig()->getNested("match.minimum-players", 2)));
            if(count($this->matches->players($id)) < $minimumPlayers || $this->matches->readyCount($id) < $minimumPlayers){
                throw new RuntimeException("At least " . $minimumPlayers . " players must join and ready up before starting.");
            }
            $this->matches->transition($id, MatchState::COUNTDOWN);
            $sender->sendMessage("§aMatch countdown started.");
            $seconds = max(1, min(120, (int) $this->plugin->getConfig()->getNested("match.countdown-seconds", 20)));
            $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($id) : void{
                try{
                    $current = $this->matches->get($id);
                    if($current !== null && $current["state"] === MatchState::COUNTDOWN->value){
                        $this->matches->transition($id, MatchState::RUNNING);
                    }
                }catch(Throwable $e){
                    $this->plugin->getLogger()->warning("Could not start match " . $id . ": " . $e->getMessage());
                }
            }), $seconds * 20);
            return;
        }
        if($action === "pause" || $action === "resume" || $action === "stop"){
            $match = $this->matches->latestActive();
            if($match === null){
                throw new RuntimeException("No active match exists.");
            }
            if($action === "pause" && $match["state"] !== MatchState::RUNNING->value){
                throw new RuntimeException("Only a running match can be paused.");
            }
            if($action === "resume" && $match["state"] !== MatchState::PAUSED->value){
                throw new RuntimeException("Only a paused match can be resumed.");
            }
            $state = match($action){
                "pause" => MatchState::PAUSED,
                "resume" => MatchState::RUNNING,
                default => MatchState::FINISHED
            };
            $this->matches->transition((string) $match["id"], $state);
            if($action === "stop"){
                foreach($this->worlds->getMatchWorld()->getPlayers() as $player){
                    $player->teleport($this->worlds->getLobby()->getSpawnLocation());
                }
            }
            $sender->sendMessage("§aMatch state updated: " . $state->value);
            return;
        }
        if($action === "debug"){
            $sender->sendMessage("§7Territory records: " . count($this->territories->all()));
            $sender->sendMessage("§7Map metadata: " . json_encode($this->territories->metadata()));
            return;
        }
        throw new RuntimeException("Admin commands: setup, start, stop, pause, resume, reload, debug, status.");
    }

    private function requirePermission(CommandSender $sender, string $permission) : void{
        if(!$sender->hasPermission($permission)){
            throw new RuntimeException($this->language->get("no-permission"));
        }
    }

    /** @return array<string, mixed> */
    private function requireCountry(Player $player) : array{
        $country = $this->countries->byPlayer($player->getName());
        if($country === null){
            throw new RuntimeException($this->language->get("not-in-country"));
        }
        return $country;
    }
}
