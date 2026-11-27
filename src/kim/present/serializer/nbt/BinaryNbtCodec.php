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

use function chr;
use function count;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;

/**
 * Binary NBT codec producing and accepting exactly the same bytes (and failing with the same exceptions)
 * as {@see \pocketmine\nbt\BigEndianNbtSerializer} and {@see \pocketmine\nbt\LittleEndianNbtSerializer},
 * without their per-value stream and closure overhead.
 *
 * The only intended difference : negative values of TAG_Int_Array are decoded with their sign,
 * while pocketmine/nbt decodes them as unsigned 32-bit values.
 *
 * @internal
 */
final class BinaryNbtCodec{

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

    /** Byte size of the fixed size tags */
    private const SIZE_OF_TYPE = [
        self::TYPE_BYTE   => 1,
        self::TYPE_SHORT  => 2,
        self::TYPE_INT    => 4,
        self::TYPE_LONG   => 8,
        self::TYPE_FLOAT  => 4,
        self::TYPE_DOUBLE => 8,
    ];

    private const MAX_STRING_LENGTH = 32767;

    private static ?self $big = null;
    private static ?self $little = null;

    /** @var array<int, string> pack() format of each fixed size tag type */
    private readonly array $formats;

    /** pack() format of unsigned short, unsigned int, long, float and double */
    private function __construct(
        private readonly string $short,
        private readonly string $int,
        private readonly string $long,
        private readonly string $float,
        private readonly string $double
    ){
        $this->formats = [
            self::TYPE_BYTE   => "c",
            self::TYPE_SHORT  => $short,
            self::TYPE_INT    => $int,
            self::TYPE_LONG   => $long,
            self::TYPE_FLOAT  => $float,
            self::TYPE_DOUBLE => $double,
        ];
    }

    public static function get(Endian $endian) : self{
        return $endian === Endian::BIG
            ? self::$big ??= new self("n", "N", "J", "G", "E")
            : self::$little ??= new self("v", "V", "P", "g", "e");
    }

    /**
     * Encodes the tag as a root tag with an empty name.
     *
     * @return string|null null if the tree contains a tag type that this codec does not know
     */
    public function encode(Tag $tag) : ?string{
        $type = self::TYPE_OF_CLASS[$tag::class] ?? null;
        if($type === null){
            return null;
        }

        $out = chr($type) . "\x00\x00";
        return $this->writeBody($tag, $type, $out) ? $out : null;
    }

    private function writeBody(Tag $tag, int $type, string &$out) : bool{
        switch($type){
            case self::TYPE_COMPOUND:
                foreach($tag->getValue() as $name => $child){
                    $childType = self::TYPE_OF_CLASS[$child::class] ?? null;
                    if($childType === null){
                        return false;
                    }

                    $name = (string) $name;
                    $nameLength = strlen($name);
                    if($nameLength > self::MAX_STRING_LENGTH){
                        self::throwStringTooLong($nameLength);
                    }
                    $out .= chr($childType) . pack($this->short, $nameLength) . $name;
                    switch($childType){ // inlined scalar types, the most common entries
                        case self::TYPE_BYTE:
                            $out .= chr($child->getValue() & 0xff);
                            break;
                        case self::TYPE_STRING:
                            $value = $child->getValue();
                            $valueLength = strlen($value);
                            if($valueLength > self::MAX_STRING_LENGTH){
                                self::throwStringTooLong($valueLength);
                            }
                            $out .= pack($this->short, $valueLength) . $value;
                            break;
                        case self::TYPE_SHORT:
                        case self::TYPE_INT:
                        case self::TYPE_LONG:
                        case self::TYPE_FLOAT:
                        case self::TYPE_DOUBLE:
                            $out .= pack($this->formats[$childType], $child->getValue());
                            break;
                        default:
                            if(!$this->writeBody($child, $childType, $out)){
                                return false;
                            }
                    }
                }
                $out .= "\x00";
                return true;
            case self::TYPE_LIST:
                $children = $tag->getValue();
                $childType = $tag->getTagType();
                $out .= chr($childType & 0xff) . pack($this->int, count($children));
                if($children === []){
                    return true;
                }

                if(isset(self::SIZE_OF_TYPE[$childType])){ // list of fixed size values : pack them at once
                    $values = [];
                    foreach($children as $child){
                        $values[] = $child->getValue();
                    }
                    $out .= pack($this->formats[$childType] . "*", ...$values);
                    return true;
                }

                foreach($children as $child){
                    $childType = self::TYPE_OF_CLASS[$child::class] ?? null;
                    if($childType === null || !$this->writeBody($child, $childType, $out)){
                        return false;
                    }
                }
                return true;
            case self::TYPE_STRING:
                $value = $tag->getValue();
                $valueLength = strlen($value);
                if($valueLength > self::MAX_STRING_LENGTH){
                    self::throwStringTooLong($valueLength);
                }
                $out .= pack($this->short, $valueLength) . $value;
                return true;
            case self::TYPE_BYTE:
                $out .= chr($tag->getValue() & 0xff);
                return true;
            case self::TYPE_SHORT:
            case self::TYPE_INT:
            case self::TYPE_LONG:
            case self::TYPE_FLOAT:
            case self::TYPE_DOUBLE:
                $out .= pack($this->formats[$type], $tag->getValue());
                return true;
            case self::TYPE_BYTE_ARRAY:
                $value = $tag->getValue();
                $out .= pack($this->int, strlen($value)) . $value;
                return true;
            case self::TYPE_INT_ARRAY:
                $value = $tag->getValue();
                $out .= pack($this->int, count($value)) . pack($this->int . "*", ...$value);
                return true;
        }
        return false;
    }

