<?php
declare(strict_types=1);
namespace OpenFrontMC\Economy;
use OpenFrontMC\Storage\Database;
use RuntimeException;
final class EconomyManager{
    /** @param array<string,mixed> $config */
    public function __construct(private Database $database,private array $config){}
    /** @return array<string,int> */
    public function balance(string $countryId) : array{
        $row=$this->database->fetchOne("SELECT * FROM resources WHERE country_id=?",[$countryId]);
        if($row===null){throw new RuntimeException("Country resources were not found.");}
        $out=[]; foreach(EconomyRules::RESOURCES as $r){$out[$r]=(int)$row[$r];} return $out;
    }
    /** @param array<string,int> $changes */
    public function change(string $countryId,string $actor,string $reason,array $changes) : void{
        $capacity=(int)($this->config["capacity"]??100000);
        $this->database->transaction(function() use($countryId,$actor,$reason,$changes,$capacity) : void{
            $row=$this->database->fetchOne("SELECT * FROM resources WHERE country_id=?",[$countryId]);
            if($row===null){throw new RuntimeException("Country resources were not found.");}
            $next=EconomyRules::apply($row,$changes,$capacity);
            $set=implode(",",array_map(static fn(string $key):string=>$key."=?",EconomyRules::RESOURCES));
            $values=array_values($next); $values[]=time(); $values[]=$countryId;
            $this->database->execute("UPDATE resources SET ".$set.",updated_at=? WHERE country_id=?",$values);
            $this->database->execute("INSERT INTO economy_transactions(country_id,actor_name,reason,changes_json,created_at) VALUES(?,?,?,?,?)",[$countryId,$actor,$reason,json_encode($changes,JSON_THROW_ON_ERROR),time()]);
        });
    }
    public function accrueIncome() : void{
        $income=$this->config["income_per_period"]??[];
        foreach($this->database->fetchAll("SELECT country_id FROM resources") as $row){$this->change((string)$row["country_id"],"SYSTEM","PERIODIC_INCOME",is_array($income)?$income:[]);}
    }
}
