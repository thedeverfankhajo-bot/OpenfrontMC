<?php

declare(strict_types=1);

namespace OpenFrontMC\Country;

use OpenFrontMC\Security\InputValidator;
use OpenFrontMC\Storage\Database;
use RuntimeException;

final class CountryManager{

    /** @param array<string, int> $startingResources */
    public function __construct(private Database $database, private array $startingResources = []){}

    public function create(string $owner, string $name, string $tag) : string{
        $name = trim($name);
        $tag = strtoupper(trim($tag));
        if(!InputValidator::countryName($name) || preg_match('/^[A-Z0-9]{2,4}$/', $tag) !== 1 || !InputValidator::playerName($owner)){
            throw new RuntimeException("Country name, tag or player name is invalid.");
        }
        $id = bin2hex(random_bytes(8));
        $now = time();
        $this->database->transaction(function() use($id, $owner, $name, $tag, $now) : void{
            if($this->database->fetchOne("SELECT player_name FROM country_members WHERE player_name=?", [$owner]) !== null){
                throw new RuntimeException("You are already a member of a country.");
            }
            $this->database->execute(
                "INSERT INTO countries(id,name,tag,color,owner_name,created_at) VALUES(?,?,?,?,?,?)",
                [$id, $name, $tag, "3366CC", $owner, $now]
            );
            $this->database->execute(
                "INSERT INTO country_members(country_id,player_name,role,joined_at) VALUES(?,?,?,?)",
                [$id, $owner, "LEADER", $now]
            );
            $values = [];
            foreach(["gold", "food", "materials", "energy", "research", "influence"] as $resource){
                $amount = $this->startingResources[$resource] ?? 0;
                $values[] = is_int($amount) ? max(0, min(100000000, $amount)) : 0;
            }
            $this->database->execute(
                "INSERT INTO resources(country_id,gold,food,materials,energy,research,influence,updated_at) VALUES(?,?,?,?,?,?,?,?)",
                array_merge([$id], $values, [$now])
            );
            $this->record($owner, "COUNTRY_CREATED", $id, ["name" => $name, "tag" => $tag], $now);
        });
        return $id;
    }

    public function invite(string $actor, string $targetPlayer, int $durationSeconds = 86400) : string{
        if(!InputValidator::playerName($targetPlayer) || $actor === $targetPlayer){
            throw new RuntimeException("A valid different player name is required.");
        }
        $country = $this->byPlayer($actor);
        if($country === null){
            throw new RuntimeException("You are not a member of a country.");
        }
        $countryId = (string) $country["id"];
        $this->assertOfficer($countryId, $actor);
        if($this->byPlayer($targetPlayer) !== null){
            throw new RuntimeException("That player already belongs to a country.");
        }

        $id = bin2hex(random_bytes(8));
        $now = time();
        $expiresAt = $now + max(300, min(604800, $durationSeconds));
        $this->database->transaction(function() use($actor, $targetPlayer, $countryId, $id, $now, $expiresAt) : void{
            if($this->database->fetchOne("SELECT player_name FROM country_members WHERE player_name=?", [$targetPlayer]) !== null){
                throw new RuntimeException("That player already belongs to a country.");
            }
            if($this->database->fetchOne(
                "SELECT id FROM country_invitations WHERE country_id=? AND target_player=? AND status='PENDING' AND expires_at>?",
                [$countryId, $targetPlayer, $now]
            ) !== null){
                throw new RuntimeException("That player already has a pending invitation from your country.");
            }
            $this->database->execute(
                "INSERT INTO country_invitations(id,country_id,target_player,inviter_name,status,created_at,expires_at) VALUES(?,?,?,?,'PENDING',?,?)",
                [$id, $countryId, $targetPlayer, $actor, $now, $expiresAt]
            );
            $this->record($actor, "COUNTRY_INVITATION_SENT", $id, ["country" => $countryId, "target" => $targetPlayer], $now);
        });
        return $id;
    }

    public function acceptInvitation(string $player, string $invitationId) : string{
        if(!InputValidator::playerName($player) || preg_match('/^[a-f0-9]{16}$/', $invitationId) !== 1){
            throw new RuntimeException("Invalid invitation or player name.");
        }
        return $this->database->transaction(function() use($player, $invitationId) : string{
            $invitation = $this->database->fetchOne(
                "SELECT * FROM country_invitations WHERE id=? AND target_player=?",
                [$invitationId, $player]
            );
            if($invitation === null || $invitation["status"] !== "PENDING"){
                throw new RuntimeException("Pending invitation not found.");
            }
            $now = time();
            if((int) $invitation["expires_at"] <= $now){
                throw new RuntimeException("This invitation has expired.");
            }
            if($this->database->fetchOne("SELECT player_name FROM country_members WHERE player_name=?", [$player]) !== null){
                throw new RuntimeException("You already belong to a country.");
            }
            $countryId = (string) $invitation["country_id"];
            $this->database->execute(
                "INSERT INTO country_members(country_id,player_name,role,joined_at) VALUES(?,?,?,?)",
                [$countryId, $player, "MEMBER", $now]
            );
            $this->database->execute(
                "UPDATE country_invitations SET status='ACCEPTED',responded_at=? WHERE id=? AND status='PENDING'",
                [$now, $invitationId]
            );
            $this->record($player, "COUNTRY_INVITATION_ACCEPTED", $invitationId, ["country" => $countryId], $now);
            return $countryId;
        });
    }