    private static function throwStringTooLong(int $length) : never{
        throw new \InvalidArgumentException("NBT string length too large ($length > " . self::MAX_STRING_LENGTH . ")");
    }

    /**
     * Decodes the root tag of the given buffer (the root name is discarded, trailing data is ignored).
     *
     * @param int $maxDepth maximum nesting depth of compound and list tags
     *
     * @throws NbtDataException
     */
    public function decode(string $buffer, int $maxDepth) : Tag{
        $length = strlen($buffer);
        if($length < 3){
            if($length > 0 && $buffer[0] === "\x00"){
                throw new NbtDataException("Found TAG_End at the start of buffer");
            }
            self::throwNotEnough(3, $length);
        }
        $type = ord($buffer[0]);
        if($type === 0){
            throw new NbtDataException("Found TAG_End at the start of buffer");
        }

        $offset = 1;
        $this->readString($buffer, $length, $offset); // root name
        return $this->readTag($type, $buffer, $length, $offset, $maxDepth);
    }

    /** @throws NbtDataException */
    private static function throwNotEnough(int $required, int $have) : never{
        throw new NbtDataException("Not enough bytes left in buffer: need $required, have $have");
    }

    /** @throws NbtDataException */
    private function readString(string $buffer, int $length, int &$offset) : string{
        if($length - $offset < 2){
            self::throwNotEnough(2, $length - $offset);
        }
        $stringLength = unpack($this->short, $buffer, $offset)[1];
        $offset += 2;
        if($stringLength > self::MAX_STRING_LENGTH){
            throw new NbtDataException("NBT string length too large ($stringLength > " . self::MAX_STRING_LENGTH . ")");
        }
        if($stringLength === 0){
            return "";
        }
        if($length - $offset < $stringLength){
            self::throwNotEnough($stringLength, $length - $offset);
        }

        $offset += $stringLength;
        return substr($buffer, $offset - $stringLength, $stringLength);
    }

