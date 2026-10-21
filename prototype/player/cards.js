// Cards and small helpers shared by several pages: venue, open game, tournament, status pills.
I18N.add({
  "card.next": ["Next free", "Մոտակա ազատ", "Ближайшее свободное"],
  "card.noFree": ["Fully booked this day", "Այս օրը ամբողջությամբ զբաղված է", "На этот день всё занято"],
  "card.noFreeToday": ["No free hours left today", "Այսօր ազատ ժամ այլևս չկա", "Сегодня свободных часов больше нет"],
  "card.spot1": ["Last spot", "Վերջին տեղը", "Последнее место"],
  "card.spots": ["{n} spots left", "Մնացել է {n} տեղ", "Осталось мест: {n}"],
  "card.full": ["Full", "Լրացված է", "Мест нет"],
  "card.per": ["per player", "մեկ խաղացողի համար", "с игрока"],
  "card.host": ["Host", "Կազմակերպիչ", "Организатор"],
  "card.joined": ["You're in", "Դուք միացել եք", "Вы в игре"],
  "card.teams": ["Teams", "Թիմեր", "Команды"],
  "card.fee": ["Entry", "Մուտքավճար", "Взнос"],
  "card.prize": ["Prize", "Մրցանակ", "Приз"],
  "card.perTeam": ["/ team", "/ թիմ", "/ команда"],
  "card.places": ["{a} of {b} places taken", "Զբաղված է {a}-ը {b}-ից", "Занято {a} из {b}"],
  "card.amateur": ["Amateur", "Սիրողական", "Любительский"],
  "card.startsOn": ["Starts {d}", "Մեկնարկ՝ {d}", "Старт {d}"],
  "card.closesOn": ["Sign-up until {d}", "Գրանցում՝ մինչև {d}", "Запись до {d}"],
  "card.champion": ["Champion: {n}", "Չեմպիոն՝ {n}", "Чемпион: {n}"],
  "card.fields": ["5×5 and 11×11", "5×5 և 11×11", "5×5 и 11×11"],
  // booking statuses
  "bs.pending": ["Waiting for the field", "Սպասում է դաշտի հաստատմանը", "Ждёт подтверждения"],
  "bs.accepted": ["Confirmed", "Հաստատված", "Подтверждено"],
  "bs.declined": ["Declined", "Մերժված", "Отклонено"],
  "bs.cancelled": ["Cancelled", "Չեղարկված", "Отменено"],
  // team registration statuses
  "rs.pending": ["Request sent · waiting", "Հայտն ուղարկված է · սպասում է", "Заявка отправлена · ждёт"],
  "rs.accepted": ["Team accepted", "Թիմն ընդունված է", "Команда принята"],
  "rs.declined": ["Not accepted", "Չի ընդունվել", "Не принята"]
});

// field size colours (small 5×5 = green, big 11×11 = lime)
window.SIZE_COLOR = { small: "#5ef0a8", big: "#c8ff3d" };
const sizeLabel = size => `${tt(KH.SIZES[size].name)} ${KH.SIZES[size].format}`;
const myPhone = () => { const p = STORE.get("profile", null); return p ? p.phone : null; };

function bookingStatusPill(status) {
  const m = { pending: ["warn", "clock"], accepted: ["green", "check"], declined: ["hot", "x"], cancelled: ["", "x"] }[status] || ["", "clock"];
  return `<span class="pill ${m[0]}">${ICON(m[1], "sm")}${T("bs." + status)}</span>`;
}
function regStatusPill(status) {
  const m = { pending: ["warn", "clock"], accepted: ["green", "check"], declined: ["hot", "x"] }[status] || ["warn", "clock"];
  return `<span class="pill ${m[0]}">${ICON(m[1], "sm")}${T("rs." + status)}</span>`;
}
// this user's team sign-up for a tournament (matched by the confirmed phone)
function myRegFor(t) {
  const ph = myPhone(); if (!ph) return null;
  const rs = t.regs.filter(r => r.phone === ph).sort((a, b) => (+new Date(b.created) || 0) - (+new Date(a.created) || 0));
  return rs[0] || null;
}

// venue card: cover, both fields, price and the next free hours of each field
// o: {size, day, time: "morning"|"day"|"evening"}
const inTimeOf = (h, t) => !t || (t === "morning" ? h < 12 : t === "day" ? h >= 12 && h < 18 : h >= 18);
function venueFreeSlots(v, size, day, time, n) {
  const ct = v.courts.find(c => c.size === size), dur = KH.SIZES[size].defDur, now = new Date();
  const from = day === 0 ? Math.ceil(now.getHours() + now.getMinutes() / 60) : v.open[0], out = [];
  for (let t = Math.max(from, v.open[0]); t < v.open[1] && out.length < n; t += 1) if (inTimeOf(t, time) && KH.isFree(ct.id, day, t, dur)) out.push(t);
  return out;
}
function venueCard(v, o) {
  o = o || {};
  const day = o.day || 0, sizes = o.size ? [o.size] : ["small", "big"];
  const small = v.courts.find(c => c.size === "small"), big = v.courts.find(c => c.size === "big");
  const href = link("venue.html", Object.assign({ v: v.id }, o.size ? { size: o.size } : {}, day ? { d: day } : {}));
  const rows = sizes.map(s => {
    const sl = o.slots && o.slots[s] ? o.slots[s] : venueFreeSlots(v, s, day, o.time, o.size ? 6 : 4);
    return `<div class="fslots"><span class="fz" style="color:${SIZE_COLOR[s]}">${KH.SIZES[s].format}</span>${sl.length ? sl.map(t => `<span class="slot-mini">${FMT.time(t)}</span>`).join("") : `<span class="faint" style="font-size:12.5px">${T(day === 0 ? "card.noFreeToday" : "card.noFree")}</span>`}</div>`;
  }).join("");
  return `<a class="card vcard" href="${href}">
    <div class="art">${COURT_ART(o.size || "big", { color: big.surface === "grass" && o.size !== "small" ? "#2b7d3f" : undefined })}<div class="badges">
      <span class="pill">${ICON(small.indoor ? "roof" : "sun", "sm")}5×5</span><span class="pill">${ICON("ball", "sm")}11×11${big.surface === "grass" ? " · " + tt(KH.SURFACES.grass) : ""}</span></div></div>
    <div class="body">
      <div class="row between"><h3>${v.name}</h3><span class="rating">${ICON("star", "sm")}${v.rating}</span></div>
      <div class="meta"><span>${ICON("pin", "sm")}${tt(KH.DISTRICTS[v.district])}</span><span>${ICON("clock", "sm")}${FMT.time(v.open[0])}–${FMT.time(v.open[1])}</span>
        <span class="price">${T("from")} ${FMT.amd(KH.minPrice(v, o.size))} <small>${T("perHour")}</small></span></div>
      <div class="slots">${rows}</div>
    </div></a>`;
}

