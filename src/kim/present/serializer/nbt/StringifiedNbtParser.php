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

use pocketmine\nbt\InvalidTagValueException;
use pocketmine\nbt\JsonNbtParser;
use pocketmine\nbt\NBT;
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
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;

use function chr;
use function get_class;
use function is_numeric;
use function str_contains;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

/** Base source from {@link JsonNbtParser} */
final class StringifiedNbtParser extends BinaryStream{

    public function __construct(string $buffer = "", int $offset = 0){
        parent::__construct(trim($buffer, " \r\n\t"), $offset);
    }

    /**
     * @throws BinaryDataException
     * @throws NbtDataException
     * @throws InvalidTagValueException
     */
    public function getSnbt() : Tag{
        $value = "";
        $inQuotes = false;

        $offset = $this->getOffset();

        $foundEnd = false;

        /** @var Tag|null $retval */
        $retval = null;

        while(!$this->feof()){
            $offset = $this->getOffset();
            $c = $this->get(1);

            if($inQuotes){ //anything is allowed inside quotes, except unescaped quotes
                if($c === '"'){
                    $inQuotes = false;
                    $retval = new StringTag(json_decode('"' . $value . '"'));
                    $foundEnd = true;
                }elseif($c === "\\"){
                    $value .= $c . $this->get(1);
                }else{
                    $value .= $c;
                }
            }else{
                if($c === "," or $c === "}" or $c === "]"){ //end of parent tag
                    $this->setOffset($this->getOffset() - 1); //the caller needs to be able to read this character
                    break;
                }

                if($value === "" or $foundEnd){
                    if($c === "\r" or $c === "\n" or $c === "\t" or $c
                        === " "){ //leading or trailing whitespace, ignore it
                        continue;
                    }

                    if($foundEnd){ //unexpected non-whitespace character after end of value
                        throw new NbtDataException("Syntax error: unexpected '$c' after end of value at offset $offset");
                    }
                }

                if($c === '"'){ //start of quoted string
                    if($value !== ""){
                        throw new NbtDataException("Syntax error: unexpected quote at offset $offset");
                    }
                    $inQuotes = true;
                }elseif($c === "{"){ //start of compound tag
                    if($value !== ""){
                        throw new NbtDataException("Syntax error: unexpected compound start at offset $offset (enclose in double quotes for literal)");
                    }

                    $retval = $this->getSnbtCompound();
                    $foundEnd = true;
                }elseif($c === "["){
                    if($value !== ""){
                        throw new NbtDataException("Syntax error: unexpected list start at offset $offset (enclose in double quotes for literal)");
                    }

                    $retval = $this->getSnbtListOrArray();
                    $foundEnd = true;
                }else{ //any other character
                    $value .= $c;
                }
            }
        }

        if($retval !== null){
            return $retval;
        }

        if($value === ""){
            throw new NbtDataException("Syntax error: empty value at offset $offset");
        }

        $last = strtolower(substr($value, -1));
        $part = substr($value, 0, -1);

        if($last !== "b" and $last !== "s" and $last !== "l" and $last !== "f" and $last !== "d"){
            $part = $value;
            $last = null;
        }

        if(is_numeric($part)){
            if(
                $last === "f" or $last === "d" or
                str_contains($part, ".") or str_contains($part, "e")
            ){ //e = scientific notation
                $value = (float) $part;
                return match ($last) {
                    "d"     => new DoubleTag($value),
                    default => new FloatTag($value),
                };
            }

            $value = (int) $part;
            return match ($last) {
                "b"     => new ByteTag($value),
                "s"     => new ShortTag($value),
                "l"     => new LongTag($value),
                default => new IntTag($value),
            };
        }

        return new StringTag($value);
    }

