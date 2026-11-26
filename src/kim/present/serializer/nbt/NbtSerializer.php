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

use kim\present\serializer\nbt\exception\SnbtDataException;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\LittleEndianNbtSerializer;
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

use function array_map;
use function base64_decode;
use function base64_encode;
use function bin2hex;
use function chr;
use function dechex;
use function get_class;
use function gzcompress;
use function gzdecode;
use function gzdeflate;
use function gzencode;
use function gzinflate;
use function gzuncompress;
use function hash;
use function hex2bin;
use function implode;
use function is_finite;
use function is_nan;
use function json_encode;
use function ksort;
use function str_pad;
use function str_repeat;
use function strlen;
use function strspn;
use function strtr;
use function unpack;
use function var_export;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const SORT_STRING;

final class NbtSerializer{

    private const SAFE_KEY_CHARS = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789._+-";

    /** Default maximum nesting depth of compound and list tags while deserializing (same as Minecraft) */
    public const DEFAULT_MAX_DEPTH = 512;

    /** Default maximum size of the decompressed data (64 MiB) */
    public const DEFAULT_MAX_DECOMPRESSED_LENGTH = 64 * 1024 * 1024;

    /** @var array<string, string>|null */
    private static ?array $escapeMap = null;

    private function __construct(){
        //NOOP
    }

    /**
     * Serialize the nbt tag to binary string
     * Warning : There is a possibility of data corruption if used without any additional encoding.
     */
    public static function toBinary(Tag $tag, Endian $endian = Endian::BIG) : string{
        return BinaryNbtCodec::get($endian)->encode($tag) ?? (
            $endian === Endian::BIG ? new BigEndianNbtSerializer() : new LittleEndianNbtSerializer()
        )->write(new TreeRoot($tag));
    }

    /**
     * Deserialize the nbt tag from binary string
     * Warning : There is a possibility of data corruption if used without any additional encoding.
     *
     * @throws NbtDataException
     */
    public static function fromBinary(
        string $contents,
        Endian $endian = Endian::BIG,
        int $maxDepth = self::DEFAULT_MAX_DEPTH
    ) : Tag{
        return BinaryNbtCodec::get($endian)->decode($contents, $maxDepth);
    }

    /** Serialize the nbt tag to base64 string (with binary string) */
    public static function toBase64(Tag $tag, Endian $endian = Endian::BIG) : string{
        return base64_encode(self::toBinary($tag, $endian));
    }

    /**
     * Deserialize the nbt tag from base64 string (with binary string)
     *
     * @throws \InvalidArgumentException if the contents are not valid base64
     * @throws NbtDataException
     */
    public static function fromBase64(
        string $contents,
        Endian $endian = Endian::BIG,
        int $maxDepth = self::DEFAULT_MAX_DEPTH
    ) : Tag{
        $decoded = base64_decode($contents, true);
        if($decoded === false){
            throw new \InvalidArgumentException('Invalid base64');
        }
        return self::fromBinary($decoded, $endian, $maxDepth);
    }

    /** Serialize the nbt tag to hex string (with binary string) */
    public static function toHex(Tag $tag, Endian $endian = Endian::BIG) : string{
        return bin2hex(self::toBinary($tag, $endian));
    }

    /**
     * Deserialize the nbt tag from hex string (with binary string)
     *
     * @throws \InvalidArgumentException if the contents are not valid hex
     * @throws NbtDataException
     */
    public static function fromHex(
        string $contents,
        Endian $endian = Endian::BIG,
        int $maxDepth = self::DEFAULT_MAX_DEPTH
    ) : Tag{
        $length = strlen($contents);
        if($length % 2 !== 0 || strspn($contents, "0123456789abcdefABCDEF") !== $length){
            throw new \InvalidArgumentException('Invalid hex');
        }
        return self::fromBinary((string) hex2bin($contents), $endian, $maxDepth);
    }

    /** Serialize the nbt tag to compressed binary string */
    public static function toCompressed(
        Tag $tag,
        Compression $compression = Compression::GZIP,
        Endian $endian = Endian::BIG,
        int $level = -1
    ) : string{
        $binary = self::toBinary($tag, $endian);
        $compressed = match ($compression) {
            Compression::GZIP    => gzencode($binary, $level),
            Compression::ZLIB    => gzcompress($binary, $level),
            Compression::DEFLATE => gzdeflate($binary, $level),
        };
        if($compressed === false){
            throw new \InvalidArgumentException("Failed to compress with level $level");
        }
        return $compressed;
    }

    /**
     * Deserialize the nbt tag from compressed binary string
     *
     * @param int $maxLength maximum size of the decompressed data, to protect against decompression bombs
     *
     * @throws NbtDataException if the contents can not be decompressed or are not valid NBT
     */
    public static function fromCompressed(
        string $contents,
        Compression $compression = Compression::GZIP,
        Endian $endian = Endian::BIG,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        int $maxLength = self::DEFAULT_MAX_DECOMPRESSED_LENGTH
    ) : Tag{
        // the zlib functions emit a warning on failure, which PocketMine-MP turns into an exception
        $decompressed = match ($compression) {
            Compression::GZIP    => @gzdecode($contents, $maxLength),
            Compression::ZLIB    => @gzuncompress($contents, $maxLength),
            Compression::DEFLATE => @gzinflate($contents, $maxLength),
        };
        if($decompressed === false){
            throw new NbtDataException("Failed to decompress the contents (corrupted, or larger than $maxLength bytes)");
        }
        return self::fromBinary($decompressed, $endian, $maxDepth);
    }

