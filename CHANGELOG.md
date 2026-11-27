# Changelog

## Unreleased

### 🐛 Fixes
- `toSnbtPretty()` no longer writes a trailing space at the end of each line.

### 📝 Documentation
- Rewrote the README : highlights, use cases, format comparison, usage of every API and exceptions.

## 3.0.0 (2026-11-27)

The original [pmmp/PocketMine-MP](https://github.com/pmmp/PocketMine-MP) 5.x (Poggit virion) version is kept on the
[`legacy/pmmp-5.44`](https://github.com/presentkim-pm/nbt-serializer/tree/legacy/pmmp-5.44) branch.

### ⚠️ Breaking changes
- Targets [axolotl-pm/PocketMine-MP](https://github.com/axolotl-pm/PocketMine-MP) and **PHP 8.4+**.
  The library now requires `pocketmine/nbt ^1.2` and `ext-zlib` instead of `pocketmine/pocketmine-mp`.
- Distributed with Composer (`composer require presentkim/nbt-serializer`) : the Poggit virion files are removed.
- SNBT output
  - compound entries are **sorted by key** by default (`toSnbt($tag, sortKeys: false)` keeps the insertion order)
  - floats are written without precision loss (`0.30000000000000004d`), non-finite values as `NaN` / `Infinity` / `-Infinity`
  - strings keep unicode as-is (`"한글"` instead of `"한글"`), only quotes, backslashes and control characters are escaped
  - byte arrays are written with signed values (`[B;-1b]`), as Minecraft does
- SNBT parsing is strict : out of range numbers, mixed list entry types, trailing data and invalid escapes
  throw `pocketmine\nbt\NbtDataException` (instead of `TypeError`, `InvalidTagValueException` or silently ignoring them).
- Negative `TAG_Int_Array` values are decoded with their sign (pocketmine/nbt decodes them as unsigned).
- The internal `BigEndianNbtCodec` class is renamed to `BinaryNbtCodec`.

### ✨ Features
- `NbtSerializer::hash()` : equal tags always have the same hash, regardless of the order of the compound entries.
- `tryFrom*()` variants returning `null` for invalid input, and `maxDepth` (512 by default) / `maxLength` limits for untrusted input.
- `Endian::LITTLE` for the Bedrock Edition binary format, byte-for-byte identical to `LittleEndianNbtSerializer`.
- `toCompressed()` / `fromCompressed()` with gzip, zlib and raw deflate, and a decompression size limit (64 MiB by default).
- `NbtPath` : read and modify tags with a path like the Minecraft NBT path (`tag.display.Lore[0]`, `"quoted.key"[-1]`).
- `NbtTree` : deep `merge()` (like `/data merge`), `diff()` and `apply()`.

### 🐛 Fixes
- Implicit nullable parameters, deprecated in PHP 8.4 (an exception under PocketMine-MP's error handler).
- `NAN` to string coercion warning on PHP 8.5.
- Strings ending with a backslash, `\'` in single-quoted strings and strings that are not valid UTF-8 could not be read back
  (or were silently written as `""`).
- Spaces between a quoted key and `:`, upper case byte array suffixes (`[B;1B]`) and exponents (`1e5`, now a double) are accepted.
- Invalid hex input no longer emits a warning, binary and SNBT nesting is limited to avoid stack overflows.
- Unsigned byte (0 – 255) and int (0 – 0xffffffff) array values written by the previous versions are still accepted.

### ⚡ Performance
Binary (de)serialization is 13–71% faster and SNBT up to 19% faster, see [PERFORMANCE.md](PERFORMANCE.md).

### 🧪 Development
- PHPUnit tests (129 tests, including randomized comparisons with pocketmine/nbt) and GitHub Actions CI on PHP 8.4 / 8.5.
