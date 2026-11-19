<?php

declare(strict_types=1);

namespace OpenFrontMC\Listener;

use OpenFrontMC\Match\MatchManager;
use OpenFrontMC\World\WorldManager;
use pocketmine\event\Listener;
use pocketmine\event\inventory\InventoryOpenEvent;
use pocketmine\event\player\PlayerBucketEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;

final class PlayerSessionListener implements Listener{

    public function __construct(
        private MatchManager $matches,
        private WorldManager $worlds
    ){}

    public function onPlayerJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $match = $this->matches->forPlayer($player->getName());
        if($match !== null){
            $player->teleport($this->worlds->getMatchWorld()->getSpawnLocation());
        }
    }

    public function onPlayerDropItem(PlayerDropItemEvent $event) : void{
        $player = $event->getPlayer();
        if($this->worlds->isManaged($player->getWorld()) && !$player->hasPermission("openfront.admin.build")){
            $event->cancel();
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event) : void{
        $player = $event->getPlayer();
        if($this->worlds->isManaged($player->getWorld()) && !$player->hasPermission("openfront.admin.build")){
            $event->cancel();
        }
    }

    public function onInventoryOpen(InventoryOpenEvent $event) : void{
        $player = $event->getPlayer();
        if($this->worlds->isManaged($player->getWorld()) && !$player->hasPermission("openfront.admin.build")){
            $event->cancel();
        }
    }

    public function onBucket(PlayerBucketEvent $event) : void{
        $player = $event->getPlayer();
        if($this->worlds->isManaged($player->getWorld()) && !$player->hasPermission("openfront.admin.build")){
            $event->cancel();
        }
    }
}