    /** @throws NbtDataException */
    private function readTag(int $type, string $buffer, int $length, int &$offset, int $depth) : Tag{
        switch($type){
            case self::TYPE_COMPOUND:
                if(--$depth < 0){
                    throw new NbtDataException("Nesting depth limit exceeded");
                }

                $entries = [];
                while(true){
                    if($offset >= $length){
                        self::throwNotEnough(1, 0);
                    }
                    $childType = ord($buffer[$offset++]);
                    if($childType === 0){
                        return TagFactory::compound($entries);
                    }

                    $name = $this->readString($buffer, $length, $offset);
                    $child = $this->readTag($childType, $buffer, $length, $offset, $depth);
                    //duplicated names keep the first value, as pocketmine/nbt does
                    $entries[$name] ??= $child;
                }
            case self::TYPE_LIST:
                if(--$depth < 0){
                    throw new NbtDataException("Nesting depth limit exceeded");
                }
                if($length - $offset < 5){
                    self::throwNotEnough(5, $length - $offset);
                }
                $childType = ord($buffer[$offset]);
                $size = unpack($this->int, $buffer, $offset + 1)[1] << 32 >> 32;
                $offset += 5;
                if($size <= 0){
                    return new ListTag([], $childType);
                }
                if($childType === 0){
                    throw new NbtDataException("Unexpected non-empty list of TAG_End");
                }

                $children = [];
                $width = self::SIZE_OF_TYPE[$childType] ?? null;
                if($width !== null){ // list of fixed size values : unpack them at once
                    $byteLength = $size * $width;
                    if($length - $offset < $byteLength){
                        self::throwNotEnough($byteLength, $length - $offset);
                    }
                    $values = unpack($this->formats[$childType] . $size, $buffer, $offset);
                    $offset += $byteLength;
                    switch($childType){
                        case self::TYPE_BYTE:
                            foreach($values as $value){
                                $children[] = new ByteTag($value);
                            }
                            break;
                        case self::TYPE_SHORT:
                            foreach($values as $value){
                                $children[] = new ShortTag($value << 48 >> 48);
                            }
                            break;
                        case self::TYPE_INT:
                            foreach($values as $value){
                                $children[] = new IntTag($value << 32 >> 32);
                            }
                            break;
                        case self::TYPE_LONG:
                            foreach($values as $value){
                                $children[] = new LongTag($value);
                            }
                            break;
                        case self::TYPE_FLOAT:
                            foreach($values as $value){
                                $children[] = new FloatTag($value);
                            }
                            break;
                        default:
                            foreach($values as $value){
                                $children[] = new DoubleTag($value);
                            }
                    }
                }else{
                    for($i = 0; $i < $size; ++$i){
                        $children[] = $this->readTag($childType, $buffer, $length, $offset, $depth);
                    }
                }
                return TagFactory::list($children, $childType);
            case self::TYPE_STRING:
                return new StringTag($this->readString($buffer, $length, $offset));
            case self::TYPE_BYTE:
                if($offset >= $length){
                    self::throwNotEnough(1, 0);
                }
                return new ByteTag(ord($buffer[$offset++]) << 56 >> 56);
            case self::TYPE_SHORT:
                if($length - $offset < 2){
                    self::throwNotEnough(2, $length - $offset);
                }
                $offset += 2;
                return new ShortTag(unpack($this->short, $buffer, $offset - 2)[1] << 48 >> 48);
            case self::TYPE_INT:
                if($length - $offset < 4){
                    self::throwNotEnough(4, $length - $offset);
                }
                $offset += 4;
                return new IntTag(unpack($this->int, $buffer, $offset - 4)[1] << 32 >> 32);
            case self::TYPE_LONG:
                if($length - $offset < 8){
                    self::throwNotEnough(8, $length - $offset);
                }
                $offset += 8;
                return new LongTag(unpack($this->long, $buffer, $offset - 8)[1]);
            case self::TYPE_FLOAT:
                if($length - $offset < 4){
                    self::throwNotEnough(4, $length - $offset);
                }
                $offset += 4;
                return new FloatTag(unpack($this->float, $buffer, $offset - 4)[1]);
            case self::TYPE_DOUBLE:
                if($length - $offset < 8){
                    self::throwNotEnough(8, $length - $offset);
                }
                $offset += 8;
                return new DoubleTag(unpack($this->double, $buffer, $offset - 8)[1]);
            case self::TYPE_BYTE_ARRAY:
                $size = $this->readArraySize($buffer, $length, $offset);
                if($size === 0){
                    return new ByteArrayTag("");
                }
                if($length - $offset < $size){
                    self::throwNotEnough($size, $length - $offset);
                }
                $offset += $size;
                return new ByteArrayTag(substr($buffer, $offset - $size, $size));
            case self::TYPE_INT_ARRAY:
                $size = $this->readArraySize($buffer, $length, $offset);
                if($size === 0){
                    return new IntArrayTag([]);
                }
                $byteLength = $size * 4;
                if($length - $offset < $byteLength){
                    self::throwNotEnough($byteLength, $length - $offset);
                }
                $values = [];
                foreach(unpack($this->int . $size, $buffer, $offset) as $value){
                    $values[] = $value << 32 >> 32; // sign extension
                }
                $offset += $byteLength;
                return new IntArrayTag($values);
            default:
                throw new NbtDataException("Unknown NBT tag type $type");
        }
    }

    /** @throws NbtDataException */
    private function readArraySize(string $buffer, int $length, int &$offset) : int{
        if($length - $offset < 4){
            self::throwNotEnough(4, $length - $offset);
        }
        $size = unpack($this->int, $buffer, $offset)[1] << 32 >> 32;
        $offset += 4;
        if($size < 0){
            throw new NbtDataException("Array length cannot be less than zero ($size < 0)");
        }
        return $size;
    }
}
