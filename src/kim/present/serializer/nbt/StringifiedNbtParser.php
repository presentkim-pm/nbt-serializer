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
use kim\present\serializer\nbt\exception\SnbtSyntaxException;
use kim\present\serializer\nbt\exception\SnbtUnexpectedEndException;
use pocketmine\nbt\InvalidTagValueException;
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

use function array_pop;
use function chr;
use function count;
use function explode;
use function hexdec;
use function is_int;
use function is_numeric;
use function preg_match;
use function preg_replace_callback;
use function strcspn;
use function strlen;
use function strpbrk;
use function strpos;
use function strspn;
use function strtoupper;
use function substr;
use function trim;

use const INF;
use const NAN;

final class StringifiedNbtParser{

    /** Characters that are less than or equal to a space */
    private const WHITESPACES = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f ";

    /** Separators skipped between entries : a comma and every whitespace */
    private const SEPARATORS = "," . self::WHITESPACES;

    /** Type suffix characters (case-insensitive) mapped to their lowercase form */
    private const SUFFIXES = [
        "b" => "b", "B" => "b",
        "s" => "s", "S" => "s",
        "l" => "l", "L" => "l",
        "f" => "f", "F" => "f",
        "d" => "d", "D" => "d",
    ];

    /** Non-finite float literals, only accepted with the 'f' or 'd' suffix */
    private const NON_FINITE = [
        "NaN"       => NAN,
        "Infinity"  => INF,
        "+Infinity" => INF,
        "-Infinity" => -INF,
    ];

    /** Single character escapes */
    private const ESCAPES = [
        '"'  => '"',
        "'"  => "'",
        "\\" => "\\",
        "/"  => "/",
        "b"  => "\x08",
        "f"  => "\x0c",
        "n"  => "\n",
        "r"  => "\r",
        "t"  => "\t",
    ];

    /** Maximum nesting depth of compound and list tags (same as Minecraft) */
    public const MAX_DEPTH = 512;

    private string $buffer;
    private int $offset = 0;
    private int $depth = 0;

    public function __construct(string $buffer){
        $this->buffer = trim($buffer);
    }

    /**
     * Read a single tag that must span the whole buffer.
     *
     * @throws SnbtSyntaxException if there is trailing data after the tag
     * @throws SnbtDataException
     */
    public function readRoot() : Tag{
        $tag = $this->readTag();
        if(isset($this->buffer[$this->offset])){
            throw new SnbtSyntaxException("unexpected '{$this->buffer[$this->offset]}'", $this->offset, "end of input");
        }
        return $tag;
    }

    public function readTag() : Tag{
        $buffer = $this->buffer;
        $offset = $this->offset;

        // consume the plain run of characters up to the next structural character at once
        $runLength = isset($buffer[$offset]) ? strcspn($buffer, ",}]\"'{[", $offset) : 0;
        $end = $offset + $runLength;
        $c = $buffer[$end] ?? null;

        if($c === null || $c === "," || $c === "}" || $c === "]"){ // end of the value (or of the parent tag)
            $this->offset = $end;
            $value = trim(substr($buffer, $offset, $runLength));
            if($value === ""){
                throw new SnbtDataException("empty value", $end);
            }
            return self::parseValue($value, $offset);
        }

        if($runLength !== 0 && strspn($buffer, self::WHITESPACES, $offset, $runLength) !== $runLength){
            throw new SnbtSyntaxException("unexpected '$c'", $end);
        }

        $this->offset = $end + 1;
        if($c === '"' || $c === "'"){ // start of quoted string
            return new StringTag($this->readQuotedString($c));
        }

        if($c === "{"){ // start of compound tag
            $this->enter();
            $tag = $this->readCompoundTag();
            $this->depth--;
            return $tag;
        }

        // start of collection
        if(isset($buffer[$this->offset + 1])){
            $listHead = strtoupper(substr($buffer, $this->offset, 2));
            if($listHead === "B;"){
                $this->offset += 2;
                return $this->readByteArrayTag();
            }elseif($listHead === "I;"){
                $this->offset += 2;
                return $this->readIntArrayTag();
            }
        }
        $this->enter();
        $tag = $this->readListTag();
        $this->depth--;
        return $tag;
    }

