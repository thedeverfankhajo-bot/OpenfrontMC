<?php
declare(strict_types=1);
namespace OpenFrontMC\Listener;
use OpenFrontMC\World\WorldManager;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\Listener;
final class WorldProtectionListener implements Listener{
    public function __construct(private WorldManager $worlds){}
    public function onBlockBreak(BlockBreakEvent $event) : void{
        if($this->worlds->isManaged($event->getBlock()->getPosition()->getWorld()) && !$event->getPlayer()->hasPermission("openfront.admin.build")){
            $event->cancel(); $event->getPlayer()->sendMessage("§cThis world is protected.");
        }
    }
    public function onBlockPlace(BlockPlaceEvent $event) : void{
        if($this->worlds->isManaged($event->getBlock()->getPosition()->getWorld()) && !$event->getPlayer()->hasPermission("openfront.admin.build")){
            $event->cancel(); $event->getPlayer()->sendMessage("§cThis world is protected.");
        }
    }
}
