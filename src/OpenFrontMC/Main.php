<?php

declare(strict_types=1);

namespace OpenFrontMC;

use OpenFrontMC\Core\Bootstrap;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginBase;
use Throwable;

final class Main extends PluginBase{

    private ?Bootstrap $bootstrap = null;

    protected function onEnable() : void{
        if(!extension_loaded("sqlite3")){
            $this->getLogger()->critical("OpenFrontMC requires the PHP SQLite3 extension.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        try{
            $this->saveDefaultConfig();
            $this->bootstrap = new Bootstrap($this);
            $this->bootstrap->start();
            $this->getLogger()->info("OpenFrontMC enabled.");
        }catch(Throwable $e){
            $this->getLogger()->critical("OpenFrontMC could not start: " . $e->getMessage());
            $this->getServer()->getPluginManager()->disablePlugin($this);
        }
    }

    protected function onDisable() : void{
        $this->bootstrap?->shutdown();
        $this->bootstrap = null;
    }

    /**
     * PocketMine-MP API 5 routes manifest-declared plugin commands here.
     *
     * @param string[] $args
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if($command->getName() !== "openfront" || $this->bootstrap === null){
            return false;
        }

        $this->bootstrap->getCommand()->execute($sender, $args);
        return true;
    }
}
