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

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function get_class;
use function hex2bin;
use function implode;
use function json_encode;
use function str_repeat;
use function strlen;
use function strspn;
use function unpack;

final class NbtSerializer{

    private const SAFE_KEY_CHARS = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._+-";

    private static ?BigEndianNbtSerializer $binarySerializer = null;

    private static function binarySerializer() : BigEndianNbtSerializer{
        return self::$binarySerializer ??= new BigEndianNbtSerializer();
    }

    /**
     * Serialize the nbt tag to binary string
     * Warning : There is a possibility of data corruption if used without any additional encoding.
     */
    public static function toBinary(Tag $tag) : string{
        return BigEndianNbtCodec::encode($tag) ?? self::binarySerializer()->write(new TreeRoot($tag));
    }

    /**
     * Deserialize the nbt tag from binary string
     * Warning : There is a possibility of data corruption if used without any additional encoding.
     */
    public static function fromBinary(string $contents) : Tag{
        return BigEndianNbtCodec::decode($contents);
    }

    /** Serialize the nbt tag to base64 string (with binary string) */
    public static function toBase64(Tag $tag) : string{
        return base64_encode(self::toBinary($tag));
    }

    /** Deserialize the nbt tag from base64 string (with binary string) */
    public static function fromBase64(string $contents) : Tag{
        $decoded = base64_decode($contents, true);
        if($decoded === false){
            throw new \InvalidArgumentException('Invalid base64');
        }
        return self::fromBinary($decoded);
    }

    /** Serialize the nbt tag to hex string (with binary string) */
    public static function toHex(Tag $tag) : string{
        return bin2hex(self::toBinary($tag));
    }

    /** Deserialize the nbt tag from hex string (with binary string) */
    public static function fromHex(string $contents) : Tag{
        $decoded = hex2bin($contents);
        if($decoded === false){
            throw new \InvalidArgumentException('Invalid hex');
        }
        return self::fromBinary($decoded);
    }

    /** Serialize the nbt tag to SNBT (stringified Named Binary Tag) */
    public static function toSnbt(Tag $tag) : string{
        switch(get_class($tag)){
            case ByteTag::class:
                return $tag->getValue() . "b";
            case ShortTag::class:
                return $tag->getValue() . "s";
            case IntTag::class:
                return (string) $tag->getValue();
            case LongTag::class:
                return $tag->getValue() . "l";
            case FloatTag::class:
                return $tag->getValue() . "f";
            case DoubleTag::class:
                return $tag->getValue() . "d";
            case StringTag::class:
                $j = json_encode($tag->getValue());
                return $j !== false ? $j : '""';
            case CompoundTag::class:
                $parts = [];
                foreach($tag->getValue() as $key => $child){
                    $parts[] = self::encodeKey((string) $key) . ":" . self::toSnbt($child);
                }
                return "{" . implode(",", $parts) . "}";
            case ListTag::class:
                $parts = [];
                foreach($tag->getValue() as $child){
                    $parts[] = self::toSnbt($child);
                }
                return "[" . implode(",", $parts) . "]";
            case ByteArrayTag::class:
                $value = $tag->getValue();
                return $value === ''
                    ? "[B;]"
                    : "[B;" . implode("b,", unpack("C*", $value)) . "b]";
            case IntArrayTag::class:
                $value = $tag->getValue();
                return $value === []
                    ? "[I;]"
                    : "[I;" . implode(",", $value) . "]";
            default:
                throw new \InvalidArgumentException("Unknown tag type " . get_class($tag));
        }
    }

    /**
     * Serialize the nbt tag to SNBT with spacing and indenting
     * Warning: The deeper the indenting, the more degraded the deserialize performance.
     */
    public static function toSnbtPretty(
        Tag $tag,
        int $indentLevel = 0,
        string $indentChar = "    ",
        string $lineBreak = "\n"
    ) : string{
        $class = get_class($tag);
        if($class !== CompoundTag::class && $class !== ListTag::class){
            return self::toSnbt($tag);
        }

        $value = $tag->getValue();
        if($value === []){
            return $class === CompoundTag::class ? "{}" : "[]";
        }

        $tap = str_repeat($indentChar, $indentLevel);
        $innerTap = "$lineBreak$tap$indentChar";
        $separator = ", $innerTap";
        $next = $indentLevel + 1;
        $result = "";
        if($class === CompoundTag::class){
            foreach($value as $key => $child){
                $result .= ($result === "" ? "{" . $innerTap : $separator)
                    . self::encodeKey((string) $key) . ": "
                    . self::toSnbtPretty($child, $next, $indentChar, $lineBreak);
            }
            return "$result$lineBreak$tap}";
        }

        foreach($value as $child){
            $result .= ($result === "" ? "[" . $innerTap : $separator)
                . self::toSnbtPretty($child, $next, $indentChar, $lineBreak);
        }
        return "$result$lineBreak$tap]";
    }

    /** Quote the compound key only when it contains characters outside of [a-zA-Z0-9._+-] */
    private static function encodeKey(string $key) : string{
        return strspn($key, self::SAFE_KEY_CHARS) === strlen($key) ? $key : json_encode($key);
    }

    /** Deserialize the nbt tag from SNBT (stringified Named Binary Tag) */
    public static function fromSnbt(string $contents) : Tag{
        return (new StringifiedNbtParser($contents))->readTag();
    }

}
