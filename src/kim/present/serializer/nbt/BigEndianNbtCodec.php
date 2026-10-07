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

use function array_values;
use function chr;
use function count;
use function get_class;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Big endian NBT codec producing and accepting exactly the same bytes (and failing with the same exceptions)
 * as {@see \pocketmine\nbt\BigEndianNbtSerializer}, without its per-value stream and closure overhead.
 *
 * @internal
 */
final class BigEndianNbtCodec{

    private const TYPE_BYTE = 1;
    private const TYPE_SHORT = 2;
    private const TYPE_INT = 3;
    private const TYPE_LONG = 4;
    private const TYPE_FLOAT = 5;
    private const TYPE_DOUBLE = 6;
    private const TYPE_BYTE_ARRAY = 7;
    private const TYPE_STRING = 8;
    private const TYPE_LIST = 9;
    private const TYPE_COMPOUND = 10;
    private const TYPE_INT_ARRAY = 11;

    private const TYPE_OF_CLASS = [
        ByteTag::class      => self::TYPE_BYTE,
        ShortTag::class     => self::TYPE_SHORT,
        IntTag::class       => self::TYPE_INT,
        LongTag::class      => self::TYPE_LONG,
        FloatTag::class     => self::TYPE_FLOAT,
        DoubleTag::class    => self::TYPE_DOUBLE,
        ByteArrayTag::class => self::TYPE_BYTE_ARRAY,
        StringTag::class    => self::TYPE_STRING,
        ListTag::class      => self::TYPE_LIST,
        CompoundTag::class  => self::TYPE_COMPOUND,
        IntArrayTag::class  => self::TYPE_INT_ARRAY,
    ];

    private const MAX_STRING_LENGTH = 32767;

    private function __construct(){
        //NOOP
    }

    /**
     * Encodes the tag as a root tag with an empty name.
     *
     * @return string|null null if the tree contains a tag type that this codec does not know
     */
    public static function encode(Tag $tag) : ?string{
        $type = self::TYPE_OF_CLASS[get_class($tag)] ?? null;
        if($type === null){
            return null;
        }

        $out = chr($type) . "\x00\x00";
        return self::writeBody($tag, $type, $out) ? $out : null;
    }

    private static function writeBody(Tag $tag, int $type, string &$out) : bool{
        switch($type){
            case self::TYPE_BYTE:
                $out .= chr($tag->getValue() & 0xff);
                return true;
            case self::TYPE_SHORT:
                $out .= pack("n", $tag->getValue());
                return true;
            case self::TYPE_INT:
                $out .= pack("N", $tag->getValue());
                return true;
            case self::TYPE_LONG:
                $out .= pack("J", $tag->getValue());
                return true;
            case self::TYPE_FLOAT:
                $out .= pack("G", $tag->getValue());
                return true;
            case self::TYPE_DOUBLE:
                $out .= pack("E", $tag->getValue());
                return true;
            case self::TYPE_BYTE_ARRAY:
                $value = $tag->getValue();
                $out .= pack("N", strlen($value)) . $value;
                return true;
            case self::TYPE_STRING:
                $value = $tag->getValue();
                $out .= pack("n", self::checkWriteStringLength(strlen($value))) . $value;
                return true;
            case self::TYPE_INT_ARRAY:
                $value = $tag->getValue();
                $out .= pack("N", count($value)) . pack("N*", ...$value);
                return true;
            case self::TYPE_LIST:
                $children = $tag->getValue();
                $out .= chr($tag->getTagType() & 0xff) . pack("N", count($children));
                foreach($children as $child){
                    $childType = self::TYPE_OF_CLASS[get_class($child)] ?? null;
                    if($childType === null || !self::writeBody($child, $childType, $out)){
                        return false;
                    }
                }
                return true;
            case self::TYPE_COMPOUND:
                foreach($tag->getValue() as $name => $child){
                    $childType = self::TYPE_OF_CLASS[get_class($child)] ?? null;
                    if($childType === null){
                        return false;
                    }

                    $name = (string) $name;
                    $out .= chr($childType) . pack("n", self::checkWriteStringLength(strlen($name))) . $name;
                    if(!self::writeBody($child, $childType, $out)){
                        return false;
                    }
                }
                $out .= "\x00";
                return true;
        }
        return false;
    }

    private static function checkWriteStringLength(int $length) : int{
        if($length > self::MAX_STRING_LENGTH){
            throw new \InvalidArgumentException("NBT string length too large ($length > " . self::MAX_STRING_LENGTH . ")");
        }
        return $length;
    }

    /**
     * Decodes the root tag of the given buffer (the root name is discarded, trailing data is ignored).
     *
     * @throws NbtDataException
     */
    public static function decode(string $buffer) : Tag{
        $length = strlen($buffer);
        $offset = 0;

        self::need($length, $offset, 1);
        $type = ord($buffer[$offset++]);
        if($type === 0){
            throw new NbtDataException("Found TAG_End at the start of buffer");
        }

        self::readString($buffer, $length, $offset); // root name
        return self::readTag($type, $buffer, $length, $offset);
    }

