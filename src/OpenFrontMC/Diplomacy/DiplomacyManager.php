<?php

declare(strict_types=1);

namespace OpenFrontMC\Diplomacy;

use OpenFrontMC\Storage\Database;
use RuntimeException;

final class DiplomacyManager{

    private const TYPES = ["ALLIANCE", "TRADE", "NON_AGGRESSION", "PEACE"];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private Database $database, private array $config = []){}

    public function offer(string $fromCountryId, string $targetTag, string $type, string $actor) : string{
        $targetTag = strtoupper(trim($targetTag));
        $type = strtoupper(trim($type));
        if(preg_match('/^[A-Z0-9]{2,4}$/', $targetTag) !== 1 || !in_array($type, self::TYPES, true)){
            throw new RuntimeException("Use a valid country tag and treaty type.");
        }

        $target = $this->database->fetchOne("SELECT id FROM countries WHERE tag=?", [$targetTag]);
        if($target === null){
            throw new RuntimeException("That country does not exist.");
        }
        $toCountryId = (string) $target["id"];
        if($toCountryId === $fromCountryId){
            throw new RuntimeException("You cannot make an agreement with your own country.");
        }

        $id = bin2hex(random_bytes(8));
        $now = time();
        $requestExpiry = max(300, min(604800, (int) ($this->config["request-expiry-seconds"] ?? 86400)));
        $maxPerHour = max(1, min(20, (int) ($this->config["max-pending-per-hour"] ?? 4)));

        $this->database->transaction(function() use($fromCountryId, $toCountryId, $type, $actor, $id, $now, $requestExpiry, $maxPerHour) : void{
            if($this->database->fetchOne(
                "SELECT player_name FROM country_members WHERE country_id=? AND player_name=?",
                [$fromCountryId, $actor]
            ) === null){
                throw new RuntimeException("You are not a member of the proposing country.");
            }

            $pending = $this->database->fetchOne(
                "SELECT id FROM treaties WHERE from_country_id=? AND to_country_id=? AND type=? AND status='PENDING' AND expires_at>?",
                [$fromCountryId, $toCountryId, $type, $now]
            );
            if($pending !== null){
                throw new RuntimeException("An identical request is already pending.");
            }

            $recent = $this->database->fetchOne(
                "SELECT COUNT(*) AS amount FROM treaties WHERE from_country_id=? AND created_at>=?",
                [$fromCountryId, $now - 3600]
            );
            if((int) ($recent["amount"] ?? 0) >= $maxPerHour){
                throw new RuntimeException("Your country has reached the diplomacy request limit.");
            }

            $this->database->execute(
                "INSERT INTO treaties(id,from_country_id,to_country_id,type,status,expires_at,created_at,updated_at) VALUES(?,?,?,?,'PENDING',?,?,?)",
                [$id, $fromCountryId, $toCountryId, $type, $now + $requestExpiry, $now, $now]
            );
            $this->record($actor, "TREATY_OFFERED", $id, ["from" => $fromCountryId, "to" => $toCountryId, "type" => $type], $now);
        });

        return $id;
    }

    public function accept(string $countryId, string $actor, string $treatyId) : void{
        $this->respond($countryId, $actor, $treatyId, "ACTIVE");
    }

    public function reject(string $countryId, string $actor, string $treatyId) : void{
        $this->respond($countryId, $actor, $treatyId, "REJECTED");
    }

    public function end(string $countryId, string $actor, string $treatyId) : void{
        $this->database->transaction(function() use($countryId, $actor, $treatyId) : void{
            $treaty = $this->database->fetchOne(
                "SELECT * FROM treaties WHERE id=? AND (from_country_id=? OR to_country_id=?)",
                [$treatyId, $countryId, $countryId]
            );
            if($treaty === null || $treaty["status"] !== "ACTIVE" || ($treaty["expires_at"] !== null && (int) $treaty["expires_at"] <= time())){
                throw new RuntimeException("Active agreement not found.");
            }
            $now = time();
            $this->database->execute("UPDATE treaties SET status='ENDED',expires_at=NULL,updated_at=? WHERE id=?", [$now, $treatyId]);
            $this->record($actor, "TREATY_ENDED", $treatyId, ["country" => $countryId], $now);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forCountry(string $countryId) : array{
        return $this->database->fetchAll(
            "SELECT t.*, a.name AS from_name, b.name AS to_name FROM treaties t INNER JOIN countries a ON a.id=t.from_country_id INNER JOIN countries b ON b.id=t.to_country_id WHERE t.from_country_id=? OR t.to_country_id=? ORDER BY t.created_at DESC LIMIT 50",
            [$countryId, $countryId]
        );
    }

    public function expire() : void{
        $now = time();
        $expired = $this->database->fetchAll(
            "SELECT id,from_country_id,to_country_id,type FROM treaties WHERE status IN ('PENDING','ACTIVE') AND expires_at IS NOT NULL AND expires_at<=?",
            [$now]
        );
        if($expired === []){
            return;
        }
        $this->database->transaction(function() use($expired, $now) : void{
            foreach($expired as $treaty){
                $this->database->execute(
                    "UPDATE treaties SET status='EXPIRED',updated_at=? WHERE id=? AND status IN ('PENDING','ACTIVE')",
                    [$now, $treaty["id"]]
                );
                $this->record("SYSTEM", "TREATY_EXPIRED", (string) $treaty["id"], ["from" => $treaty["from_country_id"], "to" => $treaty["to_country_id"], "type" => $treaty["type"]], $now);
            }
        });
    }

    private function respond(string $countryId, string $actor, string $treatyId, string $status) : void{
        $this->database->transaction(function() use($countryId, $actor, $treatyId, $status) : void{
            $treaty = $this->database->fetchOne(
                "SELECT * FROM treaties WHERE id=? AND to_country_id=?",
                [$treatyId, $countryId]
            );
            if($treaty === null || $treaty["status"] !== "PENDING"){
                throw new RuntimeException("Pending agreement not found for your country.");
            }

            $now = time();
            if($treaty["expires_at"] !== null && (int) $treaty["expires_at"] <= $now){
                throw new RuntimeException("This request has expired.");
            }

            $expiry = null;
            if($status === "ACTIVE"){
                $durations = $this->config["treaty-duration-seconds"] ?? [];
                $duration = is_array($durations) ? max(0, min(2592000, (int) ($durations[$treaty["type"]] ?? 0))) : 0;
                $expiry = $duration > 0 ? $now + $duration : null;
            }
            $this->database->execute(
                "UPDATE treaties SET status=?,expires_at=?,updated_at=? WHERE id=? AND status='PENDING'",
                [$status, $expiry, $now, $treatyId]
            );
            $this->record($actor, "TREATY_" . $status, $treatyId, ["country" => $countryId], $now);
        });
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(string $actor, string $action, string $subject, array $details, int $time) : void{
        $this->database->execute(
            "INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES(?,?,?,?,?)",
            [$actor, $action, $subject, json_encode($details, JSON_THROW_ON_ERROR), $time]
        );
    }
}
