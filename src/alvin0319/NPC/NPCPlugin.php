<?php
declare(strict_types=1);

namespace alvin0319\NPC;

use alvin0319\NPC\config\EntityConfig;
use alvin0319\NPC\config\ImageConfig;
use alvin0319\NPC\entity\CustomEntity;
use alvin0319\NPC\entity\EntityBase;
use alvin0319\NPC\entity\NPCHuman;
use alvin0319\NPC\lang\PluginLang;
use alvin0319\NPC\task\CheckVersionAsyncTask;
use alvin0319\NPC\util\ExtensionNotLoadedException;
use alvin0319\NPC\util\FileNotFoundException;
use alvin0319\NPC\util\Promise;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Skin;
use pocketmine\level\Level;
use pocketmine\level\Location;
use pocketmine\nbt\LittleEndianNBTStream;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;

use function array_keys;
use function array_map;
use function array_merge;
use function array_values;
use function chr;
use function count;
use function extension_loaded;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function imagecolorat;
use function imagecreatefrompng;
use function imagedestroy;
use function imagesx;
use function imagesy;
use function implode;
use function in_array;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function time;
use function unlink;
use function version_compare;

class NPCPlugin extends PluginBase{

	private static $instance = null;

	public const CONFIG_VERSION = "1.0.1";

	protected $data;

	/** @var PluginLang */
	protected $lang;

	/** @var EntityBase[] */
	protected $entities = [];

	/** @var ImageConfig */
	protected $imageConfig;

	public function onLoad() : void{
		self::$instance = $this;
	}

	public static function getInstance() : NPCPlugin{
		return self::$instance;
	}

	public function onEnable() : void{
		if(!file_exists($this->getDataFolder() . "images/") or !is_dir($this->getDataFolder() . "images/")){
			mkdir($this->getDataFolder() . "images/");
		}

		$this->lang = new PluginLang($this);

		$this->saveResource("config.yml");

		if(version_compare(self::CONFIG_VERSION, $this->getConfig()->getNested("config-version", self::CONFIG_VERSION)) > 0){
			$this->getLogger()->debug("Found old config data, replacing...");
			unlink($this->getDataFolder() . "config.yml");
			$this->saveResource("config.yml");
			$this->lang->saveNewLang();
		}

		$this->getServer()->getPluginManager()->registerEvents(new EventListener(), $this);

		$this->data = $this->getConfig()->getAll();

		if(!file_exists($file = $this->getDataFolder() . "npc.dat")){
			$nbt = new CompoundTag();
			$nbt->setTag(new ListTag("npc"));
			file_put_contents($file, (new LittleEndianNBTStream())->writeCompressed($nbt));
		}

		$data = (new LittleEndianNBTStream())->readCompressed(file_get_contents($file));

		foreach($data->getListTag("npc")->getValue() as $tag){
			if($tag instanceof CompoundTag){
				switch($tag->getInt("type")){
					case NPCHuman::NETWORK_ID:
						$class = NPCHuman::nbtDeserialize($tag);
						break;
					case CustomEntity::NETWORK_ID:
						$class = CustomEntity::nbtDeserialize($tag);
						break;
					default:
						throw new \InvalidStateException("Unknown entity type " . $tag->getInt("type"));
				}

				if(!$class->getLocation()->getLevel() instanceof Level){
					$this->getLogger()->debug("Skip registering " . $class->getRealName() . " due to not level loaded.");
					continue;
				}

				$this->entities[$class->getId()] = $class;
			}
		}

		$promise = new Promise();

		$this->getServer()->getAsyncPool()->submitTask(new CheckVersionAsyncTask($promise));

		$promise->then(function(array $result){
			$ver = $this->getDescription()->getVersion();

			$lastVer = $result["version"];

			if(version_compare($lastVer, $ver) > 0){
				$mustUpdate = $result["update"] ?? false;
				$message = $result["message"] ?? "";

				$this->getLogger()->notice("The New version of NPC was released. Now version: " . $ver . ", Last version: " . $lastVer);
				$this->getLogger()->info("Update message: " . $message);

				if($mustUpdate){
					$this->getLogger()->emergency("You must update this plugin before you can use it.");
					$this->getServer()->getPluginManager()->disablePlugin($this);
				}
			}else{
				$this->getLogger()->info("The latest version.");
			}
		})->catch(function(string $err){
			$this->getLogger()->critical($err);
		});

		$this->imageConfig = new ImageConfig($this);
	}