    private function enter() : void{
        if(++$this->depth > self::MAX_DEPTH){
            throw new SnbtDataException("nesting depth exceeds " . self::MAX_DEPTH, $this->offset - 1);
        }
    }

    /** Parse the unquoted value (number with optional type suffix, or plain string) */
    private static function parseValue(string $value, int $offset) : Tag{
        $suffix = self::SUFFIXES[$value[-1]] ?? null;
        $number = $suffix !== null ? substr($value, 0, -1) : $value;

        if(($suffix === "f" || $suffix === "d") && isset(self::NON_FINITE[$number])){
            $float = self::NON_FINITE[$number];
            return $suffix === "f" ? new FloatTag($float) : new DoubleTag($float);
        }

        if(!is_numeric($number)){
            return new StringTag($value);
        }

        try{
            if($suffix === "f"){
                return new FloatTag((float) $number);
            }
            if($suffix === "d"){
                return new DoubleTag((float) $number);
            }

            if(strpbrk($number, ".eE") !== false){ // not an integer
                return $suffix === null ? new DoubleTag((float) $number) : new StringTag($value);
            }

            $int = isset($number[18]) ? +$number : (int) $number; // up to 18 characters always fit in 64-bit
            if(!is_int($int)){
                throw new SnbtDataException("integer '$number' is out of 64-bit range", $offset);
            }
            return match ($suffix) {
                null => new IntTag($int),
                "b"  => new ByteTag($int),
                "s"  => new ShortTag($int),
                "l"  => new LongTag($int)
            };
        }catch(InvalidTagValueException $e){
            throw new SnbtDataException($e->getMessage(), $offset);
        }
    }

    private function readListTag() : ListTag{
        $result = new ListTag();
        while(true){
            $this->offset += strspn($this->buffer, self::SEPARATORS, $this->offset);
            if(!isset($this->buffer[$this->offset])){
                break;
            }

            if($this->buffer[$this->offset] === "]"){
                $this->offset++;
                return $result;
            }

            $offset = $this->offset;
            $tag = $this->readTag();
            if($result->count() !== 0 && $tag->getType() !== $result->getTagType()){
                throw new SnbtDataException("list entries must be the same type", $offset);
            }
            $result->push($tag);
        }
        throw new SnbtUnexpectedEndException("']'");
    }

    /**
     * Read the comma separated entries of an array (until ']') and validate them with the pattern
     *
     * @return string[] the first capture group of each entry
     */
    private function readArrayEntries(string $pattern, string $expected) : array{
        $closePos = strpos($this->buffer, "]", $this->offset);
        if($closePos === false){
            throw new SnbtUnexpectedEndException("']'");
        }

        $offset = $this->offset;
        $this->offset = $closePos + 1;

        $entries = explode(",", substr($this->buffer, $offset, $closePos - $offset));
        if(trim($entries[count($entries) - 1]) === ""){ // allow empty array and trailing comma
            array_pop($entries);
        }

        $result = [];
        foreach($entries as $entry){
            if(preg_match($pattern, trim($entry), $match) !== 1){
                throw new SnbtSyntaxException("unexpected '" . trim($entry) . "'", $offset, $expected);
            }
            $result[] = $match[1];
        }
        return $result;
    }

    private function readByteArrayTag() : ByteArrayTag{
        $offset = $this->offset;
        $result = "";
        foreach($this->readArrayEntries('/^([+-]?\d+)[bB]$/', "byte entries") as $entry){
            $value = (int) $entry;
            if($value < -128 || $value > 255){ // unsigned values (0 ~ 255) are accepted for the data written by older versions
                throw new SnbtDataException("byte '$entry' is out of range", $offset);
            }
            $result .= chr($value & 0xff);
        }
        return new ByteArrayTag($result);
    }

    private function readIntArrayTag() : IntArrayTag{
        $offset = $this->offset;
        $result = [];
        foreach($this->readArrayEntries('/^([+-]?\d+)$/', "integer entries") as $entry){
            $value = +$entry;
            // unsigned values (0 ~ 0xffffffff) are accepted for the arrays decoded as unsigned by pocketmine/nbt
            if(!is_int($value) || $value < -0x80000000 || $value > 0xffffffff){
                throw new SnbtDataException("integer '$entry' is out of range", $offset);
            }
            $result[] = $value << 32 >> 32;
        }
        return new IntArrayTag($result);
    }

