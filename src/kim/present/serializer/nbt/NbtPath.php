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

use kim\present\serializer\nbt\exception\NbtPathException;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;

use function array_values;
use function count;
use function implode;
use function is_int;
use function ord;
use function preg_match;
use function strcspn;
use function strlen;
use function strpos;
use function strspn;
use function substr;

/**
 * Path to a tag inside of an NBT tree, like the Minecraft NBT path.
 *
 * Syntax :
 *  - `key`, `"quoted key"`, `'quoted key'` : entry of a compound tag
 *  - `[index]` : entry of a list tag (or byte / int array, only for {@see get()}), negative index counts from the end
 *  - segments are joined with `.` before a key, e.g. `tag.display.Lore[0]`, `[0].Name`, `"a.b"[-1]`
 *  - an empty string is the root tag itself
 */
final class NbtPath implements \Stringable{

    /** Characters of the keys that are written without quotes */
    private const SAFE_KEY_CHARS = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_+-:";

    private const CACHE_SIZE = 1024;

    /** @var array<string, self> */
    private static array $cache = [];

    /** @param list<string|int> $segments compound keys (string) and list indexes (int) */
    private function __construct(private readonly array $segments){
    }

    /**
     * Parse the path string. Parsed paths are cached, so calling this repeatedly with the same path is cheap.
     *
     * @throws NbtPathException if the path is invalid
     */
    public static function parse(string $path) : self{
        if(isset(self::$cache[$path])){
            return self::$cache[$path];
        }
        if(count(self::$cache) >= self::CACHE_SIZE){
            self::$cache = [];
        }
        return self::$cache[$path] = new self(self::parseSegments($path));
    }

    /** Create the path from the compound keys (string) and list indexes (int) */
    public static function of(string|int ...$segments) : self{
        return new self(array_values($segments));
    }

    /** @return list<string|int> */
    private static function parseSegments(string $path) : array{
        $segments = [];
        $length = strlen($path);
        $offset = 0;
        while($offset < $length){
            $c = $path[$offset];
            if($c === "["){
                $closePos = strpos($path, "]", $offset);
                $index = $closePos === false ? "" : substr($path, $offset + 1, $closePos - $offset - 1);
                if(preg_match('/^-?\d+$/', $index) !== 1){
                    throw new NbtPathException("Invalid list index at offset $offset in '$path'");
                }
                $segments[] = (int) $index;
                $offset = $closePos + 1;
                continue;
            }

            if($segments !== []){
                if($c !== "."){
                    throw new NbtPathException("Unexpected '$c' at offset $offset in '$path', expected '.' or '['");
                }
                $c = $path[++$offset] ?? "";
            }

            if($c === '"' || $c === "'"){
                $end = $offset + 1;
                while(true){
                    $end += strcspn($path, $c . "\\", $end);
                    if($end >= $length){
                        throw new NbtPathException("Unclosed quoted key at offset $offset in '$path'");
                    }
                    if($path[$end] === $c){
                        break;
                    }
                    $end += 2;
                }
                $key = NbtSerializer::tryFromSnbt(substr($path, $offset, $end - $offset + 1));
                if(!$key instanceof StringTag){
                    throw new NbtPathException("Invalid quoted key at offset $offset in '$path'");
                }
                $segments[] = $key->getValue();
                $offset = $end + 1;
            }else{
                $keyLength = strcspn($path, ".[]\"'", $offset);
                if($keyLength === 0){
                    throw new NbtPathException("Empty key at offset $offset in '$path'");
                }
                $segments[] = substr($path, $offset, $keyLength);
                $offset += $keyLength;
            }
        }
        return $segments;
    }

    /** @return list<string|int> compound keys (string) and list indexes (int) */
    public function getSegments() : array{
        return $this->segments;
    }

    /** Return the new path with the segment appended */
    public function append(string|int $segment) : self{
        $segments = $this->segments;
        $segments[] = $segment;
        return new self($segments);
    }

    /** Get the tag at the path, or null if it does not exist */
    public function get(Tag $root) : ?Tag{
        $tag = $root;
        foreach($this->segments as $segment){
            $tag = self::child($tag, $segment);
            if($tag === null){
                return null;
            }
        }
        return $tag;
    }

    /** Check whether the tag at the path exists */
    public function has(Tag $root) : bool{
        return $this->get($root) !== null;
    }

