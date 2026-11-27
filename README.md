<!-- PROJECT BADGES -->
<div align="center">

[![Packagist][packagist-badge]][packagist-url]
[![Downloads][downloads-badge]][packagist-url]
[![CI][ci-badge]][ci-url]
[![PHP][php-badge]][php-url]
[![Stars][stars-badge]][stars-url]
[![License][license-badge]][license-url]

</div>

<!-- PROJECT LOGO -->
<br />
<div align="center">
  <img src="https://raw.githubusercontent.com/presentkim-pm/nbt-serializer/main/assets/icon.png" alt="Logo" width="80" height="80"/>
  <h3>nbt-serializer</h3>
  <p align="center">
    A powerful library that makes (de)serializing NBT shorter, faster, and easier!

[View on Packagist][packagist-url] · [View on GitHub][repo-url] · [Report a bug][issues-url] · [Request a feature][issues-url]

  </p>
</div>


<!-- ABOUT THE PROJECT -->
## 🌟 About The Project

This library provides the most convenient and high-performance tools for handling NBT, including **SNBT** support.
Supports multiple serialization formats to fit various use cases, from human-readable strings to compact binary representations.  

### Requirements
| | Version |
|---|---|
| Server | [axolotl-pm/PocketMine-MP][axolotl-url] |
| PHP | **8.4** or newer |
| [pocketmine/nbt][nbt-url] | `^1.2` (bundled with the server) |

