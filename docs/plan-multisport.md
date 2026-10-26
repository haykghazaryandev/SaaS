# Plan: many sports on one platform, starting with the ping-pong club

Status: draft for discussion, local only (not pushed). Date: 2026-10-26.

## Goal

One platform, a new name and brand, for booking any sport: football, ping-pong, basketball, tennis, and more later.
- The admin decides which sports and which places players see.
- Each place gets its own set of features, switched on or off by the admin.
- First real client: the Pingpong.land club (Sahak). Everything below is done locally first and shown before anything goes to staging.

## What we already have and can reuse

| Already built (football) | Becomes (any sport) |
|---|---|
| Places, opening hours, closures | same |
| Fields (5×5 / 11×11, max one of each size per place) | **courts / tables** of a sport, any number per place ("Table 1…6") |
| Prices per field by weekday and time (weekday/weekend prices already work) | same, per court |
| Booking requests, accept/decline, owner schedule, live updates | same |
| Games: N vs N, from 3×3 to 11×11 | games whose format comes from the sport: ping-pong 1×1 or 2×2, basketball 3×3 or 5×5 … |
| Booking team, invite link, chat, friends, notifications, Telegram bot and Mini App | same for every sport |
| Venue rules (notice, cancel window, …) | same, per place |

About 19 files have football baked in (5×5 / 11×11, "field", pitch drawings). Those become sport-driven.

## Phase 0: brand in one place

- The name, logo, colors, bot name and texts with "Bookball" in them all come from one config/brand file. Changing the brand is then one change, not ~30 files.
- We keep "Bookball" as a placeholder until the new name and logo are decided.

## Phase 1: sports and switches in the admin panel

**Sports** (admin → Sports):
- Each sport: name in hy/ru/en, icon, what a court is called (field / table / court), allowed lengths (e.g. 30/60/90 min), and game formats (1×1, 2×2 …).
- A checkbox **"Show on the home page"**. Football can stay hidden while only ping-pong is live.

**Places** (admin → Places):
- A checkbox **"Show on the home page"** for each place (separate from active / paused).
- **Features**: one switch per feature, per place:

| Feature | What it gives the place |
|---|---|
| Booking | players book courts (today's flow) |
| Games | players create / join games at this place |
| Booking team & invite link | |
| Reviews ("How was the place?") | |
| Player cards (QR) & balance | top up, charge, cashback, discounts |
| Rating | owner sets player ratings, history |
| Trainings | groups / coaches, attendance, payments |
| Tournaments | registration link, levels, 3 price options, results history |
| Shop | products with photo, price, discount; sales history |
| Daily money report | rent / sales / trainings / tournaments, separately |

- A new feature made for one client is just another switch. It stays off everywhere else and can be turned on for any other place later.
- In code, every screen, route and button checks `place has feature X`. A place without it never shows it.

**Home page:**
1. Choose the sport (chips: ⚽ Football · 🏓 Ping-pong · 🏀 Basketball … only the visible ones).
2. The places of that sport.
3. The place page shows its courts / tables and only the features that place has switched on.

## Phase 2: courts for any sport

- A court gets: sport, name ("Table 3"), indoor/outdoor, capacity, lengths, active. The "one 5×5 + one 11×11 per place" limit goes away.
- Players pick **a time first, then any free table** (simplest for ping-pong), or a specific table if the place wants that (a switch).
- Games: the format list comes from the sport. Ping-pong defaults to 1×1, also 2×2.
- Existing football data keeps working (migration: fields → courts of sport "football").

## Phase 3: the ping-pong club package (Sahak's list)

### Player QR card (his point 7, "cards")
- Every player has a personal QR in the app, in Profile, also in the Mini App. It holds a signed code, not the raw id, so it can't be faked or guessed.
- For players without a phone: printed plastic cards with the same kind of QR. The owner links a card to a player once.
- The owner presses **Scan**. In the Telegram Mini App this uses Telegram's built-in QR scanner; in a browser, the phone camera. The owner then sees the player's card for **this place**:
  - balance, with **Top up** and **Charge** (e.g. "1 hour table −3000")
  - discount % and cashback
  - rating (see below)
  - visits, trainings, payments history
- **Money stays per place**: a player's balance at Pingpong.land can only be used there. Every change is a line in a history (who, when, how much, why), so nothing can be edited without a trace.
  - Reason: we are not a bank or payment provider; the club holds its own prepaid money, like a gym card.

### Table rental (point 3)
- Weekday/weekend prices already exist.
- New: discounts for regular clients (per player, from the QR card).

### Trainings (point 3)
- Groups or private lessons, coach, schedule, price.
- Attendance via QR scan, payment from balance or cash.
- History.

### Tournaments (points 3 and 4)
- A registration link to put in the Instagram bio, e.g. `…/pingpong-land/join`; the club opens a new round each month.
- Form: first name, last name, phone, level (number, also mapped to the rating), payment option (free / price A / price B, set by the owner).
- The owner sees applications, accepts them, then records results.
- Tournament history.

### Rating (point 6)
- The owner scans the player's QR after watching games and sets or changes the rating, with a note. Every change is kept.
- A field for the international rating number, which the club fills in itself (they have permission).
- Later, maybe: a rating formula from match results. Sahak offered to listen to ideas.

### Shop (point 5)
- Products with photo, price, discount, in stock or not.
- Sales recorded by the owner (scan QR → pay from balance or cash).
- History.
- Later: players order in the app.

### Daily money report (point 3)
- One screen per day / month: table rental, trainings, tournaments, shop, top-ups. Each separately, with history.

### Instagram (point 2)
- Nothing to build: a short link to the place page, or the tournament link, goes in the bio or Highlights.
- We can make a nice preview image for the link.

## Later (not now)

- **POS / ՀԴՄ** tax receipts printed automatically (point 1): needs an integration with the club's cash register or e-HDM provider. Decided: later.
- Online payments (card / Idram / …) for top-ups and the shop.

## How we work on it

- A local branch (`multisport`), not pushed. A local demo place "Pingpong.land" with 6 tables, prices, players, trainings, a tournament.
- Steps, each shown to you locally before the next: Phase 0+1 → Phase 2 → QR card & balance → rating → trainings → tournaments → shop → report.
- Football keeps working the whole time (tests stay green).

## Questions to decide

1. New brand name and logo: who makes them? Until then we keep a placeholder.
2. Pingpong.land: how many tables, opening hours, prices (weekday / weekend), booking length (30 or 60 min)?
3. Booking ping-pong: "any free table" or a specific table?
4. Trainings: groups with a monthly fee, single lessons, or both? Are there several coaches?
5. Tournaments: how often (monthly?), what levels, what the 3 price options mean (free / entry fee / entry fee + something)?
6. Rating: which international rating (name / website), and what numbers does it use?
7. Cards: is the QR in the app enough to start, or does the club want printed cards from day one?
8. Who at the club uses the owner panel: only the owner, or also staff / coaches with fewer rights?
