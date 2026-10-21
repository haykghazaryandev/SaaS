# Bookball for fields (owner app prototype)

The app for football field owners in Yerevan. Players send booking requests in the player app
(`../player`); the owner sees them here, accepts or declines them, edits bookings and adds
bookings for phone calls, walk-ins and regular teams. Every place has two fields: a small 5×5 and a big 11×11.

Open `login.html` (or `index.html`, which sends you to sign in first). Plain HTML/CSS/JS, works from `file://`.
English, Armenian and Russian.

| Page | What it does |
|---|---|
| `login.html` | Sign in with phone + a code from Telegram (demo: the code is shown on screen) |
| `index.html` | Today: KPIs, requests waiting (Accept / Decline), next games, 7-day occupancy |
| `bookings.html` | Schedule: day view (two fields side by side), week view, list with search and filters; booking details, edit, new booking, weekly repeats |
| `settings.html` | Place profile, field prices, Telegram notifications, booking rules, staff |

## How the two apps share data

**Prototype:** both apps load the same scripts from `../player/` (`styles.css`, `common.js`, `data.js`)
and keep everything in the browser's `localStorage` (keys `kh.*`). In Chrome all `file://` pages share one storage,
so a request sent in the player app appears here at once, and an owner's answer appears in the player's
Telegram panel (messages are simulated with `KH.notify`, key `kh.tg`). Made-up demo bookings are generated
by `data.js`; the owner's changes to them are kept in `kh.demoState`.

Keys used by the owner app: `owner` (signed-in owner + chosen place), `bookings`, `demoState`, `prices`,
`tg`, `regs`, `mytours`, plus its own `ownerSettings`, `ownerStaff`, `ownerShowOff`.

**Production:** both apps talk to one Laravel API instead of browser storage: bookings, prices, tournaments
and sign-ups live in one database, owners and players sign in with their phone (code via the Telegram bot),
and the bot sends the real Telegram messages. No SMS.
