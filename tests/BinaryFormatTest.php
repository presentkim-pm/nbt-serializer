<?php

declare(strict_types=1);

namespace kim\present\serializer\nbt\tests;

use kim\present\serializer\nbt\Compression;
use kim\present\serializer\nbt\Endian;
use kim\present\serializer\nbt\NbtSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntArrayTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\TreeRoot;

final class BinaryFormatTest extends TestCase{

    private static function sample() : CompoundTag{
        return CompoundTag::create()
            ->setByte("b", -5)
            ->setShort("s", -1234)
            ->setInt("i", -123456789)
            ->setLong("l", PHP_INT_MIN)
            ->setFloat("f", 1.5)
            ->setDouble("d", 0.1)
            ->setString("str", "한글 text")
            ->setTag("ba", new ByteArrayTag("\x00\xff"))
            ->setTag("ia", new IntArrayTag([-1, 7]))
            ->setTag("list", new ListTag([new StringTag("a"), new StringTag("b")]))
            ->setTag("c", CompoundTag::create()->setInt("x", 1));
    }

    public function testLittleEndianMatchesPocketmine() : void{
        $tag = self::sample();
        $binary = NbtSerializer::toBinary($tag, Endian::LITTLE);
        self::assertSame((new LittleEndianNbtSerializer())->write(new TreeRoot($tag)), $binary);
        self::assertTrue(NbtSerializer::fromBinary($binary, Endian::LITTLE)->equals($tag));
        self::assertNotSame($binary, NbtSerializer::toBinary($tag));
        self::assertTrue(NbtSerializer::fromBase64(NbtSerializer::toBase64($tag, Endian::LITTLE), Endian::LITTLE)->equals($tag));
        self::assertTrue(NbtSerializer::fromHex(NbtSerializer::toHex($tag, Endian::LITTLE), Endian::LITTLE)->equals($tag));
    }

    /** @return array<string, array{Compression, Endian}> */
    public static function compressionProvider() : array{
        $cases = [];
        foreach(Compression::cases() as $compression){
            foreach(Endian::cases() as $endian){
                $cases["$compression->name $endian->name"] = [$compression, $endian];
            }
        }
        return $cases;
    }

    #[DataProvider("compressionProvider")]
    public function testCompressionRoundTrip(Compression $compression, Endian $endian) : void{
        $tag = self::sample();
        $compressed = NbtSerializer::toCompressed($tag, $compression, $endian);
        self::assertTrue(NbtSerializer::fromCompressed($compressed, $compression, $endian)->equals($tag));
    }

    public function testCompressionIsSmaller() : void{
        $list = new ListTag();
        for($i = 0; $i < 100; ++$i){
            $list->push(CompoundTag::create()->setString("Name", "minecraft:diamond_sword")->setByte("Count", 1));
        }
        $tag = CompoundTag::create()->setTag("Items", $list);
        self::assertLessThan(strlen(NbtSerializer::toBinary($tag)) / 5, strlen(NbtSerializer::toCompressed($tag, Compression::DEFLATE)));
    }

    public function testGzipMatchesGzdecode() : void{
        $tag = self::sample();
        self::assertSame(NbtSerializer::toBinary($tag), gzdecode(NbtSerializer::toCompressed($tag)));
    }

    public function testCorruptedCompressedThrows() : void{
        $this->expectException(NbtDataException::class);
        NbtSerializer::fromCompressed("not compressed");
    }

    public function testDecompressionBombIsRejected() : void{
        $compressed = gzencode(str_repeat("\x00", 1024 * 1024));
        self::assertNull(NbtSerializer::tryFromCompressed($compressed, maxLength: 1024));
    }

    public function testInvalidMaxLengthIsRejected() : void{
        $compressed = NbtSerializer::toCompressed(self::sample());
        foreach([0, -1] as $maxLength){
            try{
                NbtSerializer::tryFromCompressed($compressed, maxLength: $maxLength);
                self::fail("maxLength $maxLength must be rejected");
            }catch(\InvalidArgumentException){
            }
        }
        self::assertNotNull(NbtSerializer::tryFromCompressed($compressed));
    }

    public function testBinaryDepthLimit() : void{
        $tag = new ListTag();
        for($i = 1; $i < 20; ++$i){
            $tag = new ListTag([$tag]);
        }
        $binary = NbtSerializer::toBinary($tag);
        self::assertTrue(NbtSerializer::fromBinary($binary, maxDepth: 20)->equals($tag));

        $this->expectException(NbtDataException::class);
        NbtSerializer::fromBinary($binary, maxDepth: 19);
    }

    public function testDeeplyNestedBinaryDoesNotOverflow() : void{
        // list of list of ... (no end), hand made to avoid building the tree
        $binary = "\x09\x00\x00" . str_repeat("\x09\x00\x00\x00\x01", 1_000_000);
        $this->expectException(NbtDataException::class);
        NbtSerializer::fromBinary($binary);
    }

    public function testTryVariants() : void{
        self::assertNull(NbtSerializer::tryFromBinary("\x0a"));
        self::assertNull(NbtSerializer::tryFromBase64("!!!"));
        self::assertNull(NbtSerializer::tryFromHex("abc"));
        self::assertNull(NbtSerializer::tryFromHex("zz"));
        self::assertNull(NbtSerializer::tryFromSnbt("{a:"));
        self::assertNull(NbtSerializer::tryFromSnbt("[[1]]", maxDepth: 1));
        self::assertNull(NbtSerializer::tryFromSnbt("{a:1}", maxLength: 4));
        self::assertNotNull(NbtSerializer::tryFromSnbt("{a:1}", maxLength: 5));
        self::assertTrue(NbtSerializer::tryFromHex(NbtSerializer::toHex(self::sample()))?->equals(self::sample()));
    }

    public function testInvalidHexThrowsWithoutWarning() : void{
        $this->expectException(\InvalidArgumentException::class);
        NbtSerializer::fromHex("abc");
    }

}