    /**
     * Hash the nbt tag. Equal tags always have the same hash, regardless of the order of the compound entries.
     *
     * @param string $algo any algorithm supported by {@see hash()}
     */
    public static function hash(Tag $tag, string $algo = "xxh128") : string{
        return hash($algo, self::toSnbt($tag, true));
    }

    /** Same as {@see fromBinary()}, but returns null instead of throwing for invalid contents */
    public static function tryFromBinary(
        string $contents,
        Endian $endian = Endian::BIG,
        int $maxDepth = self::DEFAULT_MAX_DEPTH
    ) : ?Tag{
        try{
            return self::fromBinary($contents, $endian, $maxDepth);
        }catch(NbtDataException){
            return null;
        }
    }

    /** Same as {@see fromBase64()}, but returns null instead of throwing for invalid contents */
    public static function tryFromBase64(
        string $contents,
        Endian $endian = Endian::BIG,
        int $maxDepth = self::DEFAULT_MAX_DEPTH
    ) : ?Tag{
        try{
            return self::fromBase64($contents, $endian, $maxDepth);
        }catch(NbtDataException | \InvalidArgumentException){
            return null;
        }
    }

    /** Same as {@see fromHex()}, but returns null instead of throwing for invalid contents */
    public static function tryFromHex(
        string $contents,
        Endian $endian = Endian::BIG,
        int $maxDepth = self::DEFAULT_MAX_DEPTH
    ) : ?Tag{
        try{
            return self::fromHex($contents, $endian, $maxDepth);
        }catch(NbtDataException | \InvalidArgumentException){
            return null;
        }
    }

    /** Same as {@see fromCompressed()}, but returns null instead of throwing for invalid contents */
    public static function tryFromCompressed(
        string $contents,
        Compression $compression = Compression::GZIP,
        Endian $endian = Endian::BIG,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        int $maxLength = self::DEFAULT_MAX_DECOMPRESSED_LENGTH
    ) : ?Tag{
        try{
            return self::fromCompressed($contents, $compression, $endian, $maxDepth, $maxLength);
        }catch(NbtDataException){
            return null;
        }
    }

    /** Same as {@see fromSnbt()}, but returns null instead of throwing for invalid contents */
    public static function tryFromSnbt(
        string $contents,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        ?int $maxLength = null
    ) : ?Tag{
        try{
            return self::fromSnbt($contents, $maxDepth, $maxLength);
        }catch(NbtDataException){
            return null;
        }
    }

    /**
     * Serialize the nbt tag to SNBT (stringified Named Binary Tag)
     *
     * @param bool $sortKeys sort the compound entries by key, so that equal tags always produce the same string
     */
    public static function toSnbt(Tag $tag, bool $sortKeys = true) : string{
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
                $value = $tag->getValue();
                if($sortKeys){
                    ksort($value, SORT_STRING);
                }
                $parts = [];
                foreach($value as $key => $child){
                    $parts[] = self::encodeKey((string) $key) . ":" . self::toSnbt($child, $sortKeys);
                }
                return "{" . implode(",", $parts) . "}";
            case ListTag::class:
                $parts = [];
                foreach($tag->getValue() as $child){
                    $parts[] = self::toSnbt($child, $sortKeys);
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
                    : "[I;" . implode(",", array_map(self::signInt(...), $value)) . "]";
            default:
                throw new \InvalidArgumentException("Unknown tag type " . get_class($tag));
        }
    }

    /**
     * Serialize the nbt tag to SNBT with spacing and indenting
     * Warning: The deeper the indenting, the more degraded the deserialize performance.
     *
     * @param bool $sortKeys sort the compound entries by key, so that equal tags always produce the same string
     */
    public static function toSnbtPretty(
        Tag $tag,
        int $indentLevel = 0,
        string $indentChar = "    ",
        string $lineBreak = "\n",
        bool $sortKeys = true
    ) : string{
        $class = get_class($tag);
        if($class !== CompoundTag::class && $class !== ListTag::class){
            return self::toSnbt($tag, $sortKeys);
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
            if($sortKeys){
                ksort($value, SORT_STRING);
            }
            foreach($value as $key => $child){
                $result .= ($result === "" ? "{" . $innerTap : $separator)
                    . self::encodeKey((string) $key) . ": "
                    . self::toSnbtPretty($child, $next, $indentChar, $lineBreak, $sortKeys);
            }
            return "$result$lineBreak$tap}";
        }

        foreach($value as $child){
            $result .= ($result === "" ? "[" . $innerTap : $separator)
                . self::toSnbtPretty($child, $next, $indentChar, $lineBreak, $sortKeys);
        }
        return "$result$lineBreak$tap]";
    }

    /** Normalize the unsigned 32-bit values (as decoded by pocketmine/nbt) to signed */
    private static function signInt(int $value) : int{
        return $value << 32 >> 32;
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
        // fast path : the short representation (precision ini, 14 digits by default) when it is lossless
        $short = (string) $value;
        if((float) $short === $value){
            return $short;
        }
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
        // fast path : json_encode() does the same escaping for valid UTF-8 strings
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if($json !== false){
            return $json;
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
     * @param int      $maxDepth  maximum nesting depth of compound and list tags
     * @param int|null $maxLength maximum length of the contents in bytes, null for no limit
     *
     * @throws NbtDataException if the contents are not a single valid SNBT value
     */
    public static function fromSnbt(
        string $contents,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        ?int $maxLength = null
    ) : Tag{
        if($maxLength !== null && strlen($contents) > $maxLength){
            throw new SnbtDataException("contents are longer than $maxLength bytes");
        }
        return (new StringifiedNbtParser($contents, $maxDepth))->readRoot();
    }

}
