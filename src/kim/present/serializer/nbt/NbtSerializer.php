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
use pocketmine\nbt\NbtDataException;
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
use function chr;
use function get_class;
use function hex2bin;
use function dechex;
use function implode;
use function is_finite;
use function is_nan;
use function str_pad;
use function str_repeat;
use function strlen;
use function strcspn;
use function strspn;
use function strtr;
use function unpack;
use function var_export;

final class NbtSerializer{

    private const SAFE_KEY_CHARS = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._+-";

    /** Characters that must be escaped in quoted strings : quotes, backslashes and control characters */
    private const ESCAPE_CHARS = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f\x7f";

    private static ?BigEndianNbtSerializer $binarySerializer = null;

    /** @var array<string, string>|null */
    private static ?array $escapeMap = null;

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
                return self::encodeFloat($tag->getValue()) . "f";
            case DoubleTag::class:
                return self::encodeFloat($tag->getValue()) . "d";
            case StringTag::class:
                return self::quoteString($tag->getValue());
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
                    : "[B;" . implode("b,", unpack("c*", $value)) . "b]";
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

    /** Quote the compound key only when it is empty or contains characters outside of [a-zA-Z0-9._+-] */
    private static function encodeKey(string $key) : string{
        return $key !== "" && strspn($key, self::SAFE_KEY_CHARS) === strlen($key) ? $key : self::quoteString($key);
    }

    /**
     * Format the float without losing precision (shortest round-trip representation).
     * Non-finite values are written as NaN, Infinity and -Infinity.
     */
    private static function encodeFloat(float $value) : string{
        if(is_finite($value)){
            return var_export($value, true);
        }
        return is_nan($value) ? "NaN" : ($value > 0 ? "Infinity" : "-Infinity");
    }

    /**
     * Quote the string with double quotes, escaping only quotes, backslashes and control characters.
     * Other bytes are written as-is, so strings that are not valid UTF-8 are preserved too.
     */
    private static function quoteString(string $value) : string{
        if(strcspn($value, self::ESCAPE_CHARS) === strlen($value)){
            return '"' . $value . '"';
        }
        return '"' . strtr($value, self::$escapeMap ??= self::buildEscapeMap()) . '"';
    }

    /** @return array<string, string> */
    private static function buildEscapeMap() : array{
        $map = ['"' => '\\"', "\\" => "\\\\", "\x7f" => "\\u007f"];
        for($i = 0; $i < 0x20; ++$i){
            $map[chr($i)] = "\\u" . str_pad(dechex($i), 4, "0", STR_PAD_LEFT);
        }
        $map["\n"] = "\\n";
        $map["\r"] = "\\r";
        $map["\t"] = "\\t";
        $map["\x08"] = "\\b";
        $map["\x0c"] = "\\f";
        return $map;
    }

    /**
     * Deserialize the nbt tag from SNBT (stringified Named Binary Tag)
     *
     * @throws NbtDataException if the contents are not a single valid SNBT value
     */
    public static function fromSnbt(string $contents) : Tag{
        return (new StringifiedNbtParser($contents))->readRoot();
    }

}
