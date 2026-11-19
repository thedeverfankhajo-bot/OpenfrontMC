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
use OpenFrontMC\Research\ResearchManager;
use OpenFrontMC\Research\TechnologyTree;
use OpenFrontMC\Storage\Database;
use OpenFrontMC\Territory\TerritoryManager;
use OpenFrontMC\World\MapGenerator;
use OpenFrontMC\World\WorldManager;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\Config;
use Throwable;

final class Bootstrap{

    private Database $database;
    private OpenFrontCommand $command;

    public function __construct(private PluginBase $plugin){}

    public function start() : void{
        $this->plugin->saveDefaultConfig();
        foreach(["buildings/buildings.yml", "research/technology.yml", "economy.yml", "languages/en_US.yml", "languages/fa_IR.yml"] as $resource){
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

        $economyConfig = (new Config($this->plugin->getDataFolder() . "economy.yml", Config::YAML))->getAll();
        if(!is_array($economyConfig)){
            throw new \RuntimeException("Economy configuration must be a mapping.");
        }
        $countries = new CountryManager($this->database, is_array($economyConfig["starting"] ?? null) ? $economyConfig["starting"] : []);
        $economy = new EconomyManager($this->database, $economyConfig);
        $matches = new MatchManager($this->database);

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
            $language
        );

        $pluginManager = $this->plugin->getServer()->getPluginManager();
        $pluginManager->registerEvents(new WorldProtectionListener($worlds), $this->plugin);
        $pluginManager->registerEvents(new PlayerSessionListener($matches, $worlds), $this->plugin);

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
        if(isset($this->database)){
            $this->database->close();
        }
    }
}