    public function rejectInvitation(string $player, string $invitationId) : void{
        if(!InputValidator::playerName($player) || preg_match('/^[a-f0-9]{16}$/', $invitationId) !== 1){
            throw new RuntimeException("Invalid invitation or player name.");
        }
        $this->database->transaction(function() use($player, $invitationId) : void{
            $invitation = $this->database->fetchOne(
                "SELECT id,country_id,status FROM country_invitations WHERE id=? AND target_player=?",
                [$invitationId, $player]
            );
            if($invitation === null || $invitation["status"] !== "PENDING"){
                throw new RuntimeException("Pending invitation not found.");
            }
            $now = time();
            $this->database->execute(
                "UPDATE country_invitations SET status='REJECTED',responded_at=? WHERE id=? AND status='PENDING'",
                [$now, $invitationId]
            );
            $this->record($player, "COUNTRY_INVITATION_REJECTED", $invitationId, ["country" => $invitation["country_id"]], $now);
        });
    }

    /** @return list<array<string, mixed>> */
    public function pendingInvitations(string $player) : array{
        return $this->database->fetchAll(
            "SELECT i.id,i.inviter_name,i.created_at,i.expires_at,c.name AS country_name,c.tag AS country_tag
             FROM country_invitations i
             INNER JOIN countries c ON c.id=i.country_id
             WHERE i.target_player=? AND i.status='PENDING' AND i.expires_at>?
             ORDER BY i.created_at DESC LIMIT 20",
            [$player, time()]
        );
    }

    public function rename(string $actor, string $name) : void{
        $name = trim($name);
        if(!InputValidator::countryName($name)){
            throw new RuntimeException("Country name must contain 3 to 24 letters, numbers, spaces or hyphens.");
        }
        $country = $this->requireLeaderCountry($actor);
        $this->database->transaction(function() use($actor, $name, $country) : void{
            $this->database->execute("UPDATE countries SET name=? WHERE id=?", [$name, $country["id"]]);
            $this->record($actor, "COUNTRY_RENAMED", (string) $country["id"], ["name" => $name], time());
        });
    }

    public function setColor(string $actor, string $color) : void{
        $color = strtoupper(ltrim(trim($color), "#"));
        if(preg_match('/^[0-9A-F]{6}$/', $color) !== 1){
            throw new RuntimeException("Country color must be a six-digit hexadecimal value.");
        }
        $country = $this->requireLeaderCountry($actor);
        $this->database->transaction(function() use($actor, $color, $country) : void{
            $this->database->execute("UPDATE countries SET color=? WHERE id=?", [$color, $country["id"]]);
            $this->record($actor, "COUNTRY_COLOR_CHANGED", (string) $country["id"], ["color" => $color], time());
        });
    }

    public function kick(string $actor, string $targetPlayer) : void{
        if(!InputValidator::playerName($targetPlayer) || $actor === $targetPlayer){
            throw new RuntimeException("Invalid member to remove.");
        }
        $country = $this->byPlayer($actor);
        if($country === null){
            throw new RuntimeException("You are not a member of a country.");
        }
        $countryId = (string) $country["id"];
        $actorRole = $this->memberRole($countryId, $actor);
        $targetRole = $this->memberRole($countryId, $targetPlayer);
        if(!in_array($actorRole, ["LEADER", "OFFICER"], true) || $targetRole === null || $targetRole === "LEADER"){
            throw new RuntimeException("You cannot remove that country member.");
        }
        if($actorRole === "OFFICER" && $targetRole !== "MEMBER"){
            throw new RuntimeException("Officers may only remove regular members.");
        }
        $this->database->transaction(function() use($actor, $targetPlayer, $countryId) : void{
            $this->database->execute("DELETE FROM country_members WHERE country_id=? AND player_name=? AND role<>'LEADER'", [$countryId, $targetPlayer]);
            $this->record($actor, "COUNTRY_MEMBER_REMOVED", $countryId, ["target" => $targetPlayer], time());
        });
    }

