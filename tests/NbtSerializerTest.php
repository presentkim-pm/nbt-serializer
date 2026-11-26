<?php

declare(strict_types=1);

namespace kim\present\serializer\nbt\tests;

use kim\present\serializer\nbt\NbtSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\BigEndianNbtSerializer;
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
use pocketmine\nbt\TreeRoot;

final class NbtSerializerTest extends TestCase{

    private static function randomTag(int $depth) : Tag{
        $type = mt_rand(1, $depth > 3 ? 8 : 11);
        switch($type){
            case 1: return new ByteTag(mt_rand(-128, 127));
            case 2: return new ShortTag(mt_rand(-32768, 32767));
            case 3: return new IntTag(mt_rand(-0x80000000, 0x7fffffff));
            case 4: return new LongTag(mt_rand(PHP_INT_MIN, PHP_INT_MAX));
            case 5: return new FloatTag(unpack("G", pack("G", mt_rand() / 7.3))[1]);
            case 6: return new DoubleTag(mt_rand() / 3.1);
            case 7: return new ByteArrayTag(($n = mt_rand(0, 6)) > 0 ? random_bytes($n) : "");
            case 8: return new StringTag(["", "a b", "q\"\\x", "한글", "x\ny", "{,}[]:", "a\\", "'", "\xff\xfe"][mt_rand(0, 8)]);
            case 9:
                $list = new ListTag();
                $kind = mt_rand(1, 8);
                for($i = mt_rand(0, 3); $i > 0; --$i){
                    $list->push(self::listEntry($kind));
                }
                return $list;
            case 10:
                $compound = CompoundTag::create();
                for($i = mt_rand(0, 4); $i > 0; --$i){
                    $compound->setTag(["k", "a b", "", "1", "é", ":x", "true", "a\\"][mt_rand(0, 7)] . $i, self::randomTag($depth + 1));
                }
                return $compound;
            default:
                return new IntArrayTag(array_map(static fn() => mt_rand(-0x80000000, 0x7fffffff), range(1, mt_rand(1, 4))));
        }
    }

    private static function listEntry(int $kind) : Tag{
        return match ($kind) {
            1 => new ByteTag(-1),
            2 => new ShortTag(2),
            3 => new IntTag(3),
            4 => new LongTag(PHP_INT_MIN),
            5 => new FloatTag(1.5),
            6 => new DoubleTag(0.1),
            7 => new StringTag("s\\"),
            default => CompoundTag::create()->setInt("a", 1),
        };
    }

    public function testRandomTreesRoundTrip() : void{
        mt_srand(20261126);
        $reference = new BigEndianNbtSerializer();
        for($i = 0; $i < 2000; ++$i){
            $tag = self::randomTag(0);

            $binary = NbtSerializer::toBinary($tag);
            self::assertSame($reference->write(new TreeRoot($tag)), $binary, "binary output must match pocketmine/nbt");
            self::assertTrue(NbtSerializer::fromBinary($binary)->equals($tag), "binary round trip");
            self::assertTrue(NbtSerializer::fromBase64(NbtSerializer::toBase64($tag))->equals($tag), "base64 round trip");
            self::assertTrue(NbtSerializer::fromHex(NbtSerializer::toHex($tag))->equals($tag), "hex round trip");

            $snbt = NbtSerializer::toSnbt($tag);
            self::assertTrue(NbtSerializer::fromSnbt($snbt)->equals($tag), "SNBT round trip : $snbt");
            self::assertTrue(NbtSerializer::fromSnbt(NbtSerializer::toSnbtPretty($tag))->equals($tag), "pretty SNBT round trip : $snbt");
        }
    }

    /** @return array<string, array{Tag, string}> */
    public static function snbtOutputProvider() : array{
        return [
            "double precision"   => [new DoubleTag(0.1 + 0.2), "0.30000000000000004d"],
            "integral double"    => [new DoubleTag(3.0), "3d"],
            "float infinity"     => [new FloatTag(INF), "Infinityf"],
            "double -infinity"   => [new DoubleTag(-INF), "-Infinityd"],
            "trailing backslash" => [new StringTag("a\\"), '"a\\\\"'],
            "control characters" => [new StringTag("a\n\t\x01"), '"a\\n\\t\\u0001"'],
            "unicode as-is"      => [new StringTag("한글"), '"한글"'],
            "signed bytes"       => [new ByteArrayTag("\x01\xff"), "[B;1b,-1b]"],
            "empty key"          => [CompoundTag::create()->setInt("", 1), '{"":1}'],
            "quoted key"         => [CompoundTag::create()->setInt("a b", 1), '{"a b":1}'],
        ];
    }

