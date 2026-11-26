<?php

declare(strict_types=1);

namespace kim\present\serializer\nbt\tests;

use kim\present\serializer\nbt\NbtSerializer;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;

final class CanonicalSnbtTest extends TestCase{

    public function testKeysAreSortedByDefault() : void{
        $a = CompoundTag::create()->setInt("b", 1)->setInt("a", 2)->setTag("c", CompoundTag::create()->setInt("z", 1)->setInt("y", 2));
        $b = CompoundTag::create()->setTag("c", CompoundTag::create()->setInt("y", 2)->setInt("z", 1))->setInt("a", 2)->setInt("b", 1);

        self::assertSame("{a:2,b:1,c:{y:2,z:1}}", NbtSerializer::toSnbt($a));
        self::assertSame(NbtSerializer::toSnbt($a), NbtSerializer::toSnbt($b));
        self::assertSame(NbtSerializer::toSnbtPretty($a), NbtSerializer::toSnbtPretty($b));
        self::assertSame(NbtSerializer::hash($a), NbtSerializer::hash($b));
    }

    public function testInsertionOrderCanBeKept() : void{
        $tag = CompoundTag::create()->setInt("b", 1)->setInt("a", 2);
        self::assertSame("{b:1,a:2}", NbtSerializer::toSnbt($tag, false));
        self::assertSame("{\n    b: 1, \n    a: 2\n}", NbtSerializer::toSnbtPretty($tag, sortKeys: false));
    }

    public function testNumericKeysAreSortedAsStrings() : void{
        $tag = CompoundTag::create()->setInt("10", 1)->setInt("9", 2)->setInt("a", 3);
        self::assertSame('{10:1,9:2,a:3}', NbtSerializer::toSnbt($tag));
        self::assertTrue(NbtSerializer::fromSnbt(NbtSerializer::toSnbt($tag))->equals($tag));
    }

    public function testHashOfSignedZero() : void{
        // FloatTag compares the 32-bit representation : -0.0 differs, but the extra 64-bit precision is ignored
        self::assertFalse((new FloatTag(0.0))->equals(new FloatTag(-0.0)));
        self::assertNotSame(NbtSerializer::hash(new FloatTag(0.0)), NbtSerializer::hash(new FloatTag(-0.0)));
        $float32 = new FloatTag(unpack("G", pack("G", 0.1))[1]);
        self::assertTrue((new FloatTag(0.1))->equals($float32));
        self::assertSame(NbtSerializer::hash(new FloatTag(0.1)), NbtSerializer::hash($float32));

        // DoubleTag compares with === : -0.0 equals 0.0
        self::assertTrue((new DoubleTag(0.0))->equals(new DoubleTag(-0.0)));
        self::assertSame(
            NbtSerializer::hash(CompoundTag::create()->setDouble("d", 0.0)),
            NbtSerializer::hash(CompoundTag::create()->setDouble("d", -0.0))
        );
        // the SNBT itself keeps the sign
        self::assertSame("-0d", NbtSerializer::toSnbt(new DoubleTag(-0.0)));
    }

    public function testHashDiffersForDifferentTags() : void{
        $a = CompoundTag::create()->setInt("a", 1);
        $b = CompoundTag::create()->setByte("a", 1);
        self::assertNotSame(NbtSerializer::hash($a), NbtSerializer::hash($b));
        self::assertNotSame(NbtSerializer::hash(new ListTag()), NbtSerializer::hash(CompoundTag::create()));
        self::assertSame(64, strlen(NbtSerializer::hash($a, "sha256")));
    }

}
