<?php

declare(strict_types=1);

namespace OpenFrontMC\World;

use OpenFrontMC\Storage\Database;
use OpenFrontMC\Territory\TerritoryManager;
use pocketmine\block\VanillaBlocks;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\world\World;
use RuntimeException;
use Throwable;

/**
 * Materializes the persisted strategic territory map as a protected block board.
 *
 * A territory is intentionally aligned with one PocketMine chunk (16x16 blocks).
 * Chunk population is requested through the public World API; all block edits are
 * performed by the completion callback on PocketMine's main thread.
 */
final class TerrainRenderer{

    /** @var list<array{id:string,x:int|string,z:int|string,terrain:string,special:string}> */
    private array $queue = [];

    /** @var array<string, int> */
    private array $attempts = [];

    private ?TaskHandler $task = null;
    private ?World $world = null;
    private ?array $currentTile = null;
    private int $nextIndex = 0;
    private int $baseY = 0;
    private int $territorySize = 16;
    private int $mapWidth = 0;
    private int $mapHeight = 0;
    private bool $ready = false;
    private bool $stopped = false;
    private ?string $failure = null;

    public function __construct(
        private PluginBase $plugin,
        private Database $database,
        private TerritoryManager $territories
    ){}

    /**
     * Starts resumable map rendering.
     *
     * @param int $intervalTicks Delay between chunk requests; larger values reduce generation pressure.
     */
    public function start(World $world, int $intervalTicks = 4) : void{
        if($this->task !== null || $this->ready || $this->failure !== null){
            return;
        }
        $this->stopped = false;

        $metadata = $this->territories->metadata();
        if($metadata === null){
            throw new RuntimeException("Cannot render the map before its territory metadata exists.");
        }

        $this->mapWidth = (int) $metadata["width"];
        $this->mapHeight = (int) $metadata["height"];
        $this->territorySize = (int) $metadata["territory_size"];

        if($this->mapWidth < 4 || $this->mapHeight < 4 || $this->territorySize !== 16){
            throw new RuntimeException("The physical map currently requires at least 4x4 territories aligned to 16-block chunks.");
        }
        if($this->mapWidth * $this->mapHeight > 4096){
            throw new RuntimeException("The physical map exceeds the configured safe limit of 4096 territories.");
        }

        if((int) $metadata["rendered"] === 1){
            $this->ready = true;
            return;
        }

        $this->world = $world;
        $this->baseY = $world->getMaxY() - 24;
        $maxBlockX = ($this->mapWidth * $this->territorySize) - 1;
        $maxBlockZ = ($this->mapHeight * $this->territorySize) - 1;
        if(
            !$world->isInWorld(0, $this->baseY + 5, 0) ||
            !$world->isInWorld($maxBlockX, $this->baseY + 4, $maxBlockZ) ||
            $this->baseY < $world->getMinY()
        ){
            throw new RuntimeException("The managed world bounds do not have enough room for the configured map board.");
        }

        $this->queue = $this->database->fetchAll(
            "SELECT t.id,t.x,t.z,t.terrain,t.special
             FROM territories t
             LEFT JOIN rendered_territories r ON r.territory_id=t.id
             WHERE r.territory_id IS NULL
             ORDER BY t.z,t.x"
        );

        if($this->queue === []){
            $this->finish();
            return;
        }

        $intervalTicks = max(1, min(200, $intervalTicks));
        $this->task = $this->plugin->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function() : void{
                $this->tick();
            }),
            $intervalTicks
        );

        $this->plugin->getLogger()->notice("Rendering OpenFrontMC terrain: " . count($this->queue) . " territory chunks queued.");
    }

    public function isReady() : bool{
        return $this->ready;
    }

    public function getFailure() : ?string{
        return $this->failure;
    }

    public function shutdown() : void{
        $this->stopped = true;
        $this->task?->cancel();
        $this->task = null;
        $this->currentTile = null;
    }

    private function tick() : void{
        if($this->stopped || $this->currentTile !== null || $this->world === null){
            return;
        }

        if($this->nextIndex >= count($this->queue)){
            $this->finish();
            return;
        }

        $tile = $this->queue[$this->nextIndex++];
        $this->currentTile = $tile;
        $chunkX = (int) $tile["x"];
        $chunkZ = (int) $tile["z"];

        try{
            $this->world->orderChunkPopulation($chunkX, $chunkZ, null)->onCompletion(
                function() use($tile) : void{
                    if($this->stopped){
                        return;
                    }
                    try{
                        $this->renderTile($tile);
                        $this->database->execute(
                            "INSERT OR IGNORE INTO rendered_territories(territory_id,rendered_at) VALUES(?,?)",
                            [(string) $tile["id"], time()]
                        );
                        unset($this->attempts[(string) $tile["id"]]);
                        $this->currentTile = null;

                        if($this->nextIndex >= count($this->queue)){
                            $this->finish();
                        }
                    }catch(Throwable $e){
                        $this->retry($tile, $e);
                    }
                },
                function() use($tile) : void{
                    if(!$this->stopped){
                        $this->retry($tile, new RuntimeException("PocketMine failed to populate the requested map chunk."));
                    }
                }
            );
        }catch(Throwable $e){
            if(!$this->stopped){
                $this->retry($tile, $e);
            }
        }
    }

    /**
     * @param array{id:string,x:int|string,z:int|string,terrain:string,special:string} $tile
     */
    private function renderTile(array $tile) : void{
        if($this->world === null){
            throw new RuntimeException("The managed world is unavailable.");
        }

        $x = (int) $tile["x"];
        $z = (int) $tile["z"];
        if($x < 0 || $x >= $this->mapWidth || $z < 0 || $z >= $this->mapHeight){
            throw new RuntimeException("A stored territory is outside the generated map bounds.");
        }

        $terrain = strtoupper($tile["terrain"]);
        if(!in_array($terrain, ["LAND", "ISLAND", "WATER"], true)){
            throw new RuntimeException("A stored territory has an unsupported terrain type.");
        }

        $bedrock = VanillaBlocks::BEDROCK();
        $stone = VanillaBlocks::STONE();
        $dirt = VanillaBlocks::DIRT();
        $grass = VanillaBlocks::GRASS();
        $sand = VanillaBlocks::SAND();
        $water = VanillaBlocks::WATER()->setStill(true);

        $originX = $x * $this->territorySize;
        $originZ = $z * $this->territorySize;

        for($localZ = 0; $localZ < $this->territorySize; ++$localZ){
            for($localX = 0; $localX < $this->territorySize; ++$localX){
                $worldX = $originX + $localX;
                $worldZ = $originZ + $localZ;

                $this->world->setBlockAt($worldX, $this->baseY, $worldZ, $bedrock, false);
                $this->world->setBlockAt($worldX, $this->baseY + 1, $worldZ, $stone, false);

                if($terrain === "WATER"){
                    $this->world->setBlockAt($worldX, $this->baseY + 2, $worldZ, $sand, false);
                    $this->world->setBlockAt($worldX, $this->baseY + 3, $worldZ, $water, false);
                }else{
                    $this->world->setBlockAt($worldX, $this->baseY + 2, $worldZ, $dirt, false);
                    $this->world->setBlockAt($worldX, $this->baseY + 3, $worldZ, $grass, false);
                }
            }
        }

        if($tile["special"] === "CITY_SITE" && $terrain !== "WATER"){
            $markerX = $originX + intdiv($this->territorySize, 2);
            $markerZ = $originZ + intdiv($this->territorySize, 2);
            $this->world->setBlockAt($markerX, $this->baseY + 3, $markerZ, VanillaBlocks::GOLD(), false);
        }
    }

    /**
     * @param array{id:string,x:int|string,z:int|string,terrain:string,special:string} $tile
     */
    private function retry(array $tile, Throwable $error) : void{
        if($this->stopped){
            return;
        }
        $id = (string) $tile["id"];
        $attempt = ($this->attempts[$id] ?? 0) + 1;
        $this->attempts[$id] = $attempt;
        $this->currentTile = null;

        if($attempt > 3){
            $this->failure = "Could not render territory " . $id . " after 3 attempts: " . $error->getMessage();
            $this->plugin->getLogger()->error($this->failure);
            $this->task?->cancel();
            $this->task = null;
            return;
        }

        $this->nextIndex = max(0, $this->nextIndex - 1);
        $this->plugin->getLogger()->warning("Retrying terrain chunk for territory " . $id . " (" . $attempt . "/3): " . $error->getMessage());
    }

    private function finish() : void{
        if($this->stopped || $this->world === null || $this->ready){
            return;
        }

        $spawnX = intdiv($this->mapWidth * $this->territorySize, 2);
        $spawnZ = intdiv($this->mapHeight * $this->territorySize, 2);
        $this->world->setSpawnLocation(new Vector3($spawnX + 0.5, $this->baseY + 4, $spawnZ + 0.5));
        $this->territories->markRendered();
        $this->ready = true;
        $this->task?->cancel();
        $this->task = null;

        $this->plugin->getLogger()->notice("OpenFrontMC terrain rendering completed; match-world spawn is ready.");
    }
}
