# V0Game Theme — Custom Planet Artwork Handoff

## 1. Aesthetic & Technical Spec
- **Target folder:** `public/styles/theme/v0game/planeten/` (overrides `assetSets => ['nebula']` and `common/planeten/`).
- **World / Mood:** Night-time command console, calm and precise (`DESIGN.md`, `public/styles/theme/v0game/theme.css`).
- **Palette:** Near-black ground (`#070b09`), layered slate-green panels (`#0e1512`), emerald accent (`#2fbf71`), pale mint (`#9fe0bd`), frost blue (`#6cb6ff`), amber warning (`#f0b84c`).
- **Space / Nebula Range:** **Minimal ("boring") to Moderate** only.
  - *Minimal:* Quiet `#070b09` void, sparse pinprick stars, faint cosmic dust lane.
  - *Moderate:* Restrained dark-emerald, deep-teal, or slate-indigo nebula wisps fading to `#070b09` corners.
  - *Rejected:* Extravagant/loud multi-color nebula clouds, bright starbursts, lens flares, neon glow.
- **Framing:** `1:1` square, single centered sphere (or ring system) occupying ~65–72% of frame width with clean dark margin on all sides. No text, no UI borders.
- **Format:** Generated as `.jpg` and converted to `300x300` WebP (`q90`) via `tools/images/convert-theme-images.sh`.

---

## 2. Progress: Completed (25 / 25 Lean Set — Converted to 300x300 WebP)
All 25 assets are in `public/styles/theme/v0game/planeten/*.webp` (300×300, q90):
- `dschjungelplanet01.webp` — Terran/jungle continents, slate-teal oceans, night-side amber city lights, thin mint rim, minimal starfield.
- `dschjungelplanet02.webp` — Emerald-jade rainforest supercontinent, river basins, cloud bands, moderate dark-teal nebula wisps.
- `dschjungelplanet03.webp` — Olive/moss volcanic island chains, twin tropical cyclones, minimal starfield.
- `eisplanet01.webp` — Europa-style pale-ivory/icy-cyan crust with dark rust-slate tectonic lineae, minimal starfield.
- `eisplanet02.webp` — Fractured glacial ice sheets, tectonic crevasses, frozen craters, cool ice-blue rim.
- `eisplanet03.webp` — Alpine snow continents, glacial fjords, frozen navy seas, faint night-side polar aurora, moody slate/indigo nebula wisps.
- `wuestenplanet01.webp` — Amber-ochre canyons, dark basalt plateaus, dust storms, sharp terminator crater relief.
- `wuestenplanet02.webp` — Pale golden-sandstone ergs, salt flats, dark mesas, dusty-amber limb haze, minimal starfield.
- `wuestenplanet03.webp` — Rust-terracotta badlands, giant impact basins, dried riverbeds, muted ochre dust plumes, subtle warm-slate cosmic dust.
- `gasplanet01.webp` — Deep teal, midnight-navy, and pale-cyan banded storm belts with pale anticyclonic vortex, minimal starfield.
- `gasplanet02.webp` — Muted-ochre, taupe, and charcoal bands with turbulent eddies and two distant moons, moderate slate/emerald wisps.
- `gasplanet03.webp` — Banded slate-emerald & pale-champagne gas giant with wide icy rings and shepherd moon, minimal starfield.
- `wasserplanet01.webp` — Deep-sapphire/slate-teal global ocean, polar ice caps, swirling clouds, specular terminator glint, minimal starfield.
- `wasserplanet02.webp` — Shallow turquoise coral-shelf seas, dark volcanic atolls, wispy clouds, restrained deep-teal nebula wisps.
- `wasserplanet06.webp` — Deep abyssal-blue/teal archipelago world with emerald-cyan bioluminescent coastlines, moderate dark-emerald/indigo nebula.
- `trockenplanet01.webp` — Dark basalt/obsidian crust with thin glowing amber-orange night-side tectonic rifts, cratered highlands, minimal starfield.
- `trockenplanet02.webp` — Heavily cratered slate-grey/iron-brown silicate world with sharp ejecta ray craters, minimal starfield.
- `trockenplanet03.webp` — Cracked rust-umber and dark-shale plateau world with deep rift valleys, sharp terminator relief, subtle dark-slate dust.
- `normaltempplanet01.webp` — Sage-green/slate-brown continents, deep navy oceans, polar caps, wispy clouds, amber night-side lights, minimal starfield.
- `normaltempplanet02.webp` — Olive-gold/slate steppe continents, deep dark-teal seas, sweeping cloud fronts, restrained dark-emerald/slate wisps.
- `normaltempplanet03.webp` — High-latitude pine-green archipelago world, cool slate oceans, white cloud bands, subtle night-side lights, minimal starfield.
- `mond01.webp` — Barren pale-grey/dark-basalt cratered moon with sharp ejecta rays, minimal starfield.
- `mond02.webp` — Fractured dark-iron and dusty-ochre cratered moon with subtle rim lighting, minimal starfield.
- `debris.webp` — Drifting shattered hull plates, solar-array trusses, and rocky fragments with faint emerald/amber salvage-beacon glints.
- `wreckfield.webp` — Derelict broken capital-ship superstructure with drifting hull debris and faint cold-blue/amber emergency lights.

