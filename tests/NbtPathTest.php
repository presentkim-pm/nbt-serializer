<?php

declare(strict_types=1);

namespace kim\present\serializer\nbt\tests;

use kim\present\serializer\nbt\exception\NbtPathException;
use kim\present\serializer\nbt\NbtPath;
use kim\present\serializer\nbt\NbtSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;

final class NbtPathTest extends TestCase{

    private static function item() : CompoundTag{
        return NbtSerializer::fromSnbt('{Name:"minecraft:diamond_sword",tag:{display:{Lore:["a","b","c"]},"a.b":{"x y":1},ench:[{id:1s},{id:2s}],ba:[B;1b,-2b],ia:[I;5,6]}}');
    }

    /** @return array<string, array{string, list<string|int>}> */
    public static function parseProvider() : array{
        return [
            "root"         => ["", []],
            "simple"       => ["tag.display.Lore", ["tag", "display", "Lore"]],
            "index"        => ["tag.display.Lore[1]", ["tag", "display", "Lore", 1]],
            "root index"   => ["[0].Name", [0, "Name"]],
            "negative"     => ["a[-1][2]", ["a", -1, 2]],
            "quoted"       => ['tag."a.b".\'x y\'', ["tag", "a.b", "x y"]],
            "escaped"      => ['"a\\"b"', ['a"b']],
            "namespaced"   => ["minecraft:id", ["minecraft:id"]],
        ];
    }

    /** @param list<string|int> $segments */
    #[DataProvider("parseProvider")]
    public function testParse(string $path, array $segments) : void{
        self::assertSame($segments, NbtPath::parse($path)->getSegments());
        self::assertSame($segments, NbtPath::parse((string) NbtPath::of(...$segments))->getSegments(), "format round trip");
    }

    /** @return array<string, array{string}> */
    public static function invalidPathProvider() : array{
        return [
            "trailing dot"    => ["a."],
            "leading dot"     => [".a"],
            "double dot"      => ["a..b"],
            "bad index"       => ["a[x]"],
            "unclosed index"  => ["a[1"],
            "unclosed quote"  => ['"abc'],
            "missing dot"     => ['a[0]b'],
            "bad escape"      => ['"\\x"'],
        ];
    }

    #[DataProvider("invalidPathProvider")]
    public function testInvalidPath(string $path) : void{
        $this->expectException(NbtPathException::class);
        NbtPath::parse($path);
    }

    /** @return array<string, array{string, ?Tag}> */
    public static function getProvider() : array{
        return [
            "string"        => ["Name", new StringTag("minecraft:diamond_sword")],
            "list entry"    => ["tag.display.Lore[1]", new StringTag("b")],
            "last entry"    => ["tag.display.Lore[-1]", new StringTag("c")],
            "quoted keys"   => ['tag."a.b"."x y"', new IntTag(1)],
            "in list"       => ["tag.ench[1].id", NbtSerializer::fromSnbt("2s")],
            "byte array"    => ["tag.ba[1]", NbtSerializer::fromSnbt("-2b")],
            "int array"     => ["tag.ia[-1]", new IntTag(6)],
            "missing"       => ["tag.nothing", null],
            "out of range"  => ["tag.display.Lore[3]", null],
            "too negative"  => ["tag.display.Lore[-4]", null],
            "key on list"   => ["tag.display.Lore.x", null],
            "index on comp" => ["tag[0]", null],
            "below scalar"  => ["Name.x", null],
        ];
    }

    #[DataProvider("getProvider")]
    public function testGet(string $path, ?Tag $expected) : void{
        $actual = NbtPath::parse($path)->get(self::item());
        if($expected === null){
            self::assertNull($actual);
            self::assertFalse(NbtPath::parse($path)->has(self::item()));
        }else{
            self::assertNotNull($actual);
            self::assertTrue($actual->equals($expected), NbtSerializer::toSnbt($actual));
        }
    }

    public function testGetRoot() : void{
        $item = self::item();
        self::assertSame($item, NbtPath::parse("")->get($item));
    }

    public function testSet() : void{
        $item = self::item();
        NbtPath::parse("tag.display.Name")->set($item, new StringTag("Sword"));
        NbtPath::parse("tag.display.Lore[0]")->set($item, new StringTag("z"));
        NbtPath::parse("tag.display.Lore[3]")->set($item, new StringTag("appended"));
        NbtPath::parse("tag.display.Lore[-1]")->set($item, new StringTag("last"));
        NbtPath::parse("new.deep.key")->set($item, new IntTag(1));

        self::assertSame("Sword", $item->getCompoundTag("tag")?->getCompoundTag("display")?->getString("Name"));
        self::assertSame('["z","b","c","last"]', NbtSerializer::toSnbt(NbtPath::parse("tag.display.Lore")->get($item) ?? new ListTag()));
        self::assertSame(1, $item->getCompoundTag("new")?->getCompoundTag("deep")?->getInt("key"));
    }

    /** @return array<string, array{string, Tag}> */
    public static function invalidSetProvider() : array{
        return [
            "root"            => ["", new IntTag(1)],
            "list type"       => ["tag.display.Lore[0]", new IntTag(1)],
            "list range"      => ["tag.display.Lore[5]", new StringTag("x")],
            "key on list"     => ["tag.display.Lore.x", new StringTag("x")],
            "below scalar"    => ["Name.x", new StringTag("x")],
            "missing list"    => ["tag.missing[0]", new StringTag("x")],
            "into array"      => ["tag.ia[0]", new IntTag(1)],
        ];
    }

    #[DataProvider("invalidSetProvider")]
    public function testInvalidSet(string $path, Tag $value) : void{
        $this->expectException(NbtPathException::class);
        NbtPath::parse($path)->set(self::item(), $value);
    }

    public function testRemove() : void{
        $item = self::item();
        self::assertTrue(NbtPath::parse("tag.display.Lore[0]")->remove($item));
        self::assertTrue(NbtPath::parse("tag.display.Lore[-1]")->remove($item));
        self::assertTrue(NbtPath::parse('tag."a.b"')->remove($item));
        self::assertFalse(NbtPath::parse("tag.nothing")->remove($item));
        self::assertFalse(NbtPath::parse("tag.nothing.deeper")->remove($item));
        self::assertFalse(NbtPath::parse("tag.display.Lore[9]")->remove($item));

        self::assertSame('["b"]', NbtSerializer::toSnbt(NbtPath::parse("tag.display.Lore")->get($item) ?? new ListTag()));
        self::assertNull(NbtPath::parse('tag."a.b"')->get($item));
    }

    public function testFormat() : void{
        self::assertSame('tag."a.b"[0]."x y"', (string) NbtPath::of("tag", "a.b", 0, "x y"));
        self::assertSame('[0].""', (string) NbtPath::of(0, ""));
        self::assertSame("", (string) NbtPath::of());
    }

    public function testArraysAreReadOnlyThroughPath() : void{
        $tag = CompoundTag::create()->setTag("ba", new ByteArrayTag("\x01"))->setTag("ia", new IntArrayTag([1]));
        self::assertFalse(NbtPath::parse("ba[0]")->remove($tag));
        self::assertFalse(NbtPath::parse("ia[0]")->remove($tag));
    }

}
