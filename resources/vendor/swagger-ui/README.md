# Swagger UI assets

Pinned distribution: `swagger-ui-dist` **5.33.1**, from the [official npm package](https://www.npmjs.com/package/swagger-ui-dist).

Downloaded from `https://registry.npmjs.org/swagger-ui-dist/-/swagger-ui-dist-5.33.1.tgz` on 8 October 2026. The complete archive was verified against the registry's published SHA-512 integrity before extracting the JavaScript bundle, stylesheet, Apache 2.0 license and upstream notices. These assets are served locally through the guarded documentation controller, without a CDN or build step.

Archive integrity: `sha512-H872wWkA53bFIsGgi7OWgmq+CRWw3nFQGdJWRqOB9wNwTm6e5ol34+qPDkV4AJlK+gglM2EsJiOOzsGsEGbluA==`.

| File | SHA-256 |
| --- | --- |
| `swagger-ui-bundle.js` | `050bc415ee7048dcd881682678f720264e7da5e373f7461d7c58c755305255f7` |
| `swagger-ui.css` | `1ac324f7dcd27e4b9386b4bd6421271ec147e922a22c05ba24b11515e9aa6321` |
| `LICENSE` | `cfc7749b96f63bd31c3c42b5c471bf756814053e847c10f3eb003417bc523d30` |
| `swagger-ui-bundle.js.LICENSE.txt` | `63818894e4b04cd0e3180d9cb20761e227a939121e7484f8e1d528227c756f89` |
| `NOTICE` | `0d20d1adef18aee3f40dd258172155521ce702ac445cb5f7b7d60ed32dad2fb2` |

The upstream files are unmodified. These five distribution files are intentionally committed; `.gitattributes` preserves their exact bytes on Windows checkouts and allows their existing upstream whitespace. Preserve `LICENSE`, `NOTICE` and bundled dependency notices when updating them, pin the new version and verify its archive integrity before replacing assets and hashes. No runtime dependency on npm is required.
