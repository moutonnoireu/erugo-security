# E-mail languages

One JSON file per language: `en.json` is the reference and the fallback for any missing text.

To add a language, copy `en.json` to `<code>.json` (for example `es.json`), set `_meta.name` and translate the values. Keep the `{placeholders}` as they are. Texts you leave in English stay in English.

You can also create a language and edit its texts from Settings > Email templates. Those edits are stored in `storage/templates/lang/` and win over the files here.

The language used is the instance language (`default_language`).
