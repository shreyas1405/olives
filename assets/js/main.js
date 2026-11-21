/* Herbs n Olives: front-end behaviour. No dependencies. */
(function () {
  'use strict';

  // ---------- Settings ----------
  const CONFIG = {
    endpoint: 'api/reserve.php',
    open: '11:30',             // opening time (IST)
    close: '23:00',            // closing time (IST)
    lastDineIn: '22:00',       // last table slot
    lastTakeaway: '22:30',     // last pickup slot
    slotMinutes: 30,
    leadDineIn: 60,            // minutes' notice needed for a table
    leadTakeaway: 30,          // minutes' notice needed for a pickup
    maxGuests: 20,
    daysAhead: 60
  };

  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ---------- India time helpers (works whatever the visitor's timezone) ----------
  function istNow() {
    const p = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Kolkata', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false })
      .formatToParts(new Date()).reduce((a, x) => (a[x.type] = x.value, a), {});
    return { date: `${p.year}-${p.month}-${p.day}`, minutes: (+p.hour % 24) * 60 + +p.minute };
  }
  const toMin = t => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
  const toHHMM = m => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
  const pretty = m => { let h = Math.floor(m / 60), mm = m % 60, ap = h >= 12 ? 'PM' : 'AM'; h = h % 12 || 12; return `${h}:${String(mm).padStart(2, '0')} ${ap}`; };
  const addDays = (iso, n) => { const d = new Date(iso + 'T00:00:00Z'); d.setUTCDate(d.getUTCDate() + n); return d.toISOString().slice(0, 10); };
  const prettyDate = iso => new Date(iso + 'T00:00:00Z').toLocaleDateString('en-IN', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

  // ---------- Open / closed ----------
  (function openStatus() {
    const { minutes } = istNow(); const dot = $('#open-dot'), txt = $('#open-text');
    if (!dot) return;
    const o = toMin(CONFIG.open), c = toMin(CONFIG.close);
    if (minutes >= o && minutes < c) { txt.textContent = `Open now · until ${pretty(c)}`; }
    else { dot.classList.add('closed'); txt.textContent = `Closed now · opens ${pretty(o)}`; }
  })();
  const yr = $('#year'); if (yr) yr.textContent = istNow().date.slice(0, 4);

  // ---------- Mobile nav ----------
  const toggle = $('#nav-toggle'), links = $('#nav-links');
  if (toggle) {
    toggle.addEventListener('click', () => { const o = links.classList.toggle('open'); toggle.setAttribute('aria-expanded', o); });
    $$('a', links).forEach(a => a.addEventListener('click', () => { links.classList.remove('open'); toggle.setAttribute('aria-expanded', 'false'); }));
  }

  // ---------- Lightbox ----------
  const lb = $('#lightbox');
  function openLightbox(src, cap) { if (!lb) return; $('#lb-img').src = src; $('#lb-img').alt = cap; $('#lb-cap').textContent = cap; lb.classList.add('open'); $('#lb-close').focus(); }
  function closeLightbox() { lb.classList.remove('open'); }
  if (lb) {
    $('#lb-close').addEventListener('click', closeLightbox);
    lb.addEventListener('click', e => { if (e.target === lb) closeLightbox(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });
  }

  const getJSON = url => fetch(url, { cache: 'no-cache' }).then(r => { if (!r.ok) throw new Error(r.status); return r.json(); });

  // ---------- Menu ----------
  (function menu() {
    const tabs = $('#menu-tabs'), panel = $('#menu-panel');
    if (!tabs) return;
    getJSON('data/menu.json').then(({ categories, items }) => {
      tabs.innerHTML = categories.map((c, i) =>
        `<button class="menu-tab" role="tab" id="tab-${esc(c.id)}" data-cat="${esc(c.id)}" aria-selected="${i === 0}">${esc(c.title)}</button>`).join('');
      const show = id => {
        const cat = categories.find(c => c.id === id);
        $$('.menu-tab', tabs).forEach(t => t.setAttribute('aria-selected', t.dataset.cat === id));
        const rows = items.filter(d => d.cat === id);
        panel.setAttribute('aria-labelledby', 'tab-' + id);
        panel.innerHTML = `<div class="menu-panel-head"><h3>${esc(cat.title)}</h3><p>${esc(cat.sub || '')}</p></div>
          <div class="menu-list">${rows.map(d => {
            const src = d.image ? `images/menu/${d.image}.jpg` : '';
            const thumb = src ? `<button class="dish-thumb" type="button" data-src="${esc(src)}" data-cap="${esc(d.name)}" aria-label="View photo of ${esc(d.name)}"><img src="${esc(src)}" alt="" loading="lazy"></button>` : '';
            return `<div class="dish">${thumb}<div class="dish-body">
              <div class="dish-top"><span class="dish-name">${esc(d.name)}</span><span class="dish-dots"></span><span class="dish-price">${esc(d.price)}</span></div>
              ${d.note ? `<div class="dish-note">${esc(d.note)}</div>` : ''}
              ${d.desc ? `<p class="dish-desc">${esc(d.desc)}</p>` : ''}</div></div>`;
          }).join('')}</div>`;
      };
      tabs.addEventListener('click', e => { const b = e.target.closest('.menu-tab'); if (b) show(b.dataset.cat); });
      tabs.addEventListener('keydown', e => {
        if (!['ArrowRight', 'ArrowLeft'].includes(e.key)) return;
        const all = $$('.menu-tab', tabs), i = all.indexOf(document.activeElement); if (i < 0) return;
        const n = all[(i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length]; n.focus(); show(n.dataset.cat);
      });
      panel.addEventListener('click', e => { const b = e.target.closest('.dish-thumb'); if (b) openLightbox(b.dataset.src, b.dataset.cap); });
      show(categories[0].id);
    }).catch(() => { panel.innerHTML = '<p class="center">Our menu could not load just now. Please call us on <a href="tel:+919740280453">097402 80453</a>.</p>'; });
  })();

  // ---------- Gallery ----------
  (function gallery() {
    const grid = $('#gallery-grid'); if (!grid) return;
    getJSON('data/gallery.json').then(({ photos }) => {
      grid.innerHTML = photos.map(p => p.src
        ? `<figure class="g-tile ${esc(p.size)}"><button type="button" data-src="${esc(p.src)}" data-cap="${esc(p.caption)}" aria-label="Enlarge: ${esc(p.caption)}"><img src="${esc(p.src)}" alt="${esc(p.caption)}" loading="lazy"></button><figcaption>${esc(p.caption)}</figcaption></figure>`
        : `<figure class="g-tile blank ${esc(p.size)}" aria-hidden="true"><figcaption>${esc(p.caption)}</figcaption></figure>`).join('');
      grid.addEventListener('click', e => { const b = e.target.closest('button[data-src]'); if (b) openLightbox(b.dataset.src, b.dataset.cap); });
    }).catch(() => { grid.closest('section').hidden = true; });
  })();

  // ---------- Reviews ----------
  (function reviews() {
    const row = $('#ratings-row'), groups = $('#review-groups'); if (!row) return;
    const stars = n => '★★★★★'.slice(0, n) + '☆☆☆☆☆'.slice(0, 5 - n);
    const label = { google: 'Google', zomato: 'Zomato' };
    getJSON('data/reviews.json').then(data => {
      row.innerHTML = ['google', 'zomato'].map(k => {
        const s = data[k]; if (!s) return '';
        const score = s.rating ? `<div class="score">${s.rating.toFixed(1)}</div>` : `<div class="score na">—</div>`;
        return `<div class="rating-card">${score}<div>
          <div class="src">${label[k]} ${s.rating ? `<span class="stars">${stars(Math.round(s.rating))}</span>` : ''}</div>
          <div class="meta">${s.count ? esc(s.count) + ' reviews' : 'Rated by our guests'}</div>
          <a href="${esc(s.url)}" target="_blank" rel="noopener">Read all on ${label[k]} →</a></div></div>`;
      }).join('');
      groups.innerHTML = ['google', 'zomato'].map(k => {
        const list = (data[k] && data[k].reviews) || [];
        if (!list.length) return '';
        return `<h3 class="review-group-title">From ${label[k]}</h3><div class="review-grid">${list.map(r => `
          <article class="review"><span class="stars" aria-label="${r.stars} out of 5 stars">${stars(r.stars)}</span>
            <p>${esc(r.text)}</p>
            <footer><span class="avatar" aria-hidden="true">${esc(r.name.trim()[0] || '?')}</span>
              <span class="who"><b>${esc(r.name)}</b><span>${label[k]} review${r.date ? ' · ' + esc(r.date) : ''}</span></span></footer></article>`).join('')}</div>`;
      }).join('');
    }).catch(() => { row.closest('section').hidden = true; });
  })();

  // ---------- Booking form ----------
  (function booking() {
    const form = $('#booking-form'); if (!form) return;
    const state = { mode: 'dinein', guests: 2, time: '' };
    const dateIn = $('#f-date'), slotsEl = $('#slots'), err = $('#form-error'), btn = $('#submit-btn');
    const today = istNow().date;
    dateIn.min = today; dateIn.max = addDays(today, CONFIG.daysAhead); dateIn.value = today;

    function setMode(mode) {
      state.mode = mode; $('#f-mode').value = mode;
      $$('.mode-switch button').forEach(b => b.setAttribute('aria-pressed', b.dataset.mode === mode));
      $$('[data-for]', form).forEach(el => { el.hidden = el.dataset.for !== mode; });
      const take = mode === 'takeaway';
      $('#time-label').textContent = take ? 'Pickup time' : 'Arrival time';
      $('#date-label').textContent = take ? 'Pickup date' : 'Date';
      btn.textContent = take ? 'Place Takeaway Request' : 'Request Reservation';
      renderSlots();
    }
    $$('.mode-switch button').forEach(b => b.addEventListener('click', () => setMode(b.dataset.mode)));

    function setGuests(n) {
      state.guests = Math.max(1, Math.min(CONFIG.maxGuests, n));
      $('#f-guests').value = state.guests;
      $('#g-out').textContent = state.guests + (state.guests === 1 ? ' guest' : ' guests') + (state.guests === CONFIG.maxGuests ? '+' : '');
    }
    $('#g-minus').addEventListener('click', () => setGuests(state.guests - 1));
    $('#g-plus').addEventListener('click', () => setGuests(state.guests + 1));

    function renderSlots() {
      const take = state.mode === 'takeaway';
      const first = toMin(take ? CONFIG.open : '12:00'), last = toMin(take ? CONFIG.lastTakeaway : CONFIG.lastDineIn);
      const now = istNow(), lead = take ? CONFIG.leadTakeaway : CONFIG.leadDineIn;
      const isToday = dateIn.value === now.date;
      const out = [];
      for (let m = first; m <= last; m += CONFIG.slotMinutes) {
        if (isToday && m < now.minutes + lead) continue; // too soon: hide it
        out.push(`<button type="button" class="slot" data-t="${toHHMM(m)}" aria-pressed="${state.time === toHHMM(m)}">${pretty(m)}</button>`);
      }
      slotsEl.innerHTML = out.length ? out.join('') : `<p class="slots-empty">No more slots today. Please pick another date or call us.</p>`;
      if (!slotsEl.querySelector(`.slot[aria-pressed="true"]`)) { state.time = ''; $('#f-time').value = ''; }
    }
    slotsEl.addEventListener('click', e => {
      const b = e.target.closest('.slot'); if (!b || b.disabled) return;
      state.time = b.dataset.t; $('#f-time').value = state.time;
      $$('.slot', slotsEl).forEach(s => s.setAttribute('aria-pressed', s === b));
    });
    dateIn.addEventListener('change', renderSlots);

    const showErr = m => { err.textContent = m; err.classList.add('show'); err.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); };
    const normPhone = p => { const d = p.replace(/\D/g, '').replace(/^(91|0)(?=\d{10}$)/, ''); return /^[6-9]\d{9}$/.test(d) ? d : null; };

    form.addEventListener('submit', async e => {
      e.preventDefault(); err.classList.remove('show');
      const fd = Object.fromEntries(new FormData(form).entries());
      if (!fd.date || fd.date < today) return showErr('Please choose a date from today onwards.');
      if (!fd.time) return showErr(`Please pick ${state.mode === 'takeaway' ? 'a pickup' : 'an arrival'} time.`);
      if (!fd.name.trim()) return showErr('Please tell us your name.');
      const phone = normPhone(fd.phone || '');
      if (!phone) return showErr('Please enter a valid 10-digit Indian mobile number.');
      if (state.mode === 'takeaway' && !fd.items.trim()) return showErr('Please tell us what you would like to pick up.');
      fd.phone = phone;
      if (state.mode !== 'dinein') delete fd.guests;
      if (state.mode !== 'takeaway') delete fd.items;

      btn.disabled = true; const label = btn.textContent; btn.textContent = 'Sending…';
      try {
        const res = await fetch(CONFIG.endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(fd) });
        const out = await res.json().catch(() => ({}));
        if (!res.ok || !out.ok) throw new Error(out.error || 'Something went wrong.');
        showSuccess(fd, out.ref);
      } catch (x) {
        showErr((x.message && x.message !== 'Failed to fetch' ? x.message + ' ' : '') + 'If it keeps happening, please call us on 097402 80453.');
      } finally { btn.disabled = false; btn.textContent = label; }
    });

    function showSuccess(d, ref) {
      const take = d.mode === 'takeaway';
      $('#success-title').textContent = take ? 'Grazie! Your takeaway request is in.' : 'Grazie! Your table request is in.';
      $('#summary').innerHTML = [
        ['Name', d.name], ['Phone', d.phone],
        [take ? 'Pickup' : 'Arriving', `${prettyDate(d.date)} at ${pretty(toMin(d.time))}`],
        !take && ['Guests', d.guests], take && ['Order', d.items], d.notes && ['Notes', d.notes], ref && ['Reference', ref]
      ].filter(Boolean).map(([k, v]) => `<div><b>${k}:</b> ${esc(v)}</div>`).join('');
      form.hidden = true; $('.mode-switch').hidden = true; const s = $('#success'); s.hidden = false; s.focus();
    }
    $('#book-again').addEventListener('click', () => {
      form.reset(); dateIn.value = today; setGuests(2); state.time = '';
      $('#success').hidden = true; form.hidden = false; $('.mode-switch').hidden = false; setMode(state.mode);
    });

    setGuests(2); setMode('dinein');
  })();

  // ---------- Reveal on scroll ----------
  if ('IntersectionObserver' in window) {
    const io = new IntersectionObserver(es => es.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }), { threshold: .12 });
    $$('.reveal').forEach(el => io.observe(el));
  } else { $$('.reveal').forEach(el => el.classList.add('in')); }
})();
