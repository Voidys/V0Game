# Third-party licences

V0Game itself is licensed under the GNU AGPL v3 (`LICENSE` in the project root). This folder holds the licence
texts of components bundled outside `vendor/` (Composer packages carry their own licence files in `vendor/`).

| Text | Component | Where |
| --- | --- | --- |
| `LGPLv3-LICENSE.txt` + `GPLv3-LICENSE.txt` | Smarty's trimwhitespace output filter, ported (LGPL-3.0 is a set of additional permissions on top of the GPL v3, so both texts ship) | `src/View/HtmlWhitespace.php` |
| `MIT-LICENSE.txt` | Chart.js (reduced build) | `public/scripts/lib/chart.js` |
| `ISC-LICENSE.txt` | Lucide icons | `public/styles/resource/icons/lucide.svg` |

Fonts carry their licence next to the files: Michroma (OFL, `public/styles/resource/fonts/michroma/OFL.txt`),
Droid Sans Mono (Apache 2.0, `public/styles/resource/fonts/Google Android License.txt`), Share Tech Mono (OFL,
`public/styles/theme/voidverse/fonts/OFL.txt`). The GitHub and Facebook glyphs in the icon sprite come from
Simple Icons (CC0, no licence text required).
