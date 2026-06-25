(function () {
let saved = localStorage.getItem('uc_theme');
if (saved !== 'dark' && saved !== 'light') {
saved = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
}
document.documentElement.setAttribute('data-bs-theme', saved);
})();
if (window.matchMedia) {
try {
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
if (!localStorage.getItem('uc_theme')) {
document.documentElement.setAttribute('data-bs-theme', e.matches ? 'dark' : 'light');
syncThemeIcon();
}
});
} catch (e) {}
}
function toggleTheme() {
const html = document.documentElement;
const next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
html.setAttribute('data-bs-theme', next);
localStorage.setItem('uc_theme', next);
try {
fetch('ajax/user-theme.php', {
method: 'POST',
credentials: 'same-origin',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ theme: next })
}).catch(() => { });
} catch (_) {}
syncThemeIcon();
}
function syncThemeIcon() {
const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
const cls = isDark ? 'bi bi-sun' : 'bi bi-moon';
const ids = ['theme-icon', 'theme-icon-mobile'];
for (const id of ids) {
const el = document.getElementById(id);
if (el) el.className = cls;
}
}
document.addEventListener('DOMContentLoaded', syncThemeIcon);
function showToast(msg, opts = {}) {
let wrap = document.getElementById('toast-wrap');
if (!wrap) {
wrap = document.createElement('div');
wrap.id = 'toast-wrap';
wrap.className = 'mv-toast-wrap';
document.body.appendChild(wrap);
}
const el = document.createElement('div');
el.className = 'mv-toast';
el.setAttribute('data-testid', opts.title ? 'rich-toast' : 'toast');
const ttl = opts.duration || 3200;
el.style.setProperty('--toast-ttl', ttl + 'ms');
el.innerHTML =
(opts.icon ? '<span class="mv-toast-icon">' + opts.icon + '</span>' : '') +
'<div class="mv-toast-body">' +
(opts.title ? '<div class="mv-toast-title">' + opts.title + '</div>' : '') +
'<div class="mv-toast-msg">' + msg + '</div>' +
(opts.actionHref
? '<a href="' + opts.actionHref + '" class="mv-toast-action" data-testid="toast-open-cart">' + opts.actionLabel + ' <i class="bi bi-arrow-right"></i></a>'
: '') +
'</div>' +
'<button class="mv-toast-close" aria-label="Dismiss" data-testid="toast-close"><i class="bi bi-x-lg"></i></button>' +
'<span class="mv-toast-progress"></span>';
wrap.appendChild(el);
let timer;
const dismiss = () => {
clearTimeout(timer);
el.classList.add('hide');
setTimeout(() => el.remove(), 320);
};
el.querySelector('.mv-toast-close').addEventListener('click', dismiss);
timer = setTimeout(dismiss, ttl);
}
async function cartAction(payload) {
const res = await fetch('ajax/cart.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify(payload),
});
return res.json();
}
function updateCartBadge(count) {
document.querySelectorAll('.cart-count-badge').forEach((b) => {
b.textContent = count;
b.classList.toggle('d-none', count === 0);
b.classList.remove('cart-bump');
void b.offsetWidth;
b.classList.add('cart-bump');
});
}
function markAdded(btn) {
btn.classList.add('added');
btn.dataset.added = '1';
const big = btn.classList.contains('btn-lg');
btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i>' + (big ? 'Added to Cart — View' : 'Added');
btn.title = 'Already in your cart — click to view';
}
document.addEventListener('DOMContentLoaded', () => {
const inCart = window.CART_SLUGS || [];
document.querySelectorAll('.add-to-cart-btn').forEach((b) => {
if (inCart.includes(b.dataset.slug)) markAdded(b);
});
});
document.addEventListener('click', async (e) => {
const btn = e.target.closest('.add-to-cart-btn');
if (btn) {
e.preventDefault();
if (btn.dataset.added) { window.location.href = 'cart.php'; return; }
const qty = parseInt(btn.dataset.qty || document.getElementById('pd-qty')?.value || '1', 10);
const data = await cartAction({ action: 'add', slug: btn.dataset.slug, qty });
updateCartBadge(data.count);
markAdded(btn);
if (window.CART_SLUGS && !window.CART_SLUGS.includes(btn.dataset.slug)) window.CART_SLUGS.push(btn.dataset.slug);
/* add_to_cart event — fires to whichever tracker is configured (GA4 +
Bing UET). Reads price/name from the data attributes the server
renders onto the button when available; falls back gracefully. */
try {
var ev = {
item_id: btn.dataset.slug,
item_name: btn.dataset.name || btn.dataset.slug,
price: parseFloat(btn.dataset.price || data.unitPrice || 0) || 0,
quantity: qty,
currency: btn.dataset.currency || data.currency || 'USD'
};
if (typeof gtag === 'function') {
gtag('event', 'add_to_cart', { currency: ev.currency, value: ev.price * ev.quantity, items: [ev] });
}
if (window.uetq) {
window.uetq.push('event', 'add_to_cart', {
event_category: 'ecommerce', event_label: ev.item_id,
revenue_value: ev.price * ev.quantity, currency: ev.currency
});
}
} catch (_) { }
showToast('Open the cart to review your items, or keep shopping.', {
title: 'Added to cart!',
icon: '<i class="bi bi-bag-check-fill"></i>',
actionHref: 'cart.php',
actionLabel: 'Open Cart',
duration: 4500,
});
return;
}
const buy = e.target.closest('.buy-now-btn');
if (buy) {
e.preventDefault();
const qty = parseInt(buy.dataset.qty || document.getElementById('pd-qty')?.value || '1', 10);
await cartAction({ action: 'set', slug: buy.dataset.slug, qty });
window.location.href = 'cart.php';
return;
}
const notify = e.target.closest('.notify-me-btn');
if (notify) {
e.preventDefault();
openNotifyModal(notify.dataset.slug || '', notify.dataset.name || 'this product');
}
});
function openNotifyModal(slug, name) {
let modal = document.getElementById('notify-modal');
if (!modal) {
modal = document.createElement('div');
modal.id = 'notify-modal';
modal.className = 'notify-modal-overlay';
modal.innerHTML = `
<div class="notify-modal-card" role="dialog" aria-modal="true" aria-labelledby="notify-modal-title">
<button type="button" class="notify-modal-close" onclick="closeNotifyModal()" aria-label="Close" data-testid="notify-modal-close"><i class="bi bi-x-lg"></i></button>
<div class="notify-modal-icon"><i class="bi bi-bell-fill"></i></div>
<h3 class="notify-modal-title" id="notify-modal-title">Get notified when it's back</h3>
<p class="notify-modal-sub">We'll email you the moment <strong id="notify-modal-product">this product</strong> is back in stock. No spam, ever.</p>
<form id="notify-modal-form" onsubmit="submitNotify(event)">
<input type="email" id="notify-modal-email" class="form-control" placeholder="you@company.com" required autocomplete="email" data-testid="notify-modal-email">
<button type="submit" class="notify-modal-submit" data-testid="notify-modal-submit"><i class="bi bi-bell-fill me-2"></i>Notify me</button>
</form>
<div id="notify-modal-msg" class="notify-modal-msg" data-testid="notify-modal-msg"></div>
</div>`;
document.body.appendChild(modal);
modal.addEventListener('click', (ev) => { if (ev.target === modal) closeNotifyModal(); });
document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') closeNotifyModal(); });
}
modal.dataset.slug = slug;
document.getElementById('notify-modal-product').textContent = name;
document.getElementById('notify-modal-email').value = '';
document.getElementById('notify-modal-msg').textContent = '';
document.getElementById('notify-modal-msg').className = 'notify-modal-msg';
modal.classList.add('is-open');
setTimeout(() => document.getElementById('notify-modal-email').focus(), 100);
}
function closeNotifyModal() {
const m = document.getElementById('notify-modal');
if (m) m.classList.remove('is-open');
}
async function submitNotify(ev) {
ev.preventDefault();
const modal = document.getElementById('notify-modal');
const email = document.getElementById('notify-modal-email').value.trim();
const msg = document.getElementById('notify-modal-msg');
const slug = modal.dataset.slug || '';
if (!email || !slug) return;
msg.textContent = 'Saving…';
msg.className = 'notify-modal-msg is-pending';
try {
const r = await fetch('ajax/notify-stock.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ product_slug: slug, email })
});
const j = await r.json();
if (j && j.ok) {
msg.textContent = j.message || "You're on the list!";
msg.className = 'notify-modal-msg is-ok';
setTimeout(closeNotifyModal, 2200);
} else {
msg.textContent = (j && j.error) || 'Something went wrong — please try again.';
msg.className = 'notify-modal-msg is-err';
}
} catch (e) {
msg.textContent = 'Network error — please retry.';
msg.className = 'notify-modal-msg is-err';
}
}
async function refreshCheckoutSummary() {
const host = document.getElementById('checkout-summary');
if (!host) return false;
const qs = window.location.search || '';
try {
const r = await fetch('ajax/checkout-summary.php' + qs, { credentials: 'same-origin' });
if (r.status === 204) { window.location.href = 'cart.php'; return true; }
if (!r.ok) return false;
host.innerHTML = await r.text();
return true;
} catch (e) { return false; }
}
document.addEventListener('click', async (e) => {
const qbtn = e.target.closest('[data-cart-qty]');
if (qbtn) {
const data = await cartAction({ action: 'update', slug: qbtn.dataset.slug, qty: parseInt(qbtn.dataset.cartQty, 10) });
updateCartBadge(data.count);
if (await refreshCheckoutSummary()) return;
location.reload();
return;
}
const rbtn = e.target.closest('[data-cart-remove]');
if (rbtn) {
const data = await cartAction({ action: 'remove', slug: rbtn.dataset.cartRemove });
updateCartBadge(data.count);
if (await refreshCheckoutSummary()) return;
location.reload();
}
});
function subscribeNewsletter(ev) {
ev.preventDefault();
const input = ev.target.querySelector('input[type="email"]');
if (!input || !input.value) return;
showToast('<i class="bi bi-check-circle me-1"></i> Subscribed! You\'ll receive exclusive deals soon.');
input.value = '';
}
async function applyCoupon(code) {
const data = await cartAction({ action: 'coupon', code: code || '' });
if (data.ok) {
showToast(data.coupon ? '<i class="bi bi-tag-fill me-1"></i> Coupon applied — ' + (data.pct || '') + '% off!' : 'Coupon removed.');
if (await refreshCheckoutSummary()) return;
location.reload();
} else {
showToast('<i class="bi bi-x-circle me-1"></i> ' + (data.error || 'Invalid coupon code'));
}
}
document.addEventListener('DOMContentLoaded', () => {
if (window.bootstrap) {
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => new bootstrap.Tooltip(el));
}
const couponInput = document.getElementById('coupon-input');
if (couponInput) {
couponInput.addEventListener('keydown', (e) => {
if (e.key === 'Enter') {
e.preventDefault();
applyCoupon(couponInput.value);
}
});
}
});
function syncPhoneFlag(sel) {
const flag = document.getElementById('phone-flag');
const opt = sel.options[sel.selectedIndex];
if (flag && opt) flag.textContent = opt.dataset.flag || '🇺🇸';
}
function detectCardBrand(digits) {
if (digits.startsWith('4')) return 'visa';
if (digits.startsWith('5')) return 'mastercard';
if (digits.startsWith('3')) return 'amex';
if (digits.startsWith('6')) return 'discover';
return '';
}
document.addEventListener('input', (e) => {
if (e.target.id === 'card-number') {
const digits = e.target.value.replace(/\D/g, '').slice(0, 16);
e.target.value = digits.replace(/(\d{4})(?=\d)/g, '$1 ');
const brand = digits.length ? detectCardBrand(digits) : '';
document.querySelectorAll('#card-brands .card-brand-icon').forEach((i) => {
i.classList.toggle('active', i.dataset.brand === brand);
i.classList.toggle('dimmed', brand !== '' && i.dataset.brand !== brand);
});
} else if (e.target.id === 'card-exp') {
let v = e.target.value.replace(/\D/g, '').slice(0, 4);
if (v.length >= 3) v = v.slice(0, 2) + '/' + v.slice(2);
e.target.value = v;
} else if (e.target.id === 'card-cvv') {
e.target.value = e.target.value.replace(/\D/g, '').slice(0, 4);
}
});
function selectPayMethod(method) {
document.querySelectorAll('.pay-option').forEach((o) => o.classList.remove('active'));
const opt = document.getElementById('pay-' + method);
if (opt) opt.classList.add('active');
const radios = document.querySelectorAll('input[name="pm_radio"]');
radios.forEach((r) => { r.checked = false; });
const sel = document.querySelector('#pay-' + method + ' input[name="pm_radio"]');
if (sel) sel.checked = true;
const cardForm = document.getElementById('card-form');
const paypalInfo = document.getElementById('paypal-info');
const cardBtn = document.getElementById('btn-pay-card');
const ppBtn = document.getElementById('btn-pay-paypal');
const input = document.getElementById('payment-method-input');
if (input) input.value = method;
if (cardForm) cardForm.classList.toggle('d-none', method !== 'card');
if (paypalInfo) paypalInfo.classList.toggle('d-none', method !== 'paypal');
if (cardBtn) cardBtn.classList.toggle('d-none', method !== 'card');
if (ppBtn) ppBtn.classList.toggle('d-none', method !== 'paypal');
}
let _lastChatToggle = 0;
function toggleChat() {
const _now = Date.now();
if (_now - _lastChatToggle < 150) return;
_lastChatToggle = _now;
const panel = document.getElementById('chat-panel');
if (!panel) return;
panel.classList.toggle('open');
if (panel.classList.contains('open')) {
clearChatBell();
if (typeof hideChatMsgPreview === 'function') hideChatMsgPreview();
if (!localStorage.getItem('uc_lead_done')) {
const form = document.getElementById('chat-lead-form');
if (form) form.style.display = 'block';
const welcome = document.getElementById('chat-welcome-msg');
const chips = document.getElementById('chat-chips');
if (welcome) welcome.style.display = 'none';
if (chips) chips.style.display = 'none';
setTimeout(() => {
const ne = document.getElementById('lead-name');
if (ne && !ne.value) ne.focus();
}, 60);
} else {
revealChatInputRow();
const form = document.getElementById('chat-lead-form');
if (form) form.style.display = 'none';
const welcome = document.getElementById('chat-welcome-msg');
const chips = document.getElementById('chat-chips');
if (welcome) welcome.style.display = 'none';
if (chips) chips.style.display = 'none';
}
}
}
function playChatTypingIntro() {
const welcome = document.getElementById('chat-welcome-msg');
const chips = document.getElementById('chat-chips');
if (!welcome) return;
welcome.style.display = 'none';
if (chips) chips.style.display = 'none';
const typing = document.createElement('div');
typing.className = 'chat-msg bot typing';
typing.id = 'chat-intro-typing';
typing.setAttribute('data-testid', 'chat-intro-typing');
typing.innerHTML = '<span></span><span></span><span></span>';
const body = document.getElementById('chat-body');
if (body) body.insertBefore(typing, body.firstChild);
setTimeout(() => {
typing.remove();
welcome.style.display = '';
welcome.classList.add('is-fade-in');
if (chips) {
chips.style.display = '';
chips.classList.add('is-fade-in');
}
}, 1200);
}
/* Persisted via localStorage so the bright red count survives page
navigations — admin replies that arrive while the customer is on a
different page still surface a badge on the chat bubble. */
let _chatBellCount = 0;
const UC_UNREAD_KEY = 'uc_chat_unread';
function _persistUnread(n) {
try { (n > 0) ? localStorage.setItem(UC_UNREAD_KEY, String(n)) : localStorage.removeItem(UC_UNREAD_KEY); } catch (e) {}
}
function _renderBell() {
const bell = document.getElementById('chat-bell');
const cnt = document.getElementById('chat-bell-count');
if (!bell || !cnt) return;
if (_chatBellCount > 0) {
bell.style.display = 'inline-flex';
cnt.textContent = _chatBellCount > 9 ? '9+' : String(_chatBellCount);
} else {
bell.style.display = 'none';
}
}
function showChatBell(addCount) {
_chatBellCount = (typeof addCount === 'number' ? _chatBellCount + addCount : _chatBellCount + 1);
_persistUnread(_chatBellCount);
_renderBell();
}
function clearChatBell() {
_chatBellCount = 0;
_persistUnread(0);
_renderBell();
hideChatMsgPreview();
}
document.addEventListener('DOMContentLoaded', () => {
try {
const stored = parseInt(localStorage.getItem(UC_UNREAD_KEY) || '0', 10);
if (stored > 0) { _chatBellCount = stored; _renderBell(); }
} catch (e) {}
});
let _msgPreviewTimer = null;
function showChatMsgPreview(text) {
const card = document.getElementById('chat-msg-preview');
const body = document.getElementById('chat-msg-preview-body');
if (!card || !body) return;
let preview = String(text || '').trim();
if (preview.length > 180) preview = preview.slice(0, 177) + '…';
body.textContent = preview;
card.style.display = 'block';
card.classList.remove('is-hiding');
if (_msgPreviewTimer) { clearTimeout(_msgPreviewTimer); _msgPreviewTimer = null; }
}
function hideChatMsgPreview() {
const card = document.getElementById('chat-msg-preview');
if (!card) return;
card.classList.add('is-hiding');
if (_msgPreviewTimer) { clearTimeout(_msgPreviewTimer); _msgPreviewTimer = null; }
setTimeout(() => { if (card.classList.contains('is-hiding')) card.style.display = 'none'; }, 220);
}
function openChatFromPreview() {
hideChatMsgPreview();
const panel = document.getElementById('chat-panel');
if (panel && !panel.classList.contains('open')) {
if (typeof toggleChat === 'function') toggleChat();
}
}
function updateChatAgentName(name) {
if (!name) return;
const el = document.querySelector('[data-testid="chat-head-name"]');
if (el && el.textContent.trim() !== name) el.textContent = name;
const sub = document.querySelector('.chat-head-sub');
if (sub) sub.textContent = 'Support agent · online';
}
function _chatChime() {
try {
const Ctx = window.AudioContext || window.webkitAudioContext;
if (!Ctx) return;
const ctx = new Ctx();
const now = ctx.currentTime;
const tone = (freq, start, dur) => {
const o = ctx.createOscillator(); const g = ctx.createGain();
o.type = 'sine'; o.frequency.value = freq;
g.gain.setValueAtTime(0.0001, start);
g.gain.exponentialRampToValueAtTime(0.18, start + 0.02);
g.gain.exponentialRampToValueAtTime(0.0001, start + dur);
o.connect(g); g.connect(ctx.destination);
o.start(start); o.stop(start + dur + 0.05);
};
tone(880, now, 0.18); tone(1320, now + 0.12, 0.22);
setTimeout(() => ctx.close && ctx.close(), 800);
} catch (_) {}
}
function leadValues() {
return {
name: document.getElementById('lead-name').value.trim(),
email: document.getElementById('lead-email').value.trim(),
phone: document.getElementById('lead-phone').value.trim(),
};
}
function chatDefaultGreeting(firstName) {
var phone = (window.SITE_PHONE || '').trim();
return 'Thanks for contacting us' + (firstName ? ', ' + firstName : '') + '! '
+ (phone ? '\ud83d\udcde Please call our support line at ' + phone + ', or one of our representatives'
: 'One of our representatives')
+ ' will give you a call on your registered phone number \u2014 so please stay near your phone. '
+ 'For any further message, just type below and we\u2019ll get right back to you.';
}
async function submitLead(callback) {
const v = leadValues();
const errBox = document.getElementById('chat-lead-error');
const sendBtn = document.querySelector('[data-testid="lead-send-btn"]');
if (errBox) errBox.style.display = 'none';
function showErr(msg) {
if (errBox) { errBox.textContent = msg; errBox.style.display = 'block'; }
else { showToast(msg); }
}
if (!v.name) { showErr('Please enter your full name.'); return; }
if (!/\S+@\S+\.\S+/.test(v.email)) { showErr('Please enter a valid email address.'); return; }
if (v.phone.length < 7) { showErr('Please enter your phone number.'); return; }
if (sendBtn) { sendBtn.disabled = true; sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>'; }
let sid = localStorage.getItem('uc_chat_session');
if (!sid) { sid = 's' + Date.now() + Math.random().toString(36).slice(2, 8); localStorage.setItem('uc_chat_session', sid); }
try {
const r = await fetch('ajax/lead.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ session_id: sid, callback_requested: !!callback, ...v }),
});
const j = await r.json().catch(() => ({}));
if (r.status >= 400 || (j && j.ok === false)) {
const detail = (j && (j.error || j.message)) || 'Something went wrong — please try again.';
showErr(detail);
if (sendBtn) { sendBtn.disabled = false; sendBtn.innerHTML = '<i class="bi bi-send-fill"></i>'; }
return;
}
if (j && j.chat_token) {
localStorage.setItem('uc_chat_token', j.chat_token);
localStorage.setItem('uc_lead_id', String(j.lead_id || ''));
startAdminPolling();
}
} catch (e) {
showErr('Network error — please check your connection and try again.');
if (sendBtn) { sendBtn.disabled = false; sendBtn.innerHTML = '<i class="bi bi-send-fill"></i>'; }
return;
}
localStorage.setItem('uc_lead_done', '1');
localStorage.setItem('uc_chat_human_only', '1');
document.getElementById('chat-lead-form').style.display = 'none';
revealChatInputRow();
const firstName = (v.name.split(' ')[0] || '').trim();
const bubble = chatAppend('bot', chatDefaultGreeting(firstName));
if (bubble) bubble.classList.add('agent-greeting');
_paSchedInitDone = false;
setTimeout(paSchedInit, 200);
}
function skipLead() {
localStorage.setItem('uc_lead_done', '1');
document.getElementById('chat-lead-form').style.display = 'none';
revealChatInputRow();
chatAppend('bot', 'No problem — ask me anything about products, pricing, installation or activation. I\'m happy to help.');
}
function revealChatInputRow() {
const row = document.getElementById('chat-input-row');
if (!row) return;
row.classList.remove('d-none');
row.classList.add('d-flex');
row.style.display = 'flex';
row.style.visibility = 'visible';
if (!row.classList.contains('is-fade-in')) row.classList.add('is-fade-in');
const inp = document.getElementById('chat-input');
if (inp) setTimeout(() => inp.focus(), 60);
}
function chatAppend(role, text) {
const body = document.getElementById('chat-body');
const div = document.createElement('div');
div.className = 'chat-msg ' + role;
div.textContent = text;
body.appendChild(div);
body.scrollTop = body.scrollHeight;
return div;
}
let _adminPollTimer = null;
let _adminLastMsgId = 0;
async function startAdminPolling() {
if (_adminPollTimer) return;
const token = localStorage.getItem('uc_chat_token');
if (!token) return;
_adminPollTimer = setInterval(adminPollOnce, 5000);
adminPollOnce();
}
async function adminPollOnce() {
const token = localStorage.getItem('uc_chat_token');
if (!token) return;
try {
const r = await fetch('ajax/chat-customer.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'poll', token: token, since: _adminLastMsgId }),
});
const j = await r.json();
if (j && j.ok && j.agent_joined) {
localStorage.setItem('uc_chat_human_only', '1');
if (j.agent_name) updateChatAgentName(j.agent_name);
}
if (j && j.ok && Array.isArray(j.messages) && j.messages.length) {
const panel = document.getElementById('chat-panel');
const panelOpen = panel && panel.classList.contains('open');
for (const m of j.messages) {
if (m.attachment_url) { chatAppendAttachment('bot', m); } else { chatAppend('bot', m.message); }
if (m.id > _adminLastMsgId) _adminLastMsgId = m.id;
}
if (!panelOpen) {
showChatBell(j.messages.length);
_chatChime();
const latest = j.messages[j.messages.length - 1];
showChatMsgPreview(latest && latest.message ? latest.message : '');
}
}
const t = document.getElementById('chat-typing');
if (t) {
const show = !!(j && j.admin_typing);
t.style.display = show ? 'block' : 'none';
if (show) { const body = document.getElementById('chat-body'); if (body) body.scrollTop = body.scrollHeight; }
}
} catch (e) { }
}
let _custTypingAt = 0;
function pingCustomerTyping(on){
const token = localStorage.getItem('uc_chat_token');
if (!token) return;
const now = Date.now();
if (on && (now - _custTypingAt) < 2000) return;
_custTypingAt = on ? now : 0;
try {
fetch('ajax/chat-customer.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'typing', token: token, typing: on ? 1 : 0 }),
});
} catch(_) {}
}
document.addEventListener('DOMContentLoaded', () => {
const i = document.getElementById('chat-input');
if (!i) return;
i.addEventListener('input', () => {
if (i.value.trim().length > 0) clearChatBell();
pingCustomerTyping(i.value.trim().length > 0);
maybeShowLeadNudge();
});
i.addEventListener('focus', () => { clearChatBell(); maybeShowLeadNudge(); });
i.addEventListener('blur', () => pingCustomerTyping(false));
});
function maybeShowLeadNudge(){
if (localStorage.getItem('uc_lead_done') === '1') return;
const form = document.getElementById('chat-lead-form');
const nudge = document.getElementById('chat-lead-nudge');
const input = document.getElementById('chat-input');
if (!form || !nudge || !input) return;
const hasIntent = input.value.trim().length > 0 || document.activeElement === input;
if (!hasIntent) { nudge.style.display = 'none'; return; }
if (form.style.display === 'none') form.style.display = '';
nudge.style.display = 'flex';
try { form.scrollIntoView({behavior:'smooth', block:'end'}); } catch(_) {}
}
async function relayCustomerMessageToAdmin(text) {
const token = localStorage.getItem('uc_chat_token');
if (!token) return;
try {
await fetch('ajax/chat-customer.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'send', token: token, message: text }),
});
} catch (e) {}
}
if (typeof window !== 'undefined' && localStorage.getItem('uc_chat_token')) {
document.addEventListener('DOMContentLoaded', () => startAdminPolling());
}
let _paSchedSelectedDate = null;
let _paSchedInitDone = false;
let _paSchedTz = 'America/New_York';
let _paSchedTzList = null;
function _paGuessTz() {
try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'America/New_York'; }
catch (e) { return 'America/New_York'; }
}
function _paSchedEl(id) { return document.getElementById(id); }
function _paSchedError(msg) {
const e = _paSchedEl('pa-sched-error');
if (!e) return;
if (msg) { e.textContent = msg; e.style.display = 'block'; }
else { e.style.display = 'none'; e.textContent = ''; }
}
function paSchedInit() {
if (_paSchedInitDone) return;
const token = localStorage.getItem('uc_chat_token');
if (!token) return;
_paSchedInitDone = true;
fetch('ajax/proassist-schedule.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'status', token }),
})
.then(r => r.json())
.then(j => {
if (!j || !j.ok || !j.is_proassist) return;
_paSchedTzList = j.timezones || {};
const lf = document.getElementById('chat-lead-form');
if (lf) lf.style.display = 'none';
revealChatInputRow();
try {
if (!localStorage.getItem('uc_pa_greeted')) {
const nm = (j.customer && j.customer.name ? String(j.customer.name).split(' ')[0] : '').trim();
const gb = chatAppend('bot', chatDefaultGreeting(nm));
if (gb) gb.classList.add('agent-greeting');
localStorage.setItem('uc_pa_greeted', '1');
}
} catch (e) {}
if (j.schedule && j.schedule.status && j.schedule.status !== 'cancelled') {
paSchedShowConfirmed(j.schedule);
} else {
paSchedShowPicker();
}
})
.catch(() => {});
}
function _paSchedRenderTzSelect() {
const sel = _paSchedEl('pa-sched-tz');
if (!sel || !_paSchedTzList) return;
if (sel.options.length === 0) {
Object.keys(_paSchedTzList).forEach(function (value) {
const opt = document.createElement('option');
opt.value = value;
opt.textContent = _paSchedTzList[value];
sel.appendChild(opt);
});
const guess = _paGuessTz();
_paSchedTz = (_paSchedTzList[guess]) ? guess : 'America/New_York';
sel.value = _paSchedTz;
sel.addEventListener('change', function () {
_paSchedTz = sel.value || 'America/New_York';
if (_paSchedSelectedDate) paSchedLoadSlots(_paSchedSelectedDate);
});
} else {
sel.value = _paSchedTz;
}
}
function paSchedShowPicker() {
const card = _paSchedEl('pa-sched-card');
const conf = _paSchedEl('pa-sched-confirm');
if (conf) conf.style.display = 'none';
if (card) card.style.display = 'block';
_paSchedError('');
_paSchedRenderTzSelect();
paSchedRenderDates();
const step = _paSchedEl('pa-sched-times-step');
if (step) step.style.display = 'none';
_paSchedSelectedDate = null;
revealChatInputRow();
}
function paSchedRenderDates() {
const wrap = _paSchedEl('pa-sched-dates');
if (!wrap) return;
wrap.innerHTML = '';
const dows = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const mons = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const today = new Date();
for (let i = 0; i < 21; i++) {
const d = new Date(today.getFullYear(), today.getMonth(), today.getDate() + i);
const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const btn = document.createElement('button');
btn.type = 'button';
btn.className = 'pa-sched-date';
btn.dataset.date = iso;
btn.setAttribute('data-testid', 'pa-sched-date-' + iso);
btn.innerHTML = '<span class="pa-sched-date-dow">' + (i === 0 ? 'Today' : dows[d.getDay()]) + '</span>'
+ '<span class="pa-sched-date-day">' + d.getDate() + '</span>'
+ '<span class="pa-sched-date-mon">' + mons[d.getMonth()] + '</span>';
btn.addEventListener('click', function () { paSchedSelectDate(iso, btn); });
wrap.appendChild(btn);
}
}
function paSchedSelectDate(iso, btn) {
_paSchedSelectedDate = iso;
document.querySelectorAll('#pa-sched-dates .pa-sched-date').forEach(function (b) {
b.classList.toggle('is-selected', b === btn);
});
paSchedLoadSlots(iso);
}
function paSchedBackToDates() {
const step = _paSchedEl('pa-sched-times-step');
if (step) step.style.display = 'none';
_paSchedSelectedDate = null;
document.querySelectorAll('#pa-sched-dates .pa-sched-date').forEach(b => b.classList.remove('is-selected'));
}
function paSchedLoadSlots(date) {
_paSchedError('');
const step = _paSchedEl('pa-sched-times-step');
const times = _paSchedEl('pa-sched-times');
const tzLab = _paSchedEl('pa-sched-times-tz');
if (!step || !times) return;
step.style.display = 'block';
times.innerHTML = '<div class="pa-sched-empty">Loading…</div>';
if (tzLab && _paSchedTzList) tzLab.textContent = '(' + (_paSchedTzList[_paSchedTz] || _paSchedTz) + ')';
const token = localStorage.getItem('uc_chat_token');
fetch('ajax/proassist-schedule.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'slots', token, date, tz: _paSchedTz }),
})
.then(r => r.json())
.then(j => {
if (!j || !j.ok) { times.innerHTML = '<div class="pa-sched-empty">Could not load times.</div>'; return; }
times.innerHTML = '';
const avail = (j.slots || []).filter(s => !s.past && !s.taken);
if (avail.length === 0) {
times.innerHTML = '<div class="pa-sched-empty">No times left on this day — try another date.</div>';
return;
}
(j.slots || []).forEach(function (s) {
const b = document.createElement('button');
b.type = 'button';
b.className = 'pa-sched-time' + (s.taken ? ' is-taken' : '') + (s.past ? ' is-past' : '');
b.textContent = s.label;
b.setAttribute('data-testid', 'pa-sched-time-' + s.time);
if (!s.taken && !s.past) {
b.addEventListener('click', function () { paSchedBook(date, s.time); });
}
times.appendChild(b);
});
})
.catch(() => { times.innerHTML = '<div class="pa-sched-empty">Network error — please retry.</div>'; });
}
function paSchedBook(date, time) {
_paSchedError('');
const token = localStorage.getItem('uc_chat_token');
fetch('ajax/proassist-schedule.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ action: 'book', token, date, time, tz: _paSchedTz }),
})
.then(r => r.json())
.then(j => {
if (!j || !j.ok) { _paSchedError((j && j.error) || 'Could not book that slot — please try another.'); return; }
paSchedShowConfirmed(j.schedule, j.rescheduled);
const verb = j.rescheduled ? 'Rescheduled' : 'Confirmed';
chatAppend('bot', '✅ ' + verb + ' — your install call is booked for ' + ((j.schedule && j.schedule.pretty) || '') + '.');
})
.catch(() => _paSchedError('Network error — please retry.'));
}
function paSchedShowConfirmed(sched, rescheduled) {
const card = _paSchedEl('pa-sched-card');
const conf = _paSchedEl('pa-sched-confirm');
if (card) card.style.display = 'none';
if (!conf) return;
const pretty = (sched && sched.pretty) || 'your selected time';
conf.innerHTML =
'<div class="pa-sched-confirm-icon"><i class="bi bi-check-circle-fill"></i></div>'
+ '<div class="pa-sched-confirm-title">Install call ' + (rescheduled ? 'rescheduled' : 'scheduled') + '!</div>'
+ '<div class="pa-sched-confirm-when">' + pretty + '</div>'
+ '<button type="button" class="pa-sched-reschedule" onclick="paSchedReschedule()" data-testid="pa-sched-reschedule">Reschedule</button>';
conf.style.display = 'block';
revealChatInputRow();
}
function paSchedReschedule() { paSchedShowPicker(); }
(function(){
const origToggle = window.toggleChat;
window.toggleChat = function(){
if (typeof origToggle === 'function') origToggle.apply(this, arguments);
const panel = document.getElementById('chat-panel');
if (panel && panel.classList.contains('open')) {
setTimeout(paSchedInit, 50);
}
};
})();
document.addEventListener('DOMContentLoaded', () => {
if (localStorage.getItem('uc_chat_token')) {
setTimeout(paSchedInit, 400);
}
});
function quickAsk(text) {
const chips = document.getElementById('chat-chips');
if (chips) chips.remove();
const input = document.getElementById('chat-input');
input.value = text;
sendChat(new Event('submit'));
}
async function sendChat(ev) {
ev.preventDefault();
const input = document.getElementById('chat-input');
const msg = input.value.trim();
if (!msg) return;
input.value = '';
pingCustomerTyping(false);
chatAppend('user', msg);
relayCustomerMessageToAdmin(msg);
if (localStorage.getItem('uc_chat_human_only') === '1') {
if (!window._humanOnlyAckedOnce) {
window._humanOnlyAckedOnce = true;
const ack = chatAppend('bot',
'Got it — your message was sent to our support team. A live agent will reply here as soon as they\'re free.'
);
if (ack) ack.classList.add('agent-greeting');
}
return;
}
const typing = chatAppend('bot', '');
typing.classList.add('typing');
typing.innerHTML = '<span></span><span></span><span></span>';
let sid = localStorage.getItem('uc_chat_session');
if (!sid) { sid = 's' + Date.now() + Math.random().toString(36).slice(2, 8); localStorage.setItem('uc_chat_session', sid); }
try {
const res = await fetch('ajax/chat.php', {
method: 'POST',
headers: { 'Content-Type': 'application/json' },
body: JSON.stringify({ message: msg, session_id: sid }),
});
const data = await res.json();
typing.classList.remove('typing');
typing.textContent = data.reply;
} catch (err) {
typing.classList.remove('typing');
typing.textContent = 'Sorry, something went wrong. Call us at ' + (window.SITE_PHONE || '1-888-632-9902') + ' or email us.';
}
}
function chatAttachClick() {
const inp = document.getElementById('chat-file-input');
if (inp) inp.click();
}
document.addEventListener('DOMContentLoaded', function () {
const inp = document.getElementById('chat-file-input');
if (inp) {
inp.addEventListener('change', function () {
if (inp.files && inp.files[0]) { chatUploadFile(inp.files[0], 'file'); inp.value = ''; }
});
}
});
function _chatAttachStatus(msg, isErr) {
const s = document.getElementById('chat-attach-status');
if (!s) return;
if (!msg) { s.style.display = 'none'; s.textContent = ''; return; }
s.textContent = msg;
s.style.display = 'block';
s.style.color = isErr ? '#dc2626' : 'var(--chat-text-soft,#64748b)';
}
function _chatEsc(s) {
return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}
function renderAttachmentHTML(att) {
const url = _chatEsc(att.attachment_url);
const name = _chatEsc(att.attachment_name || 'attachment');
if (att.attachment_type === 'image') {
return '<a href="' + url + '" target="_blank" rel="noopener"><img src="' + url + '" alt="' + name + '" class="chat-msg-img"></a>';
}
if (att.attachment_type === 'audio') {
return '<audio controls preload="metadata" src="' + url + '" class="chat-msg-audio"></audio>';
}
return '<a href="' + url + '" target="_blank" rel="noopener" download class="chat-msg-file"><i class="bi bi-paperclip"></i> ' + name + '</a>';
}
function chatAppendAttachment(role, att) {
const body = document.getElementById('chat-body');
if (!body) return null;
const div = document.createElement('div');
div.className = 'chat-msg ' + role + ' chat-msg-attach';
div.innerHTML = renderAttachmentHTML(att);
body.appendChild(div);
body.scrollTop = body.scrollHeight;
return div;
}
async function chatUploadFile(file, kind) {
const token = localStorage.getItem('uc_chat_token');
if (!token) {
_chatAttachStatus('Please share your contact details first, then you can attach files.', true);
const form = document.getElementById('chat-lead-form');
if (form) form.style.display = 'block';
return;
}
_chatAttachStatus(kind === 'voice' ? 'Sending voice message…' : 'Uploading…', false);
const fd = new FormData();
fd.append('file', file, file.name || (kind === 'voice' ? 'voice.webm' : 'file'));
fd.append('token', token);
fd.append('kind', kind || 'file');
try {
const r = await fetch('ajax/chat-upload.php', { method: 'POST', body: fd });
const j = await r.json().catch(() => ({}));
if (!j || !j.ok) { _chatAttachStatus((j && j.error) || 'Upload failed — please try again.', true); return; }
_chatAttachStatus('', false);
chatAppendAttachment('user', j.message);
} catch (e) {
_chatAttachStatus('Network error — please retry.', true);
}
}
const _CHAT_EMOJIS = ['😀','😃','😁','😉','😊','😍','😘','😎','🤔','🙂','🙏','👍','👎','👌','👏','🙌','💪','🎉','🔥','✨','❤️','💙','💚','💜','😢','😅','😂','🤣','😭','😡','😱','🤝','💻','📎','📧','📞','✅','❌','⭐','💯'];
function chatToggleEmoji(ev) {
if (ev) ev.stopPropagation();
const panel = document.getElementById('chat-emoji-panel');
if (!panel) return;
if (panel.style.display === 'grid') { panel.style.display = 'none'; return; }
if (!panel.dataset.built) {
_CHAT_EMOJIS.forEach(function (e) {
const b = document.createElement('button');
b.type = 'button';
b.className = 'chat-emoji-item';
b.textContent = e;
b.addEventListener('click', function () {
const inp = document.getElementById('chat-input');
if (inp) { inp.value += e; inp.focus(); }
});
panel.appendChild(b);
});
panel.dataset.built = '1';
}
panel.style.display = 'grid';
}
document.addEventListener('click', function (e) {
const panel = document.getElementById('chat-emoji-panel');
const btn = document.getElementById('chat-emoji-btn');
if (!panel || panel.style.display !== 'grid') return;
if (panel.contains(e.target) || (btn && btn.contains(e.target))) return;
panel.style.display = 'none';
});
/* ---- Voice typing (Web Speech API → text in the input, sent as a normal
text message, NOT an audio recording) ---- */
/* ---- Voice typing (record → Whisper transcription → text in the input).
Uses MediaRecorder (universal: Chrome/Edge/Firefox/Safari) + server-side
Whisper, which is far more reliable than the browser Web Speech API. ---- */
let _chatRec = null, _chatRecChunks = [], _chatRecStream = null, _chatRecTimer = null, _chatRecStart = 0, _chatRecBusy = false;
function _chatStopTracks() {
if (_chatRecStream) { try { _chatRecStream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) {} _chatRecStream = null; }
}
function _chatVoiceTimer(on) {
const t = document.getElementById('chat-voice-timer'), tm = document.getElementById('chat-voice-time');
if (!t) return;
if (on) {
t.style.display = 'inline-flex'; _chatRecStart = Date.now();
_chatRecTimer = setInterval(function () {
const s = Math.floor((Date.now() - _chatRecStart) / 1000);
if (tm) tm.textContent = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
if (s >= 90 && _chatRec && _chatRec.state === 'recording') _chatRec.stop();
}, 250);
} else {
if (_chatRecTimer) { clearInterval(_chatRecTimer); _chatRecTimer = null; }
t.style.display = 'none';
}
}
async function chatToggleVoice() {
const micBtn = document.getElementById('chat-mic-btn');
if (_chatRec && _chatRec.state === 'recording') { try { _chatRec.stop(); } catch (e) {} return; }
if (_chatRecBusy) return;
if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
_chatAttachStatus('Voice typing is not supported on this browser — please type your message.', true);
return;
}
_chatAttachStatus('Starting microphone…', false);
let stream;
try {
stream = await navigator.mediaDevices.getUserMedia({ audio: true });
} catch (e) {
let msg = 'Microphone is blocked. Allow mic access in your browser and try again.';
const n = (e && e.name) || '';
if (n === 'NotFoundError' || n === 'DevicesNotFoundError') msg = 'No microphone was found on this device.';
else if (n === 'NotReadableError') msg = 'Your microphone is in use by another app.';
else if (window.self !== window.top) msg += ' If it stays blocked, open the site in its own browser tab.';
_chatAttachStatus(msg, true);
return;
}
_chatRecStream = stream;
_chatRecChunks = [];
let mime = '';
['audio/webm', 'audio/mp4', 'audio/ogg'].some(function (m) { if (MediaRecorder.isTypeSupported(m)) { mime = m; return true; } return false; });
try { _chatRec = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream); }
catch (e) { _chatRec = new MediaRecorder(stream); }
_chatRec.ondataavailable = function (e) { if (e.data && e.data.size) _chatRecChunks.push(e.data); };
_chatRec.onstart = function () {
if (micBtn) micBtn.classList.add('is-recording');
_chatAttachStatus('Listening… tap the mic again when you\u2019re done', false);
_chatVoiceTimer(true);
};
_chatRec.onstop = async function () {
if (micBtn) micBtn.classList.remove('is-recording');
_chatVoiceTimer(false);
_chatStopTracks();
const blob = new Blob(_chatRecChunks, { type: (_chatRec && _chatRec.mimeType) || 'audio/webm' });
if (!blob.size) { _chatAttachStatus('Didn\u2019t catch any audio — try again.', true); return; }
_chatRecBusy = true;
_chatAttachStatus('Transcribing…', false);
const token = localStorage.getItem('uc_chat_token');
const fd = new FormData();
fd.append('audio', blob, 'voice.webm');
if (token) fd.append('token', token);
try {
const r = await fetch('ajax/transcribe.php', { method: 'POST', body: fd, credentials: 'same-origin' });
const j = await r.json().catch(function () { return null; });
if (j && j.ok && (j.text || '').trim()) {
const inp = document.getElementById('chat-input');
if (inp) {
inp.value = (inp.value ? inp.value.trim() + ' ' : '') + j.text.trim();
inp.focus();
pingCustomerTyping(true);
}
_chatAttachStatus('', false);
} else if (j && j.ok) {
_chatAttachStatus('Didn\u2019t catch that — please try again.', true);
} else {
_chatAttachStatus((j && j.error) || 'Could not transcribe — please type your message.', true);
}
} catch (e) {
_chatAttachStatus('Network error — please try again or type your message.', true);
} finally {
_chatRecBusy = false;
}
};
try { _chatRec.start(); }
catch (e) { _chatStopTracks(); _chatAttachStatus('Could not start recording — please type your message.', true); }
}
(() => {
if (!('IntersectionObserver' in window) ||
window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
const cols = document.querySelectorAll('section .row > [class*="col-"], .accordion-item');
cols.forEach((el) => {
const idx = Array.prototype.indexOf.call(el.parentElement.children, el);
el.classList.add('reveal');
el.style.transitionDelay = `${Math.min(idx, 5) * 70}ms`;
});
const io = new IntersectionObserver((entries) => {
entries.forEach((e) => {
if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
});
}, { threshold: 0.06, rootMargin: '0px 0px -30px 0px' });
document.querySelectorAll('.reveal').forEach((el) => io.observe(el));
})();
(() => {
const bar = document.getElementById('deal-bar');
if (!bar) return;
if (sessionStorage.getItem('uc_dealbar_dismissed') === '1') { bar.remove(); return; }
document.body.classList.add('has-deal-bar');
bar.querySelector('.deal-bar-close-x, .deal-close').addEventListener('click', () => {
sessionStorage.setItem('uc_dealbar_dismissed', '1');
bar.remove();
document.body.classList.remove('has-deal-bar');
});
const out = document.getElementById('deal-countdown');
const pad = (n) => String(n).padStart(2, '0');
const tick = () => {
const now = new Date();
const end = new Date(now);
end.setHours(24, 0, 0, 0);
let s = Math.max(0, Math.floor((end - now) / 1000));
const h = Math.floor(s / 3600); s %= 3600;
out.textContent = pad(h) + ':' + pad(Math.floor(s / 60)) + ':' + pad(s % 60);
};
tick();
setInterval(tick, 1000);
})();
(() => {
const frame = document.querySelector('.pd-360-frame');
if (!frame || !window.matchMedia('(prefers-reduced-motion: no-preference)').matches) return;
const img = frame.querySelector('.pd-360-img');
let dragging = false, startX = 0, baseRy = 0, ry = 0;
frame.addEventListener('pointerdown', (e) => {
dragging = true; startX = e.clientX; baseRy = ry;
frame.classList.add('dragging');
frame.setPointerCapture(e.pointerId);
e.preventDefault();
});
frame.addEventListener('pointermove', (e) => {
if (dragging) {
ry = baseRy + (e.clientX - startX) * 0.9;
img.style.setProperty('--ry', ry.toFixed(1) + 'deg');
img.style.setProperty('--rx', '0deg');
} else {
const r = frame.getBoundingClientRect();
const x = (e.clientX - r.left) / r.width - 0.5;
const y = (e.clientY - r.top) / r.height - 0.5;
frame.classList.add('tilting');
ry = x * 46;
img.style.setProperty('--ry', ry.toFixed(1) + 'deg');
img.style.setProperty('--rx', (-y * 18).toFixed(1) + 'deg');
}
});
const endDrag = () => { dragging = false; frame.classList.remove('dragging'); };
frame.addEventListener('pointerup', endDrag);
frame.addEventListener('pointercancel', endDrag);
frame.addEventListener('pointerleave', () => { endDrag(); frame.classList.remove('tilting'); ry = 0; });
})();
(() => {
const icons = document.querySelectorAll('.hero-big-icon');
if (icons.length < 2) return;
let i = 0;
setInterval(() => {
icons[i].classList.remove('active');
i = (i + 1) % icons.length;
icons[i].classList.add('active');
}, 3000);
})();
(() => {
if (!window.matchMedia('(prefers-reduced-motion: no-preference)').matches) return;
const stage = document.querySelector('.hero-stage');
const frame = document.querySelector('.hero-showcase-frame');
if (stage && frame) {
frame.addEventListener('pointermove', (e) => {
const r = frame.getBoundingClientRect();
const x = (e.clientX - r.left) / r.width - 0.5;
const y = (e.clientY - r.top) / r.height - 0.5;
stage.classList.add('tilting');
stage.style.setProperty('--ry', (x * 38).toFixed(1) + 'deg');
stage.style.setProperty('--rx', (-y * 22).toFixed(1) + 'deg');
});
frame.addEventListener('pointerleave', () => stage.classList.remove('tilting'));
}
const bindTilt = (el, maxY, maxX) => {
el.addEventListener('pointermove', (e) => {
const r = el.getBoundingClientRect();
const x = (e.clientX - r.left) / r.width - 0.5;
const y = (e.clientY - r.top) / r.height - 0.5;
el.classList.add('tilting');
el.style.setProperty('--ry', (x * maxY).toFixed(1) + 'deg');
el.style.setProperty('--rx', (-y * maxX).toFixed(1) + 'deg');
});
el.addEventListener('pointerleave', () => el.classList.remove('tilting'));
};
document.querySelectorAll('.logo-3d').forEach((el) => bindTilt(el, 70, 50));
document.querySelectorAll('.product-card.tilt-3d').forEach((el) => bindTilt(el, 16, 12));
})();
/* =====================================================================
* CSP-safe chat wiring.
* Some live hosts (cPanel / Cloudflare / security plugins) add a
* Content-Security-Policy that BLOCKS inline onclick/onsubmit handlers — which
* stops the chat from opening on the deployed site even though it works in
* preview. We re-wire every chat control here via JavaScript (allowed by
* script-src 'self'), so the chat works regardless of CSP. The original inline
* handlers stay for non-CSP hosts; toggleChat() is debounced so a double-fire
* is harmless.
* ===================================================================== */
(function () {
function callFn(name, arg) { try { if (typeof window[name] === 'function') window[name](arg); } catch (e) {} }
document.addEventListener('click', function (e) {
var el = e.target && e.target.closest ? e.target.closest('[data-testid]') : null;
if (!el) return;
switch (el.getAttribute('data-testid')) {
case 'chat-bubble':
case 'chat-back':
case 'chat-menu':
case 'chat-close': e.preventDefault(); callFn('toggleChat'); break;
case 'chat-msg-preview': e.preventDefault(); callFn('openChatFromPreview'); break;
case 'chat-msg-preview-close':e.preventDefault(); e.stopPropagation(); callFn('hideChatMsgPreview'); break;
case 'lead-send-btn':
case 'lead-chat-btn': e.preventDefault(); if (typeof submitLead === 'function') submitLead('chat'); break;
case 'chat-attach-btn': e.preventDefault(); callFn('chatAttachClick'); break;
case 'chat-mic-btn': e.preventDefault(); callFn('chatToggleVoice'); break;
case 'chat-emoji-btn': e.preventDefault(); if (typeof chatToggleEmoji === 'function') chatToggleEmoji(e); break;
case 'pa-sched-back': e.preventDefault(); callFn('paSchedBackToDates'); break;
}
}, false);
document.addEventListener('submit', function (e) {
var f = e.target;
if (!f || !f.id) return;
if (f.id === 'chat-input-row') { e.preventDefault(); if (typeof sendChat === 'function') sendChat(e); }
}, false);
})();