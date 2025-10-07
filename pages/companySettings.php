<?php
require_once("../includes/header.php");
$csrf = generateCsrfToken();
?>
<!-- companySettings.php -->


<meta name="csrf-token" content="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

<style>
  .roleSub{font-size:.9em;color:#666;}
  .iconButton{display:inline-flex;align-items:center;justify-content:center}
  .toolbarIcon{display:block}
  .flash{animation:flash 1.2s ease-out 1}
  @keyframes flash{0%{background:#fff9c4}100%{background:transparent}}
  #billingCta .cta-wrap{
	margin-left:auto;display:flex;align-items:flex-end;gap:.75rem;flex-wrap:wrap;
	border:1px solid #e5e7eb;border-radius:10px;padding:.6rem .8rem;background:#fff;
  }
  #billingCta .cta-lines{display:grid;gap:.25rem}
  #billingCta .muted{color:#666;font-size:.95em}
  #billingCta button{padding:.5rem .8rem}
  @media (max-width:600px){
	#billingCta .cta-wrap{align-items:stretch}
	#billingCta button{width:100%}
  }
  table.mini { width:100%; border-collapse:collapse; }
  table.mini th, table.mini td { padding:.35rem .5rem; border-bottom:1px solid #eee; }
  table.mini th { text-align:left; font-weight:600; }
  table.mini .right { text-align:right; }
</style>

<script>


// ===== Icons (inline SVG) =====
const ICONS = {
  edit: `
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
	   stroke="currentColor" fill="none" stroke-width="1.5"
	   width="1em" height="1em" class="toolbarIcon">
	<path stroke-linecap="round" stroke-linejoin="round"
		  d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.651 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125"/>
  </svg>`,
  remove: `
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
	   stroke="currentColor" fill="none" stroke-width="1.5"
	   width="1em" height="1em" class="toolbarIcon">
	<path stroke-linecap="round" stroke-linejoin="round"
		  d="M18.364 18.364A9 9 0 0 0 5.636 5.636m12.728 12.728A9 9 0 0 1 5.636 5.636m12.728 12.728L5.636 5.636"/>
  </svg>`,
  activate: `
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
	   stroke="currentColor" fill="none" stroke-width="1.5"
	   width="1em" height="1em" class="toolbarIcon">
	<path stroke-linecap="round" stroke-linejoin="round"
		  d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
  </svg>`
};

// ===== Page-level state =====
let userYearEndValue = 12;
let userEstablishedYear = new Date().getFullYear();
let accessLevels = [];

const monthYear = [
  { num: 1,  name: "January" },  { num: 2,  name: "February" }, { num: 3,  name: "March" },
  { num: 4,  name: "April" },    { num: 5,  name: "May" },      { num: 6,  name: "June" },
  { num: 7,  name: "July" },     { num: 8,  name: "August" },   { num: 9,  name: "September" },
  { num: 10, name: "October" },  { num: 11, name: "November" }, { num: 12, name: "December" }
];

// ===== Seats & Billing state =====
const seatState = {};     // { [ref]: { name, price, seats, used } }
let pendingDelta = {};  // { [ref]: +N|-N }
let seatOrder = [];       // explicit render order

// ===== Access Levels (used by Users + Seats) =====
const accessLevelsPromise = fetch("../scripts/getAccessLevels.php", {
  method: "POST",
  headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
  body: JSON.stringify({})
})
.then(res => res.json())
.then(data => {
  if (data.status === 'success' && Array.isArray(data.accessLevels)) {
	accessLevels = data.accessLevels.map(level => ({
	  ref: level.REF,
	  code: level.CODE,
	  name: level.NAME,
	  mrr: Number(level.MRR),
	  description: level.DESCRIPTION || ''
	}));
  }
});

// ===== Company settings (year-end / established) =====
fetch("../scripts/getCompanySettings.php", {
  method: "POST",
  headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
  body: JSON.stringify({})
})
.then(r=>r.json())
.then(data => {
  if (data.status === 'success') {
	userYearEndValue = parseInt(data.value,10) || userYearEndValue;
	userEstablishedYear = parseInt(data.year,10) || userEstablishedYear;
	if (userYearEndValue >= 1 && userYearEndValue <= 12) {
	  document.getElementById('yearEndSelect').value = userYearEndValue;
	}
	if (userEstablishedYear >= 1850) {
	  document.getElementById('firstYearSelect').value = userEstablishedYear;
	}
  }
});

// ===== Populate the year/firstYear selects =====
document.addEventListener("DOMContentLoaded", () => {
  const yearEndSelect = document.getElementById('yearEndSelect');
  const firstYearSelect = document.getElementById('firstYearSelect');

  monthYear.forEach(m => {
	const opt = document.createElement('option');
	opt.value = m.num;
	opt.textContent = m.name;
	yearEndSelect.appendChild(opt);
  });

  const currentYear = new Date().getFullYear();
  for (let i = currentYear; i >= 1800; i--) {
	const opt = document.createElement('option');
	opt.value = i;
	opt.textContent = i;
	firstYearSelect.appendChild(opt);
  }
});

// ========================== Helpers (IDs, refs, users) ==========================
function codeToRef(){
  return Object.fromEntries(accessLevels.map(l => [String(l.code), Number(l.ref)]));
}
function normaliseLevelToRef(v){
  const n = Number(v);
  if (!Number.isNaN(n) && accessLevels.some(l => Number(l.ref) === n)) return n;
  const byCode = codeToRef()[String(v)];
  return (byCode !== undefined) ? byCode : null;
}

async function fetchUsers(){
  const res = await fetch("../scripts/getUsers.php", {
	method: "POST",
	headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
	body: JSON.stringify({})
  });
  const data = await res.json();
  if (data.status !== 'success') throw new Error('getUsers failed');
  return data.users || [];
}

// used = active; paid = active + inactive whose PAID_UNTIL >= today
function computeSeatCounts(users){
  const usedByRef = {};
  const paidByRef = {};
  const todayMidnight = new Date(); todayMidnight.setHours(0,0,0,0);
  const todayTs = todayMidnight.getTime();

  users.forEach(u => {
	const ref = normaliseLevelToRef(u.ACCESS_LEVEL);
	if (ref == null) return;

	if (Number(u.ACTIVE) === 1){
	  usedByRef[ref] = (usedByRef[ref] || 0) + 1;
	  paidByRef[ref] = (paidByRef[ref] || 0) + 1;
	  return;
	}
	const raw = (u.PAID_UNTIL || "").replace(' ', 'T');
	const paidUntilTs = raw ? Date.parse(raw) : NaN;
	if (!Number.isNaN(paidUntilTs) && paidUntilTs >= todayTs) {
	  paidByRef[ref] = (paidByRef[ref] || 0) + 1;
	}
  });
  return { usedByRef, paidByRef };
}

async function refreshUsersAndSeats(){
  const users = await fetchUsers();                 // you already have this
  const committedByRef = await fetchCompanySeats(); // NEW

  // assigned (used) from active users
  const usedByRef = {};
  users.forEach(u => {
	const ref = normaliseLevelToRef(u.ACCESS_LEVEL);
	if (ref != null && Number(u.ACTIVE) === 1) {
	  usedByRef[ref] = (usedByRef[ref] || 0) + 1;
	}
  });

  // rebuild seatState from company_seats
  for (const k in seatState) delete seatState[k];
  seatOrder = [];
  
  sortedPaidLevels().forEach(l => {
	const ref   = Number(l.ref);
	const seats = Number(committedByRef[ref] || 0);
	const used  = Number(usedByRef[ref] || 0);
	seatState[ref] = { name: l.name, price: Number(l.mrr)||0, seats, used };
	seatOrder.push(ref);
  });

  buildSeatsTable();
  renderCapacityTable();
  populateUsers();
  updateAddUserButton();
}

// Show the + button only if there is any unassigned paid capacity
function updateAddUserButton(){
  const btn = document.getElementById('addUserBtn');
  if (!btn) return;
  const hasSpare = seatOrder.some(ref => unassignedPaidFor(ref) > 0);
  btn.style.display = hasSpare ? 'inline-block' : 'none';
}

function hasSpareFor(ref){
  // uses pendingDelta-aware unassignedFor()
  return unassignedFor(Number(ref)) > 0;
}

function buildRoleOptionsForUser(currentRef, isActive){
  const options = [];
  const seen = new Set();

  // For ACTIVE users: always include current role
  if (isActive && currentRef != null){
	options.push({ ref: Number(currentRef), label: accessLevels.find(l => Number(l.ref) === Number(currentRef))?.name || 'Current' });
	seen.add(Number(currentRef));
  }

  // Add roles that have spare seats (respect pending deltas)
  accessLevels.forEach(l => {
	if (l.code === 'SUPERUSER' || Number(l.mrr) <= 0) return;
	const r = Number(l.ref);
	if (!seen.has(r) && hasSpareFor(r)) {
	  options.push({ ref: r, label: l.name });
	  seen.add(r);
	}
  });

  // For INACTIVE: if you prefer always showing their current role too, uncomment:
  // if (!isActive && currentRef != null && !seen.has(Number(currentRef))) {
  //   const nm = accessLevels.find(l => Number(l.ref) === Number(currentRef))?.name || 'Current';
  //   options.unshift({ ref: Number(currentRef), label: nm });
  // }

  return options;
}

async function fetchCompanySeats(){
  const res = await fetch("../scripts/getCompanySeats.php", {
	method: "POST",
	headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
	body: JSON.stringify({})
  });
  const data = await res.json();

  // Accept: raw array OR {status:'success', rows:[...]} OR {seats:[...]}
  const rows = Array.isArray(data) ? data : (data.rows || data.seats || []);

  const byRef = {};
  rows.forEach((r) => {
	const ref =
	  Number(
		r.ACCESS_LEVEL_REF ??
		r.access_level_ref ??
		r.ref
	  );
	const committed =
	  Number(
		r.SEATS_COMMITTED ??
		r.seats_committed ??
		r.seats ??
		0
	  );
	if (!Number.isNaN(ref)) byRef[ref] = committed;
  });

  return byRef; // { [access_level_ref]: seats_committed }
}

// ---- Seat ordering helper (use everywhere) ----
const ORDER  = ['FULL_ACCESS','DEPT_MGR','ADMIN','VIEW_LINE_MGR','VIEW_PAYROLL'];
const WEIGHT = Object.fromEntries(ORDER.map((c,i)=>[c,i]));
function sortedPaidLevels() {
  return accessLevels
	.filter(l => l.code !== 'SUPERUSER' && Number(l.mrr) > 0)
	.sort((a,b) => (WEIGHT[a.code] ?? 999) - (WEIGHT[b.code] ?? 999));
}

// ============================ Seats & Billing UI ================================
function unassignedFor(ref){
  const row = seatState[ref];
  const pending = pendingDelta[ref] || 0;
  return Math.max(0, (row.seats + pending) - row.used);
}

function initSeatRow(tr, ref){
  const minus = tr.querySelector('button[data-act="minus"]');
  const plus  = tr.querySelector('button[data-act="plus"]');
  const input = tr.querySelector('input.seatCount');

  minus.disabled = (seatState[ref].seats <= seatState[ref].used);

  plus.addEventListener('click', () => adjustSeats(ref, +1, tr));
  minus.addEventListener('click', () => adjustSeats(ref, -1, tr));
  input.addEventListener('change', () => {
	const v = Math.max(seatState[ref].used, Math.floor(Number(input.value) || 0));
	setSeats(ref, v, tr);
  });
}

function adjustSeats(ref, delta, tr){
  const next = Math.max(seatState[ref].used, seatState[ref].seats + (pendingDelta[ref]||0) + delta);
  setSeats(ref, next, tr);
}

function setSeats(ref, next, tr){
  const { seats, price, used } = seatState[ref];
  pendingDelta[ref] = next - seats;

  tr.querySelector('input.seatCount').value = next;
  tr.querySelector('.mrr').textContent   = `£${(next * price).toFixed(2)}`;
  tr.querySelector('.today').textContent = (pendingDelta[ref] > 0)
	  ? `£${(pendingDelta[ref] * price * prorationToday()).toFixed(2)}`
	  : '£0.00';

  tr.querySelector('button[data-act="minus"]').disabled = (next <= used);

  renderSeatsCta();
}

function prorationToday(){
  const now = new Date();
  const daysInMonth = new Date(now.getFullYear(), now.getMonth()+1, 0).getDate();
  return (daysInMonth - now.getDate() + 1) / daysInMonth;
}

function renderSeatsCta(){
  const hasIncrease = Object.values(pendingDelta).some(v => v > 0);
  document.getElementById('seatsTable')
	.classList.toggle('no-today', !hasIncrease);
  const cta = document.getElementById('billingCta');
  const changes = Object.values(pendingDelta).some(v => v !== 0);
  if (!cta || !changes) { if (cta) cta.innerHTML=''; return; }

  let up=0, down=0, today=0, monthly=0;
  for (const [ref, row] of Object.entries(seatState)){
	const d = pendingDelta[ref] || 0;
	monthly += (row.seats + d) * row.price;
	if (d > 0) { up += d * row.price; today += d * row.price * prorationToday(); }
	if (d < 0) { down += (-d) * row.price; }
  }

  const VAT_RATE = 0.20;
  const fmt   = n => `£${Number(n).toFixed(2)}`;
  const exInc = n => `${fmt(n)} ex VAT  <span class="muted">(${fmt(n*(1+VAT_RATE))} inc VAT)</span>`;

  if (up > 0){
	cta.innerHTML = `
	  <div class="cta-wrap">
		<div class="cta-lines">
		  <div><strong>Monthly Total (due 1st of the month):</strong> ${exInc(monthly)}</div>
		  <div title="Prorated for the rest of the current billing period">
			<strong>Payment due now for this change:</strong> ${exInc(today)}
		  </div>
		</div>
		<button id="reviewPayBtn">Review &amp; pay</button>
	  </div>`;
  } else {
	cta.innerHTML = `
	  <div class="cta-wrap">
		<div class="cta-lines">
		  <div><strong>New monthly total:</strong> ${exInc(monthly)}</div>
		  <div class="muted">Reductions (-${fmt(down)}/mo) apply at renewal.</div>
		</div>
		<button id="reviewChangesBtn">Review changes</button>
	  </div>`;
  }

  (document.getElementById('reviewPayBtn') || document.getElementById('reviewChangesBtn'))
	.addEventListener('click', () => startCheckout(pendingDelta));
}

async function startCheckout(delta){
  // Convert { "9":2, "10":-1, ... } -> [{ref:9, delta:2}, ...]
  const changes = Object.entries(pendingDelta)
	.map(([k, v]) => ({ ref: Number(k), delta: Number(v) }))
	.filter(it => it.delta !== 0);
  
  const res = await fetch('../scripts/updateSeats.php', {
	method: 'POST',
	headers: { 'Content-Type':'application/json', 'X-CSRF-Token': window.csrfToken },
	body: JSON.stringify({ changes })
  }).then(r => r.json()).catch(() => null);
  
  

  if (res?.status === 'success') {
	for (const k in pendingDelta) delete pendingDelta[k];
	const [users, seatsByRef] = await Promise.all([fetchUsers(), fetchCompanySeats()]);
	const { usedByRef } = computeSeatCounts(users);
	buildSeatStateFromCompanySeats(seatsByRef, usedByRef);
	buildSeatsTable();
	populateUsers();
	renderCapacityTable();
	updateAddUserButton();
	return;
  }

  if (res?.url) { window.location = res.url; return; }
  console.error('updateSeats failed:', res);
  alert(res?.message || 'Couldn’t start checkout. Please try again.');
}

function buildSeatStateFromCompanySeats(seatsByRef = {}, usedByRef = {}){
  for (const k in seatState) delete seatState[k];
  seatOrder = [];

  sortedPaidLevels().forEach(l => {
	const committed = Number(seatsByRef[l.ref] || 0);
	const used      = Number(usedByRef[l.ref] || 0);
	seatState[l.ref] = { name: l.name, price: Number(l.mrr)||0, seats: committed, used };
	seatOrder.push(l.ref);
  });
}

// ---- Users capacity table (Assigned / Unassigned) ----
function renderCapacityTable(){
  const t = document.getElementById('roleCapacityBody');
  if (!t) return;                  // page might not have the table
  t.innerHTML = '';

  seatOrder.forEach(ref => {
	const row = seatState[ref];    // { name, price, seats, used }
	if (!row) return;
	const assigned   = Number(row.used || 0);
	const unassigned = Math.max(0, Number(row.seats || 0) - assigned);

	const tr = document.createElement('tr');
	tr.innerHTML = `
	  <td>${row.name}</td>
	  <td class="right">${assigned}</td>
	  <td class="right">${unassigned}</td>
	`;
	t.appendChild(tr);
  });

  updateAddUserButton();
}

// How many paid seats remain for a role (committed [+ pending]) minus assigned
function unassignedPaidFor(ref){
  const row = seatState[ref] || { seats: 0, used: 0 };
  const pending = (typeof pendingDelta !== 'undefined' && pendingDelta)
	? (pendingDelta[ref] || 0)
	: 0;
  return Math.max(0, (Number(row.seats || 0) + pending) - Number(row.used || 0));
}

function buildSeatsTable(){
  const body = document.getElementById('seatsBody');
  if (!body) return;
  body.innerHTML = '';

  seatOrder.forEach(ref => {
	const row = seatState[ref];
	const tr = document.createElement('tr');
	tr.setAttribute('data-ref', ref);
	tr.innerHTML = `
	  <td>${row.name}</td>
	  <td><button data-act="minus" data-ref="${ref}" disabled>-</button></td>
	  <td><input class="seatCount" data-ref="${ref}" type="number" value="${row.seats}" min="${row.used}" /></td>
	  <td><button data-act="plus" data-ref="${ref}">+</button></td>
	  <td class="mrr">£${(row.seats * row.price).toFixed(2)}</td>
	  <td class="today">£0.00</td>
	`;
	body.appendChild(tr);
	initSeatRow(tr, Number(ref));
  });
  
  renderSeatsCta();
  renderCapacityTable();
}

// =============================== Users UI ======================================
function isPaidRef(ref){
  if (!Array.isArray(accessLevels) || accessLevels.length === 0) return false;
  const lvl = accessLevels.find(l => String(l.ref) === String(ref));
  return !!(lvl && Number(lvl.mrr) > 0);
}

// Seat-aware role change
async function changeUserAccess(number){
  const sel = document.getElementById(`userSelect${number}`);
  const userRef = sel.ref;
  const newRef  = String(sel.value);
  const oldRef  = String(sel.dataset.currentAccessRef || '');

  if (newRef === oldRef) return;

  if (isPaidRef(newRef)){
	const spare = unassignedFor(Number(newRef));
	if (spare <= 0){
	  const rowEl = document.querySelector(`tr[data-ref="${newRef}"]`);
	  document.getElementById('activeSeats').scrollIntoView({behavior:'smooth', block:'start'});
	  if (rowEl){ rowEl.classList.add('flash'); setTimeout(()=>rowEl.classList.remove('flash'), 1200); }
	  alert('No spare seats for that role. Add a seat first.');
	  sel.value = oldRef;
	  return;
	}
  }

  const res = await fetch("../scripts/updateAccessLevel.php", {
	method: "POST",
	headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
	body: JSON.stringify({ userRef: userRef, newAccessLevel: Number(newRef) })
  }).then(r=>r.json()).catch(()=>null);

  sel.dataset.currentAccessRef = newRef;

  await refreshUsersAndSeats();
}

// Build the users list
function populateUsers(){
  const active = document.getElementById('activeUsersSection');
  const inactive = document.getElementById('inactiveUsersSection');
  active.innerHTML = `<h3>Active Users</h3>`;
  inactive.innerHTML = ``;
  let inactiveCount = 0;

  fetch("../scripts/getUsers.php", {
	method: "POST",
	headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
	body: JSON.stringify({})
  })
  .then(res=>res.json())
  .then(data=>{
	if (!(data.status === 'success' && Array.isArray(data.users))) return;

	let x = 1;
	data.users.forEach(user=>{
	  const div = document.createElement('div');
	  div.classList.add("menuRow");

	  // name (Column 1)
		const label = document.createElement('label');
		label.htmlFor = `userAccess${x}`;
		label.textContent = `${user.FIRSTNAME} ${user.SURNAME}`;
		div.appendChild(label);
	  
		// icons (Column 2) — fixed-width strip
		const icons = document.createElement('span');
		icons.className = 'iconStrip';
	  
		if (Number(user.ACTIVE) === 1){
		  const editButton = document.createElement('button');
		  editButton.type = 'button';
		  editButton.classList.add('iconButton');
		  editButton.innerHTML = ICONS.edit;
		  icons.appendChild(editButton);
	  
		  const removeButton = document.createElement('button');
		  removeButton.type = 'button';
		  removeButton.classList.add('iconButton');
		  removeButton.innerHTML = ICONS.remove;
		  removeButton.dataset.action    = 'deactivate';
		  removeButton.dataset.userRef   = user.REF;
		  removeButton.dataset.accessRef = user.ACCESS_LEVEL;
		  icons.appendChild(removeButton);
		} else {
		  const activateButton = document.createElement('button');
		  activateButton.type = 'button';
		  activateButton.classList.add('iconButton');
		  activateButton.innerHTML = ICONS.activate;
		  activateButton.dataset.action    = 'activate';
		  activateButton.dataset.userRef   = user.REF;
		  activateButton.dataset.accessRef = user.ACCESS_LEVEL;
	  
		  const targetRef = String(user.ACCESS_LEVEL);
		  if (isPaidRef(targetRef) && unassignedPaidFor(Number(targetRef)) <= 0){
			activateButton.disabled = true;
			activateButton.title = "No spare seats for this role. Add a seat first.";
		  }
		  icons.appendChild(activateButton);
		}
	  
		div.appendChild(icons); // <-- Column 2
	  
		// select (Column 3) - Access select
		const select = document.createElement('select');
		select.name = `userSelect${x}`;
		select.id   = `userSelect${x}`;
		select.ref  = user.REF;
		select.dataset.currentAccessRef = String(user.ACCESS_LEVEL);
		select.addEventListener("change", (e) => {
		  const idx = Number(e.currentTarget.id.replace('userSelect',''));
		  changeUserAccess(idx);
		});
		
		// Build filtered options
		const currentRef = Number(user.ACCESS_LEVEL);
		const isActive   = Number(user.ACTIVE) === 1;
		const opts       = buildRoleOptionsForUser(currentRef, isActive);
		
		select.innerHTML = ''; // clear
		opts.forEach(o => {
		  const opt = document.createElement('option');
		  opt.value = o.ref;
		  opt.textContent = o.label;
		  if (o.ref === currentRef) opt.selected = true;
		  select.appendChild(opt);
		});
		
		// Disable inactive users’ select if there’s no eligible target
		if (!isActive) {
		  const hasEligible = opts.length > 0 && (opts.length > 1 || hasSpareFor(opts[0]?.ref));
		  select.disabled = !hasEligible;
		  if (select.disabled) select.title = "No roles available with spare seats.";
		}
		
		div.appendChild(select); // <-- Column 3

	  if (Number(user.ACTIVE) === 1) {
		active.appendChild(div);
	  } else {
		inactive.appendChild(div);
		inactiveCount++;                    // <- bump it here
	  }
	  
	  if (inactiveCount > 0) {
		inactive.insertAdjacentHTML('afterbegin', `<h3>Inactive Users</h3>`);
	  }

	  x++;
	});
  });
}

// ============================ Users: Activate/Deactivate ========================
async function setUserActive(userRef, active, accessLevel){
  const res = await fetch("../scripts/setUserActive.php", {
	method: "POST",
	headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
	body: JSON.stringify({ userRef: Number(userRef), active: Number(active), accessLevel })
  }).then(r=>r.json()).catch(()=>null);
  if (!res || res.status !== 'success') throw new Error(res?.message || 'setUserActive failed');
  return res;
}

// Event delegation for Users panel buttons
document.addEventListener('DOMContentLoaded', () => {
  const usersPanel = document.getElementById('usersPanel');
  if (!usersPanel) return;

  usersPanel.addEventListener('click', async (e) => {
	const btn = e.target.closest('button[data-action]');
	if (!btn) return;

	const userRef     = Number(btn.dataset.userRef);
	const accessLevel = btn.dataset.accessRef;

	if (btn.dataset.action === 'deactivate'){
	  if (!confirm(`Deactivate this user?`)) return;
	  try {
		await setUserActive(userRef, 0, accessLevel);
		await refreshUsersAndSeats();
	  } catch (err) {
		alert('Could not deactivate user.');
	  }
	}

	if (btn.dataset.action === 'activate'){
	  if (isPaidRef(accessLevel) && unassignedPaidFor(Number(accessLevel)) <= 0){
		const rowEl = document.querySelector(`tr[data-ref="${accessLevel}"]`);
		document.getElementById('activeSeats').scrollIntoView({behavior:'smooth', block:'start'});
		if (rowEl){ rowEl.classList.add('flash'); setTimeout(()=>rowEl.classList.remove('flash'), 1200); }
		alert('No spare seats for this role. Add a seat first.');
		return;
	  }
	  try {
		await setUserActive(userRef, 1, accessLevel);
		await refreshUsersAndSeats();
	  } catch (err) {
		alert('Could not activate user.');
	  }
	}
  });
});

// ============================== Bootstrap flow =================================
Promise.all([accessLevelsPromise, fetchUsers(), fetchCompanySeats()])
  .then(([_, users, seatsByRef]) => {
	const { usedByRef } = computeSeatCounts(users);
	buildSeatStateFromCompanySeats(seatsByRef, usedByRef);
	buildSeatsTable();
	populateUsers();
	renderCapacityTable();
	updateAddUserButton();
  })
  .catch(err => console.error(err));
  
  
</script>

<!-- ================================ HTML ================================ -->

<!-- PAGE WRAPPER (scoped so we don't collide with global CSS) -->
<div id="companySettings">
	
	<div class="cs-row cs-row--prefs">
		
		<section class="settingsPanel cs-panel" id="panelAccounting">
			<h2>Accounting</h2>
			<div class="menuRow">
			<label for="yearEnd">Financial Year End:</label>
			<select id="yearEndSelect" name="yearEnd" onchange="updateYearEndSetting();"></select>
			</div>
			<div class="menuRow">
			<label for="firstYear">Year Established:</label>
			<select id="firstYearSelect" name="firstYear" onchange="updateFirstYearSetting();"></select>
			</div>
		</section>
		
		<section class="settingsPanel cs-panel" id="panelPayFreq">
			<h2>Payment Frequency</h2>
			<div class="menuRow">
			<label for="weeklyPaid">Enable weekly paid staff?</label>
			<span class="toggleWrap">
				<input type="checkbox" name="weeklyPaid" id="weeklyPaid" class="toggleSwitch">
			</span>
		</section>
		
	</div>
	
	<div class="cs-row cs-row--billing">
		
		<!-- Seats & Billing -->
		<section class="settingsPanel cs-panel" id="panelSeats">
			<h2>Seats &amp; Billing</h2>
			<div id="activeSeats">
				<div class="table-wrap">
					<table id="seatsTable" class="table-tight">
						<colgroup>
							<col style="width:200px">
							<col style="width:32px">
							<col style="width:56px">
							<col style="width:32px">
							<col style="width:92px">
							<col style="width:92px">
						</colgroup>
						<thead>
							<tr>
							<th>Role</th>
							<th></th>
							<th>Seats</th>
							<th></th>
							<th>Est. Monthly</th>
							<th>Est. Today</th>
							</tr>
						</thead>
						<tbody id="seatsBody"></tbody>
					</table>
				</div>
			</div>
			<div id="billingCta" class="menuRow" style="justify-content:flex-end;gap:.5rem;"></div>
		</section>
		
		<!-- Users -->
		<section class="settingsPanel cs-panel" id="usersPanel">
			<h2>Users</h2>
		
			<!-- Capacity table (paid seats picture) -->
			<div class="menuRow" style="display:block">
				<div class="table-wrap">
					<table class="mini">
						<thead>
							<tr>
								<th>Role</th>
								<th class="right">Assigned</th>
								<th class="right">Unassigned</th>
							</tr>
						</thead>
						<tbody id="roleCapacityBody"></tbody>
					</table>
				</div>
			</div>
		
			<div id="activeUsersSection"></div>
			<div id="inactiveUsersSection"></div>
		
			<div class="menuRow">
				<button type="button" id="addUserBtn" onclick="addUserMenu();">+</button>
			</div>
		</section>
	
	</div>
	
</div>

<?php
require_once("../includes/footer.php");
?>