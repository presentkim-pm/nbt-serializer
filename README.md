<!-- PROJECT BADGES -->
<div align="center">

[![Poggit CI][poggit-ci-badge]][poggit-ci-url]
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

[View in Poggit][poggit-ci-url] · [Report a bug][issues-url] · [Request a feature][issues-url]

  </p>
</div>


<!-- ABOUT THE PROJECT -->
## 🌟 About The Project

This library provides the most convenient and high-performance tools for handling NBT, including **SNBT** support.
Supports multiple serialization formats to fit various use cases, from human-readable strings to compact binary representations.  

---

## 🚀 Serialization methods list

### 1. StringifiedNBT Serialization
The **nbt-serializer** library is optimized for working with SNBT, a human-readable format for NBT data. 

#### Supported Functions
```php
kim\present\serializer\nbt\NbtSerializer::toSnbt(Tag $tag) : string
kim\present\serializer\nbt\NbtSerializer::fromSnbt(string $contents) : Tag
```

#### Result example
```js
{
    Count: 1b,
    Slot: 12b,
    Name: "minecraft:diamond_sword",
    Damage: 0s,
    tag: {
        rarity: "legendary",
        itemType: "weapon",
        stats: {
            attackDamage: 20,
            criticalChance: 15f,
            skill: "Dragon\u2019s Breath"
        },
        generatedBy: "GPT"
    }
}
```

#### What is SNBT?
SNBT, or **Stringified Named Binary Tag**, represents NBT data in a textual, human-readable format.
It provides developers and users with an easier way to read, debug, and modify NBT data compared to the traditional binary format.  
This format is commonly used in Minecraft commands, data packs, and debugging workflows.

This format is highly accessible compared to its binary equivalent, making it ideal for debugging or manual editing.  
The **nbt-serializer** library ensures **efficient** and **accurate** SNBT serialization and deserialization, saving you time and effort!

For more details, see [Official SNBT Documentation](https://minecraft.fandom.com/wiki/NBT_format#SNBT_format).

#### Use Cases
- **Searchable Databases**: Store NBT data in SNBT format to enable fast and human-readable queries in databases.
- **User Commands and Configuration**: Load or define NBT data directly from user input or configuration files with minimal overhead.
- **Debugging and Logging**: Easily inspect and log NBT structures in a readable format for debugging purposes.
- **Minecraft Data Packs**: Seamlessly integrate with Minecraft commands and data packs, which commonly utilize SNBT for defining custom structures.

---

### 2. Binary Serialization
Efficiently serialize NBT tags into a compact binary format using the `BigEndianNbtSerializer`.

#### Supported Functions
```php
kim\present\serializer\nbt\NbtSerializer::toBinary(Tag $tag) : string
kim\present\serializer\nbt\NbtSerializer::fromBinary(string $contents) : Tag
```

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
kim\present\serializer\nbt\NbtSerializer::toBase64(Tag $tag) : string
kim\present\serializer\nbt\NbtSerializer::fromBase64(string $contents) : Tag
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
kim\present\serializer\nbt\NbtSerializer::toHex(Tag $tag) : string
kim\present\serializer\nbt\NbtSerializer::fromHex(string $contents) : Tag
```

#### Result example
```
0a0000010005436f756e7401010004536c6f740c0800044e616d6500176d696e6563726166743a6469616d6f6e645f73776f726402000644616d61676500000a000374616708000672617269747900096c6567656e646172790800086974656d547970650006776561706f6e0a0005737461747303000c61747461636b44616d6167650000001405000e637269746963616c4368616e636541700000080005736b696c6c0011447261676f6ee2809973204272656174680008000b67656e657261746564427900034750540000
```

#### Use Cases
- Debugging binary NBT data with an easily visualized format.
- Transmitting NBT data through mediums that support only alphanumeric characters.

---

## 📊 Performance test results

Provide performance test results to help you choose the serialization method.

See [Performance Test Results][performance-url]

-----

## 🛠️ How to installation

See [Official Poggit Virion Documentation](https://github.com/poggit/support/blob/master/virion.md)

-----

## 📝 License

Distributed under the **MIT**. See [LICENSE][license-url] for more information


[poggit-ci-badge]: https://poggit.pmmp.io/ci.shield/presentkim-pm/nbt-serializer/nbt-serializer?style=for-the-badge

[stars-badge]: https://img.shields.io/github/stars/presentkim-pm/nbt-serializer.svg?style=for-the-badge

[license-badge]: https://img.shields.io/github/license/presentkim-pm/nbt-serializer.svg?style=for-the-badge

[poggit-ci-url]: https://poggit.pmmp.io/ci/presentkim-pm/nbt-serializer/nbt-serializer

[stars-url]: https://github.com/presentkim-pm/nbt-serializer/stargazers

[issues-url]: https://github.com/presentkim-pm/nbt-serializer/issues

[license-url]: https://github.com/presentkim-pm/nbt-serializer/blob/main/LICENSE

[performance-url]: https://github.com/presentkim-pm/nbt-serializer/blob/main/PERFORMANCE.md

[project-icon]: https://raw.githubusercontent.com/presentkim-pm/nbt-serializer/main/assets/icon.png
