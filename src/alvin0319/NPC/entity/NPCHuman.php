<?php
declare(strict_types=1);
namespace alvin0319\NPC\entity;

use alvin0319\NPC\NPCPlugin;
use pocketmine\entity\Location;
use pocketmine\entity\Skin;
use pocketmine\item\ItemFactory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\FloatMetadataProperty;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\protocol\types\SkinAdapterSingleton;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\UUID;

class NPCHuman extends EntityBase{

	public const NETWORK_ID = 0;

	public $width = 0.6;
	public $height = 1.8;
	public $eyeHeight = 1.62;

	protected $isCustomSkin = false;

	/** @var UUID */
	protected $uuid;

	/** @var Skin */
	protected $skin;

	public function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		$this->uuid = UUID::fromRandom();

		$skinTag = $nbt->getCompoundTag("Skin");

		if($skinTag === null){
			throw new \InvalidStateException((new \ReflectionClass($this))->getShortName() . " must have a valid skin set");
		}

		$this->skin = new Skin(
			$skinTag->getString("Name"),
			$skinTag->hasTag("Data", StringTag::class) ? $skinTag->getString("Data") : $skinTag->getByteArray("Data"),
			$skinTag->getByteArray("CapeData", ""),
			$skinTag->getString("GeometryName", ""),
			$skinTag->getByteArray("GeometryData", "")
		);

		$this->isCustomSkin = $nbt->getByte("isCustomSkin", 0) === 1;

		if($nbt->hasTag("width", FloatTag::class) and $nbt->hasTag("height", FloatTag::class)){
			$this->width = $nbt->getFloat("width");
			$this->height = $nbt->getFloat("height");
		}

		$this->scale = $nbt->getFloat("scale", 1.0);
	}

	public function getName() : string{
		return (new \ReflectionClass($this))->getShortName();
	}

	public function spawnTo(Player $player) : void{
		if(in_array($player, $this->hasSpawned, true)){
			return;
		}
		$player->getNetworkSession()->sendDataPacket(PlayerListPacket::add([PlayerListEntry::createAdditionEntry($this->uuid, $this->id, $this->getName(), SkinAdapterSingleton::get()->toSkinData($this->skin))]));

		$pk = new AddPlayerPacket();
		$pk->uuid = $this->uuid;
		$pk->username = $this->getRealName();
		$pk->entityRuntimeId = $this->getId();
		$pk->position = $this->location->asVector3();
		$pk->motion = null;
		$pk->yaw = $this->location->yaw;
		$pk->pitch = $this->location->pitch;
		$pk->item = ItemFactory::air();
		$pk->metadata = $this->getSyncedNetworkData(false);
		$player->getNetworkSession()->sendDataPacket($pk);

		$this->sendData($player, [EntityMetadataProperties::NAMETAG => new StringMetadataProperty($this->getRealName())]);

		$player->getNetworkSession()->sendDataPacket(PlayerListPacket::remove([PlayerListEntry::createRemovalEntry($this->uuid)]));

		$this->hasSpawned[] = $player;

		$this->sendData($player, [EntityMetadataProperties::SCALE => new FloatMetadataProperty($this->scale)]);
	}

	public function despawnTo(Player $player) : void{
		parent::despawnTo($player);
		$player->getNetworkSession()->sendDataPacket(PlayerListPacket::remove([PlayerListEntry::createAdditionEntry($this->uuid, $this->id, $this->getName(), SkinAdapterSingleton::get()->toSkinData($this->skin))]));
	}

	public static function nbtDeserialize(CompoundTag $nbt) : NPCHuman{
		[$x, $y, $z, $world] = explode(":", $nbt->getString("pos"));
		return new NPCHuman(
			new Location((float) $x, (float) $y, (float) $z, 0.0, 0.0, Server::getInstance()->getWorldManager()->getWorldByName($world)),
			$nbt
		);
	}

	public function nbtSerialize() : CompoundTag{
		$nbt = parent::nbtSerialize();
		$nbt->setInt("type", self::NETWORK_ID);

		$nbt->setString("command", $this->command);
		$nbt->setString("message", $this->message);

		$nbt->setTag("Skin", NPCPlugin::getInstance()->getSkinCompound($this->skin));
		$nbt->setByte("isCustomSkin", $this->isCustomSkin ? 1 : 0);
		return $nbt;
	}

	/**
	 * @param Vector3 $target
	 * @see Living::lookAt()
	 */
	public function lookAt(Vector3 $target){
		$horizontal = sqrt(($target->x - $this->location->x) ** 2 + ($target->z - $this->location->z) ** 2);
		$vertical = $target->y - $this->location->y;
		$this->location->pitch = -atan2($vertical, $horizontal) / M_PI * 180; //negative is up, positive is down

		$xDist = $target->x - $this->location->x;
		$zDist = $target->z - $this->location->z;
		$this->location->yaw = atan2($zDist, $xDist) / M_PI * 180 - 90;
		if($this->location->yaw < 0){
			$this->location->yaw += 360.0;
		}

		$pk = new MovePlayerPacket();
		$pk->position = $this->location->add(0, 1.62);
		$pk->yaw = $this->location->yaw;
		$pk->pitch = $this->location->pitch;
		$pk->entityRuntimeId = $this->id;
		$pk->headYaw = $this->location->yaw;

		foreach($this->getViewers() as $player){
			$player->getNetworkSession()->sendDataPacket($pk);
		}
	}
}