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

use function addcslashes;
use function chr;
use function is_numeric;
use function json_decode;
use function preg_match;
use function preg_match_all;
use function str_contains;
use function strcspn;
use function strlen;
use function strspn;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

final class StringifiedNbtParser{

    /** Separators skipped between entries : a comma and every character that is less than or equal to a space */
    private const SEPARATORS = "," . "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f ";

    private string $buffer;
    private int $offset = 0;

    public function __construct(string $buffer){
        $this->buffer = trim($buffer);
    }

    public function readTag() : Tag{
        $value = "";

        while(isset($this->buffer[$this->offset])){
            // consume the plain run of characters up to the next structural character at once
            $runLength = strcspn($this->buffer, ",}]\"'{[", $this->offset);
            if($runLength > 0){
                $value .= substr($this->buffer, $this->offset, $runLength);
                $this->offset += $runLength;
                if(!isset($this->buffer[$this->offset])){
                    break;
                }
            }

            $c = $this->buffer[$this->offset++];
            if($c === "," || $c === "}" || $c === "]"){ // end of parent tag
                $this->offset--;
                break;
            }

            if($c === '"' || $c === "'"){ // start of quoted string
                return new StringTag($this->readEscapedString($c));
            }

            if($c === "{"){ // start of compound tag
                return $this->readCompoundTag();
            }

            // start of collection
            if(isset($this->buffer[$this->offset + 2])){
                $listHead = strtoupper(substr($this->buffer, $this->offset, 2));
                if($listHead === "B;"){
                    $this->offset += 2;
                    return $this->getByteArrayTag();
                }elseif($listHead === "I;"){
                    $this->offset += 2;
                    return $this->getIntArrayTag();
                }
            }
            return $this->getListTag();
        }

        $value = trim($value);
        if($value === ""){
            throw new SnbtDataException(
                "empty value in '" . addcslashes($value, "\r\n ") . "'",
                $this->offset
            );
        }

        $last = strtolower($value[-1]);
        if($last === "b" || $last === "s" || $last === "l" || $last === "f" || $last === "d"){
            $value = substr($value, 0, -1);
        }else{
            $last = null;
        }

        if(is_numeric($value)){
            return match ($last) {
                "b"     => new ByteTag((int) $value),
                "s"     => new ShortTag((int) $value),
                "l"     => new LongTag((int) $value),
                "f"     => new FloatTag((float) $value),
                "d"     => new DoubleTag((float) $value),
                default => str_contains($value, ".") ? new DoubleTag((float) $value) : new IntTag((int) $value)
            };
        }

        return new StringTag($value . $last);
    }

    private function getListTag() : ListTag{
        $result = new ListTag();
        while(true){
            $this->offset += strspn($this->buffer, self::SEPARATORS, $this->offset);
            if(!isset($this->buffer[$this->offset])){
                break;
            }

            $c = $this->buffer[$this->offset++];
            if($c === "]"){
                return $result;
            }

            $this->offset--;
            $result->push($this->readTag());
        }
        throw new SnbtUnexpectedEndException("']'");
    }

    private function getByteArrayTag() : ByteArrayTag{
        $closePos = strpos($this->buffer, "]", $this->offset);
        if($closePos === false){
            throw new SnbtUnexpectedEndException("']'");
        }

        $offset = $this->offset;
        $this->offset = $closePos + 1;
        if($offset === $closePos){
            return new ByteArrayTag("");
        }

        $value = substr($this->buffer, $offset, $closePos - $offset);
        if(preg_match("/^(\s*-?\d{0,3}b\s*(,|$)\s*)*$/", $value) !== 1){
            throw new SnbtSyntaxException("unexpected char", $offset, "byte entries");
        }

        preg_match_all("/(-?\d+)b/", $value, $matches);
        $result = "";
        foreach($matches[1] as $ord){
            $result .= chr((int) $ord);
        }
        return new ByteArrayTag($result);
    }

    private function getIntArrayTag() : IntArrayTag{
        $closePos = strpos($this->buffer, "]", $this->offset);
        if($closePos === false){
            throw new SnbtUnexpectedEndException("']'");
        }

        $offset = $this->offset;
        $this->offset = $closePos + 1;
        if($offset === $closePos){
            return new IntArrayTag([]);
        }

        $value = substr($this->buffer, $offset, $closePos - $offset);
        if(preg_match("/^(\s*-?\d+\s*(,|$)\s*)*$/", $value) !== 1){
            throw new SnbtSyntaxException("unexpected char", $offset, "integer entries");
        }

        preg_match_all("/(-?\d+)/", $value, $matches);
        $result = [];
        foreach($matches[1] as $ord){
            $result[] = (int) $ord;
        }
        $this->offset = $closePos + 1;
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
                $key = $this->readEscapedString($c);

                $c = $this->buffer[$this->offset++];
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

    private function readEscapedString(string $quote) : string{
        $substrOffset = $this->offset;
        while(true){
            $closePos = strpos($this->buffer, $quote, $this->offset);
            if($closePos === false){
                throw new SnbtUnexpectedEndException("'$quote'");
            }

            $this->offset = $closePos + 1;
            if($this->buffer[$closePos - 1] === "\\"){
                continue;
            }else{
                $raw = substr($this->buffer, $substrOffset, $closePos - $substrOffset);
                // fast path : no escapes, no control characters and valid UTF-8 means json_decode is an identity
                if(strcspn($raw, "\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f") === strlen($raw)
                    && preg_match("//u", $raw) === 1
                ){
                    return $raw;
                }
                return json_decode('"' . $raw . '"');
            }
        }
    }

}
