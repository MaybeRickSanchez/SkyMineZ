<div align="center">

<svg width="720" height="150" viewBox="0 0 720 150" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="SkyMineZ">
  <defs>
    <linearGradient id="sky" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#0b1026"/>
      <stop offset="1" stop-color="#1b2a5e"/>
    </linearGradient>
    <linearGradient id="ore" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="#c084fc"/>
      <stop offset="1" stop-color="#7c3aed"/>
    </linearGradient>
  </defs>
  <rect x="4" y="4" width="712" height="142" rx="18" fill="url(#sky)" stroke="#7c3aed" stroke-width="2"/>
  <g fill="#c084fc">
    <rect x="96" y="52" width="14" height="14" rx="2"/>
    <rect x="114" y="52" width="14" height="14" rx="2" fill="#f0abfc"/>
    <rect x="96" y="70" width="14" height="14" rx="2" fill="#f0abfc"/>
    <rect x="114" y="70" width="14" height="14" rx="2"/>
    <rect x="96" y="88" width="14" height="14" rx="2"/>
    <rect x="114" y="88" width="14" height="14" rx="2" fill="#f0abfc"/>
  </g>
  <rect x="88" y="44" width="48" height="66" rx="4" fill="none" stroke="#c084fc" stroke-width="2"/>
  <text x="160" y="82" font-family="Verdana, sans-serif" font-size="44" font-weight="bold" fill="#e9d5ff">SkyMine<tspan fill="#f0abfc">Z</tspan></text>
  <text x="162" y="108" font-family="Verdana, sans-serif" font-size="15" fill="#a5b4fc">mines &#183; crates &#183; outposts &#183; zero-lag refills</text>
</svg>

<svg width="720" height="28" viewBox="0 0 720 28" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="build badges">
  <g font-family="Verdana, sans-serif" font-size="11">
    <rect x="0" y="0" width="46" height="24" rx="4" fill="#7c3aed"/>
    <text x="7" y="16" fill="#ffffff">status</text>
    <rect x="46" y="0" width="114" height="24" rx="4" fill="#1b2a5e"/>
    <text x="54" y="16" fill="#f0abfc">developing</text>
    <rect x="170" y="0" width="150" height="24" rx="4" fill="#1b2a5e"/>
    <rect x="170" y="0" width="52" height="24" rx="4" fill="#0b1026"/>
    <text x="178" y="16" fill="#a5b4fc">pmmp</text>
    <text x="230" y="16" fill="#e9d5ff">5.44 &#183; PHP 8.1+</text>
    <rect x="330" y="0" width="150" height="24" rx="4" fill="#1b2a5e"/>
    <rect x="330" y="0" width="62" height="24" rx="4" fill="#0b1026"/>
    <text x="338" y="16" fill="#a5b4fc">phpstan</text>
    <text x="400" y="16" fill="#7CFC00">level 9 clean</text>
    <rect x="490" y="0" width="230" height="24" rx="4" fill="#1b2a5e"/>
    <rect x="490" y="0" width="70" height="24" rx="4" fill="#0b1026"/>
    <text x="498" y="16" fill="#a5b4fc">server-tested</text>
    <text x="568" y="16" fill="#e9d5ff">boots &#183; commands pass</text>
  </g>
</svg>

</div>

## Its under a huge update( fixing ai shits )

# SkyMineZ

A SkyMine plugin for PocketMine-MP 5 (NetherGames fork) that treats **tick time as a
budget**: mine refills are spread across ticks, player scans are batched, and
lookups that run on every interaction are O(1) indexes instead of linear scans.

Inspired by the SkyMine mode of `play.bitonetop.com`.

## Project status: developing

This plugin is under active development.

---

## Systems