> Looking for the version for the original [pmmp/PocketMine-MP](https://github.com/pmmp/PocketMine-MP) 5.x (Poggit virion)?  
> See the [`legacy/pmmp-5.44`][legacy-url] branch, or install `presentkim/nbt-serializer:^2.1`.

---

## 🚀 Serialization methods list

> Every class is in the `kim\present\serializer\nbt` namespace.

### 1. StringifiedNBT Serialization
The **nbt-serializer** library is optimized for working with SNBT, a human-readable format for NBT data. 

#### Supported Functions
```php
NbtSerializer::toSnbt(Tag $tag, bool $sortKeys = true) : string
NbtSerializer::toSnbtPretty(Tag $tag, int $indentLevel = 0, string $indentChar = "    ", string $lineBreak = "\n", bool $sortKeys = true) : string
NbtSerializer::fromSnbt(string $contents, int $maxDepth = 512, ?int $maxLength = null) : Tag
NbtSerializer::tryFromSnbt(string $contents, int $maxDepth = 512, ?int $maxLength = null) : ?Tag
```
> Compound entries are sorted by key by default, so equal tags always produce the same string.
> Pass `sortKeys: false` to keep the insertion order.

#### Result example
```js
{
    Count: 1b,
    Damage: 0s,
    Name: "minecraft:diamond_sword",
    Slot: 12b,
    tag: {
        generatedBy: "GPT",
        itemType: "weapon",
        rarity: "legendary",
        stats: {
            attackDamage: 20,
            criticalChance: 15f,
            skill: "Dragon’s Breath"
        }
    }
}
```

#### What is SNBT?
SNBT, or **Stringified Named Binary Tag**, represents NBT data in a textual, human-readable format.
It provides developers and users with an easier way to read, debug, and modify NBT data compared to the traditional binary format.  
This format is commonly used in Minecraft commands, data packs, and debugging workflows.

This format is highly accessible compared to its binary equivalent, making it ideal for debugging or manual editing.  
The **nbt-serializer** library ensures **efficient** and **accurate** SNBT serialization and deserialization, saving you time and effort!

For more details, see [Official SNBT Documentation](https://minecraft.wiki/w/NBT_format#SNBT_format).

#### Format notes
- `fromSnbt()` throws `pocketmine\nbt\NbtDataException` (or its subclasses) for every invalid input,
  including out of range numbers, mixed list entry types, nesting deeper than 512 and trailing data after the value.
- Floating point values are written without precision loss (e.g. `0.30000000000000004d`),
  and non-finite values are written as `NaN`, `Infinity` and `-Infinity` with the `f` / `d` suffix.
- Strings are quoted with `"` and only quotes, backslashes and control characters are escaped,
  so every byte (including invalid UTF-8) is preserved.
- Both `"` and `'` quoted strings are accepted, with the `\\`, `\"`, `\'`, `\n`, `\t`, `\uXXXX`, ... escapes.

#### Use Cases
- **Searchable Databases**: Store NBT data in SNBT format to enable fast and human-readable queries in databases.
- **User Commands and Configuration**: Load or define NBT data directly from user input or configuration files with minimal overhead.
- **Debugging and Logging**: Easily inspect and log NBT structures in a readable format for debugging purposes.
- **Minecraft Data Packs**: Seamlessly integrate with Minecraft commands and data packs, which commonly utilize SNBT for defining custom structures.

---

### 2. Binary Serialization
Efficiently serialize NBT tags into a compact binary format, byte-for-byte identical to
`pocketmine\nbt\BigEndianNbtSerializer` (default, Java Edition files) or `LittleEndianNbtSerializer` (`Endian::LITTLE`, Bedrock Edition files).

#### Supported Functions
```php
NbtSerializer::toBinary(Tag $tag, Endian $endian = Endian::BIG) : string
NbtSerializer::fromBinary(string $contents, Endian $endian = Endian::BIG, int $maxDepth = 512) : Tag
NbtSerializer::tryFromBinary(string $contents, Endian $endian = Endian::BIG, int $maxDepth = 512) : ?Tag
```
> Unlike pocketmine/nbt, negative values of `TAG_Int_Array` are decoded with their sign.

#### Result example
> It contains values that cannot be expressed in text, so provides Unicode Escaped content.
```
\n\u0000\u0000\u0001\u0000\u0005Count\u0001\u0001\u0000\u0004Slot\f\b\u0000\u0004Name\u0000\u0017minecraft:diamond_sword\u0002\u0000\u0006Damage\u0000\u0000\n\u0000\u0003tag\b\u0000\u0006rarity\u0000\tlegendary\b\u0000\bitemType\u0000\u0006weapon\n\u0000\u0005stats\u0003\u0000\fattackDamage\u0000\u0000\u0000\u0014\u0005\u0000\u000ecriticalChanceAp\u0000\u0000\b\u0000\u0005skill\u0000\u0011Dragon\u2019s Breath\u0000\b\u0000\u000bgeneratedBy\u0000\u0003GPT\u0000\u0000
```

#### Why Use Binary Serialization?
Binary serialization is optimal for:
- Storing or transmitting NBT data with minimal size overhead.
- Ensuring fast data processing and parsing.

However, this format is **not suitable for text-based storage** (e.g., JSON or databases) without additional encoding.

---

### 3. Base64-Encoded Binary Serialization
Base64 encoding allows binary data to be represented in text format, making it suitable for text-based storage.

#### Supported Functions
```php
NbtSerializer::toBase64(Tag $tag, Endian $endian = Endian::BIG) : string
NbtSerializer::fromBase64(string $contents, Endian $endian = Endian::BIG, int $maxDepth = 512) : Tag
NbtSerializer::tryFromBase64(string $contents, Endian $endian = Endian::BIG, int $maxDepth = 512) : ?Tag
```

#### Result example
```
CgAAAQAFQ291bnQBAQAEU2xvdAwIAAROYW1lABdtaW5lY3JhZnQ6ZGlhbW9uZF9zd29yZAIABkRhbWFnZQAACgADdGFnCAAGcmFyaXR5AAlsZWdlbmRhcnkIAAhpdGVtVHlwZQAGd2VhcG9uCgAFc3RhdHMDAAxhdHRhY2tEYW1hZ2UAAAAUBQAOY3JpdGljYWxDaGFuY2VBcAAACAAFc2tpbGwAEURyYWdvbuKAmXMgQnJlYXRoAAgAC2dlbmVyYXRlZEJ5AANHUFQAAA==
```

#### Use Cases
- Storing binary NBT data in text-based systems like JSON, URLs, or databases.
- Ensuring compatibility while preserving data integrity.

---

### 4. Hexadecimal-Encoded Binary Serialization
Hexadecimal encoding provides an alternative way to convert binary data into a human-readable text format.

#### Supported Functions
```php
NbtSerializer::toHex(Tag $tag, Endian $endian = Endian::BIG) : string
NbtSerializer::fromHex(string $contents, Endian $endian = Endian::BIG, int $maxDepth = 512) : Tag
NbtSerializer::tryFromHex(string $contents, Endian $endian = Endian::BIG, int $maxDepth = 512) : ?Tag
```

#### Result example
```
0a0000010005436f756e7401010004536c6f740c0800044e616d6500176d696e6563726166743a6469616d6f6e645f73776f726402000644616d61676500000a000374616708000672617269747900096c6567656e646172790800086974656d547970650006776561706f6e0a0005737461747303000c61747461636b44616d6167650000001405000e637269746963616c4368616e636541700000080005736b696c6c0011447261676f6ee2809973204272656174680008000b67656e657261746564427900034750540000
```

#### Use Cases
- Debugging binary NBT data with an easily visualized format.
- Transmitting NBT data through mediums that support only alphanumeric characters.

---

### 5. Compressed Binary Serialization
Compress the binary format with gzip (default, the format of Java Edition `.nbt` / `.dat` files), zlib or raw deflate.
NBT repeats the same key names a lot, so it is usually several times smaller.

#### Supported Functions
```php
NbtSerializer::toCompressed(Tag $tag, Compression $compression = Compression::GZIP, Endian $endian = Endian::BIG, int $level = -1) : string
NbtSerializer::fromCompressed(string $contents, Compression $compression = Compression::GZIP, Endian $endian = Endian::BIG, int $maxDepth = 512, int $maxLength = 64 MiB) : Tag
NbtSerializer::tryFromCompressed(/* same as fromCompressed */) : ?Tag
```
> `$maxLength` limits the size of the decompressed data, to protect against decompression bombs.  
> Combine with `base64_encode()` to store it in text-based storage.

---

## 🧰 Utilities

### Deserializing untrusted input
Every `from*()` method has a `tryFrom*()` variant returning `null` instead of throwing,
and accepts `$maxDepth` (nesting depth of compound and list tags, 512 by default) to reject maliciously deep data.
```php
$tag = NbtSerializer::tryFromSnbt($userInput, maxDepth: 32, maxLength: 4096);
if($tag === null){
    // invalid input
}
```

### Hashing
```php
NbtSerializer::hash(Tag $tag, string $algo = "xxh128") : string
```
Equal tags always have the same hash, regardless of the order of the compound entries.
Useful for deduplication, cache keys and comparing items.

### NBT path
Read and modify a tag deep in the tree with a path like the Minecraft NBT path.
```php
use kim\present\serializer\nbt\NbtPath;

$path = NbtPath::parse('tag.display.Lore[0]');  // parsed paths are cached
$path->get($tag);                                 // ?Tag
$path->has($tag);                                 // bool
$path->set($tag, new StringTag("First line"));    // missing compounds are created
$path->remove($tag);                              // bool, whether it existed

NbtPath::parse('tag."key with.dot"[-1]');         // quoted keys, negative index from the end
(string) NbtPath::of("tag", "a.b", 0);            // 'tag."a.b"[0]'
```
- `key` / `"key"` / `'key'` : entry of a compound tag
- `[index]` : entry of a list tag (byte / int arrays can be read too), `[-1]` is the last entry
- an empty path is the root tag itself
- an invalid path, or a `set()` that can not be applied, throws `NbtPathException`

### Merge and diff
```php
use kim\present\serializer\nbt\NbtTree;

// deep merge like the `/data merge` command, neither of the arguments is modified
$merged = NbtTree::merge($base, $patch);

// differences between two trees
foreach(NbtTree::diff($old, $new) as $change){
    echo $change->path, " : ", $change->isAdded() ? "added" : ($change->isRemoved() ? "removed" : "modified"), "\n";
}

// apply the differences to another tree
NbtTree::apply($target, NbtTree::diff($old, $new));
```

---

## 📊 Performance test results

Provide performance test results to help you choose the serialization method.

See [Performance Test Results][performance-url]

-----

## 🛠️ Installation

The package is published on [Packagist][packagist-url], install it with [Composer](https://getcomposer.org) :
```bash
composer require presentkim/nbt-serializer
```
or add it to your `composer.json` :
```json
{
  "require": {
    "presentkim/nbt-serializer": "^3.0"
  }
}
```
See [CHANGELOG.md][changelog-url] for the changes of each version.

When distributing a plugin, bundle the `vendor` directory into the plugin and load `vendor/autoload.php` (e.g. in `onLoad()`).  
Since the library is no longer shaded as a virion, use a single copy of it per server (or prefix the namespace with a tool such as [PHP-Scoper](https://github.com/humbug/php-scoper)) to avoid class conflicts between plugins.

### Running tests
```bash
composer install
composer test
```

-----

## 📝 License

Distributed under the **MIT**. See [LICENSE][license-url] for more information


[packagist-badge]: https://img.shields.io/packagist/v/presentkim/nbt-serializer.svg?style=for-the-badge

[downloads-badge]: https://img.shields.io/packagist/dt/presentkim/nbt-serializer.svg?style=for-the-badge

[ci-badge]: https://img.shields.io/github/actions/workflow/status/presentkim-pm/nbt-serializer/ci.yml?branch=main&style=for-the-badge

[php-badge]: https://img.shields.io/badge/php-%3E%3D8.4-777BB4.svg?style=for-the-badge

[stars-badge]: https://img.shields.io/github/stars/presentkim-pm/nbt-serializer.svg?style=for-the-badge

[license-badge]: https://img.shields.io/github/license/presentkim-pm/nbt-serializer.svg?style=for-the-badge

[ci-url]: https://github.com/presentkim-pm/nbt-serializer/actions/workflows/ci.yml

[php-url]: https://www.php.net/releases/8.4/

[packagist-url]: https://packagist.org/packages/presentkim/nbt-serializer

[changelog-url]: https://github.com/presentkim-pm/nbt-serializer/blob/main/CHANGELOG.md

[repo-url]: https://github.com/presentkim-pm/nbt-serializer

[axolotl-url]: https://github.com/axolotl-pm/PocketMine-MP

[nbt-url]: https://github.com/pmmp/NBT

[legacy-url]: https://github.com/presentkim-pm/nbt-serializer/tree/legacy/pmmp-5.44

[stars-url]: https://github.com/presentkim-pm/nbt-serializer/stargazers

[issues-url]: https://github.com/presentkim-pm/nbt-serializer/issues

[license-url]: https://github.com/presentkim-pm/nbt-serializer/blob/main/LICENSE

[performance-url]: https://github.com/presentkim-pm/nbt-serializer/blob/main/PERFORMANCE.md

[project-icon]: https://raw.githubusercontent.com/presentkim-pm/nbt-serializer/main/assets/icon.png
