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

## 2. Progress: Completed (8 / 25 Lean Set)
Placed in `public/styles/theme/v0game/planeten/` (raw `.jpg`, ready for `convert-theme-images.sh`):
- `dschjungelplanet01.jpg` — Terran/jungle continents, slate-teal oceans, night-side amber city lights, thin mint rim, minimal starfield.
- `dschjungelplanet02.jpg` — Emerald-jade rainforest supercontinent, river basins, cloud bands, moderate dark-teal nebula wisps.
- `dschjungelplanet03.jpg` — Olive/moss volcanic island chains, twin tropical cyclones, minimal starfield.
- `eisplanet01.jpg` — Europa-style pale-ivory/icy-cyan crust with dark rust-slate tectonic lineae, minimal starfield.
- `eisplanet02.jpg` — Fractured glacial ice sheets, tectonic crevasses, frozen craters, cool ice-blue rim.
- `wuestenplanet01.jpg` — Amber-ochre canyons, dark basalt plateaus, dust storms, sharp terminator crater relief.
- `gasplanet03.jpg` — Banded slate-emerald & pale-champagne gas giant with wide icy rings and shepherd moon, minimal starfield.
- `wasserplanet06.jpg` — Deep abyssal-blue/teal archipelago world with emerald-cyan bioluminescent coastlines, moderate dark-emerald/indigo nebula.

---

## 3. Remaining to Generate (17 / 25 Lean Set)
*(Paused due to `gemini-3.1-flash-image` 429 quota exhaustion; resets `2026-11-19T20:31:12Z`.)*

Use this shared base prefix for all prompts below:
> **Base prefix:** `Generate a 1:1 square sci-fi planet artwork for V0Game ("night-time command console" aesthetic: calm, crisp, minimal-to-moderate nebula, NO loud/extravagant multi-color nebula, NO lens flares, NO text, NO UI borders). Composition: Centered spherical planet (~68% of frame) with generous dark space margin around all edges.`

### Ice (1 remaining)
- **`eisplanet03`**
  > `[Base prefix] Subject: Ice Planet variant 3 (eisplanet03). Rugged snow-covered alpine continents, frozen dark-navy seas, glacial fjords, and a faint pale-mint/cyan polar aurora glow on the night side, crisp day/night terminator. Background: Moderate, moody deep-slate and dark-indigo nebula wisps fading to near-black (#070b09) corners with sparse stars.`

### Desert (2 remaining)
- **`wuestenplanet02`**
  > `[Base prefix] Subject: Desert Planet variant 2 (wuestenplanet02). Pale golden-sandstone ergs, vast white-tan salt flats, dark wind-scoured mesas, and a thin dusty-amber atmospheric haze along the lit limb, crisp day/night terminator. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`
- **`wuestenplanet03`**
  > `[Base prefix] Subject: Desert Planet variant 3 (wuestenplanet03). Rust-terracotta badlands, giant impact basins, dried riverbeds, and sweeping dust plumes in muted ochre and umber, crisp day/night terminator. Background: Subtle, restrained warm-slate and dark-umber cosmic dust fading into near-black (#070b09) space.`

### Gas Giants (2 remaining)
- **`gasplanet01`**
  > `[Base prefix] Subject: Gas Giant variant 1 (gasplanet01). Deep teal, midnight-navy, and pale-cyan banded storm belts with a single large pale anticyclonic vortex, subtle thin icy-blue rim light, crisp day/night terminator. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`
- **`gasplanet02`**
  > `[Base prefix] Subject: Gas Giant variant 2 (gasplanet02). Warm muted-ochre, taupe, and charcoal atmospheric bands with subtle turbulent eddies and two tiny distant moons, crisp day/night terminator. Background: Moderate, restrained dark-slate and faint emerald-tinted nebula wisps fading to near-black (#070b09) corners.`

### Water / Ocean (2 remaining)
- **`wasserplanet01`**
  > `[Base prefix] Subject: Water Planet variant 1 (wasserplanet01). Vast unbroken deep-sapphire and slate-teal global ocean with swirling white cloud systems, polar ice caps, and crisp specular sunlight glint near the terminator. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`
- **`wasserplanet02`**
  > `[Base prefix] Subject: Water Planet variant 2 (wasserplanet02). Shallow turquoise coral-shelf seas, scattered dark volcanic atolls, delicate wispy cloud arcs, and a thin pale-cyan atmospheric rim. Background: Moderate, restrained deep-teal nebula wisps fading to near-black (#070b09) corners.`

### Dry / Arid / Rocky (3 remaining)
- **`trockenplanet01`** *(calm replacement for scrapped extravagant version)*
  > `[Base prefix] Subject: Dry/Volcanic Planet variant 1 (trockenplanet01). Dark basalt and obsidian crust with thin, subtle glowing amber-orange tectonic rifts on the night side, cratered highlands, and a faint dusty-gold rim. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`
- **`trockenplanet02`**
  > `[Base prefix] Subject: Dry/Rocky Planet variant 2 (trockenplanet02). Mercury/Moon-like heavily cratered slate-grey and iron-brown silicate world with sharp ray craters and long terminator shadows. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`
- **`trockenplanet03`**
  > `[Base prefix] Subject: Dry/Arid Planet variant 3 (trockenplanet03). Cracked rust-umber and dark-shale plateau world with deep rift valleys, sparse thin haze, and sharp crater relief along the terminator. Background: Subtle dark-slate cosmic dust fading to near-black (#070b09) space.`

### Temperate (3 remaining)
- **`normaltempplanet01`**
  > `[Base prefix] Subject: Temperate Planet variant 1 (normaltempplanet01). Earth-like cool-climate continents in muted sage-green and slate-brown, deep navy oceans, polar ice caps, wispy clouds, and tiny amber city lights on the night side. Background: Minimal, quiet near-black (#070b09) deep space with sparse pinprick stars.`
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