| System | What it does |
| --- | --- |
| `mine/` | Cuboid mines with weighted block lists, hologram countdowns, automatic timed refills via a per-tick block budget (`MineFillTask`), placement grief protection, drops-to-inventory rewards, full JSON persistence |
| `crate/` | Animated shulker crates with configurable color, weighted rewards, NBT key items (consumed exactly once), read-only previews and spin windows, explosion protection, O(1) position index |
| `outpost/` | Capturable zones with progress, cooldowns, owner gold payouts, holograms with settable label positions, JSON persistence |
| `slapper/` | Clickable NPCs (your skin, messages, player/`console:` commands) plus clickable blocks bound to them; look-at-players on a 5s interval |
| `leaderboard/` | Floating top-10 holograms for money, gold, mined, deaths, kills and team level/wins; hash-guarded re-renders (no flicker) |
| `team/` | Teams with invites, kicks, disband, levels/XP, explicit-accept duels with arena rendezvous teleport and safe return, live-duel sidebar while fighting, JSON persistence |
| `warp/` | Named server warps with create/move/delete, per-player teleport, missing-world-safe, JSON persistence |
| `label/` | General multi-line floating labels (wiki/help/tutorials), full CRUD, JSON persistence |
| `lobby/` | Hub + mid-lobby positions, mid-lobby scoreboard zone (pos1/pos2), map-wide lobby protection (non-staff cannot break/place/interact; crates, slapper blocks and mines keep working), join teleport modes, fall/void/hunger guards with ground-or-hub void rescue — all configurable |
| `shop/` | Category shop with buy/sell/sell-all through the economy managers, confirmations |
| `quest/` | Daily quests (mine/kill/earn) with progress bars, claim-once rewards, lazy daily reset, JSON persistence |
| `composer/` | Material processor: virtual workbench, multiset recipe matching, plus an in-game admin UI to create/rename/edit/delete recipes (persisted to `composer.recipes`) |
| `tools/` | Unbreakable, undroppable progression gear with block-break XP, level-gated enchant upgrades, material costs, upgrade UI |
| `trade/` | Chest-style two-player trades: private sides, double-confirm, change invalidates confirmations, safe cancel/timeout/death/disconnect handling |
| `scorehud/` | Sidebar with welcome, duel and stats screens, named-zone places with hysteresis switching, live refresh on economy/kill/death/PvP changes, delta-only line updates with title tracking, per-player toggle |
| `economy/` | Dual JSON currency (`money`, `gold`) with vetoable change events |
| `miner/` | Per-player mined (mines only)/deaths/kills/streak stats |
| `pvp/` | Per-player PvP preference with projectile attribution, duel override |
| `lagmaker/` | Item-entity stacking, per-player drop caps, TTL/all/off cleanup passes with 30/5/2/1s warnings — every pass tick-spread |
| `wand/` | Position Wand: left-click sets pos1, sneak-click sets pos2, never breaks blocks; shared `SelectionManager` for all cuboid systems |
| `form/` | Bundled FormAPI port (Simple/Custom/Modal) compatible with PM 5.44, plus the shared `Ui` toolkit (menu/confirm/input) every window is built from |
| `config/` | Typed `config.yml` access plus the `messages.*` catalog: every player-facing chat string is configurable with built-in fallbacks |
| `event/` | Public cancellable events for other plugins |
| `useless/` | `SpreadTask` (tick-spread iteration), hologram particles, `NumberFormatter`, read-only inventories, fake-chest virtual windows (`VirtualInventory` + `VirtualWindow`, offset clear of the player), shared placement-transaction unpacking (`BlockTransactions`), canonical `Positions`/`Items`/`Worlds`/`Arrays` helpers (`fromTuple`, `drain`, `removeIndex`) |

---

## How the lag stays away

<svg width="720" height="170" viewBox="0 0 720 170" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="tick budget diagram">
  <g font-family="Verdana, sans-serif">
    <rect x="4" y="4" width="712" height="162" rx="12" fill="#0b1026" stroke="#334155" stroke-width="1"/>
    <text x="24" y="32" font-size="14" font-weight="bold" fill="#e9d5ff">One 50 ms tick, one 50,000-block mine refill</text>
    <text x="24" y="58" font-size="12" fill="#f87171">before: setBlockAt x 50,000 in a single tick &#8212; the server freezes</text>
    <rect x="24" y="68" width="672" height="16" rx="3" fill="#7f1d1d"/>
    <rect x="24" y="68" width="672" height="16" rx="3" fill="#ef4444"/>
    <text x="24" y="108" font-size="12" fill="#7CFC00">after: mines.blocks-per-tick (default 3,000) per tick &#8212; ~17 ticks, players lifted out first</text>
    <rect x="24" y="118" width="672" height="16" rx="3" fill="#14532d"/>
    <g fill="#22c55e">
      <rect x="24" y="118" width="36" height="16"/><rect x="64" y="118" width="36" height="16"/><rect x="104" y="118" width="36" height="16"/><rect x="144" y="118" width="36" height="16"/><rect x="184" y="118" width="36" height="16"/><rect x="224" y="118" width="36" height="16"/><rect x="264" y="118" width="36" height="16"/><rect x="304" y="118" width="36" height="16"/><rect x="344" y="118" width="36" height="16"/><rect x="384" y="118" width="36" height="16"/><rect x="424" y="118" width="36" height="16"/><rect x="464" y="118" width="36" height="16"/><rect x="504" y="118" width="36" height="16"/><rect x="544" y="118" width="36" height="16"/><rect x="584" y="118" width="36" height="16"/><rect x="624" y="118" width="36" height="16"/><rect x="664" y="118" width="32" height="16"/>
    </g>
    <text x="24" y="156" font-size="12" fill="#a5b4fc">same pattern everywhere: sidebar passes, join hologram bursts and cleanup sweeps all run through SpreadTask</text>
  </g>
