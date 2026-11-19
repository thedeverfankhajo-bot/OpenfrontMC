<?php
declare(strict_types=1);
namespace OpenFrontMC\Territory;
use OpenFrontMC\Storage\Database;
use RuntimeException;
final class TerritoryManager{
    public function __construct(private Database $database){}
    /** @param list<array{x:int,z:int,terrain:string,special:string,resources:array<string,int>}> $territories */
    public function seedNeutralMap(int $seed,int $width,int $height,array $territories) : void{
        $this->database->transaction(function() use($seed,$width,$height,$territories) : void{
            $this->database->execute("INSERT OR REPLACE INTO map_metadata(id,seed,width,height,territory_size,rendered,generated_at) VALUES('default',?,?,?,16,0,?)",[$seed,$width,$height,time()]);
            $this->database->execute("DELETE FROM territories");
            foreach($territories as $t){ $this->database->execute("INSERT INTO territories(id,x,z,terrain,special,status,resources_json,updated_at) VALUES(?,?,?,?,?,'NEUTRAL',?,?)",[$t["x"].":".$t["z"],$t["x"],$t["z"],$t["terrain"],$t["special"],json_encode($t["resources"],JSON_THROW_ON_ERROR),time()]); }
        });
    }
    public function claim(string $player,string $countryId,int $x,int $z,int $cost) : void{
        $this->database->transaction(function() use($player,$countryId,$x,$z,$cost) : void{
            if($this->database->fetchOne("SELECT role FROM country_members WHERE player_name=? AND country_id=?",[$player,$countryId])===null){ throw new RuntimeException("You are not a member of this country."); }
            $target=$this->database->fetchOne("SELECT * FROM territories WHERE x=? AND z=?",[$x,$z]);
            if($target===null || $target["terrain"]==="WATER" || $target["owner_country_id"]!==null){ throw new RuntimeException("This territory cannot be claimed."); }
            $owned=$this->database->fetchOne("SELECT COUNT(*) AS c FROM territories WHERE owner_country_id=?",[$countryId]);
            $adjacent=$this->database->fetchOne("SELECT id FROM territories WHERE owner_country_id=? AND ((x=? AND ABS(z-?)=1) OR (z=? AND ABS(x-?)=1)) LIMIT 1",[$countryId,$x,$z,$z,$x]);
            if((int)$owned["c"]>0 && $adjacent===null){ throw new RuntimeException("You may only claim adjacent territory."); }
            $resources=$this->database->fetchOne("SELECT gold FROM resources WHERE country_id=?",[$countryId]);
            if($resources===null || (int)$resources["gold"]<$cost){ throw new RuntimeException("Not enough gold."); }
            $this->database->execute("UPDATE resources SET gold=gold-?,updated_at=? WHERE country_id=?",[$cost,time(),$countryId]);
            $this->database->execute("UPDATE territories SET owner_country_id=?,status='OWNED',updated_at=? WHERE id=? AND owner_country_id IS NULL",[$countryId,time(),$target["id"]]);
            $this->database->execute("UPDATE countries SET score=score+10 WHERE id=?",[$countryId]);
            $this->database->execute("INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES(?,?,?,?,?)",[$player,"TERRITORY_CLAIMED",$target["id"],json_encode(["country"=>$countryId],JSON_THROW_ON_ERROR),time()]);
        });
    }
    /** @return list<array<string,mixed>> */
    public function all() : array{ return $this->database->fetchAll("SELECT * FROM territories ORDER BY z,x"); }
}
