# Khagh: football field booking and amateur tournaments (Yerevan)

*Working name. Prototype: `~/Desktop/sports-booking/index.html`. Started 2026-10-19.*

## 0. Decisions (2026-10-19)
- **Sport:** football only, for both bookings and tournaments.
- **Places:** every place has exactly two fields, a small one (5×5) and a big one (11×11).
- **Tournaments:**
  - amateur only, 5×5 or 11×11
  - no live mode: statuses are registration open → closing soon → starts soon → finished
- **Messages:** no SMS. The phone code and all notifications go by **Telegram** only.
- **Tournaments:** created and run only by us (the Khagh team), shown only in the player app. Field owners do not manage tournaments.
- **Booking flow:** the player sends a **request**, and the field owner accepts or declines it. The owner can also edit bookings and add their own.
- **Two apps:**
  - the **player app** at `~/Desktop/sports-booking`
  - the **owner app** at `~/Desktop/sports-booking-owner`
  - in production they are two frontends on the same Laravel API
- **Other features:** open games (finding players) stay; no 3D.

## 1. Product in one line
Book a small or big football field in a minute, find players for a game, and sign your team up for amateur tournaments; field owners accept and manage it all in their own app.

## 2. Who uses it
| Role | What they do |
|---|---|
| **Player** | finds a court, books an hour, joins open games, registers for tournaments |
| **Captain** | registers a team, invites teammates, sees the bracket |
| **Venue staff/owner** | sees the day schedule, adds walk-ins, sets prices, runs tournaments |
| **Admin (us)** | adds venues, moderates, supports |

## 3. Main flows (all in the prototype)
1. **Home:** pick a sport, district and time; venue cards show the next free hours.
2. **Venue:**
   - a 3D view of all courts, colored by what is free at the chosen time
   - a time grid (courts × half hours)
   - duration 60/90/120 minutes, with the price shown at once
3. **Booking:**
   - name and phone, then a one-time code by Telegram (fallback SMS)
   - the code is asked only the first time on a device
   - you get a booking code; free cancellation up to 6 h before
4. **Open games:** a player posts a game ("need 2 players, 20:00, intermediate"); others join and split the price.
5. **Tournaments:**
   - formats: groups + playoff, knockout, Americano (padel), league
   - registration and entry fee; bracket and standings update live
6. **My bookings:** upcoming, past, games, tournaments; cancel and share.
7. **Venue dashboard:**
   - day calendar per court and walk-in bookings
   - KPIs: occupancy and revenue
   - price rules (day and prime time) and tournament creation

## 4. Phone check and notifications (decided: Telegram only)
- **Phone code:** Telegram Gateway API, about **$0.01 per code**. No SMS.
- **Notifications:** a Telegram bot (free) sends players:
  - request sent
  - confirmed, with a booking code
  - declined, changed or cancelled by the field
  - reminders
  - tournament registration accepted

  The owner gets new requests and a daily summary.
- Players without Telegram cannot book in the MVP. Revisit if this turns out to be a problem.
- Ask for the code only for the first booking per phone and device; the player links the bot once (`/start` with a token) to receive notifications.
- Limits:
  - 5-minute code life
  - 3 attempts
  - a limit on codes per phone and per device each hour
- Venue can see the phone is confirmed; no-show counter per phone (block after 2 no-shows).

## 5. Money (to decide in Phase 0)
- Options:
  - free for first venues, then a monthly fee per court (e.g. 10–15k ֏/court)
  - a % commission
- Tournaments are the easiest to monetise: an online entry fee (Idram / card via a local bank) with a platform fee of 5–10%.
- Court bookings: pay at the venue in the MVP; online prepayment later (helps against no-shows for prime time).

## 6. Tech (suggested, fits a PHP team)
- **Backend:** Laravel 11 + PostgreSQL + Redis, REST API `/api/v1` from day one (the mobile app comes later).
- **Web:** Inertia + Vue, or a separate Nuxt app; 3D with three.js (as in the prototype, court models are procedural, no heavy files).
- **Mobile later:** React Native (Expo) on the same API, with push notifications.
- **Key tables:**
  - venues, courts, price_rules, opening_hours
  - bookings (court_id, starts_at, ends_at, status, phone_id), with an **exclusion constraint** in Postgres (`tstzrange` overlap per court) so double booking is impossible
  - phones / verifications
  - games, game_players
  - tournaments, entries (teams/players), matches (round, slot, scores, sets JSON), groups
- **Integrations:** Telegram Gateway API, an SMS provider, Idram/bank payments later, Google/Yandex maps link.

## 7. Phases
1. **Phase 0, validation (1–2 weeks):**
   - show the prototype to 10–15 Yerevan venues: football parks, padel clubs, tennis centres
   - ask how they take bookings now (phone, Instagram) and what they would pay
2. **MVP (6–8 weeks):**
   - venues, courts, prices, booking with the phone code
   - venue dashboard
   - my bookings
   - three languages
3. **Tournaments + open games (4 weeks):** brackets, registration, entry fees.
4. **Mobile app + online payments.**

## 8. Open questions
- Final name and domain.
- Online payment for prime time from the start, or not?
- Do venues want a cancellation fee or a deposit for 8×8 and other big pitches?
- Tournament organisers: only venues, or also independent organisers?