</svg>

- **`useless/SpreadTask`** — one-shot task that walks any list N entries per
  tick, then cancels itself. Used by the sidebar tick, every join-hologram
  burst, and the lag-cleaner queue builder. (One-shot matters: scheduling it as
  repeating would leak a task per tick — an earlier revision of this plugin did
  exactly that.)
- **`mine/MineFillTask`** — refill writes at most `mines.blocks-per-tick`
  blocks per tick with arithmetically derived coordinates (no 50k position array
  allocated up front).
- **O(1) indexes** — crates and slapper blocks are resolved by a
  `world:x:y:z` hash, not by scanning every crate on every click.
- **Normalized collision boxes** — `min`/`max` are precomputed once, so the
  per-tick `isIn()` check is six float comparisons (world-aware variant
  included, no extra cost when worlds already match).
- **Delta-only sidebar** — unchanged scoreboard lines cost one string compare
  and zero packets.
- **Single-timer clear warnings** — the 30/5/2/1s item-clear countdowns ride on
  the same counter that triggers the clear; no extra tasks are scheduled.
- **Snapshotted tuning** — per-tick config reads are cached once per tick
  (LagMaker) or once per opening (crate animation pacing).

---

## Installation

1. Requires **PocketMine-MP 5 — NetherGames fork** (API 5.44) and **PHP 8.1+** with the
   `yaml` extension.
2. Copy this folder to `plugins/SkyMineZ/` (folder plugin — no `.phar` build
   needed) and start the server.
3. `resources/config.yml` is copied to `plugin_data/SkyMineZ/config.yml` on
   first run; edit it, then run `/skymine reload`.

---

## Commands

Player commands (`skyminez.use`, granted to everyone):

| Command | Effect |
| --- | --- |
| `/skymine menu` | Main menu form (PvP/sidebar toggles, stats, warps, shop, quests, teams, composer, gear, hub, admin shortcuts) |
| `/skymine pvp [on\|off]` | Toggle your PvP preference |
| `/skymine hud` | Toggle your sidebar |
| `/skymine stats [player]` | Mining stats, works for offline names too |
| `/hub` / `/lobby` | Teleport to the configured hub (falls back to mid-lobby) |
| `/warp [name]` | Teleport to a server warp (lists them with no argument) |
| `/team <menu\|create\|info\|list\|invite\|accept\|deny\|leave>` | Teams, invitations and info |
| `/team duel challenge <team>` / `accept <id>` / `deny <id>` | Challenge and fight other teams (owners only) |
| `/quest` | Daily quests with progress bars and claim buttons |
| `/shop` | Category shop (buy/sell/sell-all with confirmations) |
| `/trade <player\|accept\|deny\|cancel>` | Chest-style player trading |
| `/composer` | Material composer (pick a recipe, fill the workbench, close to craft) |
| `/tools` | Progression gear: attune the held item, spend XP/materials on upgrades |

Admin commands (`skyminez.admin`, op by default):

| Command | Effect |
| --- | --- |
| `/skymine money\|gold <give\|take\|set\|check> <player> [amount]` | Manage balances |
| `/skymine pos1` / `/skymine pos2` | Mark cuboid corners for `/mine create` and `/outpost create` |
| `/skymine wand` | Get the Position Wand (admin) |
| `/skymine lagmaker <status\|toggle\|cleanup <off\|ttl\|all>>` | Lag protection controls |
| `/skymine reload` / `/skymine save` | Reload config+data / flush every store |
| `/crate create\|remove\|move\|list\|givekey\|open\|color\|menu\|save` | Crates, keys and shulker color |
| `/crate reward <add\|remove\|list\|weight\|type>` | Reward entries with live chance display |
| `/mine create\|remove\|list\|info\|reset\|setinterval\|setlabel\|clearblocks\|menu` | Mines |
| `/mine block <add\|remove\|list>` | Weighted block list (`/mine block add <mine> stone 60`) |
| `/mine pos1\|pos2` | Same markers, mine-flavoured aliases |
| `/outpost create\|remove\|list\|info\|owner\|reset\|setlabel\|menu` | Outposts (`owner <name> <player\|clear>`) |
| `/slapper create\|remove\|list\|move\|menu` | NPCs with your skin |
| `/slapper msg\|cmd <add\|remove\|clear\|list>` | Messages and commands (`{player}` placeholder, `console:` prefix runs as console) |
| `/slapper block <add\|remove\|list>` | Clickable blocks bound to a slapper |
| `/lb create\|remove\|list\|info\|title\|setpos\|refresh\|menu` | Leaderboards (`/lb create top money Top Money`) |
| `/hub set\|setmid\|setmidzone\|unset\|unsetmid\|clearmidzone\|info` | Lobby positions, mid-lobby scoreboard zone and map protection state |
| `/warp create\|delete\|move\|list` | Warp management |
| `/label create\|set\|addline\|delline\|move\|remove\|list\|info` | Floating text labels |
| `/team kick\|disband` | Owner controls (plus everything in the player table) |
| `/team arena <seta\|setb\|clear\|info>` | Duel rendezvous points; fighters teleport on accept, return afterwards |
| `/composer manage` | Create/rename/edit/delete composer recipes in game |

