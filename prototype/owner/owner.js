// Owner app ("Bookball for fields"): layout (sidebar / bottom bar), sign-in check, and the booking sheets
// shared by the dashboard and the schedule. Loaded after ../player/common.js and data.js,
// so it uses the same storage (STORE) and the same bookings as the player app.
(function () {
  I18N.add({
    "o.brand": ["Bookball for fields", "Bookball Ակումբ", "Bookball для полей"],
    "o.brandShort": ["Fields", "Ակումբ", "Поля"],
    "o.nav.today": ["Today", "Այսօր", "Сегодня"],
    "o.nav.schedule": ["Schedule", "Ժամանակացույց", "Расписание"],
    "o.nav.tournaments": ["Tournaments", "Մրցաշարեր", "Турниры"],
    "o.nav.settings": ["Settings", "Կարգավորումներ", "Настройки"],
    "o.place": ["Your place", "Ձեր ակումբը", "Ваш клуб"],
    "o.placeDemo": ["Demo: you can switch between all places", "Դեմո․ կարող եք փոխել ցանկացած ակումբի", "Демо: можно переключать любой клуб"],
    "o.playerApp": ["Open player app", "Բացել խաղացողի հավելվածը", "Открыть приложение игрока"],
    "o.signOut": ["Sign out", "Դուրս գալ", "Выйти"],
    "o.newBooking": ["New booking", "Նոր ամրագրում", "Новая бронь"],
    "o.small": ["Small field", "Փոքր դաշտ", "Малое поле"],
    "o.big": ["Big field", "Մեծ դաշտ", "Большое поле"],
    // statuses
    "st.pending": ["Request", "Հայտ", "Заявка"],
    "st.accepted": ["Confirmed", "Հաստատված", "Подтверждено"],
    "st.owner": ["Added by you", "Ավելացրել եք դուք", "Добавлено вами"],
    "st.declined": ["Declined", "Մերժված", "Отклонено"],
    "st.cancelled": ["Cancelled", "Չեղարկված", "Отменено"],
    // actions
    "a.accept": ["Accept", "Ընդունել", "Принять"],
    "a.decline": ["Decline", "Մերժել", "Отклонить"],
    "a.edit": ["Edit", "Խմբագրել", "Изменить"],
    "a.cancelBooking": ["Cancel booking", "Չեղարկել ամրագրումը", "Отменить бронь"],
    "a.message": ["Message in Telegram", "Գրել Telegram-ով", "Написать в Telegram"],
    "a.call": ["Call", "Զանգել", "Позвонить"],
    "a.save": ["Save", "Պահպանել", "Сохранить"],
    "a.create": ["Create booking", "Ստեղծել ամրագրում", "Создать бронь"],
    "a.send": ["Send", "Ուղարկել", "Отправить"],
    "a.keep": ["Keep it", "Թողնել", "Оставить"],
    "t.accepted": ["Accepted · the player got a Telegram message", "Ընդունված է · խաղացողը ստացավ Telegram հաղորդագրություն", "Принято · игрок получил сообщение в Telegram"],
    "t.declined": ["Declined · the player was told in Telegram", "Մերժված է · խաղացողին տեղեկացրինք Telegram-ով", "Отклонено · игрок получил сообщение в Telegram"],
    "t.cancelled": ["Booking cancelled · the player was told in Telegram", "Ամրագրումը չեղարկված է · խաղացողին տեղեկացրինք Telegram-ով", "Бронь отменена · игрок получил сообщение в Telegram"],
    "t.saved": ["Saved · the player got the new time in Telegram", "Պահպանված է · խաղացողը ստացավ նոր ժամը Telegram-ով", "Сохранено · игрок получил новое время в Telegram"],
    "t.savedNoTg": ["Saved", "Պահպանված է", "Сохранено"],
    "t.created": ["Booking added", "Ամրագրումն ավելացված է", "Бронь добавлена"],
    "t.createdN": ["{n} bookings added", "Ավելացվեց {n} ամրագրում", "Добавлено броней: {n}"],
    "t.skipped": ["busy dates skipped: {n}", "բաց թողնված զբաղված օրեր՝ {n}", "пропущено занятых дат: {n}"],
    "t.msgSent": ["Message sent to Telegram (demo)", "Հաղորդագրությունն ուղարկված է Telegram (դեմո)", "Сообщение отправлено в Telegram (демо)"],
    // booking sheet
    "b.code": ["Code", "Կոդ", "Код"],
    "b.field": ["Field", "Դաշտ", "Поле"],
    "b.when": ["When", "Երբ", "Когда"],
    "b.duration": ["Duration", "Տևողություն", "Длительность"],
    "b.price": ["Price", "Գին", "Цена"],
    "b.phone": ["Phone", "Հեռախոս", "Телефон"],
    "b.team": ["Team", "Թիմ", "Команда"],
    "b.note": ["Note", "Նշում", "Заметка"],
    "b.source": ["Source", "Աղբյուր", "Источник"],
    "b.fromApp": ["Request from the Bookball app", "Հայտ Bookball հավելվածից", "Заявка из приложения Bookball"],
    "b.fromOwner": ["Added by the field", "Ավելացրել է դաշտը", "Добавлено полем"],
    "b.history": ["History", "Պատմություն", "История"],
    "b.tgHint": ["The player gets every change in Telegram", "Խաղացողը ամեն փոփոխություն ստանում է Telegram-ով", "Игрок получает каждое изменение в Telegram"],
    "b.noPhone": ["No phone, so no Telegram messages", "Հեռախոս չկա, ուստի Telegram հաղորդագրություններ չեն լինի", "Нет телефона, поэтому сообщений в Telegram не будет"],
    "b.weekly": ["Regular · every week", "Մշտական · ամեն շաբաթ", "Постоянная · каждую неделю"],
    "h.request": ["Request received", "Հայտը ստացվել է", "Заявка получена"],
    "h.created": ["Added by the field", "Ավելացվել է դաշտի կողմից", "Добавлено полем"],
    "h.accepted": ["Accepted", "Ընդունվել է", "Принято"],
    "h.declined": ["Declined", "Մերժվել է", "Отклонено"],
    "h.changed": ["Changed", "Փոփոխվել է", "Изменено"],
    "h.cancelled": ["Cancelled", "Չեղարկվել է", "Отменено"],
    "h.message": ["Message sent", "Հաղորդագրություն է ուղարկվել", "Отправлено сообщение"],
    // forms
    "f.newTitle": ["New booking", "Նոր ամրագրում", "Новая бронь"],
    "f.editTitle": ["Edit booking", "Խմբագրել ամրագրումը", "Изменить бронь"],
    "f.newSub": ["For phone calls, walk-ins and regular teams", "Զանգերի, այցելուների և մշտական թիմերի համար", "Для звонков, гостей без брони и постоянных команд"],
    "f.date": ["Date", "Ամսաթիվ", "Дата"],
    "f.start": ["Start", "Սկիզբ", "Начало"],
    "f.name": ["Name", "Անուն", "Имя"],
    "f.phone": ["Phone (for Telegram)", "Հեռախոս (Telegram-ի համար)", "Телефон (для Telegram)"],
    "f.optional": ["optional", "ոչ պարտադիր", "необязательно"],
    "f.priceAuto": ["Price, ֏ · auto from your prices, you can change it", "Գին, ֏ · ըստ ձեր գների, կարող եք փոխել", "Цена, ֏ · по вашим ценам, можно изменить"],
    "f.repeat": ["Repeat every week (regular team)", "Կրկնել ամեն շաբաթ (մշտական թիմ)", "Повторять каждую неделю (постоянная команда)"],
    "f.weeks": ["weeks", "շաբաթ", "недель"],
    "f.busy": ["This time is taken, already past or outside opening hours", "Այս ժամը զբաղված է, անցել է կամ աշխատանքային ժամերից դուրս է", "Это время занято, уже прошло или вне часов работы"],
    "f.needName": ["Enter a name", "Մուտքագրեք անունը", "Введите имя"],
    "f.badPhone": ["Phone: 8 digits after +374, or leave it empty", "Հեռախոս․ 8 թիվ +374-ից հետո, կամ թողեք դատարկ", "Телефон: 8 цифр после +374, или оставьте пустым"],
    "f.notify": ["The player will get the change in Telegram", "Խաղացողը փոփոխությունը կստանա Telegram-ով", "Игрок получит изменение в Telegram"],
    "f.free": ["Free", "Ազատ է", "Свободно"],
    "r.declineTitle": ["Decline the request?", "Մերժե՞լ հայտը", "Отклонить заявку?"],
    "r.cancelTitle": ["Cancel this booking?", "Չեղարկե՞լ այս ամրագրումը", "Отменить эту бронь?"],
    "r.reason": ["Reason for the player (optional)", "Պատճառը խաղացողի համար (ոչ պարտադիր)", "Причина для игрока (необязательно)"],
    "r.ph": ["e.g. The field is closed for maintenance", "օր.՝ Դաշտը փակ է վերանորոգման համար", "напр.: Поле закрыто на обслуживание"],
    "r.quick1": ["The field is taken at this time", "Այս ժամին դաշտը զբաղված է", "Поле в это время занято"],
    "r.quick2": ["Maintenance", "Տեխնիկական աշխատանքներ", "Техническое обслуживание"],
    "r.quick3": ["Please call us", "Խնդրում ենք զանգահարել մեզ", "Пожалуйста, позвоните нам"],
    "m.title": ["Message to {name}", "Հաղորդագրություն {name}-ին", "Сообщение для {name}"],
    "m.sub": ["Goes through the Bookball bot in Telegram (demo: it appears in the player app).", "Ուղարկվում է Bookball բոտի միջոցով Telegram-ում (դեմո․ երևում է խաղացողի հավելվածում)։", "Отправляется через бота Bookball в Telegram (демо: появится в приложении игрока)."],
    "m.ph": ["e.g. Please come 10 minutes early", "օր.՝ Խնդրում ենք գալ 10 րոպե շուտ", "напр.: Приходите, пожалуйста, на 10 минут раньше"],
    "ago.now": ["just now", "հենց հիմա", "только что"],
    "ago.min": ["{n} min ago", "{n} րոպե առաջ", "{n} мин назад"],
    "ago.h": ["{n} h ago", "{n} ժ առաջ", "{n} ч назад"],
    "ago.d": ["{n} d ago", "{n} օր առաջ", "{n} дн назад"]
  });

  const q = new URLSearchParams(location.search);
  const LOGO = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17M3.5 12h17"/></svg>';
  const esc = s => String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

  const O = { esc, LOGO };
  O.me = () => STORE.get("owner", null);
  O.venue = () => { const m = O.me(); return KH.VENUE[(m && m.venue) || "zangak"] || KH.VENUE.zangak; };
  O.setVenue = id => { const m = O.me() || {}; m.venue = id; STORE.set("owner", m); location.reload(); };
  O.court = (v, size) => v.courts.find(c => c.size === size);
  O.nowH = () => { const n = new Date(); return n.getHours() + n.getMinutes() / 60; };
  O.fieldName = ct => `${T(ct.size === "small" ? "o.small" : "o.big")} ${ct.format}`;
  O.price = b => (b.price != null && b.price !== "" ? +b.price : KH.priceFor(KH.COURTS[b.court], KH.dayOfIso(b.date), b.start, b.dur));
  O.vars = (b, extra) => Object.assign({ venue: b.venue, court: b.court, date: b.date, start: b.start, dur: b.dur, code: b.code, price: O.price(b) }, extra || {});
  // visual kind of a booking block
  O.kind = b => (b.status === "pending" ? "pending" : b.status === "accepted" ? (b.by === "owner" ? "owner" : "accepted") : "off");
  O.pill = b => {
    const k = O.kind(b), cls = { pending: "warn", accepted: "green", owner: "blue", off: "" }[k];
    return `<span class="pill ${cls}">${T(k === "off" ? "st." + b.status : "st." + k)}</span>`;
  };
  O.when = b => `${FMT.rel(DAY.date(KH.dayOfIso(b.date)))} · ${FMT.time(b.start)}–${FMT.time(b.start + b.dur / 60)}`;
  O.ago = ms => {
    const m = Math.round((Date.now() - ms) / 6e4);
    return m < 1 ? T("ago.now") : m < 60 ? T("ago.min", { n: m }) : m < 1440 ? T("ago.h", { n: Math.round(m / 60) }) : T("ago.d", { n: Math.round(m / 1440) });
  };
  // all bookings of a place for days [from, to]
  O.range = (v, from, to) => {
    const out = [];
    for (let d = from; d <= to; d++) v.courts.forEach(ct => KH.bookings(ct.id, d).forEach(b => out.push(b)));
    return out;
  };
  O.pending = v => O.range(v, -1, 45).filter(b => b.status === "pending" && (KH.dayOfIso(b.date) > 0 || (KH.dayOfIso(b.date) === 0 && b.start + b.dur / 60 > O.nowH())))
    .sort((a, b) => a.date.localeCompare(b.date) || a.start - b.start);

  // ---------- actions (change the booking + tell the player in Telegram) ----------
  O.refresh = () => location.reload();
  // a toast that survives the reload after a change
  const flash = msg => { try { sessionStorage.setItem("kh.ownerToast", msg); } catch (e) { toast(msg); } };
  O.flash = flash;
  function hist(b, k, note) { return (b.history || []).concat([{ at: Date.now(), k, note: note || "" }]); }
  O.accept = b => { KH.updateBooking(b.id, { status: "accepted", history: hist(b, "h.accepted") }); KH.notify(b.phone, "tg.accepted", O.vars(b)); flash(T("t.accepted")); O.refresh(); };
  O.decline = (b, reason) => { KH.updateBooking(b.id, { status: "declined", reason: reason || "", history: hist(b, "h.declined", reason) }); KH.notify(b.phone, "tg.declined", O.vars(b, { reason })); flash(T("t.declined")); O.refresh(); };
  O.cancel = (b, reason) => { KH.updateBooking(b.id, { status: "cancelled", reason: reason || "", history: hist(b, "h.cancelled", reason) }); KH.notify(b.phone, "tg.cancelled", O.vars(b, { reason })); flash(T("t.cancelled")); O.refresh(); };

  // ---------- sheets ----------
  O.sheet = function (html, cls) {
    const scrim = document.createElement("div"); scrim.className = "scrim";
    scrim.innerHTML = `<div class="sheet ${cls || ""}" role="dialog" aria-modal="true">${html}</div>`;
    const close = () => { scrim.remove(); document.removeEventListener("keydown", onKey); };
    const onKey = e => { if (e.key === "Escape") close(); };
    scrim.onclick = e => { if (e.target === scrim) close(); };
    scrim.querySelectorAll("[data-close]").forEach(x => { x.onclick = close; });
    document.addEventListener("keydown", onKey);
    document.body.append(scrim);
    return { el: scrim, close };
  };
  const head = (title, sub) => `<div class="row" style="margin-bottom:${sub ? 4 : 14}px;align-items:flex-start"><h3>${title}</h3><button class="x" data-close aria-label="${T("close")}">${ICON("x")}</button></div>${sub ? `<p class="muted" style="margin:0 0 16px;font-size:14px">${sub}</p>` : ""}`;

  // ask for an optional reason (decline / cancel)
  O.askReason = function (title, okLabel, done) {
    const s = O.sheet(`${head(title)}
      <div class="field"><label for="rr">${T("r.reason")}</label><textarea class="input" id="rr" rows="3" placeholder="${T("r.ph")}"></textarea></div>
      <div class="chips" style="margin:10px 0 18px;flex-wrap:wrap">${["r.quick1", "r.quick2", "r.quick3"].map(k => `<button class="chip" data-q="${k}">${T(k)}</button>`).join("")}</div>
      <div class="row"><button class="btn ghost" data-close style="flex:1">${T("a.keep")}</button><button class="btn danger" id="rok" style="flex:1">${okLabel}</button></div>`);
    s.el.querySelectorAll("[data-q]").forEach(c => { c.onclick = () => { s.el.querySelector("#rr").value = T(c.dataset.q); }; });
    s.el.querySelector("#rok").onclick = () => { const r = s.el.querySelector("#rr").value.trim(); s.close(); done(r); };
  };
  O.askDecline = b => O.askReason(T("r.declineTitle"), T("a.decline"), r => O.decline(b, r));
  O.askCancel = b => O.askReason(T("r.cancelTitle"), T("a.cancelBooking"), r => O.cancel(b, r));

  // message to the player through the Telegram bot (demo)
  O.message = function (b) {
    const v = KH.VENUE[b.venue];
    const s = O.sheet(`${head(T("m.title", { name: esc(b.name) }), T("m.sub"))}
      <div class="tg-hint">${ICON("send", "sm")} ${FMT.phone(b.phone)}</div>
      <textarea class="input" id="mt" rows="4" placeholder="${T("m.ph")}" style="margin:12px 0 16px"></textarea>
      <button class="btn block blue" id="mok">${ICON("send", "sm")}${T("a.send")}</button>`);
    s.el.querySelector("#mt").focus();
    s.el.querySelector("#mok").onclick = () => {
      const txt = s.el.querySelector("#mt").value.trim(); if (!txt) return;
      // a free-text message: the text itself is used as the message key (TG.text shows unknown keys as they are)
      KH.notify(b.phone, `💬 ${v.name}: ${txt.replace(/[{}]/g, "")}`, b.court ? O.vars(b) : { venue: b.venue });
      if (b.id && b.court) KH.updateBooking(b.id, { history: hist(b, "h.message", txt) });
      s.close(); toast(T("t.msgSent"));
    };
  };

  // booking details
  O.openBooking = function (id) {
    const b = KH.findBooking(id); if (!b) return;
    const ct = KH.COURTS[b.court], live = KH.active(b);
    const h = [{ at: b.created || 0, k: b.by === "owner" ? "h.created" : "h.request" }].concat(b.history || []);
    const row = (icon, label, val) => `<div class="kv">${ICON(icon, "sm")}<span>${label}</span><b>${val}</b></div>`;
    const s = O.sheet(`
      <div class="row" style="margin-bottom:12px">${O.pill(b)}<span class="pill mono">${esc(b.code)}</span>${b.series ? `<span class="pill">${T("b.weekly")}</span>` : ""}<button class="x" data-close aria-label="${T("close")}">${ICON("x")}</button></div>
      <h3 style="font-size:22px;margin-bottom:2px">${esc(b.name)}</h3>
      <div class="muted" style="font-size:14px;margin-bottom:14px">${b.team ? esc(b.team) + " · " : ""}${T(b.by === "owner" ? "b.fromOwner" : "b.fromApp")}</div>
      <div class="kvs">
        ${row("grid", T("b.field"), O.fieldName(ct))}
        ${row("calendar", T("b.when"), O.when(b))}
        ${row("clock", T("b.duration"), FMT.dur(b.dur))}
        ${row("wallet", T("b.price"), FMT.amd(O.price(b)))}
        ${row("phone", T("b.phone"), b.phone ? `<a class="tel" href="tel:+${esc(b.phone)}">${FMT.phone(b.phone)}</a>` : "—")}
        ${b.note ? row("msg", T("b.note"), esc(b.note)) : ""}
      </div>
      <div class="tg-hint" style="margin:12px 0 4px">${ICON("send", "sm")} ${T(b.phone ? "b.tgHint" : "b.noPhone")}</div>
      <div class="hist"><div class="kicker" style="margin:14px 0 8px">${T("b.history")}</div>
        ${h.map(e => `<div class="hrow"><i></i><span>${T(e.k)}${e.note ? ` · <span class="muted">${esc(e.note)}</span>` : ""}</span><span class="faint">${e.at ? O.ago(e.at) : ""}</span></div>`).join("")}</div>
      <div class="sheet-actions">
        ${b.status === "pending" ? `<button class="btn" id="sa">${ICON("check", "sm")}${T("a.accept")}</button><button class="btn danger" id="sd">${ICON("x", "sm")}${T("a.decline")}</button>` : ""}
        ${live ? `<button class="btn ghost" id="se">${T("a.edit")}</button>` : ""}
        ${b.status === "accepted" ? `<button class="btn ghost hot" id="sc">${T("a.cancelBooking")}</button>` : ""}
        ${b.phone ? `<button class="btn ghost" id="sm">${ICON("send", "sm")}${T("a.message")}</button><a class="btn ghost" href="tel:+${esc(b.phone)}">${ICON("phone", "sm")}${T("a.call")}</a>` : ""}
      </div>`, "wide");
    const on = (sel, fn) => { const x = s.el.querySelector(sel); if (x) x.onclick = () => { s.close(); fn(); }; };
    on("#sa", () => O.accept(b)); on("#sd", () => O.askDecline(b)); on("#se", () => O.openForm({ booking: b }));
    on("#sc", () => O.askCancel(b)); on("#sm", () => O.message(b));
  };

  // create / edit form. opts: {booking} to edit, or {court, day, start} to prefill a new one
  O.openForm = function (opts) {
    const v = O.venue(), b = opts.booking || null;
    const cur = b ? { court: b.court, day: KH.dayOfIso(b.date), start: b.start, dur: b.dur, name: b.name, phone: b.phone ? String(b.phone).slice(-8) : "", team: b.team || "", note: b.note || "", price: O.price(b) }
      : { court: opts.court || O.court(v, "small").id, day: opts.day != null ? opts.day : 0, start: opts.start != null ? opts.start : Math.max(v.open[0], Math.ceil(O.nowH())), dur: 0, name: "", phone: "", team: "", note: "", price: null };
    const venue = b ? KH.VENUE[b.venue] : v;
    if (!cur.dur) cur.dur = KH.SIZES[KH.COURTS[cur.court].size].defDur;
    let touched = !!b && b.price != null;
    const times = []; for (let t = venue.open[0]; t < venue.open[1]; t += 0.5) times.push(t);
    const durs = [60, 90, 120, 150, 180];
    const s = O.sheet(`${head(T(b ? "f.editTitle" : "f.newTitle"), b ? T(b.phone ? "f.notify" : "b.noPhone") : T("f.newSub"))}
      <div class="seg" id="ff" role="group" aria-label="${T("b.field")}">${venue.courts.map(ct => `<button type="button" data-c="${ct.id}" aria-pressed="${ct.id === cur.court}">${O.fieldName(ct)}</button>`).join("")}</div>
      <div class="fgrid">
        <div class="field"><label for="fd">${T("f.date")}</label><input class="input" type="date" id="fd" value="${KH.iso(cur.day)}"></div>
        <div class="field"><label for="fs">${T("f.start")}</label><select class="input" id="fs">${times.map(t => `<option value="${t}"${t === cur.start ? " selected" : ""}>${FMT.time(t)}</option>`).join("")}</select></div>
        <div class="field"><label for="fu">${T("b.duration")}</label><select class="input" id="fu">${durs.map(d => `<option value="${d}"${d === cur.dur ? " selected" : ""}>${FMT.dur(d)}</option>`).join("")}</select></div>
        <div class="field"><label for="fp">${T("f.priceAuto")}</label><input class="input mono" id="fp" inputmode="numeric"></div>
        <div class="field"><label for="fn">${T("f.name")}</label><input class="input" id="fn" value="${esc(cur.name)}" autocomplete="off"></div>
        <div class="field"><label for="fh">${T("f.phone")}</label><div class="row" style="gap:6px"><span class="input pre">+374</span><input class="input mono" id="fh" inputmode="tel" placeholder="91 234 567" value="${esc(cur.phone)}"></div></div>
        <div class="field"><label for="ft">${T("b.team")} <span class="faint">· ${T("f.optional")}</span></label><input class="input" id="ft" value="${esc(cur.team)}"></div>
        <div class="field"><label for="fo">${T("b.note")} <span class="faint">· ${T("f.optional")}</span></label><input class="input" id="fo" value="${esc(cur.note)}"></div>
      </div>
      ${b ? "" : `<label class="check"><input type="checkbox" id="fr"><span>${T("f.repeat")}</span><select class="input" id="fw" style="width:auto;padding:6px 30px 6px 10px" disabled>${[2, 4, 6, 8, 12].map(n => `<option value="${n}"${n === 4 ? " selected" : ""}>${n} ${T("f.weeks")}</option>`).join("")}</select></label>`}
      <div class="ferr" id="fe" role="alert"></div>
      <button class="btn block" id="fok">${T(b ? "a.save" : "a.create")}</button>`, "wide");
    const $ = id => s.el.querySelector(id);
    const read = () => ({ court: cur.court, day: KH.dayOfIso($("#fd").value || KH.iso(0)), start: +$("#fs").value, dur: +$("#fu").value });
    function update() {
      const r = read(), ct = KH.COURTS[r.court];
      if (!touched) $("#fp").value = KH.priceFor(ct, r.day, r.start, r.dur);
      const moved = !b || r.court !== b.court || r.day !== KH.dayOfIso(b.date) || r.start !== b.start || r.dur !== b.dur;
      const ok = !moved || KH.isFree(r.court, r.day, r.start, r.dur, b && b.id);
      $("#fe").innerHTML = ok ? (moved ? `<span class="ok">${ICON("check", "sm")}${T("f.free")}</span>` : "") : T("f.busy");
      return ok;
    }
    $("#fp").value = cur.price != null ? cur.price : "";
    $("#fp").oninput = () => { touched = true; };
    s.el.querySelectorAll("#ff button").forEach(x => { x.onclick = () => { cur.court = x.dataset.c; s.el.querySelectorAll("#ff button").forEach(y => y.setAttribute("aria-pressed", y === x)); update(); }; });
    ["#fd", "#fs", "#fu"].forEach(id => { $(id).onchange = update; });
    if ($("#fr")) $("#fr").onchange = () => { $("#fw").disabled = !$("#fr").checked; };
    update();
    $("#fok").onclick = () => {
      const r = read(), name = $("#fn").value.trim(), ph = $("#fh").value.replace(/\D/g, ""), price = Math.max(0, +String($("#fp").value).replace(/\D/g, "") || 0);
      if (!update()) return;
      if (!name) { $("#fe").textContent = T("f.needName"); $("#fn").focus(); return; }
      if (ph && ph.length !== 8) { $("#fe").textContent = T("f.badPhone"); $("#fh").focus(); return; }
      const phone = ph ? "374" + ph : "", ct = KH.COURTS[r.court];
      const data = { court: r.court, date: KH.iso(r.day), start: r.start, dur: r.dur, name, phone, team: $("#ft").value.trim(), note: $("#fo").value.trim(), price };
      s.close();
      if (b) {
        const nb = KH.updateBooking(b.id, Object.assign(data, { history: hist(b, "h.changed") }));
        if (nb.phone) KH.notify(nb.phone, "tg.changed", O.vars(nb));
        flash(T(nb.phone ? "t.saved" : "t.savedNoTg")); O.refresh(); return;
      }
      const weeks = $("#fr").checked ? +$("#fw").value : 1, series = weeks > 1 ? "s" + Date.now().toString(36) : undefined;
      let made = 0, skipped = 0;
      for (let w = 0; w < weeks; w++) {
        const d = r.day + w * 7;
        if (w > 0 && !KH.isFree(r.court, d, r.start, r.dur)) { skipped++; continue; }
        const p = touched ? price : KH.priceFor(ct, d, r.start, r.dur);
        const rec = KH.addBooking(Object.assign({}, data, { venue: ct.venue, date: KH.iso(d), price: p, status: "accepted", by: "owner", series }));
        if (phone) KH.notify(phone, "tg.byOwner", O.vars(rec));
        made++;
      }
      flash((made > 1 ? T("t.createdN", { n: made }) : T("t.created")) + (skipped ? " · " + T("t.skipped", { n: skipped }) : ""));
      O.refresh();
    };
  };

  // ---------- layout ----------
  const LANGS = { en: "EN", hy: "ՀԱՅ", ru: "РУ" };
  O.langSwitch = () => `<div class="lang" role="group" aria-label="Language">${Object.entries(LANGS).map(([l, n]) => `<button data-l="${l}" aria-pressed="${l === I18N.lang}">${n}</button>`).join("")}</div>`;
  O.bindLang = root => root.querySelectorAll(".lang button").forEach(b => { b.onclick = () => I18N.set(b.dataset.l); });

  // shell(current): checks sign-in, draws sidebar + phone bars, returns the <main> content element
  O.shell = function (current) {
    if (!O.me()) { location.replace(link("login.html")); return document.createElement("main"); }
    const me = O.me(), v = O.venue(), pend = O.pending(v).length;
    const nav = [["index.html", "o.nav.today", "home", "today"], ["bookings.html", "o.nav.schedule", "calendar", "schedule"], ["settings.html", "o.nav.settings", "settings", "settings"]];
    const badge = id => (id === "today" && pend ? `<span class="nbadge">${pend}</span>` : "");
    const venueSel = id => `<select class="input vsel" id="${id}" aria-label="${T("o.place")}">${KH.VENUES.map(x => `<option value="${x.id}"${x.id === v.id ? " selected" : ""}>${esc(x.name)}</option>`).join("")}</select>`;
    const side = document.createElement("aside"); side.className = "oside";
    side.innerHTML = `
      <a class="logo" href="${link("index.html")}"><span class="logo-mark">${LOGO}</span><span>${T("brand")}<small>${T("o.brandShort")}</small></span></a>
      <div class="field vbox"><label for="vs1">${T("o.place")}</label>${venueSel("vs1")}<span class="faint" style="font-size:11.5px">${T("o.placeDemo")}</span></div>
      <nav class="onav">${nav.map(([u, k, i, id]) => `<a href="${link(u)}"${id === current ? ' aria-current="page"' : ""}>${ICON(i)}<span>${T(k)}</span>${badge(id)}</a>`).join("")}</nav>
      <button class="btn block" id="sbnew">${ICON("plus", "sm")}${T("o.newBooking")}</button>
      <div class="oside-foot">
        ${O.langSwitch()}
        <a class="olink" href="${link("../player/index.html")}">${ICON("share", "sm")}${T("o.playerApp")}</a>
        <div class="ome"><span class="avatar">${esc((me.name || "?").slice(0, 1).toUpperCase())}</span><span><b>${esc(me.name)}</b><small>${FMT.phone(me.phone)}</small></span>
          <button class="x" id="so" title="${T("o.signOut")}" aria-label="${T("o.signOut")}">${ICON("back", "sm")}</button></div>
      </div>`;
    const top = document.createElement("header"); top.className = "otop";
    top.innerHTML = `<a class="logo-mark" href="${link("index.html")}" aria-label="${T("o.brand")}">${LOGO}</a>${venueSel("vs2")}${O.langSwitch()}
      <a class="x" href="${link("../player/index.html")}" title="${T("o.playerApp")}" aria-label="${T("o.playerApp")}">${ICON("share", "sm")}</a>`;
    const bar = document.createElement("nav"); bar.className = "tabbar";
    bar.innerHTML = nav.map(([u, k, i, id]) => `<a href="${link(u)}"${id === current ? ' aria-current="page"' : ""}><span class="tb-ico">${ICON(i, "lg")}${badge(id)}</span>${T(k)}</a>`).join("");
    const main = document.createElement("main"); main.className = "omain";
    document.body.classList.add("owner");
    document.body.prepend(side, top); document.body.append(main, bar);
    [side, top].forEach(r => { O.bindLang(r); r.querySelector(".vsel").onchange = e => O.setVenue(e.target.value); });
    side.querySelector("#so").onclick = O.signOut;
    side.querySelector("#sbnew").onclick = () => O.openForm({});
    setTimeout(() => { try { const m = sessionStorage.getItem("kh.ownerToast"); if (m) { sessionStorage.removeItem("kh.ownerToast"); toast(m); } } catch (e) {} }, 50);
    return main;
  };
  O.signOut = () => { localStorage.removeItem("kh.owner"); location.href = link("login.html"); };
  O.title = (kicker, title, right) => `<div class="phead"><div><div class="kicker">${kicker}</div><h1>${title}</h1></div>${right ? `<div class="phead-r">${right}</div>` : ""}</div>`;

  window.OWNER = O;
  // not signed in: go to the sign-in page and stop loading this one (the page script below is not run)
  if (!O.me() && !/login\.html$/.test(location.pathname)) { window.stop(); location.replace(link("login.html")); }
})();
