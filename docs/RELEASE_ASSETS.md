# Required release assets

The application source deliberately does not contain several runtime assets that
were present in the read-only Production source snapshot. Their provenance and
redistribution rights have not been independently verified, so they must not be
copied into Git by a reconciliation task.

`release/required-assets.tsv` is the versioned release contract. Before any
release, run:

```sh
sh scripts/release/verify_required_assets.sh
```

The command is read-only, performs no network access, and fails for a missing
or checksum-mismatched required asset. A release source/artifact must supply
the exact listed bytes at the listed paths before deployment. It accepts an
explicit `--root` and `--manifest` only for local verification fixtures.

## Required assets

| Runtime dependency | Contract paths | Why missing is fatal |
| --- | --- | --- |
| Fee receipt and payroll PDFs | `NotoSansSC-Regular.ttf`, `NotoSansSC-Bold.ttf` | The receipt and payroll PDF views explicitly register both files through `public_path()`. |
| Shared application layout | `font-awesome.min.css` and `fontawesome-webfont.{eot,woff2,woff,ttf}` | The shared layout includes this CSS; its `@font-face` rule resolves those sibling files. |
| CKEditor 4 | `ckeditor-4/vendor/promise.js` | CKEditor dynamically loads it when the browser does not provide `window.Promise`. |

## Explicit exclusions

- `NotoSansSC-Bold copy.ttf`: byte-for-byte duplicate of the required bold font.
- `NotoSansSC-*.upload.tmp`: temporary/incomplete uploads.
- Other Noto Sans SC weights: no current runtime reference.
- `fontawesome-webfont (1).eot`: byte-for-byte duplicate of the required EOT.
- Font Awesome SVG fallback: not present in the authoritative snapshot, so it
  cannot be made part of this Production-parity contract.

The Font Awesome stylesheet identifies itself as version 4.7.0. The CKEditor
tree already includes its upstream license material. No further licence or
provenance claim is made here for the external font/font assets; obtaining and
approving their source is a separate release-ownership decision.
