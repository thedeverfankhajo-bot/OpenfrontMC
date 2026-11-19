<?php

declare(strict_types=1);

namespace OpenFrontMC\Listener;

use OpenFrontMC\World\WorldManager;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;

final class WorldProtectionListener implements Listener{

    public function __construct(private WorldManager $worlds){}

    public function onBlockBreak(BlockBreakEvent $event) : void{
        $player = $event->getPlayer();
        if($this->worlds->isManaged($player->getWorld()) && !$player->hasPermission("openfront.admin.build")){
            $event->cancel();
            $player->sendMessage("§cThis world is protected.");
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event) : void{
        $player = $event->getPlayer();
        if($this->worlds->isManaged($player->getWorld()) && !$player->hasPermission("openfront.admin.build")){
            $event->cancel();
            $player->sendMessage("§cThis world is protected.");
        }
    }
}
