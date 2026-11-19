<?php

declare(strict_types=1);

namespace OpenFrontMC\World;

use pocketmine\plugin\PluginBase;
use pocketmine\world\World;
use pocketmine\world\WorldCreationOptions;
use RuntimeException;

final class WorldManager{

    /** @var array<string, World> */
    private array $managed = [];

    private string $lobbyName;
    private string $matchName;

    public function __construct(private PluginBase $plugin){
        $lobby = $plugin->getConfig()->getNested("world.lobby", "openfront_lobby");
        $match = $plugin->getConfig()->getNested("world.match", "openfront_world");
        if(!is_string($lobby) || !is_string($match) || $lobby === $match ||
            preg_match('/^[a-zA-Z0-9_-]{1,48}$/', $lobby) !== 1 ||
            preg_match('/^[a-zA-Z0-9_-]{1,48}$/', $match) !== 1){
            throw new RuntimeException("Managed world names are invalid.");
        }
        $this->lobbyName = $lobby;
        $this->matchName = $match;
    }

    public function loadManagedWorlds() : void{
        $manager = $this->plugin->getServer()->getWorldManager();
        $seed = (int) $this->plugin->getConfig()->getNested("map.seed", 20261119);
        foreach([$this->lobbyName, $this->matchName] as $name){
            if(!$manager->isWorldGenerated($name)){
                $options = WorldCreationOptions::create()->setSeed($seed);
                if(!$manager->generateWorld($name, $options, true)){
                    throw new RuntimeException("Could not generate managed world " . $name . ".");
                }
            }elseif(!$manager->isWorldLoaded($name) && !$manager->loadWorld($name)){
                throw new RuntimeException("Could not load managed world " . $name . ".");
            }

            $world = $manager->getWorldByName($name);
            if($world === null){
                throw new RuntimeException("Managed world is unavailable: " . $name . ".");
            }
            $this->managed[$world->getFolderName()] = $world;
        }
    }

    public function isManaged(World $world) : bool{
        return isset($this->managed[$world->getFolderName()]);
    }

    public function getLobby() : World{
        return $this->managed[$this->lobbyName];
    }

    public function getMatchWorld() : World{
        return $this->managed[$this->matchName];
    }
}
