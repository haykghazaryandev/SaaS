// Shared by every page: icons, languages, formatting, header/footer, court drawings, local storage,
// and the phone check (name + phone + one-time code) used before a booking or a tournament sign-up.
(function () {
  // ---------- icons (24×24 line icons) ----------
  const P = {
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
    pin: '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    back: '<path d="M19 12H5M12 19l-7-7 7-7"/>',
    arrow: '<path d="M5 12h14M12 5l7 7-7 7"/>',
    calendar: '<rect x="3" y="4" width="18" height="18" rx="3"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
    user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    check: '<path d="M20 6 9 17l-5-5"/>',
    x: '<path d="M18 6 6 18M6 6l12 12"/>',
    star: '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9Z"/>',
    trophy: '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0Z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
    cube: '<path d="M21 16V8a2 2 0 0 0-1-1.7l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.7l7 4a2 2 0 0 0 2 0l7-4a2 2 0 0 0 1-1.7Z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
    home: '<path d="M3 11 12 3l9 8"/><path d="M5 10v10h14V10"/>',
    ticket: '<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2v-2a2 2 0 0 0 0-4Z"/><path d="M14 6v12" stroke-dasharray="2 2"/>',
    chart: '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    phone: '<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M11 18h2"/>',
    send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
    msg: '<path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12Z"/>',
    sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    roof: '<path d="M2 12 12 4l10 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
    bolt: '<path d="M13 2 4 14h7l-1 8 9-12h-7Z"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    filter: '<path d="M3 5h18M6 12h12M10 19h4"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>',
    shower: '<path d="M4 4h6a6 6 0 0 1 6 6v1H4Z"/><path d="M6 15v1M10 15v2M14 15v1M8 19v1M12 19v1"/>',
    car: '<path d="M5 17h14v-5l-2-5H7l-2 5Z"/><circle cx="8" cy="17" r="2"/><circle cx="16" cy="17" r="2"/>',
    locker: '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M12 3v18M9 10v2M15 10v2"/>',
    coffee: '<path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><path d="M6 2v3M10 2v3M14 2v3"/>',
    ball: '<circle cx="12" cy="12" r="9"/><path d="m12 7 4 3-1.5 5h-5L8 10Z"/><path d="m12 7V3M16 10l4-1M14.5 15l2 4M9.5 15l-2 4M8 10 4 9"/>',
    whistle: '<path d="M2 12a5 5 0 0 0 9.5 2H20l2-4H9"/><circle cx="7" cy="12" r="2"/>',
    grid: '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
    eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    share: '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
    wallet: '<rect x="3" y="6" width="18" height="14" rx="3"/><path d="M3 10h18M16 15h2"/>',
    medal: '<circle cx="12" cy="15" r="6"/><path d="M8 3l2 6M16 3l-2 6M12 12v6"/>'
  };
  window.ICON = (name, cls) => `<svg class="ico${cls ? " " + cls : ""}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${P[name] || ""}</svg>`;

  // ---------- languages: English, Armenian, Russian ----------
  const LANGS = ["en", "hy", "ru"];
  const DICT = {};
  let lang = "en";
  try { lang = localStorage.getItem("kh.lang") || lang; } catch (e) {}
  const q = new URLSearchParams(location.search);
  if (LANGS.includes(q.get("lang"))) lang = q.get("lang");
  if (!LANGS.includes(lang)) lang = "en";
  const I18N = {
    get lang() { return lang; },
    add(d) { Object.assign(DICT, d); },
    // T("key", {n: 3}) -> text in the current language; {n} placeholders are filled in
    T(key, vars) {
      const e = DICT[key]; let s = e ? (e[LANGS.indexOf(lang)] || e[0]) : key;
      if (vars) s = s.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? vars[k] : m));
      return s;
    },
    // t({en, hy, ru}) -> text in the current language
    t(o) { return o == null ? "" : typeof o === "string" ? o : (o[lang] || o.en || ""); },
    set(l) { try { localStorage.setItem("kh.lang", l); } catch (e) {} const u = new URL(location.href); u.searchParams.set("lang", l); location.href = u.toString(); },
    // Russian plural forms: [one, few, many]
    plural(n, forms) {
      if (lang !== "ru") return n === 1 ? forms[0] : forms[forms.length > 2 ? 2 : 1];
      const a = n % 10, b = n % 100;
      return a === 1 && b !== 11 ? forms[0] : a >= 2 && a <= 4 && (b < 12 || b > 14) ? forms[1] : forms[2];
    }
  };
  window.I18N = I18N; window.T = I18N.T; window.tt = I18N.t;
  document.documentElement.lang = lang;

  I18N.add({
    "brand": ["Bookball", "Bookball", "Bookball"],
    "nav.courts": ["Fields", "Դաշտեր", "Поля"],
    "nav.tournaments": ["Tournaments", "Մրցաշարեր", "Турниры"],
    "nav.games": ["Open games", "Բաց խաղեր", "Открытые игры"],
    "nav.my": ["My bookings", "Իմ ամրագրումները", "Мои брони"],
    "nav.venue": ["For venues", "Ակումբներին", "Для клубов"],
    "nav.myShort": ["Bookings", "Ամրագրումներ", "Брони"],
    "nav.home": ["Home", "Գլխավոր", "Главная"],
    "sport.football": ["Football", "Ֆուտբոլ", "Футбол"],
    "amd": ["֏", "֏", "֏"],
    "perHour": ["/ hour", "/ ժամ", "/ час"],
    "from": ["from", "սկսած", "от"],
    "today": ["Today", "Այսօր", "Сегодня"],
    "tomorrow": ["Tomorrow", "Վաղը", "Завтра"],
    "indoor": ["Indoor", "Փակ", "Крытый"],
    "outdoor": ["Outdoor", "Բացօթյա", "Открытый"],
    "min": ["min", "րոպե", "мин"],
    "h": ["h", "ժ", "ч"],
    "players": ["players", "խաղացող", "игроков"],
    "free": ["Free", "Ազատ", "Свободно"],
    "busy": ["Booked", "Զբաղված", "Занято"],
    "yours": ["Yours", "Ձերը", "Ваше"],
    "close": ["Close", "Փակել", "Закрыть"],
    "cancel": ["Cancel", "Չեղարկել", "Отмена"],
    "back": ["Back", "Հետ", "Назад"],
    "seeAll": ["See all", "Տեսնել բոլորը", "Смотреть все"],
    "reviews": ["reviews", "կարծիք", "отзывов"],
    "foot.demo": ["Demo prototype · all venues, people and prices are made up", "Դեմո նախատիպ · բոլոր ակումբները, մարդիկ և գները հորինված են", "Демо-прототип · все клубы, люди и цены вымышлены"],
    "foot.city": ["Yerevan, Armenia", "Երևան, Հայաստան", "Ереван, Армения"],
    // phone check
    "v.title": ["Confirm your phone", "Հաստատեք հեռախոսահամարը", "Подтвердите телефон"],
    "v.sub": ["We send a one-time code to your Telegram so the field knows the booking is real. Only the first time. Booking updates also come to Telegram.", "Ձեր Telegram-ին ուղարկում ենք մեկանգամյա կոդ, որ դաշտը վստահ լինի ամրագրման մեջ։ Միայն առաջին անգամ։ Ամրագրման մասին տեղեկությունները նույնպես կգան Telegram-ով։", "Отправим одноразовый код в ваш Telegram, чтобы поле было уверено в брони. Только в первый раз. Новости о брони тоже придут в Telegram."],
    "v.tgNote": ["The code comes from the Bookball bot in Telegram", "Կոդը կգա Telegram-ում Bookball բոտից", "Код придёт от бота Bookball в Telegram"],
    "v.name": ["Your name", "Ձեր անունը", "Ваше имя"],
    "v.phone": ["Phone number", "Հեռախոսահամար", "Номер телефона"],
    "v.telegram": ["Telegram", "Telegram", "Telegram"],
    "v.send": ["Send code", "Ուղարկել կոդը", "Отправить код"],
    "v.enter": ["Enter the 4-digit code we sent to Telegram for {phone}", "Մուտքագրեք 4-նիշ կոդը, որ ուղարկեցինք Telegram-ով {phone} համարին", "Введите 4-значный код, отправленный в Telegram для {phone}"],
    "v.noTg": ["No Telegram on this number? Install Telegram and try again.", "Այս համարով Telegram չկա՞։ Տեղադրեք Telegram և կրկին փորձեք։", "Нет Telegram на этом номере? Установите Telegram и попробуйте снова."],
    "v.demo": ["Demo: the code is {code}", "Դեմո․ կոդն է {code}", "Демо: код {code}"],
    "v.wrong": ["Wrong code, {n} tries left", "Սխալ կոդ, մնաց {n} փորձ", "Неверный код, осталось попыток: {n}"],
    "v.resend": ["Send again", "Կրկին ուղարկել", "Отправить снова"],
    "v.ok": ["Phone confirmed", "Հեռախոսը հաստատված է", "Телефон подтверждён"],
    "v.known": ["Booking as {name} · {phone}", "Ամրագրում՝ {name} · {phone}", "Бронь на {name} · {phone}"],
    "v.change": ["Change", "Փոխել", "Изменить"],
    "v.badPhone": ["Enter a valid Armenian number", "Մուտքագրեք ճիշտ հայկական համար", "Введите корректный армянский номер"]
  });

  // ---------- formatting ----------
  const MONTHS = {
    en: ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"],
    hy: ["հնվ", "փտր", "մրտ", "ապր", "մյս", "հնս", "հլս", "օգս", "սեպ", "հոկ", "նոյ", "դեկ"],
    ru: ["янв", "фев", "мар", "апр", "мая", "июн", "июл", "авг", "сен", "окт", "ноя", "дек"]
  };
  const DAYS = {
    en: ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"],
    hy: ["Կիր", "Երկ", "Երք", "Չրք", "Հնգ", "Ուրբ", "Շբթ"],
    ru: ["Вс", "Пн", "Вт", "Ср", "Чт", "Пт", "Сб"]
  };
  const FMT = {
    short: n => (n >= 1e6 ? +(n / 1e6).toFixed(1) + "M" : n >= 1e3 ? Math.round(n / 1e3) + "K" : String(n)) + " ֏",
    amd: n => Math.round(n).toLocaleString("en-US").replace(/,/g, " ") + " ֏",
    time: h => { const hh = Math.floor(h), mm = Math.round((h - hh) * 60); return String(hh).padStart(2, "0") + ":" + String(mm).padStart(2, "0"); },
    day: d => DAYS[lang][d.getDay()],
    date: d => `${d.getDate()} ${MONTHS[lang][d.getMonth()]}`,
    dateLong: d => `${DAYS[lang][d.getDay()]}, ${d.getDate()} ${MONTHS[lang][d.getMonth()]}`,
    rel(d) { const k = dayIndex(d); return k === 0 ? T("today") : k === 1 ? T("tomorrow") : FMT.dateLong(d); },
    phone: p => { const s = String(p).replace(/\D/g, "").slice(-8); return `+374 ${s.slice(0, 2)} ${s.slice(2, 5)} ${s.slice(5)}`; },
    dur: m => (m % 60 ? `${m} ${T("min")}` : `${m / 60} ${T("h")}`)
  };
  window.FMT = FMT;

  // dates are kept as day offsets from today (0 = today) so the demo always looks current
  const TODAY = new Date(); TODAY.setHours(0, 0, 0, 0);
  function dayDate(k) { const d = new Date(TODAY); d.setDate(d.getDate() + k); return d; }
  function dayIndex(d) { const x = new Date(d); x.setHours(0, 0, 0, 0); return Math.round((x - TODAY) / 864e5); }
  window.DAY = { TODAY, date: dayDate, index: dayIndex };

  // small seeded random so made-up bookings stay the same between reloads
  window.RNG = function (seed) {
    let h = 1779033703 ^ String(seed).length;
    for (const ch of String(seed)) { h = Math.imul(h ^ ch.charCodeAt(0), 3432918353); h = (h << 13) | (h >>> 19); }
    return function () { h = Math.imul(h ^ (h >>> 16), 2246822507); h = Math.imul(h ^ (h >>> 13), 3266489909); h ^= h >>> 16; return (h >>> 0) / 4294967296; };
  };

  // ---------- local storage (the prototype has no server yet) ----------
  const STORE = {
    get(k, def) { try { const v = localStorage.getItem("kh." + k); return v ? JSON.parse(v) : def; } catch (e) { return def; } },
    set(k, v) { try { localStorage.setItem("kh." + k, JSON.stringify(v)); } catch (e) {} },
    push(k, v) { const a = STORE.get(k, []); a.push(v); STORE.set(k, a); return a; }
  };
  window.STORE = STORE;

  // ---------- court drawings (top view, SVG) ----------
  // Football only: "small" = 5×5 field, "big" = full 11×11 pitch ("football" is kept as an alias of "small").
  const COURT = {
    football(s) {
      const c = s.color || "#2f8f4e";
      let stripes = ""; for (let i = 0; i < 10; i++) if (i % 2) stripes += `<rect x="${i * 40}" y="0" width="40" height="250" fill="#000" opacity=".07"/>`;
      return `<svg viewBox="0 0 400 250"><rect width="400" height="250" rx="6" fill="${c}"/>${stripes}
        <g fill="none" stroke="#f2fff4" stroke-width="3" opacity=".9"><rect x="14" y="14" width="372" height="222" rx="2"/><path d="M200 14v222"/><circle cx="200" cy="125" r="34"/>
        <path d="M14 70h46v110H14M386 70h-46v110h46"/><path d="M14 100h-8v50h8M386 100h8v50h-8"/></g><circle cx="200" cy="125" r="4" fill="#f2fff4"/></svg>`;
    }
  };
  COURT.small = COURT.football;
  // full-size pitch (105 × 68) with penalty areas, goal areas and arcs
  COURT.big = function (s) {
    const c = s.color || "#2a8a48";
    let stripes = ""; for (let i = 0; i < 12; i++) if (i % 2) stripes += `<rect x="${i * 35}" y="0" width="35" height="272" fill="#000" opacity=".07"/>`;
    return `<svg viewBox="0 0 420 272"><rect width="420" height="272" rx="6" fill="${c}"/>${stripes}
      <g fill="none" stroke="#f2fff4" stroke-width="2.6" opacity=".92"><rect x="12" y="12" width="396" height="248"/><path d="M210 12v248"/><circle cx="210" cy="136" r="35"/>
      <path d="M12 72h63v128H12M408 72h-63v128h63"/><path d="M12 108h21v56H12M408 108h-21v56h21"/><path d="M75 114a28 28 0 0 1 0 44M345 114a28 28 0 0 0 0 44"/>
      <path d="M12 122h-7v28h7M408 122h7v28h-7"/></g><g fill="#f2fff4"><circle cx="210" cy="136" r="3.5"/><circle cx="54" cy="136" r="2.5"/><circle cx="366" cy="136" r="2.5"/></g></svg>`;
  };
  window.COURT_SVG = (size, opts) => (COURT[size] || COURT.small)(opts || {});
  // cover: a field in perspective under floodlights
  window.COURT_ART = (size, opts) => `<div class="court-art">${(COURT[size] || COURT.small)(opts || {})}</div><div class="lights"></div>`;

  // sport icon (football only)
  window.SPORT_ICON = { football: "ball" };

  // ---------- Telegram bot messages (simulated) ----------
  // Stored by KH.notify as {phone, key, vars, at}; vars hold ids (venue, court, date, start, dur, code, price, t, team, reason)
  // and are turned into text in the reader's language here.
  I18N.add({
    "tg.request": ["📨 Request sent: {where}, {when}. The field will confirm soon.", "📨 Հայտն ուղարկված է՝ {where}, {when}։ Դաշտը շուտով կհաստատի։", "📨 Заявка отправлена: {where}, {when}. Поле скоро подтвердит."],
    "tg.accepted": ["✅ Confirmed: {where}, {when}. Booking code {code}. See you on the field!", "✅ Հաստատված է՝ {where}, {when}։ Ամրագրման կոդ՝ {code}։ Կհանդիպենք դաշտում։", "✅ Подтверждено: {where}, {when}. Код брони {code}. До встречи на поле!"],
    "tg.declined": ["❌ Sorry, the field can't take your booking: {where}, {when}.{reason}", "❌ Ցավոք, դաշտը չի կարող ընդունել ձեր ամրագրումը՝ {where}, {when}։{reason}", "❌ К сожалению, поле не может принять бронь: {where}, {when}.{reason}"],
    "tg.changed": ["✏️ The field changed your booking. Now: {where}, {when}, {price}.", "✏️ Դաշտը փոխել է ձեր ամրագրումը։ Հիմա՝ {where}, {when}, {price}։", "✏️ Поле изменило вашу бронь. Теперь: {where}, {when}, {price}."],
    "tg.cancelled": ["🚫 The field cancelled your booking: {where}, {when}.{reason}", "🚫 Դաշտը չեղարկել է ձեր ամրագրումը՝ {where}, {when}։{reason}", "🚫 Поле отменило вашу бронь: {where}, {when}.{reason}"],
    "tg.byOwner": ["📅 The field booked for you: {where}, {when}. Code {code}.", "📅 Դաշտը ամրագրել է ձեզ համար՝ {where}, {when}։ Կոդ՝ {code}։", "📅 Поле забронировало для вас: {where}, {when}. Код {code}."],
    "tg.youCancelled": ["You cancelled: {where}, {when}.", "Դուք չեղարկեցիք՝ {where}, {when}։", "Вы отменили: {where}, {when}."],
    "tg.regSent": ["🏆 {team} applied to {tour}. The organiser will confirm.", "🏆 {team}-ը հայտ է ներկայացրել {tour}-ին։ Կազմակերպիչը կհաստատի։", "🏆 {team} подали заявку на {tour}. Организатор подтвердит."],
    "tg.regAccepted": ["🏆 {team} is in {tour}! Entry fee {price} is paid at the venue before the first match.", "🏆 {team}-ը մասնակցում է {tour}-ին։ Մուտքավճարը՝ {price}, վճարվում է տեղում առաջին խաղից առաջ։", "🏆 {team} участвует в {tour}! Взнос {price} оплачивается на месте до первого матча."],
    "tg.regDeclined": ["{team} was not accepted to {tour}.{reason}", "{team}-ը չի ընդունվել {tour}-ին։{reason}", "{team} не принята в {tour}.{reason}"]
  });
  window.TG = {
    text(m) {
      const v = m.vars || {}, K = window.KH;
      const ct = K && v.court ? K.COURTS[v.court] : null;
      const where = (v.venue && K ? K.VENUE[v.venue].name : "") + (ct ? " · " + K.courtName(ct) + " " + ct.format : "");
      const when = v.date ? `${FMT.dateLong(new Date(v.date + "T00:00:00"))} ${FMT.time(v.start)}–${FMT.time(v.start + (v.dur || 60) / 60)}` : "";
      const tour = v.t && K && K.TOURNAMENT[v.t] ? tt(K.TOURNAMENT[v.t].name) : "";
      return T(m.key, { where, when, code: v.code || "", price: v.price != null ? FMT.amd(v.price) : "", team: v.team || "", tour, reason: v.reason ? " " + v.reason : "" });
    },
    // messages for a phone, newest first (messages sent in the same millisecond keep their order)
    list(phone) { return STORE.get("tg", []).map((m, i) => [m, i]).filter(x => x[0].phone === phone).sort((a, b) => b[0].at - a[0].at || b[1] - a[1]).map(x => x[0]); }
  };

  // ---------- header, tab bar, footer ----------
  const LOGO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17M3.5 12h17"/></svg>';
  window.renderChrome = function (current) {
    const links = [["index.html", "nav.courts", "grid", "courts"], ["tournaments.html", "nav.tournaments", "trophy", "tournaments"], ["games.html", "nav.games", "users", "games"], ["my.html", "nav.my", "ticket", "my"]];
    const keep = u => u + (q.get("lang") ? "?lang=" + lang : "");
    const prof = STORE.get("profile", null);
    const head = document.createElement("header"); head.className = "top";
    head.innerHTML = `<div class="wrap">
      <a class="logo" href="${keep("index.html")}"><span class="logo-mark">${LOGO}</span>${T("brand")}</a>
      <nav class="nav">${links.map(([u, k, i, id]) => `<a href="${keep(u)}"${id === current ? ' aria-current="page"' : ""}>${ICON(i)}${T(k)}</a>`).join("")}</nav>
      <div class="top-right">
        <a class="btn ghost sm" href="${keep("../owner/index.html")}">${ICON("chart", "sm")}<span>${T("nav.venue")}</span></a>
        <div class="lang" role="group" aria-label="Language">${LANGS.map(l => `<button data-l="${l}" aria-pressed="${l === lang}">${{ en: "EN", hy: "ՀԱՅ", ru: "РУ" }[l]}</button>`).join("")}</div>
        ${prof ? `<a class="avatar" href="${keep("my.html")}" title="${prof.name}">${prof.name.slice(0, 1).toUpperCase()}</a>` : ""}
      </div></div>`;
    head.querySelectorAll(".lang button").forEach(b => { b.onclick = () => I18N.set(b.dataset.l); });
    document.body.prepend(head);
    const bar = document.createElement("nav"); bar.className = "tabbar";
    bar.innerHTML = [["index.html", "nav.home", "home", "courts"], ["tournaments.html", "nav.tournaments", "trophy", "tournaments"], ["games.html", "nav.games", "users", "games"], ["my.html", "nav.myShort", "ticket", "my"]]
      .map(([u, k, i, id]) => `<a href="${keep(u)}"${id === current ? ' aria-current="page"' : ""}>${ICON(i, "lg")}${T(k)}</a>`).join("");
    document.body.append(bar);
    const foot = document.createElement("footer"); foot.className = "foot";
    foot.innerHTML = `<div class="wrap"><span>© 2026 ${T("brand")} · ${T("foot.city")}</span><span>${T("foot.demo")}</span></div>`;
    document.body.append(foot);
  };
  // links keep ?lang= so the language survives on file:// where storage may be off
  window.link = (u, params) => {
    const p = new URLSearchParams(params || {}); if (q.get("lang")) p.set("lang", lang);
    const s = p.toString(); return u + (s ? "?" + s : "");
  };

  window.toast = function (msg) {
    const el = document.createElement("div"); el.className = "toast"; el.setAttribute("role", "status");
    el.innerHTML = ICON("check") + msg; document.body.append(el); setTimeout(() => el.remove(), 2600);
  };

  // ---------- phone check before booking ----------
  // verifyPhone(done): asks for name + phone once, sends a code (here shown on screen), then calls done(profile).
  // A confirmed phone is remembered on this device, so the next booking skips the code.
  window.verifyPhone = function (done) {
    const prof = STORE.get("profile", null);
    if (prof && prof.verified) return done(prof);
    const scrim = document.createElement("div"); scrim.className = "scrim";
    const close = () => scrim.remove();
    scrim.onclick = e => { if (e.target === scrim) close(); };
    let data = { name: prof ? prof.name : "", phone: prof ? prof.phone : "", via: "telegram" }, code = "", tries = 3;
    function step1() {
      scrim.innerHTML = `<div class="sheet" role="dialog" aria-modal="true" aria-labelledby="vt">
        <div class="row" style="margin-bottom:6px"><h3 id="vt">${T("v.title")}</h3><button class="x" aria-label="${T("close")}">${ICON("x")}</button></div>
        <p class="muted" style="margin:0 0 18px;font-size:14px">${T("v.sub")}</p>
        <div class="field" style="margin-bottom:12px"><label for="vn">${T("v.name")}</label><input class="input" id="vn" autocomplete="name" value="${data.name}"></div>
        <div class="field" style="margin-bottom:6px"><label for="vp">${T("v.phone")}</label>
          <div class="row" style="gap:8px"><span class="input" style="width:auto;color:var(--muted)">+374</span><input class="input mono" id="vp" inputmode="tel" placeholder="91 234 567" value="${data.phone ? String(data.phone).slice(-8) : ""}"></div></div>
        <div class="faint" id="verr" style="font-size:12.5px;min-height:20px;color:var(--hot)"></div>
        <div class="row" style="margin-bottom:18px;gap:10px;padding:10px 12px;border-radius:12px;background:var(--blue-soft);color:var(--blue);font-size:13px;font-weight:700">${ICON("send", "sm")}${T("v.tgNote")}</div>
        <button class="btn block" id="vgo">${T("v.send")}</button></div>`;
      scrim.querySelector(".x").onclick = close;
      scrim.querySelector("#vgo").onclick = () => {
        data.name = scrim.querySelector("#vn").value.trim();
        const ph = scrim.querySelector("#vp").value.replace(/\D/g, "");
        if (!data.name || ph.length !== 8) { scrim.querySelector("#verr").textContent = T("v.badPhone"); return; }
        data.phone = "374" + ph; code = String(1000 + Math.floor(Math.random() * 9000)); tries = 3; step2();
      };
      scrim.querySelector("#vn").focus();
    }
    function step2() {
      scrim.innerHTML = `<div class="sheet" role="dialog" aria-modal="true" aria-labelledby="vt">
        <div class="row" style="margin-bottom:6px"><button class="x" style="margin:0" aria-label="${T("back")}" id="vb">${ICON("back")}</button><h3 id="vt" style="margin-left:6px">${T("v.title")}</h3><button class="x" aria-label="${T("close")}">${ICON("x")}</button></div>
        <p class="muted" style="font-size:14px">${T("v.enter", { phone: FMT.phone(data.phone) })}</p>
        <div class="code-inputs">${[0, 1, 2, 3].map(i => `<input inputmode="numeric" maxlength="1" aria-label="${i + 1}">`).join("")}</div>
        <p style="text-align:center;margin:14px 0 4px"><span class="pill lime">${T("v.demo", { code })}</span></p>
        <div id="verr" style="text-align:center;font-size:13px;color:var(--hot);min-height:20px"></div>
        <button class="btn ghost block" id="vr">${T("v.resend")}</button>
        <p class="faint" style="font-size:12px;text-align:center;margin:12px 0 0">${T("v.noTg")}</p></div>`;
      scrim.querySelector("#vb").onclick = step1;
      scrim.querySelector(".x:not(#vb)").onclick = close;
      scrim.querySelector("#vr").onclick = () => { code = String(1000 + Math.floor(Math.random() * 9000)); tries = 3; step2(); };
      const ins = [...scrim.querySelectorAll(".code-inputs input")];
      ins.forEach((inp, i) => {
        inp.oninput = () => {
          inp.value = inp.value.replace(/\D/g, "").slice(-1);
          if (inp.value && ins[i + 1]) ins[i + 1].focus();
          const v = ins.map(x => x.value).join("");
          if (v.length === 4) {
            if (v === code) {
              const p = { name: data.name, phone: data.phone, verified: true, via: data.via };
              STORE.set("profile", p); close(); toast(T("v.ok")); done(p);
            } else {
              tries--; ins.forEach(x => { x.value = ""; }); ins[0].focus();
              scrim.querySelector("#verr").textContent = tries > 0 ? T("v.wrong", { n: tries }) : "";
              if (tries <= 0) step1();
            }
          }
        };
        inp.onkeydown = e => { if (e.key === "Backspace" && !inp.value && ins[i - 1]) ins[i - 1].focus(); };
      });
      ins[0].focus();
    }
    document.body.append(scrim); step1();
  };
})();
