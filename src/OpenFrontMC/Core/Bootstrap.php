<?php
declare(strict_types=1);
namespace OpenFrontMC\Core;
use OpenFrontMC\Command\OpenFrontCommand;
use OpenFrontMC\Listener\WorldProtectionListener;
use OpenFrontMC\Storage\Database;
use OpenFrontMC\World\WorldManager;
use OpenFrontMC\World\MapGenerator;
use OpenFrontMC\Country\CountryManager;
use OpenFrontMC\Economy\EconomyManager;
use OpenFrontMC\Territory\TerritoryManager;
use OpenFrontMC\Match\MatchManager;
use pocketmine\plugin\PluginBase;
final class Bootstrap{
    private Database $database;
    private OpenFrontCommand $command;
    public function __construct(private PluginBase $plugin){}
    public function start():void{
        $this->database=new Database($this->plugin->getDataFolder()."openfront.sqlite3");
        $this->database->migrate();
        $worlds=new WorldManager($this->plugin); $worlds->loadManagedWorlds();
        $countries=new CountryManager($this->database);
        $territories=new TerritoryManager($this->database);
        if($this->database->fetchOne("SELECT id FROM map_metadata WHERE id='default'")===null){
            $map=$this->plugin->getConfig()->get("map",[]);
            $seed=(int)($map["seed"]??20261119); $width=(int)($map["width"]??16); $height=(int)($map["height"]??16);
            $tiles=(new MapGenerator())->generate($seed,$width,$height,(int)($map["continent-count"]??4),(int)($map["island-count"]??18),(float)($map["water-ratio"]??0.58));
            $territories->seedNeutralMap($seed,$width,$height,$tiles);
        }
        $economy=new EconomyManager($this->database,$this->plugin->getConfig()->get("economy",[]));
        $matches=new MatchManager($this->database);
        $this->command=new OpenFrontCommand($this->plugin,$countries,$territories,$economy,$matches,$worlds);
        $this->plugin->getServer()->getPluginManager()->registerEvents(new WorldProtectionListener($worlds),$this->plugin);
    }
    public function getCommand():OpenFrontCommand{return $this->command;}
    public function shutdown():void{if(isset($this->database)){$this->database->close();}}
}
