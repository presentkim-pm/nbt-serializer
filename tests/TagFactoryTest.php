<?php

declare(strict_types=1);

namespace kim\present\serializer\nbt\tests;

use kim\present\serializer\nbt\NbtSerializer;
use kim\present\serializer\nbt\TagFactory;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\NBT;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;

/** Tags built by TagFactory must behave exactly like the tags built with the public API */
final class TagFactoryTest extends TestCase{

    public function testListBehavesLikePublicApi() : void{
        $list = TagFactory::list([new IntTag(1), new IntTag(2)], NBT::TAG_Int);
        self::assertTrue($list->equals(new ListTag([new IntTag(1), new IntTag(2)], NBT::TAG_Int)));
        self::assertSame(NBT::TAG_Int, $list->getTagType());
        self::assertSame(2, $list->count());

        $list->push(new IntTag(3));
        self::assertSame(3, $list->count());

        $this->expectException(\TypeError::class);
        $list->push(new StringTag("type is still checked"));
    }

    public function testListCloneIsDeep() : void{
        $inner = CompoundTag::create()->setInt("a", 1);
        $list = TagFactory::list([$inner], NBT::TAG_Compound);
        $clone = clone $list;
        $inner->setInt("a", 2);
        self::assertSame(1, $clone->get(0)->getValue()["a"]->getValue());
    }

    public function testCompoundBehavesLikePublicApi() : void{
        $compound = TagFactory::compound(["a" => new IntTag(1), "1" => new StringTag("numeric key")]);
        $expected = CompoundTag::create()->setInt("a", 1)->setString("1", "numeric key");
        self::assertTrue($compound->equals($expected));
        self::assertSame("numeric key", $compound->getString("1"));
        self::assertSame(2, $compound->count());

        $clone = clone $compound;
        $compound->setInt("a", 5);
        self::assertSame(1, $clone->getInt("a"));
    }

    public function testDecodedTreesAreRegularTags() : void{
        $tag = CompoundTag::create()
            ->setTag("list", new ListTag([new IntTag(1)]))
            ->setTag("nested", CompoundTag::create()->setString("x", "y"));
        foreach([NbtSerializer::fromBinary(NbtSerializer::toBinary($tag)), NbtSerializer::fromSnbt(NbtSerializer::toSnbt($tag))] as $decoded){
            self::assertInstanceOf(CompoundTag::class, $decoded);
            self::assertTrue($decoded->equals($tag));
            $decoded->getListTag("list")?->push(new IntTag(2));
            $decoded->getCompoundTag("nested")?->setInt("z", 1);
            self::assertSame("{list:[1,2],nested:{x:\"y\",z:1}}", NbtSerializer::toSnbt($decoded));
        }
    }

}