function gameCard(g) {
  const v = KH.VENUE[g.venue], left = g.need - g.joined, mine = STORE.get("joined", []).includes(g.id), c = SIZE_COLOR[g.size];
  return `<a class="card game" href="${link("games.html", { g: g.id })}" style="--tc:${c}">
    <div class="row between"><span class="pill" style="color:${c}">${ICON("ball", "sm")}${g.format} · ${tt(KH.SIZES[g.size].name)}</span><span class="pill ${left <= 0 ? "" : left <= 2 ? "hot" : "green"}">${mine ? T("card.joined") : left <= 0 ? T("card.full") : left === 1 ? T("card.spot1") : T("card.spots", { n: left })}</span></div>
    <div><h3>${FMT.rel(DAY.date(g.day))} · ${FMT.time(g.start)}</h3><div class="muted" style="font-size:13.5px">${v.name} · ${tt(KH.LEVELS[g.level])}</div></div>
    <div class="muted" style="font-size:13.5px">${tt(g.note)}</div>
    <div class="spots${g.need > 12 ? " many" : ""}">${Array.from({ length: g.need }, (_, i) => `<i class="${i < g.joined ? "on" : ""}"></i>`).join("")}</div>
    <div class="row between"><span class="price">${FMT.amd(g.pricePer)} <small>${T("card.per")}</small></span><span class="faint" style="font-size:12.5px">${T("card.host")}: ${g.host.split(" ")[0]}</span></div></a>`;
}

function tourStatusPill(t) {
  const cls = { open: "lime", soon: "warn", closed: "blue", done: "" }[t.status];
  return `<span class="pill ${cls}">${tt(KH.STATUS[t.status])}</span>`;
}
function tourDates(t) { return t.start === t.end ? FMT.date(DAY.date(t.start)) : `${FMT.date(DAY.date(t.start))} – ${FMT.date(DAY.date(t.end))}`; }
function tourChampion(t) {
  if (t.status !== "done") return null;
  if (t.bracket) { const f = t.bracket[t.bracket.length - 1][0]; return f && f.winner; }
  if (t.groups && t.groups[0] && t.groups[0].rows[0]) return t.groups[0].rows[0].team;
  return null;
}
function tourCard(t) {
  const v = KH.VENUE[t.venue], n = t.entrants.length, c = SIZE_COLOR[t.size], mine = myRegFor(t), ch = tourChampion(t);
  const foot = t.status === "open" || t.status === "soon"
    ? `<div><div class="bar"><i style="width:${Math.min(100, Math.round(n / t.max * 100))}%"></i></div><div class="row between faint" style="font-size:12px;margin-top:6px"><span>${T("card.places", { a: n, b: t.max })}</span><span>${T("card.closesOn", { d: FMT.date(DAY.date(t.regEnd)) })}</span></div></div>`
    : t.status === "closed" ? `<div class="faint" style="font-size:12.5px;font-weight:700">${ICON("calendar", "sm")} ${T("card.startsOn", { d: FMT.dateLong(DAY.date(t.start)) })}</div>`
    : ch ? `<div style="font-size:13px;font-weight:800;color:#ffd25a">🏆 ${T("card.champion", { n: ch })}</div>` : "";
  return `<a class="card tcard" href="${link("tournament.html", { t: t.id })}">
    <div class="glow" style="background:${c}"></div>
    <div class="row between wrapflex" style="gap:6px"><span class="pill" style="color:${c}">${ICON("ball", "sm")}${t.format5} · ${tt(KH.FORMATS[t.format])}</span>${tourStatusPill(t)}</div>
    <div><h3 style="font-size:18px">${tt(t.name)}</h3><div class="muted" style="font-size:13.5px;margin-top:4px">${ICON("pin", "sm")} ${v.name} · ${tourDates(t)}</div></div>
    <div class="facts">
      <div><span>${T("card.teams")}</span><b>${n}/${t.max}</b></div>
      <div><span>${T("card.fee")}</span><b>${FMT.short(t.fee)}</b></div>
      <div><span>${T("card.prize")}</span><b>${t.prize ? FMT.short(t.prize) : `<span style="color:var(--warn)">${ICON("trophy", "sm")}</span>`}</b></div>
    </div>
    ${mine ? `<div class="row wrapflex" style="gap:6px">${regStatusPill(mine.status)}<span class="faint" style="font-size:12.5px;font-weight:700">${String(mine.name).replace(/[&<>"]/g, "")}</span></div>` : ""}
    ${foot}
  </a>`;
}