---

## 3. Remaining to Generate (0 / 25 Lean Set)
All 25 Lean Set assets are generated and converted! Below are the reference prompts used for the final 7:

Use this shared base prefix for all prompts below:
> **Base prefix:** `Generate a 1:1 square sci-fi planet artwork for V0Game ("night-time command console" aesthetic: calm, crisp, minimal-to-moderate nebula, NO loud/extravagant multi-color nebula, NO lens flares, NO text, NO UI borders). Composition: Centered spherical planet (~68% of frame) with generous dark space margin around all edges.`

### Dry / Arid / Rocky (1 remaining)
- **`trockenplanet02`**
  > `[Base prefix] Subject: Dry/Rocky Planet variant 2 (trockenplanet02). Mercury/Moon-like heavily cratered slate-grey and iron-brown silicate world with sharp ray craters and long terminator shadows. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`

### Temperate (2 remaining)
- **`normaltempplanet02`**
  > `[Base prefix] Subject: Temperate Planet variant 2 (normaltempplanet02). Autumnal/steppe continents in muted olive-gold and slate, deep teal seas, sweeping mid-latitude cloud fronts, thin pale-mint atmospheric rim. Background: Moderate, restrained dark-emerald/slate nebula wisps fading to near-black (#070b09) corners.`
- **`normaltempplanet03`**
  > `[Base prefix] Subject: Temperate Planet variant 3 (normaltempplanet03). High-latitude archipelago world with pine-green landmasses, cool slate oceans, crisp white cloud bands, and subtle night-side orbital/city lights. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`

### Moons & Special Objects (4 remaining)
- **`mond01`**
  > `[Base prefix] Subject: Moon variant 1 (mond01). Barren pale-grey and dark-basalt cratered moon (~55% of frame) with sharp impact rays, deep terminator shadows, and no atmosphere. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`
- **`mond02`**
  > `[Base prefix] Subject: Moon variant 2 (mond02). Fractured dark-iron and dusty-ochre irregular cratered moon (~55% of frame) with deep canyons and subtle rim lighting. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`
- **`debris`**
  > `Generate a 1:1 square sci-fi space icon for V0Game ("night-time command console" aesthetic: calm, crisp, NO lens flares, NO text, NO UI borders). Subject: Orbital Debris Field (debris). A drifting cluster of shattered metallic hull plates, broken solar-array trusses, and rocky asteroid fragments in low orbit, dimly lit from the top-left with subtle faint emerald/amber salvage-beacon glints against a quiet near-black (#070b09) starfield.`
- **`wreckfield`**
  > `Generate a 1:1 square sci-fi space icon for V0Game ("night-time command console" aesthetic: calm, crisp, NO lens flares, NO text, NO UI borders). Subject: Space Wreckfield (wreckfield). A derelict, broken capital-ship superstructure surrounded by drifting hull debris and faint cold-blue/amber emergency hull lights in calm near-black (#070b09) deep space with sparse pinprick stars.`

---

## 4. Next Steps After Generating the Remaining 17
1. Convert all `.jpg` files in `public/styles/theme/v0game/planeten/` to `300x300` WebP (`q90`):
   ```bash
   tools/images/convert-theme-images.sh public/styles/theme/v0game/planeten
   ```
2. Map or alias the remaining numbered filenames (`04`–`10` etc.) so V0Game never falls back to legacy `common/planeten/*.jpg` assets.
3. Run `tools/images/dedupe-theme-assets.php` if sharing assets across themes.