    public function setRole(string $actor, string $targetPlayer, string $role) : void{
        $role = strtoupper(trim($role));
        if(!InputValidator::playerName($targetPlayer) || !in_array($role, ["MEMBER", "OFFICER"], true)){
            throw new RuntimeException("Role must be MEMBER or OFFICER and the player name must be valid.");
        }
        $country = $this->requireLeaderCountry($actor);
        $countryId = (string) $country["id"];
        if($this->memberRole($countryId, $targetPlayer) === null || $this->memberRole($countryId, $targetPlayer) === "LEADER"){
            throw new RuntimeException("The target must be a non-leader member of your country.");
        }
        $this->database->transaction(function() use($actor, $targetPlayer, $role, $countryId) : void{
            $this->database->execute(
                "UPDATE country_members SET role=? WHERE country_id=? AND player_name=? AND role<>'LEADER'",
                [$role, $countryId, $targetPlayer]
            );
            $this->record($actor, "COUNTRY_ROLE_CHANGED", $countryId, ["target" => $targetPlayer, "role" => $role], time());
        });
    }

    public function transferLeadership(string $actor, string $targetPlayer) : void{
        if(!InputValidator::playerName($targetPlayer) || $actor === $targetPlayer){
            throw new RuntimeException("A valid different country member is required.");
        }
        $country = $this->requireLeaderCountry($actor);
        $countryId = (string) $country["id"];
        if($this->memberRole($countryId, $targetPlayer) === null){
            throw new RuntimeException("The new leader must already be a member of your country.");
        }
        $now = time();
        $this->database->transaction(function() use($actor, $targetPlayer, $countryId, $now) : void{
            $this->database->execute("UPDATE country_members SET role='OFFICER' WHERE country_id=? AND player_name=?", [$countryId, $actor]);
            $this->database->execute("UPDATE country_members SET role='LEADER' WHERE country_id=? AND player_name=?", [$countryId, $targetPlayer]);
            $this->database->execute("UPDATE countries SET owner_name=? WHERE id=?", [$targetPlayer, $countryId]);
            $this->record($actor, "COUNTRY_LEADERSHIP_TRANSFERRED", $countryId, ["new_leader" => $targetPlayer], $now);
        });
    }

    public function leave(string $player) : void{
        $country = $this->byPlayer($player);
        if($country === null){
            throw new RuntimeException("You are not a member of a country.");
        }
        if(($country["member_role"] ?? "") === "LEADER"){
            throw new RuntimeException("Transfer leadership before leaving your country.");
        }
        $countryId = (string) $country["id"];
        $this->database->transaction(function() use($player, $countryId) : void{
            $this->database->execute("DELETE FROM country_members WHERE country_id=? AND player_name=? AND role<>'LEADER'", [$countryId, $player]);
            $this->record($player, "COUNTRY_MEMBER_LEFT", $countryId, [], time());
        });
    }

    /** @return list<array<string, mixed>> */
    public function members(string $countryId) : array{
        return $this->database->fetchAll(
            "SELECT player_name,role,joined_at FROM country_members WHERE country_id=? ORDER BY CASE role WHEN 'LEADER' THEN 0 WHEN 'OFFICER' THEN 1 ELSE 2 END,joined_at",
            [$countryId]
        );
    }

    /** @return array<string, mixed>|null */
    public function byPlayer(string $player) : ?array{
        return $this->database->fetchOne(
            "SELECT c.*,m.role AS member_role FROM countries c INNER JOIN country_members m ON m.country_id=c.id WHERE m.player_name=?",
            [$player]
        );
    }

    /** @return array<string, mixed>|null */
    public function byTag(string $tag) : ?array{
        return $this->database->fetchOne("SELECT * FROM countries WHERE tag=?", [strtoupper($tag)]);
    }

    /** @return list<array<string, mixed>> */
    public function top(int $limit = 10) : array{
        $limit = max(1, min(50, $limit));
        return $this->database->fetchAll("SELECT name,tag,score,owner_name FROM countries ORDER BY score DESC LIMIT " . $limit);
    }

    private function requireLeaderCountry(string $player) : array{
        $country = $this->byPlayer($player);
        if($country === null || ($country["member_role"] ?? "") !== "LEADER"){
            throw new RuntimeException("Only the country leader can perform this action.");
        }
        return $country;
    }

    private function assertOfficer(string $countryId, string $player) : void{
        if(!in_array($this->memberRole($countryId, $player), ["LEADER", "OFFICER"], true)){
            throw new RuntimeException("Only a leader or officer can invite members.");
        }
    }

    private function memberRole(string $countryId, string $player) : ?string{
        $row = $this->database->fetchOne(
            "SELECT role FROM country_members WHERE country_id=? AND player_name=?",
            [$countryId, $player]
        );
        return $row === null ? null : (string) $row["role"];
    }

    /** @param array<string, mixed> $details */
    private function record(string $actor, string $action, string $subject, array $details, int $now) : void{
        $this->database->execute(
            "INSERT INTO game_history(actor_name,action,subject_id,details_json,created_at) VALUES(?,?,?,?,?)",
            [$actor, $action, $subject, json_encode($details, JSON_THROW_ON_ERROR), $now]
        );
    }
}