    /**
     * Set the tag at the path. Missing compound tags on the way are created.
     * A list index can be an existing index, or the size of the list to append.
     *
     * @throws NbtPathException if the path is empty, or can not be applied to the tree
     */
    public function set(Tag $root, Tag $value) : void{
        $last = count($this->segments) - 1;
        if($last < 0){
            throw new NbtPathException("Can not set the root tag");
        }

        // missing compound tags are created detached, and attached only after the final write succeeded
        $parent = $root;
        $attachTo = null;
        $attachKey = "";
        $detached = null;
        for($i = 0; $i < $last; ++$i){
            $segment = $this->segments[$i];
            $child = self::child($parent, $segment);
            if($child === null){
                if(!$parent instanceof CompoundTag || is_int($segment) || is_int($this->segments[$i + 1])){
                    throw new NbtPathException("Tag at '" . self::format($this->segments, $i + 1) . "' does not exist");
                }
                $child = CompoundTag::create();
                if($detached === null){
                    $attachTo = $parent;
                    $attachKey = $segment;
                    $detached = $child;
                }else{
                    $parent->setTag($segment, $child);
                }
            }
            $parent = $child;
        }

        $this->setChild($parent, $this->segments[$last], $value);
        if($attachTo !== null && $detached !== null){
            $attachTo->setTag($attachKey, $detached);
        }
    }

    private function setChild(Tag $parent, string|int $segment, Tag $value) : void{
        if($parent instanceof CompoundTag && !is_int($segment)){
            $parent->setTag($segment, $value);
            return;
        }
        if($parent instanceof ListTag && is_int($segment)){
            $count = $parent->count();
            $index = $segment < 0 ? $count + $segment : $segment;
            try{
                if($index === $count){
                    $parent->push($value);
                    return;
                }
                if($index >= 0 && $index < $count){
                    $parent->set($index, $value);
                    return;
                }
            }catch(\TypeError $e){
                throw new NbtPathException("Can not set the tag at '$this' : " . $e->getMessage(), 0, $e);
            }
            throw new NbtPathException("List index $segment is out of range at '$this'");
        }
        throw new NbtPathException("Can not set the tag at '$this' : the parent is " . $parent::class);
    }

    /**
     * Remove the tag at the path
     *
     * @return bool whether the tag existed
     */
    public function remove(Tag $root) : bool{
        $last = count($this->segments) - 1;
        if($last < 0){
            throw new NbtPathException("Can not remove the root tag");
        }

        $parent = $root;
        for($i = 0; $i < $last; ++$i){
            $parent = self::child($parent, $this->segments[$i]);
            if($parent === null){
                return false;
            }
        }

        $segment = $this->segments[$last];
        if($parent instanceof CompoundTag && !is_int($segment)){
            if($parent->getTag($segment) === null){
                return false;
            }
            $parent->removeTag($segment);
            return true;
        }
        if($parent instanceof ListTag && is_int($segment)){
            $index = $segment < 0 ? $parent->count() + $segment : $segment;
            if(!$parent->isset($index)){
                return false;
            }
            $parent->remove($index);
            return true;
        }
        return false;
    }

    private static function child(Tag $tag, string|int $segment) : ?Tag{
        if(!is_int($segment)){
            return $tag instanceof CompoundTag ? $tag->getTag($segment) : null;
        }

        if($tag instanceof ListTag){
            $index = $segment < 0 ? $tag->count() + $segment : $segment;
            return $tag->isset($index) ? $tag->get($index) : null;
        }
        if($tag instanceof IntArrayTag){
            $value = $tag->getValue();
            $index = $segment < 0 ? count($value) + $segment : $segment;
            return isset($value[$index]) ? new IntTag($value[$index]) : null;
        }
        if($tag instanceof ByteArrayTag){
            $value = $tag->getValue();
            $index = $segment < 0 ? strlen($value) + $segment : $segment;
            return $index >= 0 && isset($value[$index]) ? new ByteTag(ord($value[$index]) << 56 >> 56) : null;
        }
        return null;
    }

    /** @param list<string|int> $segments */
    private static function format(array $segments, ?int $length = null) : string{
        $parts = [];
        foreach($segments as $i => $segment){
            if($length !== null && $i >= $length){
                break;
            }
            if(is_int($segment)){
                $parts[] = "[$segment]";
                continue;
            }
            $key = $segment !== "" && strspn($segment, self::SAFE_KEY_CHARS) === strlen($segment)
                ? $segment
                : NbtSerializer::toSnbt(new StringTag($segment));
            $parts[] = $i === 0 ? $key : ".$key";
        }
        return implode("", $parts);
    }

    public function __toString() : string{
        return self::format($this->segments);
    }

}