    #[DataProvider("snbtOutputProvider")]
    public function testSnbtOutput(Tag $tag, string $expected) : void{
        self::assertSame($expected, NbtSerializer::toSnbt($tag));
        self::assertTrue(NbtSerializer::fromSnbt($expected)->equals($tag));
    }

    public function testNaNRoundTrip() : void{
        self::assertSame("NaNd", NbtSerializer::toSnbt(new DoubleTag(NAN)));
        $tag = NbtSerializer::fromSnbt("NaNf");
        self::assertInstanceOf(FloatTag::class, $tag);
        self::assertNan($tag->getValue());
    }

    /** @return array<string, array{string, Tag}> */
    public static function snbtInputProvider() : array{
        return [
            "single quote escape"   => ["'it\\'s'", new StringTag("it's")],
            "double in single"      => ["'say \"hi\"'", new StringTag('say "hi"')],
            "unicode escape"        => ['"\\u2019\\ud83d\\ude00"', new StringTag("\u{2019}\u{1F600}")],
            "exponent is double"    => ["1e5", new DoubleTag(100000.0)],
            "decimal byte is text"  => ["1.5b", new StringTag("1.5b")],
            "upper byte suffix"     => ["[B;1B,2b]", new ByteArrayTag("\x01\x02")],
            "unsigned bytes"        => ["[B;255b]", new ByteArrayTag("\xff")],
            "int array trailing"    => ["[I;1,-2,]", new IntArrayTag([1, -2])],
            "empty arrays"          => ["[[B;],[B; ]]", new ListTag([new ByteArrayTag(""), new ByteArrayTag("")])],
            "space before colon"    => ['{"a"  :1}', CompoundTag::create()->setInt("a", 1)],
            "unquoted text"         => ["{a: hello world }", CompoundTag::create()->setString("a", "hello world")],
            "pretty whitespace"     => ["{\n    a: [\n        1b\n    ]\n}", CompoundTag::create()->setTag("a", new ListTag([new ByteTag(1)]))],
        ];
    }

    #[DataProvider("snbtInputProvider")]
    public function testSnbtInput(string $snbt, Tag $expected) : void{
        $tag = NbtSerializer::fromSnbt($snbt);
        self::assertTrue($tag->equals($expected), "parsed " . NbtSerializer::toSnbt($tag));
    }

    /** @return array<string, array{string}> */
    public static function invalidSnbtProvider() : array{
        return [
            "byte out of range"     => ["300b"],
            "long out of range"     => ["99999999999999999999l"],
            "int out of range"      => ["2147483648"],
            "mixed list"            => ['[1,"a"]'],
            "mixed number list"     => ["[1b,2s]"],
            "trailing data"         => ["{a:1} trailing"],
            "int array overflow"    => ["[I;99999999999]"],
            "byte array overflow"   => ["[B;256b]"],
            "byte array no suffix"  => ["[B;1]"],
            "empty value"           => ["{a:}"],
            "unclosed string"       => ['"abc'],
            "unclosed escape"       => ['"abc\\'],
            "unclosed compound"     => ["{a:1"],
            "unclosed list"         => ["[1,2"],
            "missing colon"         => ['{"a"}'],
            "quoted key at end"     => ['{"a"'],
            "invalid escape"        => ['"\\x"'],
            "lone surrogate"        => ['"\\ud83d"'],
            "text before quote"     => ['abc"def"'],
        ];
    }

    #[DataProvider("invalidSnbtProvider")]
    public function testInvalidSnbtThrowsNbtDataException(string $snbt) : void{
        $this->expectException(NbtDataException::class);
        NbtSerializer::fromSnbt($snbt);
    }

    public function testInvalidEncodings() : void{
        try{
            NbtSerializer::fromBase64("!!!");
            self::fail("base64");
        }catch(\InvalidArgumentException){
        }

        $this->expectException(NbtDataException::class);
        NbtSerializer::fromBinary("\x0a\x00");
    }

}