Typical first setup, in game:

```
 /skymine pos1
 /skymine pos2
 /mine create coal
 /mine block add coal coal_ore 40
 /mine block add coal stone 60
 /mine reset coal
 /crate create vote
 /crate givekey Steve vote 5
 /outpost create mid
 /lb create top money Top Balance
 /slapper create guide
 /hub set
 /warp create spawn
 /team arena seta
 /team arena setb
```

### Where things are placed

Single-point create/move commands (`/crate`, `/lb`, `/mine setlabel`,
`/outpost setlabel`, `/label`, `/slapper`, `/skymine pos1|pos2`) resolve at
your **eye (middle) position** — just stand where you want it, no aiming at
blocks required. Cuboid systems (`/mine create`, `/outpost create`, `/hub setmidzone`) read
the shared pos1/pos2 selection instead: mark corners
with the subcommands above or with the Position Wand (left-click = pos1,
sneak-click = pos2, blocks are never broken). Teleports (`/warp`, `/hub`,
duel arenas) always target feet position, so you never land inside a block.

---

## Configuration

All tuning lives in `config.yml`: sidebar texts and timings (including the
duel board under `scoreboard.duel`, with `{team}`/`{enemy}`/`{duel_time}`
plus the usual stat placeholders), PvP defaults,
economy starting balances, crate animation pacing and key behavior, mine
refill budget and default interval, outpost capture/cooldown/gold values,
lobby positions/mid-zone/anti-damage, team/duel timings and arena spawns
(`teams.arena-a/b`, set in game with `/team arena seta|setb`), daily quests,
shop catalog, tool upgrade packages, composer recipes, trade timeouts, and the
lag-cleaner mode (`off` / `ttl` / `all`), TTL, interval and per-tick budget.

Every player-facing chat string lives under `messages:` (503 keys, `{braces}`
placeholders) — reword or translate without touching code. A missing or empty
entry falls back to the built-in default, so old configs keep working; the
legacy `pvp.disabled-message` key still wins when set.

Data files (`plugin_data/SkyMineZ/*.json`) are plain JSON and safe to inspect;
`mines.json`, `crates.json`, `outposts.json`, `slappers.json`,
`leaderboards.json`, `teams.json`, `warps.json`, `labels.json`, `quests.json`,
`lobby.json`, `pvp.json`, `miner.json`, `money_economy.json` and
`gold_economy.json` survive restarts including owners, timers and block lists.

---

## For developers

- **Bundled forms** — every window is built from the shared `Ui` toolkit
  (`Ui::menu()` / `Ui::confirm()` / `Ui::input()`), which sits on the bundled
  FormAPI port (`SimpleForm`/`CustomForm`/`ModalForm`) plus the
  `onCompletion` / retry / blocking members PM 5.44 requires.
- **Events** — `CrateOpenEvent`, `MineResetEvent`, `OutpostCaptureEvent`,
  `SlapperInteractEvent`, `MinerBlockMinedEvent`, `EconomyChangeEvent`,
  `ScoreHudUpdateEvent`, `PlayerPvPChangeEvent`. Handlers are guarded by
  `::hasHandlers()` so events nobody listens to skip allocation entirely
  (`MinerBlockMinedEvent` additionally funnels through
  `EventDispatcher::dispatch()`).
- **Scoreboard places** — `ScoreHud::registerPlace($id, $box, $board)` shows
  a board inside any cuboid (`middle`/`lobby`/`duel`); the mid-lobby zone and
  live duels are the built-in users. `LobbyManager::isGuarded($world)` is the
  matching query for map-wide lobby rules.
- **Static analysis** — `composer stan` (PHPStan level 9, clean). The PocketMine
  sources are server-provided; point `scanDirectories` in `phpstan.neon.dist`
  at your server checkout.
- **Verified on a live server** — the plugin boots on 5.44.2+dev and every
  console-safe command path was executed against a real server during
  development.

---

## License

MIT — see [LICENSE](LICENSE). You are free to use, modify and redistribute this
plugin, including on commercial servers, as long as the copyright notice stays
intact.