    private function readCompoundTag() : CompoundTag{
        $tag = CompoundTag::create();
        while(true){
            $this->offset += strspn($this->buffer, self::SEPARATORS, $this->offset);
            if(!isset($this->buffer[$this->offset])){
                break;
            }

            $c = $this->buffer[$this->offset++];
            if($c === "}"){
                return $tag;
            }

            if($c === ':'){
                $key = "";
            }elseif($c === '"' || $c === "'"){
                $key = $this->readQuotedString($c);

                $this->offset += strspn($this->buffer, self::WHITESPACES, $this->offset);
                $c = $this->buffer[$this->offset++] ?? null;
                if($c === null){
                    throw new SnbtUnexpectedEndException("':'");
                }
                if($c !== ":"){
                    throw new SnbtSyntaxException("unexpected '$c'", $this->offset - 1, "':'");
                }
            }else{
                $closePos = strpos($this->buffer, ":", $this->offset);
                if($closePos === false){
                    throw new SnbtUnexpectedEndException("':'");
                }
                $key = trim($c . substr($this->buffer, $this->offset, $closePos - $this->offset));
                $this->offset = $closePos + 1;
            }
            $tag->setTag($key, $this->readTag());
        }

        throw new SnbtUnexpectedEndException("'}'");
    }

    /** Read the string until the closing quote (the opening quote is already consumed) and unescape it */
    private function readQuotedString(string $quote) : string{
        $start = $this->offset;
        $length = strlen($this->buffer);
        $stops = $quote . "\\";
        $escaped = false;
        $pos = $start;
        while(true){
            $pos += strcspn($this->buffer, $stops, $pos);
            if($pos >= $length){
                throw new SnbtUnexpectedEndException("'$quote'");
            }
            if($this->buffer[$pos] === $quote){
                break;
            }
            // backslash : skip the escaped character
            $escaped = true;
            $pos += 2;
        }

        $this->offset = $pos + 1;
        $raw = substr($this->buffer, $start, $pos - $start);
        return $escaped ? self::unescape($raw, $start) : $raw;
    }

    private static function unescape(string $raw, int $offset) : string{
        return preg_replace_callback(
            '/\\\\(?:u([dD][89abAB][0-9a-fA-F]{2})\\\\u([dD][c-fC-F][0-9a-fA-F]{2})|u([0-9a-fA-F]{4})|(.))/s',
            static function(array $m) use ($offset) : string{
                if(($m[4] ?? "") !== ""){
                    return self::ESCAPES[$m[4]] ?? throw new SnbtSyntaxException("invalid escape '\\{$m[4]}'", $offset);
                }
                if(($m[3] ?? "") !== ""){
                    $codePoint = (int) hexdec($m[3]);
                    if($codePoint >= 0xd800 && $codePoint <= 0xdfff){
                        throw new SnbtSyntaxException("lone surrogate '\\u{$m[3]}'", $offset);
                    }
                }else{
                    $codePoint = 0x10000 + (((int) hexdec($m[1]) - 0xd800) << 10) + ((int) hexdec($m[2]) - 0xdc00);
                }
                return self::utf8($codePoint);
            },
            $raw
        );
    }

    private static function utf8(int $codePoint) : string{
        if($codePoint < 0x80){
            return chr($codePoint);
        }
        if($codePoint < 0x800){
            return chr(0xc0 | ($codePoint >> 6)) . chr(0x80 | ($codePoint & 0x3f));
        }
        if($codePoint < 0x10000){
            return chr(0xe0 | ($codePoint >> 12)) . chr(0x80 | (($codePoint >> 6) & 0x3f)) . chr(0x80 | ($codePoint & 0x3f));
        }
        return chr(0xf0 | ($codePoint >> 18)) . chr(0x80 | (($codePoint >> 12) & 0x3f))
            . chr(0x80 | (($codePoint >> 6) & 0x3f)) . chr(0x80 | ($codePoint & 0x3f));
    }

}