    /** @throws NbtDataException */
    private static function need(int $length, int $offset, int $required) : void{
        if($length - $offset < $required){
            throw new NbtDataException("Not enough bytes left in buffer: need $required, have " . ($length - $offset));
        }
    }

    /** @throws NbtDataException */
    private static function readString(string $buffer, int $length, int &$offset) : string{
        self::need($length, $offset, 2);
        $stringLength = (ord($buffer[$offset]) << 8) | ord($buffer[$offset + 1]);
        $offset += 2;
        if($stringLength > self::MAX_STRING_LENGTH){
            throw new NbtDataException("NBT string length too large ($stringLength > " . self::MAX_STRING_LENGTH . ")");
        }
        if($stringLength === 0){
            return "";
        }

        self::need($length, $offset, $stringLength);
        $offset += $stringLength;
        return substr($buffer, $offset - $stringLength, $stringLength);
    }

    /** @throws NbtDataException */
    private static function readTag(int $type, string $buffer, int $length, int &$offset) : Tag{
        switch($type){
            case self::TYPE_BYTE:
                self::need($length, $offset, 1);
                return new ByteTag(ord($buffer[$offset++]) << 56 >> 56);
            case self::TYPE_SHORT:
                self::need($length, $offset, 2);
                $value = ((ord($buffer[$offset]) << 8) | ord($buffer[$offset + 1])) << 48 >> 48;
                $offset += 2;
                return new ShortTag($value);
            case self::TYPE_INT:
                return new IntTag(self::readInt($buffer, $length, $offset));
            case self::TYPE_LONG:
                self::need($length, $offset, 8);
                $offset += 8;
                return new LongTag(unpack("J", $buffer, $offset - 8)[1]);
            case self::TYPE_FLOAT:
                self::need($length, $offset, 4);
                $offset += 4;
                return new FloatTag(unpack("G", $buffer, $offset - 4)[1]);
            case self::TYPE_DOUBLE:
                self::need($length, $offset, 8);
                $offset += 8;
                return new DoubleTag(unpack("E", $buffer, $offset - 8)[1]);
            case self::TYPE_BYTE_ARRAY:
                $size = self::readInt($buffer, $length, $offset);
                if($size < 0){
                    throw new NbtDataException("Array length cannot be less than zero ($size < 0)");
                }
                if($size === 0){
                    return new ByteArrayTag("");
                }

                self::need($length, $offset, $size);
                $offset += $size;
                return new ByteArrayTag(substr($buffer, $offset - $size, $size));
            case self::TYPE_STRING:
                return new StringTag(self::readString($buffer, $length, $offset));
            case self::TYPE_LIST:
                self::need($length, $offset, 1);
                $childType = ord($buffer[$offset++]);
                $size = self::readInt($buffer, $length, $offset);

                $children = [];
                if($size > 0){
                    if($childType === 0){
                        throw new NbtDataException("Unexpected non-empty list of TAG_End");
                    }
                    for($i = 0; $i < $size; ++$i){
                        $children[] = self::readTag($childType, $buffer, $length, $offset);
                    }
                }
                return new ListTag($children, $childType);
            case self::TYPE_COMPOUND:
                $result = CompoundTag::create();
                $names = [];
                while(true){
                    self::need($length, $offset, 1);
                    $childType = ord($buffer[$offset++]);
                    if($childType === 0){
                        return $result;
                    }

                    $name = self::readString($buffer, $length, $offset);
                    $child = self::readTag($childType, $buffer, $length, $offset);
                    if(isset($names[$name])){
                        //duplicated names keep the first value, as pocketmine/nbt does
                        continue;
                    }
                    $names[$name] = true;
                    $result->setTag($name, $child);
                }
            case self::TYPE_INT_ARRAY:
                $size = self::readInt($buffer, $length, $offset);
                if($size < 0){
                    throw new NbtDataException("Array length cannot be less than zero ($size < 0)");
                }
                if($size === 0){
                    return new IntArrayTag([]);
                }

                $byteLength = $size * 4;
                self::need($length, $offset, $byteLength);
                $offset += $byteLength;
                return new IntArrayTag(array_values(unpack("N*", substr($buffer, $offset - $byteLength, $byteLength))));
            default:
                throw new NbtDataException("Unknown NBT tag type $type");
        }
    }

    /** @throws NbtDataException */
    private static function readInt(string $buffer, int $length, int &$offset) : int{
        self::need($length, $offset, 4);
        $offset += 4;
        return unpack("N", $buffer, $offset - 4)[1] << 32 >> 32;
    }
}
