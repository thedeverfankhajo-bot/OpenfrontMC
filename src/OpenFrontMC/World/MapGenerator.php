<?php

declare(strict_types=1);

namespace OpenFrontMC\World;

use InvalidArgumentException;

final class MapGenerator{

    /**
     * Deterministic territory generation. Spawn and its four neighbours are always land.
     * @return list<array{x:int,z:int,terrain:string,special:string,resources:array<string,int>}>
     */
    public function generate(int $seed, int $width, int $height, int $continentCount, int $islandCount, float $waterRatio) : array{
        if($width < 4 || $height < 4 || $width > 128 || $height > 128){
            throw new InvalidArgumentException("Map dimensions must be between 4 and 128.");
        }
        if($continentCount < 1 || $continentCount > 12 || $islandCount < 0 || $islandCount > 64){
            throw new InvalidArgumentException("Map feature counts are outside supported limits.");
        }
        if(!is_finite($waterRatio) || $waterRatio < 0.20 || $waterRatio > 0.85){
            throw new InvalidArgumentException("Water ratio must be between 0.20 and 0.85.");
        }

        $centres = [];
        for($i = 0; $i < $continentCount; ++$i){
            $centres[] = [
                "x" => $this->sample($seed, "cx" . $i) * ($width - 1),
                "z" => $this->sample($seed, "cz" . $i) * ($height - 1),
                "rx" => max(2.0, $width * (0.16 + $this->sample($seed, "rx" . $i) * 0.12)),
                "rz" => max(2.0, $height * (0.16 + $this->sample($seed, "rz" . $i) * 0.12))
            ];
        }
        $islands = [];
        for($i = 0; $i < $islandCount; ++$i){
            $islands[] = [
                "x" => $this->sample($seed, "ix" . $i) * ($width - 1),
                "z" => $this->sample($seed, "iz" . $i) * ($height - 1),
                "r" => 0.55 + $this->sample($seed, "ir" . $i) * 1.15
            ];
        }

        $total = $width * $height;
        $scores = [];
        $islandFlags = [];
        $continentScores = [];
        for($z = 0; $z < $height; ++$z){
            for($x = 0; $x < $width; ++$x){
                $best = -10.0;
                foreach($centres as $centre){
                    $dx = ($x - $centre["x"]) / $centre["rx"];
                    $dz = ($z - $centre["z"]) / $centre["rz"];
                    $best = max($best, 1.0 - sqrt($dx * $dx + $dz * $dz));
                }
                $island = false;
                $islandScore = -10.0;
                foreach($islands as $islandCentre){
                    $distance = hypot($x - $islandCentre["x"], $z - $islandCentre["z"]);
                    if($distance <= $islandCentre["r"]){
                        $island = true;
                        $islandScore = max($islandScore, 0.18 + ($islandCentre["r"] - $distance) * 0.35);
                    }
                }
                $index = $z * $width + $x;
                $scores[$index] = max($best, $islandScore) + ($this->sample($seed, "terrain", $x, $z) - 0.5) * 0.28;
                $islandFlags[$index] = $island;
                $continentScores[$index] = $best;
            }
        }

        $ranking = array_keys($scores);
        usort($ranking, static fn(int $a, int $b) : int => ($scores[$b] <=> $scores[$a]) ?: ($a <=> $b));
        $landCount = max(5, min($total - 1, (int) round($total * (1.0 - $waterRatio))));
        $land = array_fill(0, $total, false);
        for($i = 0; $i < $landCount; ++$i){
            $land[$ranking[$i]] = true;
        }

        $cx = intdiv($width, 2);
        $cz = intdiv($height, 2);
        $spawn = [$cz * $width + $cx, $cz * $width + $cx - 1, $cz * $width + $cx + 1, ($cz - 1) * $width + $cx, ($cz + 1) * $width + $cx];
        $reserved = array_fill_keys($spawn, true);
        foreach($spawn as $spawnIndex){
            if($land[$spawnIndex]){
                continue;
            }
            for($i = $landCount - 1; $i >= 0; --$i){
                $candidate = $ranking[$i];
                if($land[$candidate] && !isset($reserved[$candidate])){
                    $land[$candidate] = false;
                    break;
                }
            }
            $land[$spawnIndex] = true;
        }

        $result = [];
        for($z = 0; $z < $height; ++$z){
            for($x = 0; $x < $width; ++$x){
                $index = $z * $width + $x;
                $isLand = $land[$index];
                $terrain = !$isLand ? "WATER" : ($islandFlags[$index] && $continentScores[$index] < 0.1 ? "ISLAND" : "LAND");
                $special = $isLand && $this->sample($seed, "special", $x, $z) > 0.986 ? "CITY_SITE" : "NONE";
                $resources = [];
                foreach(["gold", "food", "materials", "energy", "research", "influence"] as $resource){
                    $resources[$resource] = !$isLand ? 0 : (int) floor($this->sample($seed, $resource, $x, $z) * 11);
                }
                $result[] = ["x" => $x, "z" => $z, "terrain" => $terrain, "special" => $special, "resources" => $resources];
            }
        }
        return $result;
    }

    private function sample(int $seed, string $salt, int $x = 0, int $z = 0) : float{
        return hexdec(substr(hash("sha256", $seed . ":" . $salt . ":" . $x . ":" . $z), 0, 8)) / 4294967295;
    }
}
