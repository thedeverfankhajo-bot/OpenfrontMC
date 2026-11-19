<?php
declare(strict_types=1);
namespace OpenFrontMC\World;
use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;
use RuntimeException;
final class WorldManager{
    private const MATCH_WORLD="openfront_world";
    private const LOBBY_WORLD="openfront_lobby";
    /** @var array<string,World> */
    private array $managed=[];
    public function __construct(private PluginBase $plugin){}
    public function loadManagedWorlds():void{
        $manager=$this->plugin->getServer()->getWorldManager();
        $seed=(int)$this->plugin->getConfig()->getNested("map.seed",20261119);
        foreach([self::LOBBY_WORLD,self::MATCH_WORLD] as $name){
            if(!$manager->isWorldGenerated($name)){
                $options=WorldCreationOptions::create()->setSeed($seed);
                if(!$manager->generateWorld($name,$options,true)){throw new RuntimeException("Could not generate ".$name);}
            }elseif(!$manager->isWorldLoaded($name)&&!$manager->loadWorld($name)){throw new RuntimeException("Could not load ".$name);}
            $world=$manager->getWorldByName($name);
            if($world===null){throw new RuntimeException("Managed world unavailable: ".$name);}
            $this->managed[$name]=$world;
        }
    }
    public function isManaged(World $world):bool{return isset($this->managed[$world->getFolderName()]);}
    public function getLobby():World{return $this->managed[self::LOBBY_WORLD];}
    public function getMatchWorld():World{return $this->managed[self::MATCH_WORLD];}
}
