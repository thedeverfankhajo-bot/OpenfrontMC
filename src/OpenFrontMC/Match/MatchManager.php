<?php
declare(strict_types=1);
namespace OpenFrontMC\Match;
use OpenFrontMC\Storage\Database;
use RuntimeException;
final class MatchManager{
    public function __construct(private Database $database){}
    public function create(string $mode,int $capacity) : string{
        if(!in_array($mode,["FFA","TEAM","SOLO","CUSTOM"],true)||$capacity<2||$capacity>100){throw new RuntimeException("Invalid match settings.");}
        $id=bin2hex(random_bytes(8));
        $this->database->execute("INSERT INTO matches(id,mode,state,capacity,created_at) VALUES(?,?,'WAITING',?,?)",[$id,$mode,$capacity,time()]);
        return $id;
    }
    public function join(string $matchId,string $player) : void{
        $this->database->transaction(function() use($matchId,$player):void{
            $match=$this->database->fetchOne("SELECT * FROM matches WHERE id=?",[$matchId]);
            if($match===null||$match["state"]!==MatchState::WAITING->value){throw new RuntimeException("Match is not accepting players.");}
            if($this->database->fetchOne("SELECT match_id FROM match_players WHERE player_name=? AND left_at IS NULL",[$player])!==null){throw new RuntimeException("You are already in an active match.");}
            $count=$this->database->fetchOne("SELECT COUNT(*) AS c FROM match_players WHERE match_id=? AND left_at IS NULL",[$matchId]);
            if((int)$count["c"]>=(int)$match["capacity"]){throw new RuntimeException("Match is full.");}
            $this->database->execute("INSERT INTO match_players(match_id,player_name,joined_at) VALUES(?,?,?)",[$matchId,$player,time()]);
        });
    }
    public function leave(string $player) : void{$this->database->execute("UPDATE match_players SET left_at=? WHERE player_name=? AND left_at IS NULL",[time(),$player]);}
    public function transition(string $id,MatchState $next) : void{
        $this->database->transaction(function() use($id,$next):void{
            $row=$this->database->fetchOne("SELECT state FROM matches WHERE id=?",[$id]);
            if($row===null){throw new RuntimeException("Match not found.");}
            (new MatchStateMachine())->transition(MatchState::from((string)$row["state"]),$next);
            $this->database->execute("UPDATE matches SET state=?,started_at=COALESCE(?,started_at),ended_at=COALESCE(?,ended_at) WHERE id=?",[$next->value,$next===MatchState::RUNNING?time():null,$next===MatchState::FINISHED?time():null,$id]);
        });
    }
    /** @return array<string,mixed>|null */
    public function latestWaiting():?array{return $this->database->fetchOne("SELECT * FROM matches WHERE state='WAITING' ORDER BY created_at DESC LIMIT 1");}
}