    /**
     * @throws BinaryDataException
     * @throws NbtDataException
     */
    private function getSnbtListOrArray() : ListTag|ByteArrayTag|IntArrayTag{
        if(!$this->skipWhitespace("]")){
            return new ListTag();
        }
        // Detect result tag type
        $retval = new ListTag();
        if(strlen($this->buffer) >= ($this->offset + 2)){
            $offset = $this->offset;
            $head = strtoupper($this->get(2));
            if($head === "B;"){
                $retval = new ByteArrayTag("");
            }elseif($head === "I;"){
                $retval = new IntArrayTag([]);
            }else{
                $this->offset = $offset;
            }
        }

        if(!$this->skipWhitespace("]")){
            return $retval;
        }

        $tags = [];
        while(!$this->feof()){
            try{
                $tag = $this->getSnbt();
            }catch(InvalidTagValueException $e){
                throw new NbtDataException("Data error: " . $e->getMessage());
            }
            $tags[] = $tag;
            if($this->readBreak("]")){
                break;
            }
        }

        if($retval instanceof ListTag){
            foreach($tags as $tag){
                $expectedType = $retval->getTagType();
                if($expectedType !== NBT::TAG_End && $expectedType !== $tag->getType()){
                    throw new NbtDataException("Data error: lists can only contain one type of value");
                }
                $retval->push($tag);
            }
        }elseif($retval instanceof ByteArrayTag){
            $value = "";
            foreach($tags as $tag){
                if(!$tag instanceof ByteTag){
                    throw new NbtDataException("Data error: expected byte value, got " . get_class($tag));
                }
                $value .= chr($tag->getValue());
            }
            $retval = new ByteArrayTag($value);
        }elseif($retval instanceof IntArrayTag){
            $value = [];
            foreach($tags as $tag){
                if(!$tag instanceof IntTag){
                    throw new NbtDataException("Data error: expected int value, got " . get_class($tag));
                }
                $value[] = $tag->getValue();
            }
            $retval = new IntArrayTag($value);
        }else{
            throw new NbtDataException("Data error: unknown list type");
        }
        return $retval;
    }

    /**
     * @throws BinaryDataException
     * @throws NbtDataException
     */
    private function getSnbtCompound() : CompoundTag{
        $retval = new CompoundTag();

        if($this->skipWhitespace("}")){
            while(!$this->feof()){
                $k = $this->readKey();
                if($retval->getTag($k) !== null){
                    throw new NbtDataException("Syntax error: duplicate compound leaf node '$k'");
                }
                try{
                    $retval->setTag($k, $this->getSnbt());
                }catch(InvalidTagValueException $e){
                    throw new NbtDataException("Data error: " . $e->getMessage());
                }

                if($this->readBreak("}")){
                    return $retval;
                }
            }

            throw new NbtDataException("Syntax error: unexpected end of stream");
        }

        return $retval;
    }

    /**
     * @throws BinaryDataException
     * @throws NbtDataException
     */
    private function skipWhitespace(string $terminator) : bool{
        while(!$this->feof()){
            $b = $this->get(1);
            if($b === $terminator){
                return false;
            }
            if($b === " " or $b === "\n" or $b === "\t" or $b === "\r"){
                continue;
            }

            $this->setOffset($this->getOffset() - 1);
            return true;
        }

        throw new NbtDataException("Syntax error: unexpected end of stream, expected start of key");
    }

    /**
     * @return bool true if terminator has been found, false if comma was found
     * @throws BinaryDataException
     * @throws NbtDataException
     */
    private function readBreak(string $terminator) : bool{
        if($this->feof()){
            throw new NbtDataException("Syntax error: unexpected end of stream, expected '$terminator'");
        }
        $offset = $this->getOffset();
        $c = $this->get(1);
        if($c === ","){
            return false;
        }
        if($c === $terminator){
            return true;
        }

        throw new NbtDataException("Syntax error: unexpected '$c' end at offset $offset");
    }

    /**
     * @throws BinaryDataException
     * @throws NbtDataException
     */
    private function readKey() : string{
        $key = "";
        $offset = $this->getOffset();

        $inQuotes = false;
        $foundEnd = false;

        while(!$this->feof()){
            $c = $this->get(1);

            if($inQuotes){
                if($c === '"'){
                    $inQuotes = false;
                    $foundEnd = true;
                }elseif($c === "\\"){
                    $key .= $this->get(1);
                }else{
                    $key .= $c;
                }
            }else{
                if($c === ":"){
                    $foundEnd = true;
                    break;
                }

                if($key === "" or $foundEnd){
                    if($c === "\r" or $c === "\n" or $c === "\t" or $c === " "){
                        continue;
                    }

                    if($foundEnd){ //unexpected non-whitespace character after end of value
                        throw new NbtDataException("Syntax error: unexpected '$c' after end of value at offset $offset");
                    }
                }

                if($c === '"'){ //start of quoted string
                    if($key !== ""){
                        throw new NbtDataException("Syntax error: unexpected quote at offset $offset");
                    }
                    $inQuotes = true;
                }elseif($c === "{" or $c === "}" or $c === "[" or $c === "]" or $c === ","){
                    throw new NbtDataException("Syntax error: unexpected '$c' at offset $offset (enclose in double quotes for literal)");
                }else{ //any other character
                    $key .= $c;
                }
            }
        }

        if($key === ""){
            throw new NbtDataException("Syntax error: invalid empty key at offset $offset");
        }
        if(!$foundEnd){
            throw new NbtDataException("Syntax error: unexpected end of stream at offset $offset");
        }

        return $key;
    }
}
