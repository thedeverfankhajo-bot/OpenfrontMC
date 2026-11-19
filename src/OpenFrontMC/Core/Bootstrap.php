<?php

declare(strict_types=1);

namespace OpenFrontMC\Core;

use OpenFrontMC\Building\BuildingManager;
use OpenFrontMC\Command\OpenFrontCommand;
use OpenFrontMC\Country\CountryManager;
use OpenFrontMC\Diplomacy\DiplomacyManager;
use OpenFrontMC\Economy\EconomyManager;
use OpenFrontMC\Language\LanguageManager;
use OpenFrontMC\Listener\PlayerSessionListener;
use OpenFrontMC\Listener\WorldProtectionListener;
use OpenFrontMC\Match\MatchManager;
use OpenFrontMC\Match\VictoryManager;
use OpenFrontMC\Research\ResearchManager;
use OpenFrontMC\Research\TechnologyTree;
use OpenFrontMC\Storage\Database;
use OpenFrontMC\Territory\TerritoryManager;
use OpenFrontMC\World\MapGenerator;
use OpenFrontMC\World\TerrainRenderer;
use OpenFrontMC\World\WorldManager;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use Throwable;

final class Bootstrap{

    private Database $database;
    private OpenFrontCommand $command;
    private ?TerrainRenderer $terrainRenderer = null;

    public function __construct(private PluginBase $plugin){}

