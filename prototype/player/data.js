// Demo data shared by the player app and the owner app: football places (each has a small 5×5 and a big 11×11 field),
// bookings, open games and amateur tournaments. Everything here is invented.
// Days are offsets from today (0 = today), hours are decimal (18.5 = 18:30).
//
// Bookings flow: a player sends a request (status "pending") -> the owner accepts or declines it.
// The owner can also add and edit bookings. Every change sends the player a Telegram message (KH.notify).
// Both apps keep their data in this browser's storage (STORE), so they see each other's changes.
window.KH = (function () {
  const L = (en, hy, ru) => ({ en, hy, ru });

  const SIZES = {
    small: { id: "small", format: "5×5", players: 10, w: 40, h: 20, durations: [60, 90, 120], defDur: 60, name: L("Small field", "Փոքր դաշտ", "Малое поле") },
    big: { id: "big", format: "11×11", players: 22, w: 105, h: 68, durations: [90, 120], defDur: 90, name: L("Big field", "Մեծ դաշտ", "Большое поле") }
  };

  const SURFACES = {
    turf: L("Artificial turf", "Արհեստական խոտ", "Искусственный газон"),
    grass: L("Natural grass", "Բնական խոտ", "Натуральный газон")
  };

  const AMENITIES = {
    parking: ["car", L("Parking", "Կայանատեղի", "Парковка")],
    showers: ["shower", L("Showers", "Լոգարաններ", "Душевые")],
    lockers: ["locker", L("Changing rooms", "Հանդերձարաններ", "Раздевалки")],
    cafe: ["coffee", L("Café", "Սրճարան", "Кафе")],
    lights: ["bolt", L("Floodlights", "Լուսարձակներ", "Освещение")],
    bibs: ["ball", L("Balls & bibs", "Գնդակներ և մայկաներ", "Мячи и манишки")],
    stands: ["users", L("Stands", "Տրիբունա", "Трибуна")]
  };

  const DISTRICTS = {
    kentron: L("Kentron", "Կենտրոն", "Кентрон"), arabkir: L("Arabkir", "Արաբկիր", "Арабкир"), davtashen: L("Davtashen", "Դավթաշեն", "Давташен"),
    shengavit: L("Shengavit", "Շենգավիթ", "Шенгавит"), avan: L("Avan", "Ավան", "Аван"), nornork: L("Nor Nork", "Նոր Նորք", "Нор Норк"),
    ajapnyak: L("Ajapnyak", "Աջափնյակ", "Аджапняк"), malatia: L("Malatia", "Մալաթիա", "Малатия")
  };

  // each place: one small field (5×5) and one big field (11×11); price per hour (day / prime time: evenings 18+ and weekends)
  const place = (id, name, district, address, rating, reviews, open, amenities, about, small, big) => ({
    id, name, district, address, rating, reviews, open, amenities, about,
    courts: [
      { id: id + "-s", size: "small", sport: "football", format: "5×5", surface: small.surface || "turf", indoor: !!small.indoor, price: { day: small.day, prime: small.prime } },
      { id: id + "-b", size: "big", sport: "football", format: "11×11", surface: big.surface || "turf", indoor: false, price: { day: big.day, prime: big.prime } }
    ]
  });
  const VENUES = [
    place("zangak", "Zangak Football Park", "arabkir", L("12 Komitas Ave", "Կոմիտասի պող. 12", "пр. Комитаса, 12"), 4.8, 312, [8, 24], ["parking", "showers", "lockers", "cafe", "lights", "bibs"],
      L("A 5×5 field under a roof and a full-size 11×11 pitch with stands. Café with a view of both.", "Ծածկով 5×5 դաշտ և լիաչափ 11×11 դաշտ՝ տրիբունայով։ Սրճարան՝ երկու դաշտերի տեսարանով։", "Поле 5×5 под крышей и полноразмерное поле 11×11 с трибуной. Кафе с видом на оба поля."),
      { indoor: true, day: 15000, prime: 20000 }, { day: 40000, prime: 50000 }),
    place("masis", "Masis Football Center", "shengavit", L("40 Bagratunyats Ave", "Բագրատունյաց պող. 40", "пр. Багратуняц, 40"), 4.6, 389, [9, 24], ["parking", "showers", "lockers", "lights", "stands"],
      L("The biggest football centre in the south of the city, home of the 5×5 Amateur Cup.", "Քաղաքի հարավի ամենամեծ ֆուտբոլային կենտրոնը, 5×5 սիրողական գավաթի տունը։", "Самый большой футбольный центр на юге города, дом любительского кубка 5×5."),
      { day: 13000, prime: 17000 }, { day: 38000, prime: 48000 }),
    place("davtashen", "Davtashen Arena", "davtashen", L("3 Davtashen 4th Block", "Դավթաշեն 4-րդ թաղ. 3", "Давташен, 4-й кв., 3"), 4.7, 176, [8, 23], ["parking", "showers", "lockers", "lights"],
      L("A quiet place by the park: indoor 5×5 for winter and a natural grass big pitch.", "Հանգիստ վայր այգու մոտ․ փակ 5×5 ձմռան համար և բնական խոտով մեծ դաշտ։", "Тихое место у парка: крытое 5×5 на зиму и большое поле с натуральным газоном."),
      { indoor: true, day: 14000, prime: 19000 }, { surface: "grass", day: 45000, prime: 55000 }),
    place("avan", "Avan Stadium", "avan", L("8 Tsarav Aghbyur St", "Ծարավ Աղբյուրի փ. 8", "ул. Царав Ахбюр, 8"), 4.4, 97, [8, 23], ["parking", "showers", "bibs"],
      L("An affordable neighbourhood stadium. Balls and bibs are free.", "Մատչելի թաղային մարզադաշտ։ Գնդակներն ու մայկաները անվճար են։", "Доступный районный стадион. Мячи и манишки бесплатно."),
      { day: 10000, prime: 13000 }, { day: 30000, prime: 38000 }),
    place("nork", "Nork Football Club", "nornork", L("2 Gai Ave", "Գայի պող. 2", "пр. Гая, 2"), 4.3, 84, [10, 24], ["parking", "showers", "lights"],
      L("Late games until midnight on both fields.", "Ուշ խաղեր մինչև կեսգիշեր երկու դաշտում։", "Поздние игры до полуночи на обоих полях."),
      { indoor: true, day: 14000, prime: 18000 }, { day: 36000, prime: 45000 }),
    place("ajapnyak", "Ajapnyak Sport Base", "ajapnyak", L("15 Halabyan St", "Հալաբյանի փ. 15", "ул. Алабяна, 15"), 4.5, 122, [8, 23], ["parking", "lockers", "cafe", "stands"],
      L("A big base with stands for 300 people, often used for amateur finals.", "Մեծ բազա 300 հոգանոց տրիբունայով, որտեղ հաճախ անցկացվում են սիրողական եզրափակիչներ։", "Большая база с трибуной на 300 человек, здесь часто играют любительские финалы."),
      { day: 12000, prime: 16000 }, { day: 40000, prime: 50000 }),
    place("kentron", "Kentron Football Club", "kentron", L("27 Abovyan St", "Աբովյանի փ. 27", "ул. Абовяна, 27"), 4.6, 141, [9, 23], ["showers", "lockers", "cafe", "lights"],
      L("Football in the city centre, five minutes from the metro.", "Ֆուտբոլ քաղաքի կենտրոնում, մետրոյից հինգ րոպե։", "Футбол в центре города, пять минут от метро."),
      { indoor: true, day: 18000, prime: 24000 }, { day: 48000, prime: 58000 }),
    place("malatia", "Malatia Fields", "malatia", L("50 Sebastia St", "Սեբաստիայի փ. 50", "ул. Себастии, 50"), 4.2, 68, [9, 23], ["parking", "bibs"],
      L("Simple and cheap: good for regular weekly games.", "Պարզ և էժան․ լավ է շաբաթական կանոնավոր խաղերի համար։", "Просто и недорого: хорошо для регулярных игр каждую неделю."),
      { day: 9000, prime: 12000 }, { day: 28000, prime: 35000 })
  ];
  const VENUE = Object.fromEntries(VENUES.map(v => [v.id, v]));
  VENUES.forEach(v => v.courts.forEach(ct => { ct.venue = v.id; }));
  const COURTS = Object.fromEntries(VENUES.flatMap(v => v.courts.map(ct => [ct.id, ct])));
  const courtName = ct => tt(SIZES[ct.size].name);

  // ---------- prices ----------
  function isPrime(day, h) { const wd = DAY.date(day).getDay(); return wd === 0 || wd === 6 || h >= 18; }
  // owner price changes (owner app, "Prices") override the defaults
  function priceOf(ct) { const p = STORE.get("prices", {})[ct.id]; return p ? { day: p.day, prime: p.prime } : ct.price; }
  function priceFor(ct, day, start, dur) {
    const p = priceOf(ct); let sum = 0;
    for (let t = start; t < start + dur / 60 - 1e-6; t += 0.5) sum += (isPrime(day, t) ? p.prime : p.day) / 2;
    return sum;
  }

  // ---------- people (made up) ----------
  const FIRST = ["Aram", "Davit", "Narek", "Tigran", "Gor", "Hayk", "Arman", "Levon", "Vahe", "Ashot", "Karen", "Samvel", "Erik", "Ruben", "Mher", "Artur", "Sargis", "Hovhannes", "Grigor", "Edgar"];
  const LAST = ["Petrosyan", "Grigoryan", "Hakobyan", "Sargsyan", "Avetisyan", "Mkrtchyan", "Karapetyan", "Harutyunyan", "Ghazaryan", "Vardanyan", "Manukyan", "Martirosyan", "Hovhannisyan", "Stepanyan", "Abrahamyan", "Poghosyan"];
  const TEAMS = ["Kentron FC", "Arabkir United", "Nork Wolves", "Masis Eagles", "Davtashen Stars", "Ajapnyak Rangers", "Erebuni 2800", "Shengavit Storm", "Avan Titans", "Malatia Bulls", "Komitas Kickers", "Zeytun Boys", "Kanaker Hawks", "Nubarashen FC", "Cascade Crew", "Sevan Breeze", "Byte Factory", "Aragats Cargo", "Pixel Garden", "Cloud 374", "Garni Labs", "Code Republic"];

  // ---------- bookings ----------
  const iso = day => { const d = DAY.date(day); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; };
  const dayOfIso = s => DAY.index(new Date(s.length === 10 ? s + "T00:00:00" : s));
  // made-up bookings so the schedule looks real; stable between reloads.
  // The owner's decisions about them are kept in STORE "demoState" (id -> status / edits).
  const cache = {};
  function demo(courtId, day) {
    const key = courtId + "|" + iso(day); if (cache[key]) return cache[key];
    const ct = COURTS[courtId], v = VENUE[ct.venue], r = RNG(key), out = [], big = ct.size === "big";
    for (let t = v.open[0]; t + 1 <= v.open[1];) {
      const prime = isPrime(day, t), p = (prime ? (big ? 0.5 : 0.62) : (big ? 0.15 : 0.28)) * (day > 7 ? 0.5 : 1) + (t >= 19 && t < 22 ? 0.16 : 0);
      if (r() < p) {
        const d = big ? (r() < 0.6 ? 1.5 : 2) : (r() < 0.2 ? 2 : r() < 0.2 ? 1.5 : 1), e = Math.min(t + d, v.open[1]);
        const name = FIRST[Math.floor(r() * FIRST.length)] + " " + LAST[Math.floor(r() * LAST.length)];
        const phone = "3749" + Math.floor(r() * 10) + String(Math.floor(r() * 1e6)).padStart(6, "0");
        const id = "d-" + key + "|" + t;
        out.push({ id, demo: true, venue: ct.venue, court: courtId, date: iso(day), start: t, dur: Math.round((e - t) * 60), name, phone,
          team: r() < 0.4 ? TEAMS[Math.floor(r() * TEAMS.length)] : "", status: day >= 0 && day <= 3 && r() < 0.18 ? "pending" : "accepted", by: "player",
          code: "BB-" + (1000 + Math.floor(r() * 9000)), created: 0 });
        t = e;
      } else t += r() < 0.5 ? 0.5 : 1;
    }
    cache[key] = out; return out;
  }
  // every booking of a field on a day (demo + real) with the owner's changes applied.
  // Declined and cancelled ones are included; use KH.active(b) to keep only the ones that hold the time.
  function bookings(courtId, day) {
    const st = STORE.get("demoState", {}), list = [];
    demo(courtId, day).forEach(b => list.push(Object.assign({}, b, st[b.id] || {})));
    // demo bookings the owner moved here from another field or day
    Object.entries(st).forEach(([id, o]) => {
      if (!o.court && !o.date) return;
      const [ct0, d0] = id.slice(2).split("|");
      if (ct0 === courtId && d0 === iso(day)) return; // already in the list
      if ((o.court || ct0) === courtId && dayOfIso(o.date || d0) === day) {
        const orig = demo(ct0, dayOfIso(d0)).find(b => b.id === id); if (orig) list.push(Object.assign({}, orig, o));
      }
    });
    STORE.get("bookings", []).forEach(b => { if (b.court === courtId && dayOfIso(b.date) === day) list.push(Object.assign({}, b)); });
    return list.filter(b => b.court === courtId && dayOfIso(b.date) === day).sort((a, b) => a.start - b.start);
  }
  const active = b => b.status === "pending" || b.status === "accepted";
  // blocks that make a field busy (pending requests hold the time too)
  function busy(courtId, day) {
    const me = STORE.get("profile", null);
    return bookings(courtId, day).filter(active).map(b => ({ s: b.start, e: b.start + b.dur / 60, id: b.id, status: b.status, mine: !b.demo && b.by === "player" && !!me && b.phone === me.phone }));
  }
  function isFree(courtId, day, start, dur, ignoreId) {
    const ct = COURTS[courtId], v = VENUE[ct.venue], end = start + dur / 60;
    if (start < v.open[0] || end > v.open[1]) return false;
    if (day === 0) { const now = new Date(); if (start < now.getHours() + now.getMinutes() / 60) return false; }
    return !busy(courtId, day).some(b => b.id !== ignoreId && b.s < end - 1e-6 && b.e > start + 1e-6);
  }
  // next free start times at a place (for cards): [{court, start}]
  function nextFree(venueId, size, day, count) {
    const v = VENUE[venueId], out = [], now = new Date(), from = day === 0 ? Math.ceil(now.getHours() + now.getMinutes() / 60) : v.open[0];
    for (let t = Math.max(from, v.open[0]); t < v.open[1] && out.length < count; t += 1) {
      const ct = v.courts.find(x => (!size || x.size === size) && isFree(x.id, day, t, SIZES[x.size].defDur));
      if (ct) out.push({ court: ct, start: t });
    }
    return out;
  }
  function clearCache() { for (const k in cache) delete cache[k]; }
  function minPrice(v, size) { return Math.min(...v.courts.filter(x => !size || x.size === size).map(x => priceOf(x).day)); }

  // find any booking by id (demo or real)
  function findBooking(id) {
    if (id.startsWith("d-")) {
      const [ct, d] = id.slice(2).split("|"); const b = demo(ct, dayOfIso(d)).find(x => x.id === id);
      return b ? Object.assign({}, b, STORE.get("demoState", {})[id] || {}) : null;
    }
    return STORE.get("bookings", []).find(b => b.id === id) || null;
  }
  // change a booking (status, time, field, price, note…); demo ones are stored as overrides
  function updateBooking(id, changes) {
    if (id.startsWith("d-")) { const st = STORE.get("demoState", {}); st[id] = Object.assign(st[id] || {}, changes); STORE.set("demoState", st); }
    else STORE.set("bookings", STORE.get("bookings", []).map(b => (b.id === id ? Object.assign({}, b, changes) : b)));
    clearCache(); return findBooking(id);
  }
  // new booking: {venue, court, date (YYYY-MM-DD), start, dur, price, name, phone, team?, note?, status, by: "player"|"owner"}
  function addBooking(b) {
    const rec = Object.assign({ id: "b" + Date.now().toString(36) + Math.floor(Math.random() * 1e4), code: "BB-" + (1000 + Math.floor(Math.random() * 9000)), created: Date.now() }, b);
    STORE.push("bookings", rec); clearCache(); return rec;
  }

  // ---------- Telegram messages (simulated) ----------
  // notify(phone, key, vars): the player app shows these as the Telegram bot chat; message texts are in common.js (tg.*)
  function notify(phone, key, vars) { if (phone) STORE.push("tg", { phone, key, vars: vars || {}, at: Date.now() }); }

  // ---------- open games: people looking for players ----------
  const LEVELS = { beginner: L("Beginner", "Սկսնակ", "Новичок"), mid: L("Intermediate", "Միջին", "Средний"), pro: L("Advanced", "Բարձր", "Продвинутый") };
  const GAMES = [
    { id: "g1", court: "zangak-s", day: 0, start: 21, dur: 60, level: "mid", host: "Narek Hakobyan", joined: 8, need: 10, note: L("Friendly 5×5, bring a dark and a light shirt", "Ընկերական 5×5, բերեք մուգ և բաց շապիկ", "Дружеская 5×5, возьмите тёмную и светлую футболку") },
    { id: "g2", court: "masis-b", day: 1, start: 19, dur: 90, level: "mid", host: "Tigran Sargsyan", joined: 15, need: 22, note: L("11×11 on the big pitch, 7 places left", "11×11 մեծ դաշտում, մնացել է 7 տեղ", "11×11 на большом поле, осталось 7 мест") },
    { id: "g3", court: "avan-s", day: 1, start: 20, dur: 60, level: "beginner", host: "Gor Avetisyan", joined: 6, need: 10, note: L("Easy game after work, everyone welcome", "Թեթև խաղ աշխատանքից հետո, բոլորը սպասված են", "Лёгкая игра после работы, всем рады") },
    { id: "g4", court: "kentron-s", day: 2, start: 21, dur: 60, level: "pro", host: "Levon Harutyunyan", joined: 9, need: 10, note: L("Need one strong player, fast game", "Պետք է մեկ ուժեղ խաղացող, արագ խաղ", "Нужен один сильный игрок, быстрая игра") },
    { id: "g5", court: "davtashen-b", day: 3, start: 10, dur: 120, level: "mid", host: "Davit Grigoryan", joined: 18, need: 22, note: L("Morning game on natural grass", "Առավոտյան խաղ բնական խոտի վրա", "Утренняя игра на натуральном газоне") },
    { id: "g6", court: "nork-s", day: 4, start: 22, dur: 60, level: "beginner", host: "Hayk Mkrtchyan", joined: 5, need: 10, note: L("Late game indoors, slow pace", "Ուշ խաղ փակ դաշտում, հանգիստ տեմպ", "Поздняя игра в зале, спокойный темп") },
    { id: "g7", court: "ajapnyak-b", day: 5, start: 18, dur: 90, level: "pro", host: "Arman Karapetyan", joined: 20, need: 22, note: L("Two places left for a full 11×11", "Մնացել է երկու տեղ լրիվ 11×11-ի համար", "Осталось два места на полный 11×11") }
  ];
  GAMES.forEach(g => {
    const ct = COURTS[g.court]; g.venue = ct.venue; g.sport = "football"; g.size = ct.size; g.format = ct.format;
    g.pricePer = Math.round(priceFor(ct, g.day, g.start, g.dur) / g.need / 100) * 100;
    g.joined += STORE.get("joined", []).includes(g.id) ? 1 : 0;
  });

  // ---------- amateur tournaments (football 5×5 and 11×11) ----------
  const FORMATS = {
    groups: L("Groups + playoff", "Խմբեր + փլեյ-օֆֆ", "Группы + плей-офф"),
    knockout: L("Knockout", "Ուղղակի դուրս մնալ", "На вылет"),
    league: L("League", "Լիգա", "Лига")
  };
  // no live mode: registration open -> closing soon -> registration closed (starts soon) -> finished
  const STATUS = {
    open: L("Registration open", "Գրանցումը բաց է", "Идёт регистрация"),
    soon: L("Closing soon", "Շուտով կփակվի", "Скоро закроется"),
    closed: L("Starts soon", "Շուտով կմեկնարկի", "Скоро старт"),
    done: L("Finished", "Ավարտված", "Завершён")
  };

  // knockout bracket: rounds[[{a, b, sa?, sb?, winner?, day, hour}]]; null = not known yet
  function knockout(seed, entrants, played, startDay, startHour, dayOf) {
    const r = RNG(seed), rounds = []; let cur = entrants.slice();
    while (cur.length >= 2) {
      const k = rounds.length, ms = [];
      for (let i = 0; i < cur.length; i += 2) {
        const a = cur[i], b = cur[i + 1];
        const m = { a, b, day: startDay + dayOf(k), hour: startHour + i / 2 };
        if (k < played && a && b) { let sa = Math.floor(r() * 6), sb = Math.floor(r() * 5); if (sa === sb) sa++; Object.assign(m, { sa, sb }); m.winner = sa > sb ? a : b; }
        ms.push(m);
      }
      rounds.push(ms); cur = ms.map(m => m.winner || null);
    }
    return rounds;
  }
  // round robin tables; played = false gives the fixtures with no scores (before the start)
  function groups(seed, teams, size, played) {
    const r = RNG(seed), out = [];
    for (let g = 0; g * size < teams.length; g++) {
      const ts = teams.slice(g * size, g * size + size), rows = ts.map(t => ({ team: t, p: 0, w: 0, d: 0, l: 0, gf: 0, ga: 0, pts: 0 })), matches = [];
      for (let i = 0; i < ts.length; i++) for (let j = i + 1; j < ts.length; j++) {
        if (!played) { matches.push({ a: ts[i], b: ts[j] }); continue; }
        const sa = Math.floor(r() * 5), sb = Math.floor(r() * 4); matches.push({ a: ts[i], b: ts[j], sa, sb });
        const A = rows[i], B = rows[j]; A.p++; B.p++; A.gf += sa; A.ga += sb; B.gf += sb; B.ga += sa;
        if (sa > sb) { A.w++; B.l++; A.pts += 3; } else if (sa < sb) { B.w++; A.l++; B.pts += 3; } else { A.d++; B.d++; A.pts++; B.pts++; }
      }
      if (played) rows.sort((x, y) => y.pts - x.pts || (y.gf - y.ga) - (x.gf - x.ga) || y.gf - x.gf);
      out.push({ name: String.fromCharCode(65 + g), rows, matches });
    }
    return out;
  }

  const TOURNAMENTS = [
    { id: "cup5", size: "small", name: L("Yerevan Amateur 5×5 Cup", "Երևանի սիրողական 5×5 գավաթ", "Любительский кубок Еревана 5×5"), venue: "masis", format: "groups", status: "open",
      start: 9, end: 23, regEnd: 6, teamSize: 7, max: 16, entrants: TEAMS.slice(0, 11), fee: 60000, prize: 1000000,
      about: L("Sixteen amateur teams in four groups; the top two go to the quarter-finals. Matches 2 × 20 minutes on Saturdays and Sundays.", "Տասնվեց սիրողական թիմ չորս խմբում․ առաջին երկուսն անցնում են քառորդ եզրափակիչ։ Խաղեր՝ 2 × 20 րոպե, շաբաթ և կիրակի։", "Шестнадцать любительских команд в четырёх группах; две лучшие выходят в четвертьфинал. Матчи 2 × 20 минут по субботам и воскресеньям.") },
    { id: "league11", size: "big", name: L("Autumn 11×11 Amateur League", "Աշնանային 11×11 սիրողական լիգա", "Осенняя любительская лига 11×11"), venue: "zangak", format: "league", status: "soon",
      start: 6, end: 55, regEnd: 3, teamSize: 16, max: 8, entrants: TEAMS.slice(4, 10), fee: 250000, prize: 1500000,
      about: L("Eight teams, everyone plays everyone once, one match every Sunday. Referees and match balls are included.", "Ութ թիմ, բոլորը խաղում են բոլորի հետ մեկ անգամ, մեկ խաղ ամեն կիրակի։ Մրցավարներն ու գնդակները ներառված են։", "Восемь команд, каждый с каждым по одному разу, матч каждое воскресенье. Судьи и мячи включены.") },
    { id: "weekend5", size: "small", name: L("Weekend 5×5 Knockout", "Շաբաթավերջի 5×5 նոկաուտ", "Турнир выходного дня 5×5 на вылет"), venue: "kentron", format: "knockout", status: "open",
      start: 12, end: 12, regEnd: 10, teamSize: 6, max: 16, entrants: TEAMS.slice(6, 15), fee: 40000, prize: 500000,
      about: L("One day, sixteen teams, knockout from the first match. Games 2 × 12 minutes, penalties if level.", "Մեկ օր, տասնվեց թիմ, դուրս մնալ առաջին խաղից։ Խաղեր՝ 2 × 12 րոպե, հավասարության դեպքում՝ պենալտիներ։", "Один день, шестнадцать команд, на вылет с первого матча. Игры 2 × 12 минут, при ничьей — пенальти.") },
    { id: "corporate5", size: "small", name: L("Corporate 5×5 League", "Կորպորատիվ 5×5 լիգա", "Корпоративная лига 5×5"), venue: "ajapnyak", format: "groups", status: "closed",
      start: 2, end: 40, regEnd: -1, teamSize: 8, max: 8, entrants: TEAMS.slice(14, 22), fee: 150000, prize: 0, prizeText: L("Cup + team dinner", "Գավաթ + թիմային ընթրիք", "Кубок + ужин для команды"),
      about: L("Company teams play every Thursday evening. Registration is closed and the draw is done.", "Ընկերությունների թիմերը խաղում են ամեն հինգշաբթի երեկոյան։ Գրանցումը փակ է, վիճակահանությունն արված է։", "Команды компаний играют каждый четверг вечером. Регистрация закрыта, жеребьёвка проведена.") },
    { id: "summer11", size: "big", name: L("Summer 11×11 Cup", "Ամառային 11×11 գավաթ", "Летний кубок 11×11"), venue: "davtashen", format: "knockout", status: "done",
      start: -70, end: -56, regEnd: -75, teamSize: 16, max: 8, entrants: TEAMS.slice(4, 12), fee: 200000, prize: 1200000,
      about: L("Eight teams on natural grass. Thanks to everyone who played; see you next summer.", "Ութ թիմ բնական խոտի վրա։ Շնորհակալություն բոլոր մասնակիցներին, կհանդիպենք հաջորդ ամռանը։", "Восемь команд на натуральном газоне. Спасибо всем участникам, до встречи следующим летом.") },
    { id: "spring5", size: "small", name: L("Spring 5×5 League", "Գարնանային 5×5 լիգա", "Весенняя лига 5×5"), venue: "avan", format: "league", status: "done",
      start: -150, end: -100, regEnd: -155, teamSize: 7, max: 8, entrants: TEAMS.slice(8, 16), fee: 50000, prize: 400000,
      about: L("Eight neighbourhood teams, one round. Kanaker Hawks were the surprise of the season.", "Ութ թաղային թիմ, մեկ շրջան։ Kanaker Hawks-ը սեզոնի անակնկալն էր։", "Восемь районных команд, один круг. Kanaker Hawks — сюрприз сезона.") }
  ];
  TOURNAMENTS.forEach(t => { t.sport = "football"; t.feeUnit = "team"; t.hour = 19; t.regs = []; });
  // tournaments created in the owner app
  STORE.get("mytours", []).forEach(m => {
    const s = dayOfIso(m.start), e = dayOfIso(m.end || m.start), size = m.size || "small";
    TOURNAMENTS.push({ id: m.id, mine: true, sport: "football", size, name: L(m.name, m.name, m.name), venue: m.venue, format: m.format || "groups",
      status: s - 3 > 3 ? "open" : s - 3 > 0 ? "soon" : "closed", start: s, end: e, regEnd: s - 3, teamSize: size === "big" ? 16 : 7, max: m.max || 8, entrants: [], fee: m.fee || 0, feeUnit: "team",
      prize: m.prize || 0, prizeText: m.prize ? null : L("Set by the venue", "Սահմանում է ակումբը", "Определяет клуб"), hour: 19, regs: [],
      about: m.about ? L(m.about, m.about, m.about) : L("A new amateur tournament by the venue.", "Ակումբի նոր սիրողական մրցաշար։", "Новый любительский турнир от клуба.") });
  });
  TOURNAMENTS.forEach(t => { t.format5 = SIZES[t.size].format; });
  const TOURNAMENT = Object.fromEntries(TOURNAMENTS.map(t => [t.id, t]));
  // team sign-ups: {id, t, name (team), captain, phone, players, created, status: pending|accepted|declined}
  // the owner accepts them in the owner app; accepted teams join the list
  STORE.get("regs", []).forEach(rg => {
    const t = TOURNAMENT[rg.t]; if (!t) return;
    const r = Object.assign({ status: "pending" }, rg); t.regs.push(r);
    if (r.status === "accepted" && !t.entrants.includes(r.name)) t.entrants.push(r.name);
  });
  // draws and results (made after sign-ups so accepted teams appear)
  TOURNAMENTS.forEach(t => {
    const pad = (a, n) => a.concat(Array(Math.max(0, n - a.length)).fill(null)).slice(0, n);
    if (t.status === "done" && t.format === "knockout") t.bracket = knockout(t.id, t.entrants, 9, t.start, 17, k => k * 7);
    else if (t.status === "done") t.groups = groups(t.id, t.entrants, t.entrants.length, true);
    else if (t.format === "knockout") t.bracket = knockout(t.id, pad(t.entrants, t.max), 0, t.start, 10, () => 0);
    else if (t.format === "league") t.groups = groups(t.id, t.entrants, Math.max(1, t.entrants.length), false);
    else {
      t.groups = t.status === "closed" ? groups(t.id, t.entrants, 4, false) : null; // the draw happens when registration closes
      t.bracket = knockout(t.id + "-po", Array(Math.max(2, t.max / 2)).fill(null), 0, Math.max(t.start, t.end - 7), 18, k => k * 3);
    }
  });

  return { SIZES, SURFACES, AMENITIES, DISTRICTS, VENUES, VENUE, COURTS, LEVELS, GAMES, FORMATS, STATUS, TOURNAMENTS, TOURNAMENT, TEAMS, FIRST, LAST,
    isPrime, priceOf, priceFor, demo, bookings, busy, isFree, nextFree, clearCache, minPrice, courtName, findBooking, updateBooking, addBooking, notify, iso, dayOfIso, active, L };
})();
