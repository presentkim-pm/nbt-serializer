<?php

/**
 *
 *  ____                           _   _  ___
 * |  _ \ _ __ ___  ___  ___ _ __ | |_| |/ (_)_ __ ___
 * | |_) | '__/ _ \/ __|/ _ \ '_ \| __| ' /| | '_ ` _ \
 * |  __/| | |  __/\__ \  __/ | | | |_| . \| | | | | | |
 * |_|   |_|  \___||___/\___|_| |_|\__|_|\_\_|_| |_| |_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the MIT License. see <https://opensource.org/licenses/MIT>.
 *
 * @author       PresentKim (debe3721@gmail.com)
 * @link         https://github.com/PresentKim
 * @license      https://opensource.org/licenses/MIT MIT License
 *
 *   (\ /)
 *  ( . .) ♥
 *  c(")(")
 *
 * @noinspection PhpUnused
 */

declare(strict_types=1);

namespace kim\present\serializer\nbt;

use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\ShortTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;

use function array_keys;
use function array_map;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function get_class;
use function hex2bin;
use function implode;
use function ord;
use function str_split;

final class NbtSerializer{
	/**
	 * Serialize the nbt tag to binary string
	 * Warning : There is a possibility of data corruption if used without any additional encoding.
	 */
	public static function toBinary(Tag $tag) : string{
		return (new BigEndianNbtSerializer())->write(new TreeRoot($tag));
	}

	/**
	 * Deserialize the nbt tag from binary string
	 * Warning : There is a possibility of data corruption if used without any additional encoding.
	 */
	public static function fromBinary(string $contents) : Tag{
		return (new BigEndianNbtSerializer())->read($contents)->getTag();
	}

	/** Serialize the nbt tag to base64 string (with binary string) */
	public static function toBase64(Tag $tag) : string{
		return base64_encode(self::toBinary($tag));
	}

	/** Deserialize the nbt tag from base64 string (with binary string) */
	public static function fromBase64(string $contents) : Tag{
		return self::fromBinary(base64_decode($contents, true));
	}

	/** Serialize the nbt tag to hex string (with binary string) */
	public static function toHex(Tag $tag) : string{
		return bin2hex(self::toBinary($tag));
	}

	/** Deserialize the nbt tag from hex string (with binary string) */
	public static function fromHex(string $contents) : Tag{
		return self::fromBinary(hex2bin($contents));
	}

	/** Serialize the nbt tag to SNBT (stringified Named Binary Tag) */
	public static function toSnbt(Tag $tag) : string{
		return match (get_class($tag)) {
			ByteTag::class      => $tag->getValue() . "b",
			ShortTag::class     => $tag->getValue() . "s",
			IntTag::class       => $tag->getValue() . "",
			LongTag::class      => $tag->getValue() . "l",
			FloatTag::class     => $tag->getValue() . "f",
			DoubleTag::class    => $tag->getValue() . "d",
			StringTag::class    => '"' . $tag->getValue() . '"',
			CompoundTag::class  => "{" . implode(",", array_map(
					static fn(string $key, Tag $value) : string => $key . ":" . self::toSnbt($value),
					array_keys($tag->getValue()),
					$tag->getValue()
				)) . "}",
			ListTag::class      => "[" . implode(",", array_map(
					static fn(Tag $value) : string => self::toSnbt($value),
					$tag->getValue()
				)) . "]",
			ByteArrayTag::class => "[B;" . implode(",", array_map(
					static fn(string $char) : string => ord($char) . "b",
					str_split($tag->getValue())
				)) . "]",
			IntArrayTag::class  => "[I;" . implode(",", array_map(
					static fn(int $value) : string => $value . "",
					$tag->getValue()
				)) . "]",
			// long-array is not supported
			default             => throw new \InvalidArgumentException("Unknown tag type " . get_class($tag))
		};
	}

	/** Deserialize the nbt tag from SNBT (stringified Named Binary Tag) */
	public static function fromSnbt(string $contents) : Tag{
		return (new StringifiedNbtParser($contents))->getSnbt();
	}
}
