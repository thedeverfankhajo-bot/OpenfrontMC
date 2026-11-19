<?php

declare(strict_types=1);

namespace OpenFrontMC\Economy;

use Closure;
use OpenFrontMC\Storage\Database;
use RuntimeException;

final class EconomyManager{

    /** @param array<string, mixed> $config */
    public function __construct(private Database $database, private array $config){}

    /** @return array<string, int> */
    public function balance(string $countryId) : array{
        $row = $this->database->fetchOne("SELECT * FROM resources WHERE country_id=?", [$countryId]);
        if($row === null){
            throw new RuntimeException("Country resources were not found.");
        }
        $out = [];
        foreach(EconomyRules::RESOURCES as $resource){
            $out[$resource] = (int) $row[$resource];
        }
        return $out;
    }

    /** @param array<string, int|float|string> $changes */
    public function change(string $countryId, string $actor, string $reason, array $changes) : void{
        $changes = EconomyRules::normalizeDelta($changes);
        $capacity = max(1, min(1000000000, (int) ($this->config["capacity"] ?? 100000)));
        $this->database->transaction(function() use($countryId, $actor, $reason, $changes, $capacity) : void{
            $row = $this->database->fetchOne("SELECT * FROM resources WHERE country_id=?", [$countryId]);
            if($row === null){
                throw new RuntimeException("Country resources were not found.");
            }
            $next = EconomyRules::apply($row, $changes, $capacity);
            $this->writeBalances($countryId, $actor, $reason, $changes, $next);
        });
    }

    /**
     * Applies a debit and a related domain mutation in the same SQLite transaction.
     * The callback must only perform synchronous database work.
     *
     * @param array<string, int|float|string> $cost
     */
    public function applyAtomic(string $countryId, string $actor, string $reason, array $cost, Closure $callback) : void{
        $cost = EconomyRules::normalizeAmounts($cost);
        if($cost === []){
            throw new RuntimeException("A cost must contain at least one resource.");
        }
        $capacity = max(1, min(1000000000, (int) ($this->config["capacity"] ?? 100000)));
        $this->database->transaction(function() use($countryId, $actor, $reason, $cost, $callback, $capacity) : void{
            $row = $this->database->fetchOne("SELECT * FROM resources WHERE country_id=?", [$countryId]);
            if($row === null){
                throw new RuntimeException("Country resources were not found.");
            }
            $debit = [];
            foreach($cost as $resource => $amount){
                $debit[$resource] = -$amount;
            }
            $next = EconomyRules::apply($row, $debit, $capacity);
            $this->writeBalances($countryId, $actor, $reason, $debit, $next);
            $callback();
        });
    }

    public function accrueIncome() : void{
        $income = $this->config["income_per_period"] ?? [];
        if(!is_array($income) || $income === []){
            return;
        }
        $changes = EconomyRules::normalizeDelta($income);
        foreach($this->database->fetchAll("SELECT country_id FROM resources ORDER BY country_id") as $row){
            $this->change((string) $row["country_id"], "SYSTEM", "PERIODIC_INCOME", $changes);
        }
    }

    /**
     * @param array<string, int> $changes
     * @param array<string, int> $next
     */
    private function writeBalances(string $countryId, string $actor, string $reason, array $changes, array $next) : void{
        if(preg_match('/^[A-Z0-9_:-]{1,64}$/i', $reason) !== 1 || $actor === "" || strlen($actor) > 64){
            throw new RuntimeException("Invalid economy transaction metadata.");
        }
        $set = implode(",", array_map(static fn(string $key) : string => $key . "=?", EconomyRules::RESOURCES));
        $values = array_values($next);
        $values[] = time();
        $values[] = $countryId;
        $this->database->execute("UPDATE resources SET " . $set . ",updated_at=? WHERE country_id=?", $values);
        $this->database->execute(
            "INSERT INTO economy_transactions(country_id,actor_name,reason,changes_json,created_at) VALUES(?,?,?,?,?)",
            [$countryId, $actor, $reason, json_encode($changes, JSON_THROW_ON_ERROR), time()]
        );
    }
}