	public function onDisable() : void{
		$nbt = new CompoundTag();
		$tag = new ListTag("npc");

		foreach(array_values($this->entities) as $baseEntity){
			$tag->push($baseEntity->nbtSerialize());
		}
		$nbt->setTag($tag);
		file_put_contents($this->getDataFolder() . "npc.dat", (new LittleEndianNBTStream())->writeCompressed($nbt));

		$this->imageConfig->save();
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(!$sender instanceof Player){
			return false;
		}
		switch($args[0] ?? "x"){
			case "create":
				if(isset($args[1])){// type
					if(isset($args[2])){// nametag
						if($args[1] === "npc"){
							if(isset($args[3])){// path, or something
								$path = $args[3];
								$bool = true;
								if(isset($args[4])){// geometry path, or something
									if(file_exists($this->getDataFolder() . "images/" . $args[4])){
										$data = json_decode(file_get_contents($this->getDataFolder() . "images/" . $args[4]), true);

										if(!is_string($geometryName = $this->findGeometryName($data))){
											$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("entity.geometry"));
											break;
										}

										$geometryData = json_encode($data);
									}else{
										$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("path.notExist", [$args[4]]));
										break;
									}
								}
								try{
									$skin = $this->imageToSkin($sender, $path, isset($geometryName) ? $geometryName : "", isset($geometryData) ? $geometryData : "");
								}catch(ExtensionNotLoadedException $e){
									$bool = false;
									$sender->sendMessage(PluginLang::$prefix . $e->getMessage());
								}catch(FileNotFoundException $e){
									$bool = false;
									$sender->sendMessage(PluginLang::$prefix . $e->getMessage());
								}

								$nbt = new CompoundTag("Skin");

								if($bool && isset($skin)){
									$nbt->setString("name", $args[2]);
									$nbt->setByte("isCustomSkin", 1);
									$nbt->setTag($this->getSkinCompound($skin));

									/** @var NPCHuman $entity */
									$entity = new NPCHuman($sender->getLocation(), $nbt);
									$this->entities[$entity->getId()] = $entity;

									$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("entity.spawn", [$entity->getRealName()]));
								}
							}else{
								$skin = $sender->getSkin();
								$nbt = new CompoundTag();
								$nbt->setString("name", $args[2]);
								$nbt->setTag($this->getSkinCompound($skin));

								/** @var NPCHuman $entity */
								$entity = new NPCHuman($sender->getLocation(), $nbt);
								$this->entities[$entity->getId()] = $entity;

								$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("entity.spawn", [$entity->getRealName()]));
							}
						}else{
							if(in_array($args[1], array_keys(EntityConfig::NETWORK_IDS))){
								$nbt = new CompoundTag();
								$nbt->setString("name", $args[2]);
								/** @var EntityBase $entity */
								$entity = new CustomEntity(EntityConfig::NETWORK_IDS[$args[1]], $sender->getLocation(), $nbt);
								$this->entities[$entity->getId()] = $entity;
								$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("entity.spawn", [$entity->getRealName()]));
							}else{
								$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("entity.notExist"));
							}
						}
					}else{
						$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.create.usage"));
					}
				}else{
					$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.create.usage"));
				}
				break;
			case "remove":
				Queue::$removeQueue[$sender->getName()] = time();
				$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.remove.message"));
				break;
			case "get":
				if(count($this->entities) === 0){
					$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.list.empty"));
					break;
				}

				$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.list") . implode(", ", array_map(function(EntityBase $entityBase) : string{
						return "#" . $entityBase->getId() . " " . $entityBase->getRealName() . ": " . self::pos2hash($entityBase->getLocation());
					}, array_values($this->entities))));
				break;
			case "edit":
				if(isset($args[1])){
					if(isset($args[2])){
						if(in_array($args[1], ["command", "message", "scale"])){
							Queue::$editQueue[$sender->getName()] = [
								"mode" => $args[1],
								"target" => $args[2]
							];
							$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.edit.message"));
						}else{
							$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.edit.usage"));
						}
					}else{
						$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.edit.usage"));
					}
				}else{
					$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.edit.usage"));
				}
				break;
			case "item":
				$item = $sender->getInventory()->getItemInHand();
				Queue::$itemQueue[$sender->getName()] = $item;
				$sender->sendMessage(PluginLang::$prefix . "Please attack the entity that you want to set item.");
				break;
			case "offhand":
				$item = $sender->getInventory()->getItemInHand();
				Queue::$offItemQueue[$sender->getName()] = $item;
				$sender->sendMessage(PluginLang::$prefix . "Please attack the entity that you want to set offhand item.");
				break;
			default:
				$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.create.usage"));
				$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.delete.usage"));
				$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.edit.usage"));
				$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.get.usage"));
				$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.item.usage"));
				$sender->sendMessage(PluginLang::$prefix . $this->lang->translateLanguage("command.entity.list") . implode(", ", array_keys(EntityConfig::NETWORK_IDS)) . ", npc");
		}
		return true;
	}

	/**
	 * @param Player $player
	 * @param string $path
	 * @param string $geometryName
	 * @param string $geometryData
	 *
	 * @return Skin
	 * @throws ExtensionNotLoadedException
	 * @throws FileNotFoundException
	 *
	 * TO-DO: Process this as asynchronous
	 */
	private function imageToSkin(Player $player, string $path, string $geometryName = "", string $geometryData = "") : Skin{
		if(!extension_loaded("gd")){
			throw new ExtensionNotLoadedException($this->lang->translateLanguage("ext-gd.missing"));
		}

		if(!file_exists($path = $this->getDataFolder() . "images/" . $path)){
			throw new FileNotFoundException($this->lang->translateLanguage("path.notExist", [$path]));
		}

		$img = imagecreatefrompng($path);
		$bytes = '';
		for($y = 0; $y < imagesy($img); $y++){
			for($x = 0; $x < imagesx($img); $x++){
				$rgba = @imagecolorat($img, $x, $y);
				$a = ((~($rgba >> 24)) << 1) & 0xff;
				$r = ($rgba >> 16) & 0xff;
				$g = ($rgba >> 8) & 0xff;
				$b = $rgba & 0xff;
				$bytes .= chr($r) . chr($g) . chr($b) . chr($a);
			}
		}
		@imagedestroy($img);
		return new Skin($player->getSkin()->getSkinId(), $bytes, "", $geometryName, $geometryData);
	}

	private function findGeometryName(array $decodedJsonData) : ?string{
		if(isset($decodedJsonData["geometryName"])){
			return $decodedJsonData["geometryName"];
		}
		if(isset($decodedJsonData["minecraft:geometry"][0]["description"]["identifier"]))
			return $decodedJsonData["minecraft:geometry"][0]["description"]["identifier"];
		return null;
	}

	/**
	 * @param Skin $skin
	 *
	 * @return CompoundTag
	 */
	public function getSkinCompound(Skin $skin) : CompoundTag{
		$nbt = new CompoundTag("Skin");
		$nbt->setString("Name", $skin->getSkinId());
		$nbt->setByteArray("Data", $skin->getSkinData());
		$nbt->setByteArray("CapeData", $skin->getCapeData());
		$nbt->setString("GeometryName", $skin->getGeometryName());
		$nbt->setByteArray("GeometryData", $skin->getGeometryData());

		return $nbt;
	}

	/**
	 * @param Location $pos
	 *
	 * @return string
	 */
	public static function pos2hash(Location $pos) : string{
		return implode(":", [$pos->x, $pos->y, $pos->z, $pos->level->getFolderName()]);
	}

	/**
	 * @return PluginLang
	 * @internal
	 */
	public function getLanguage() : PluginLang{
		return $this->lang;
	}

	/**
	 * @return ImageConfig
	 * @deprecated
	 */
	public function getImageConfig() : ImageConfig{
		return $this->imageConfig;
	}

	/**
	 * @param EntityBase $entityBase
	 */
	public function removeEntity(EntityBase $entityBase){
		unset($this->entities[$entityBase->getId()]);
	}

	/**
	 * @return EntityBase[]
	 */
	public function getEntities() : array{
		return array_values($this->entities);
	}

	/**
	 * @param int $runtimeId
	 *
	 * @return EntityBase|null
	 */
	public function getEntity(int $runtimeId) : ?EntityBase{
		return $this->entities[$runtimeId] ?? null;
	}
}