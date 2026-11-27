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

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\Tag;

/**
 * Builds list and compound tags from already validated entries at once,
 * skipping the per-entry checks of {@see ListTag::push()} and {@see CompoundTag::setTag()}.
 *
 * Falls back to the public API if the internal layout of pocketmine/nbt is not the expected one.
 *
 * @internal
 */
final class TagFactory{

    /** @var (\Closure(list<Tag>, int) : ListTag)|null */
    private static ?\Closure $list = null;
    /** @var (\Closure(array<string|int, Tag>) : CompoundTag)|null */
    private static ?\Closure $compound = null;

    private function __construct(){
        //NOOP
    }

    /**
     * @param list<Tag> $children every child must be an instance of the same tag type
     */
    public static function list(array $children, int $tagType) : ListTag{
        return (self::$list ??= self::createListFactory())($children, $tagType);
    }

    /**
     * @param array<string|int, Tag> $entries every name must be at most 32767 bytes
     */
    public static function compound(array $entries) : CompoundTag{
        return (self::$compound ??= self::createCompoundFactory())($entries);
    }

    private static function createListFactory() : \Closure{
        if(self::hasProperties(ListTag::class, "value", "tagType")){
            /** @var \Closure(list<Tag>, int) : ListTag */
            return \Closure::bind(static function(array $children, int $tagType) : ListTag{
                $tag = new ListTag([], $tagType);
                $tag->value = $children;
                return $tag;
            }, null, ListTag::class);
        }
        return static fn(array $children, int $tagType) : ListTag => new ListTag($children, $tagType);
    }

    private static function createCompoundFactory() : \Closure{
        if(self::hasProperties(CompoundTag::class, "value")){
            /** @var \Closure(array<string|int, Tag>) : CompoundTag */
            return \Closure::bind(static function(array $entries) : CompoundTag{
                $tag = new CompoundTag();
                $tag->value = $entries;
                return $tag;
            }, null, CompoundTag::class);
        }
        return static function(array $entries) : CompoundTag{
            $tag = new CompoundTag();
            foreach($entries as $name => $child){
                $tag->setTag((string) $name, $child);
            }
            return $tag;
        };
    }

    /** @param class-string $class */
    private static function hasProperties(string $class, string ...$names) : bool{
        foreach($names as $name){
            if(!property_exists($class, $name)){
                return false;
            }
            $property = new \ReflectionProperty($class, $name);
            if($property->isStatic() || $property->isReadOnly() || $property->getDeclaringClass()->getName() !== $class){
                return false;
            }
        }
        return true;
    }

}
