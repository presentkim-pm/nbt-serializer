<?php

declare(strict_types=1);

namespace kim\present\serializer\nbt\tests;

use kim\present\serializer\nbt\NbtChange;
use kim\present\serializer\nbt\NbtSerializer;
use kim\present\serializer\nbt\NbtTree;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;

final class NbtTreeTest extends TestCase{

    private static function snbt(string $snbt) : CompoundTag{
        $tag = NbtSerializer::fromSnbt($snbt);
        self::assertInstanceOf(CompoundTag::class, $tag);
        return $tag;
    }

    public function testDeepMerge() : void{
        $base = self::snbt('{a:1,b:{x:1,y:2},l:[1,2]}');
        $patch = self::snbt('{b:{y:3,z:4},l:[9],c:"new"}');
        $baseCopy = clone $base;
        $patchCopy = clone $patch;

        $merged = NbtTree::merge($base, $patch);
        self::assertSame('{a:1,b:{x:1,y:3,z:4},c:"new",l:[9]}', NbtSerializer::toSnbt($merged));
        self::assertTrue($base->equals($baseCopy), "base must not be modified");
        self::assertTrue($patch->equals($patchCopy), "patch must not be modified");

        // the result must not share mutable tags with the inputs
        $merged->getCompoundTag("b")?->setInt("x", 100);
        self::assertSame(1, $base->getCompoundTag("b")?->getInt("x"));
    }

    public function testMergeReplacesDifferentTypes() : void{
        self::assertSame("{a:{b:1}}", NbtSerializer::toSnbt(NbtTree::merge(self::snbt("{a:1}"), self::snbt("{a:{b:1}}"))));
        self::assertSame("{a:1}", NbtSerializer::toSnbt(NbtTree::merge(self::snbt("{a:{b:1}}"), self::snbt("{a:1}"))));
    }

    public function testDiff() : void{
        $old = self::snbt('{a:1,b:{x:1,y:2},l:[1,2],r:"removed",t:[1]}');
        $new = self::snbt('{a:1,b:{x:1,y:3},l:[1,5],n:"added",t:[1,2]}');

        $changes = array_map(
            static fn(NbtChange $c) => [(string) $c->path, $c->old === null ? null : NbtSerializer::toSnbt($c->old), $c->new === null ? null : NbtSerializer::toSnbt($c->new)],
            NbtTree::diff($old, $new)
        );
        self::assertSame([
            ["b.y", "2", "3"],
            ["l[1]", "2", "5"],
            ["n", null, '"added"'],
            ["r", '"removed"', null],
            ["t", "[1]", "[1,2]"],
        ], $changes);

        $kinds = array_map(static fn(NbtChange $c) => [$c->isAdded(), $c->isRemoved(), $c->isModified()], NbtTree::diff($old, $new));
        self::assertSame([false, false, true], $kinds[0]);
        self::assertSame([true, false, false], $kinds[2]);
        self::assertSame([false, true, false], $kinds[3]);
    }

    public function testDiffOfEqualTreesIsEmpty() : void{
        self::assertSame([], NbtTree::diff(self::snbt("{a:1,b:[1,2]}"), self::snbt("{b:[1,2],a:1}")));
    }

    public function testDiffOfRootScalars() : void{
        $changes = NbtTree::diff(NbtSerializer::fromSnbt("1"), NbtSerializer::fromSnbt("2"));
        self::assertCount(1, $changes);
        self::assertSame("", (string) $changes[0]->path);
    }

    public function testApplyDiff() : void{
        $old = self::snbt('{a:1,b:{x:1,y:2},l:[1,2],r:"removed",t:[1],"odd key":{}}');
        $new = self::snbt('{a:1,b:{x:1,y:3},l:[1,5],n:{deep:"added"},t:[1,2],"odd key":{"a.b":1}}');

        $target = clone $old;
        NbtTree::apply($target, NbtTree::diff($old, $new));
        self::assertTrue($target->equals($new), NbtSerializer::toSnbt($target));
    }

}
