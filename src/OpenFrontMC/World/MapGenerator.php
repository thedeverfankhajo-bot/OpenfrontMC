<?php

declare(strict_types=1);

namespace OpenFrontMC\World;

use InvalidArgumentException;

final class MapGenerator{

    /**
     * Creates deterministic territory metadata. The same inputs always produce the same map.
     *
     * @return list<array{x:int,z:int,terrain:string,special:string,resources:array<string,int>}>
     */
    public function generate(
        int $seed,
        int $width,
        int $height,
        int $continentCount,
        int $islandCount,
        float $waterRatio
    ) : array{
        if($width < 4 || $height < 4 || $width > 128 || $height > 128){
            throw new InvalidArgumentException("Map territory dimensions must be between 4 and 128.");
        }
        if($continentCount < 1 || $continentCount > 12 || $islandCount < 0 || $islandCount > 64){
            throw new InvalidArgumentException("Map feature counts are outside supported limits.");
        }
        if($waterRatio < 0.20 || $waterRatio > 0.85){
            throw new InvalidArgumentException("Water ratio must be between 0.20 and 0.85.");
        }

        $centres = [];
        for($i = 0; $i < $continentCount; ++$i){
            $centres[] = [
                "x" => $this->sample($seed, "continent-x-" . $i) * ($width - 1),
                "z" => $this->sample($seed, "continent-z-" . $i) * ($height - 1),
                "rx" => max(2.0, $width * (0.16 + $this->sample($seed, "continent-rx-" . $i) * 0.12)),
                "rz" => max(2.0, $height * (0.16 + $this->sample($seed, "continent-rz-" . $i) * 0.12))
            ];
        }

        $islands = [];
        for($i = 0; $i < $islandCount; ++$i){
            $islands[] = [
                "x" => $this->sample($seed, "island-x-" . $i) * ($width - 1),
                "z" => $this->sample($seed, "island-z-" . $i) * ($height - 1),
                "radius" => 0.55 + $this->sample($seed, "island-r-" . $i) * 1.15
            ];
        }

        $result = [];
        $centreX = intdiv($width, 2);
        $centreZ = intdiv($height, 2);
        for($z = 0; $z < $height; ++$z){
            for($x = 0; $x < $width; ++$x){
                $best = -10.0;
                foreach($centres as $centre){
                    $dx = ($x - $centre["x"]) / $centre["rx"];
                    $dz = ($z - $centre["z"]) / $centre["rz"];
                    $best = max($best, 1.0 - sqrt(($dx * $dx) + ($dz * $dz)));
                }

                $island = false;
                foreach($islands as $islandCentre){
                    $distance = hypot($x - $islandCentre["x"], $z - $islandCentre["z"]);
                    if($distance <= $islandCentre["radius"]){
                        $island = true;
                        break;
                    }
                }

                $noise = ($this->sample($seed, "terrain", $x, $z) - 0.5) * 0.28;
                $isLand = ($best + $noise) > (0.82 - $waterRatio) || $island;
                if($x === $centreX && $z === $centreZ){
                    $isLand = true;
                }

                $terrain = !$isLand ? "WATER" : ($island && $best < 0.1 ? "ISLAND" : "LAND");
                $special = "NONE";
                if($terrain !== "WATER" && $this->sample($seed, "special", $x, $z) > 0.986){
                    $special = "CITY_SITE";
                }

                $resources = [];
                foreach(["gold", "food", "materials", "energy", "research", "influence"] as $resource){
                    $resources[$resource] = $terrain === "WATER" ? 0 : (int) floor($this->sample($seed, $resource, $x, $z) * 11);
                }
                $result[] = [
                    "x" => $x,
                    "z" => $z,
                    "terrain" => $terrain,
                    "special" => $special,
                    "resources" => $resources
                ];
            }
        }

        return $result;
    }

    private function sample(int $seed, string $salt, int $x = 0, int $z = 0) : float{
        $hash = hash("sha256", $seed . ":" . $salt . ":" . $x . ":" . $z);
        return hexdec(substr($hash, 0, 8)) / 4294967295;
    }
}
