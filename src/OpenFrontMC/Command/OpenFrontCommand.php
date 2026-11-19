<?php
declare(strict_types=1);
namespace OpenFrontMC\Command;
use OpenFrontMC\Country\CountryManager;
use OpenFrontMC\Economy\EconomyManager;
use OpenFrontMC\Match\MatchManager;
use OpenFrontMC\Territory\TerritoryManager;
use OpenFrontMC\World\WorldManager;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use Throwable;
final class OpenFrontCommand{
    public function __construct(private PluginBase $plugin,private CountryManager $countries,private TerritoryManager $territories,private EconomyManager $economy,private MatchManager $matches,private WorldManager $worlds){}
    /** @param string[] $args */
    public function execute(CommandSender $sender,array $args):void{
        $sub=strtolower($args[0]??"help");
        try{
            switch($sub){
                case "help":$sender->sendMessage("§6OpenFrontMC §7| country create <name> <TAG>, map, economy, join, leave, stats, top, admin");break;
                case "country":$this->country($sender,array_slice($args,1));break;
                case "map":$sender->sendMessage("§aTerritory records: ".count($this->territories->all()));break;
                case "economy":$this->economyInfo($sender);break;
                case "join":$this->join($sender);break;
                case "leave":if($sender instanceof Player){$this->matches->leave($sender->getName());$sender->teleport($this->worlds->getLobby()->getSpawnLocation());$sender->sendMessage("§aYou left the match.");}break;
                case "stats":$country=$sender instanceof Player?$this->countries->byPlayer($sender->getName()):null;$sender->sendMessage("§7Country: ".($country["name"]??"None"));break;
                case "top":foreach($this->countries->top() as $i=>$country){$sender->sendMessage("§e".($i+1).". §f".$country["name"]." §7(".$country["score"].")");}break;
                case "admin":$this->admin($sender,array_slice($args,1));break;
                case "ready":case "diplomacy":case "research":$sender->sendMessage("§eThis feature is not enabled in this MVP release.");break;
                default:$sender->sendMessage("§cUnknown subcommand. Use /openfront help.");
            }
        }catch(Throwable $e){$sender->sendMessage("§cOpenFrontMC: ".$e->getMessage());}
    }
    /** @param string[] $args */
    private function country(CommandSender $sender,array $args):void{
        if(!$sender instanceof Player){$sender->sendMessage("Players only.");return;}
        if(($args[0]??"")==="create"&&isset($args[1],$args[2])){$this->countries->create($sender->getName(),$args[1],strtoupper($args[2]));$sender->sendMessage("§aCountry created.");return;}
        $country=$this->countries->byPlayer($sender->getName());
        $sender->sendMessage($country===null?"§eNo country. Use /openfront country create <name> <TAG>.":"§a".$country["name"]." §7[".$country["tag"]."] §fScore: ".$country["score"]);
    }
    private function economyInfo(CommandSender $sender):void{
        if(!$sender instanceof Player){$sender->sendMessage("Players only.");return;}
        $country=$this->countries->byPlayer($sender->getName());if($country===null){$sender->sendMessage("§cCreate a country first.");return;}
        foreach($this->economy->balance((string)$country["id"]) as $resource=>$amount){$sender->sendMessage("§e".ucfirst($resource).": §f".$amount);}
    }
    private function join(CommandSender $sender):void{
        if(!$sender instanceof Player){$sender->sendMessage("Players only.");return;}
        $match=$this->matches->latestWaiting();if($match===null){$id=$this->matches->create("FFA",(int)$this->plugin->getConfig()->getNested("match.capacity",16));$match=["id"=>$id];}
        $this->matches->join((string)$match["id"],$sender->getName());$sender->teleport($this->worlds->getMatchWorld()->getSpawnLocation());$sender->sendMessage("§aJoined match ".$match["id"]);
    }
    /** @param string[] $args */
    private function admin(CommandSender $sender,array $args):void{
        if(!$sender->hasPermission("openfront.admin")){$sender->sendMessage("§cNo permission.");return;}
        $action=strtolower($args[0]??"status");
        if($action==="status"){$sender->sendMessage("§aOpenFrontMC enabled. Worlds: openfront_lobby, openfront_world.");}
        elseif($action==="reload"){$this->plugin->reloadConfig();$sender->sendMessage("§aConfiguration reloaded.");}
        else{$sender->sendMessage("§eAvailable in this build: status, reload.");}
    }
}