    public function start() : void{
        $this->plugin->saveDefaultConfig();
        foreach(["buildings/buildings.yml", "research/technology.yml", "economy.yml", "languages/en_US.yml", "languages/fa_IR.yml", "maps/default.yml"] as $resource){
            $this->plugin->saveResource($resource);
        }

        $this->database = new Database($this->plugin->getDataFolder() . "openfront.sqlite3");
        $this->database->migrate();

        $worlds = new WorldManager($this->plugin);
        $worlds->loadManagedWorlds();

        $territories = new TerritoryManager($this->database);
        if($this->database->fetchOne("SELECT id FROM map_metadata WHERE id='default'") === null){
            $map = $this->plugin->getConfig()->get("map", []);
            if(!is_array($map)){
                throw new \RuntimeException("Map configuration must be a mapping.");
            }
            $seed = (int) ($map["seed"] ?? 20261119);
            $width = (int) ($map["width"] ?? 16);
            $height = (int) ($map["height"] ?? 16);
            $tiles = (new MapGenerator())->generate(
                $seed,
                $width,
                $height,
                (int) ($map["continent-count"] ?? 4),
                (int) ($map["island-count"] ?? 18),
                (float) ($map["water-ratio"] ?? 0.58)
            );
            $territories->seedNeutralMap($seed, $width, $height, $tiles);
        }

        $this->terrainRenderer = new TerrainRenderer($this->plugin, $this->database, $territories);
        $renderInterval = (int) $this->plugin->getConfig()->getNested("performance.map-render-interval-ticks", 4);
        $this->terrainRenderer->start($worlds->getMatchWorld(), $renderInterval);

        $economyConfig = (new Config($this->plugin->getDataFolder() . "economy.yml", Config::YAML))->getAll();
        if(!is_array($economyConfig)){
            throw new \RuntimeException("Economy configuration must be a mapping.");
        }
        $countries = new CountryManager($this->database, is_array($economyConfig["starting"] ?? null) ? $economyConfig["starting"] : []);
        $economy = new EconomyManager($this->database, $economyConfig);
        $matches = new MatchManager($this->database);
        $victoryConfig = $this->plugin->getConfig()->getNested("match.victory", []);
        if(!is_array($victoryConfig)){
            throw new \RuntimeException("Match victory configuration must be a mapping.");
        }
        $victory = new VictoryManager($this->database, $matches, $victoryConfig);

        $buildingConfig = (new Config($this->plugin->getDataFolder() . "buildings/buildings.yml", Config::YAML))->getAll();
        $technologyConfig = (new Config($this->plugin->getDataFolder() . "research/technology.yml", Config::YAML))->getAll();
        $diplomacyConfig = $this->plugin->getConfig()->get("diplomacy", []);
        if(!is_array($diplomacyConfig)){
            throw new \RuntimeException("Diplomacy configuration must be a mapping.");
        }

        $research = new ResearchManager($this->database, $economy, new TechnologyTree($technologyConfig));
        $buildings = new BuildingManager($this->database, $economy, $buildingConfig);
        $diplomacy = new DiplomacyManager($this->database, $diplomacyConfig);
        $locale = (string) $this->plugin->getConfig()->get("language", "en_US");
        $language = new LanguageManager($this->plugin, $locale);

        $this->command = new OpenFrontCommand(
            $this->plugin,
            $countries,
            $territories,
            $economy,
            $matches,
            $worlds,
            $buildings,
            $research,
            $diplomacy,
            $language,
            $this->terrainRenderer
        );

        $pluginManager = $this->plugin->getServer()->getPluginManager();
        $pluginManager->registerEvents(new WorldProtectionListener($worlds), $this->plugin);
        $pluginManager->registerEvents(new PlayerSessionListener($matches, $worlds), $this->plugin);

        $backupIntervalHours = max(1, min(168, (int) $this->plugin->getConfig()->getNested("storage.backup-interval-hours", 6)));
        $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            try{
                $backupDirectory = $this->plugin->getDataFolder() . "backups/";
                $backupPath = $backupDirectory . "openfront-" . date("Ymd-His") . ".sqlite3";
                $this->database->backup($backupPath);

                $backups = glob($backupDirectory . "openfront-*.sqlite3");
                if(is_array($backups)){
                    rsort($backups, SORT_STRING);
                    foreach(array_slice($backups, 7) as $obsolete){
                        if(is_file($obsolete) && !is_link($obsolete)){
                            @unlink($obsolete);
                        }
                    }
                }
                $this->plugin->getLogger()->debug("OpenFrontMC database backup completed.");
            }catch(Throwable $e){
                $this->plugin->getLogger()->warning("OpenFrontMC database backup failed: " . $e->getMessage());
            }
        }), $backupIntervalHours * 3600 * 20);

        $victoryInterval = max(1, min(60, (int) $this->plugin->getConfig()->getNested("match.victory.evaluation-interval-seconds", 5)));
        $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use($victory, $worlds) : void{
            try{
                $result = $victory->evaluateActive();
                if($result === null){
                    return;
                }
                foreach($this->plugin->getServer()->getOnlinePlayers() as $player){
                    if(in_array($player->getName(), $result["players"], true)){
                        $player->sendMessage("§aMatch finished! Winner: §f" . $result["winner_name"] . " §7[" . $result["winner_tag"] . "] §8(" . $result["reason"] . ")");
                        $player->teleport($worlds->getLobby()->getSpawnLocation());
                    }
                }
                $this->plugin->getLogger()->notice("Match " . $result["match_id"] . " finished; winner " . $result["winner_tag"] . " by " . $result["reason"] . ".");
            }catch(Throwable $e){
                $this->plugin->getLogger()->warning("Match victory evaluation failed: " . $e->getMessage());
            }
        }), $victoryInterval * 20);

        $periodSeconds = max(30, min(3600, (int) ($economyConfig["period-seconds"] ?? 120)));
        $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() use($economy, $buildings, $research, $diplomacy) : void{
            try{
                $economy->accrueIncome();
                foreach($this->database->fetchAll("SELECT id FROM countries ORDER BY id") as $country){
                    $countryId = (string) $country["id"];
                    $production = $buildings->production($countryId, $research->productionMultipliers($countryId));
                    if($production !== []){
                        $economy->change($countryId, "SYSTEM", "BUILDING_PRODUCTION", $production);
                    }
                }
                $diplomacy->expire();
            }catch(Throwable $e){
                $this->plugin->getLogger()->warning("Scheduled game update failed: " . $e->getMessage());
            }
        }), $periodSeconds * 20);
    }

    public function getCommand() : OpenFrontCommand{
        return $this->command;
    }

    public function shutdown() : void{
        $this->terrainRenderer?->shutdown();
        if(isset($this->database)){
            $this->database->close();
        }
    }
}
