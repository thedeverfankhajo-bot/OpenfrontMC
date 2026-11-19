<?php

declare(strict_types=1);

namespace OpenFrontMC\Listener;

use OpenFrontMC\Match\MatchManager;
use OpenFrontMC\World\WorldManager;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerQuitEvent;

final class PlayerSessionListener implements Listener{

    public function __construct(
        private MatchManager $matches,
        private WorldManager $worlds
    ){}

    public function onPlayerQuit(PlayerQuitEvent $event) : void{
        $this->matches->leave($event->getPlayer()->getName());
    }

    public function onPlayerDropItem(PlayerDropItemEvent $event) : void{
        $player = $event->getPlayer();
        if($this->worlds->isManaged($player->getWorld()) && !$player->hasPermission("openfront.admin.build")){
            $event->cancel();
        }
    }
}
