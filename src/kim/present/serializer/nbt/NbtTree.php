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

use function array_keys;
use function count;
use function sort;

use const SORT_STRING;

/** Utilities for comparing and combining NBT trees */
final class NbtTree{

    private function __construct(){
        //NOOP
    }

    /**
     * Deep merge the patch into a copy of the base, like the Minecraft `/data merge` command.
     * Compound tags existing in both are merged recursively, every other tag of the patch replaces the base one.
     * Neither of the given tags is modified.
     */
    public static function merge(CompoundTag $base, CompoundTag $patch) : CompoundTag{
        $result = clone $base;
        foreach($patch->getValue() as $key => $tag){
            $key = (string) $key;
            $existing = $result->getTag($key);
            $result->setTag($key, $existing instanceof CompoundTag && $tag instanceof CompoundTag
                ? self::merge($existing, $tag)
                : clone $tag
            );
        }
        return $result;
    }

    /**
     * Find the differences between the two trees.
     * Compound tags are compared by key (in key order), lists of the same size and type are compared by index,
     * other tags (and lists of a different size or type) are reported as a whole.
     *
     * @return list<NbtChange>
     */
    public static function diff(Tag $old, Tag $new) : array{
        $changes = [];
        self::collectChanges($old, $new, NbtPath::of(), $changes);
        return $changes;
    }

    /** @param list<NbtChange> $changes */
    private static function collectChanges(Tag $old, Tag $new, NbtPath $path, array &$changes) : void{
        if($old instanceof CompoundTag && $new instanceof CompoundTag){
            $oldValue = $old->getValue();
            $newValue = $new->getValue();
            $keys = array_keys($oldValue + $newValue);
            sort($keys, SORT_STRING);
            foreach($keys as $key){
                $oldChild = $oldValue[$key] ?? null;
                $newChild = $newValue[$key] ?? null;
                $childPath = $path->append((string) $key);
                if($oldChild === null || $newChild === null){
                    $changes[] = new NbtChange($childPath, $oldChild, $newChild);
                }else{
                    self::collectChanges($oldChild, $newChild, $childPath, $changes);
                }
            }
            return;
        }

        if($old instanceof ListTag && $new instanceof ListTag
            && $old->count() === $new->count() && $old->getTagType() === $new->getTagType()
        ){
            $oldValue = $old->getValue();
            $newValue = $new->getValue();
            for($i = 0, $count = count($oldValue); $i < $count; ++$i){
                self::collectChanges($oldValue[$i], $newValue[$i], $path->append($i), $changes);
            }
            return;
        }

        if(!$old->equals($new)){
            $changes[] = new NbtChange($path, $old, $new);
        }
    }

    /**
     * Apply the changes (from {@see diff()}) to the tree. Changes of the root tag can not be applied.
     *
     * @param iterable<NbtChange> $changes
     *
     * @throws exception\NbtPathException if a change can not be applied
     */
    public static function apply(Tag $root, iterable $changes) : void{
        foreach($changes as $change){
            if($change->new === null){
                $change->path->remove($root);
            }else{
                $change->path->set($root, clone $change->new);
            }
        }
    }

}
