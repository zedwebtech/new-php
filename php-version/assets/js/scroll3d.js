/* =====================================================================
 * scroll3d.js — tasteful 3D scroll effects for the Maventech store.
 *
 *  1) Scroll-reveal: cards & sections gently lift toward the viewer
 *     (translateY + rotateX + fade) as they enter the viewport, with a
 *     light per-row stagger. Runs everywhere inside #main-content.
 *  2) Pointer 3D tilt: generic content cards tilt subtly under the cursor
 *     on desktop (fine-pointer) devices only.
 *
 *  Fully reduced-motion aware (bails out entirely) and progressive: if JS
 *  is off nothing is hidden. Reveal classes are removed once the entry
 *  animation completes so the theme's own :hover transforms are untouched.
 * ===================================================================== */
(function () {
  'use strict';

  var mqReduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)');
  if (mqReduce && mqReduce.matches) return;            // respect reduced motion
  if (!('IntersectionObserver' in window)) return;     // graceful no-op

  var root = document.getElementById('main-content') || document.body;
  if (!root) return;

  /* ---- 1) Collect reveal targets (skip nested cards to avoid double anim) ---- */
  var REVEAL_SEL = [
    '.card', '.product-card', '.spotlight-card',
    '.home-review-card', '.blog-card', '[data-reveal]'
  ].map(function (s) { return '#main-content ' + s; }).join(',');

  var nodes = Array.prototype.slice.call(document.querySelectorAll(REVEAL_SEL));
  var targets = [];
  nodes.forEach(function (el) {
    if (el.classList.contains('no-3d')) return;
    // Skip if an ancestor is already a reveal target (prevents nested re-anim).
    if (el.parentElement && el.parentElement.closest('.s3d')) return;
    el.classList.add('s3d');
    // Light stagger based on position among same-parent targets.
    var idx = 0, sib = el.previousElementSibling;
    while (sib) { if (sib.classList && sib.classList.contains('s3d')) idx++; sib = sib.previousElementSibling; }
    var delay = Math.min(idx, 5) * 60;
    if (delay) el.style.transitionDelay = delay + 'ms';
    targets.push(el);
  });

  function cleanup(el) {
    el.classList.remove('s3d', 's3d-in');
    el.style.transitionDelay = '';
    el.style.willChange = '';
  }

  var io = new IntersectionObserver(function (entries, obs) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      var el = entry.target;
      obs.unobserve(el);
      el.classList.add('s3d-in');
      var done = false;
      var finish = function () { if (done) return; done = true; cleanup(el); };
      el.addEventListener('transitionend', function te(ev) {
        if (ev.propertyName === 'transform') { el.removeEventListener('transitionend', te); finish(); }
      });
      // Fallback in case transitionend doesn't fire (e.g. tab backgrounded).
      setTimeout(finish, 1200);
    });
  }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });

  targets.forEach(function (el) { io.observe(el); });

  /* ---- 2) Subtle pointer 3D tilt on safe content cards (desktop only) ---- */
  var finePointer = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  var wideEnough  = window.innerWidth >= 992;
  if (!finePointer || !wideEnough) return;

  // Exclude cards that already carry meaningful :hover transforms in the theme.
  var EXCLUDE = '.product-card, .spotlight-card, .shop-row, .side-product-row, .home-review-card, .blog-card, .strip-card';
  var tiltCards = Array.prototype.slice
    .call(document.querySelectorAll('#main-content .card'))
    .filter(function (el) {
      if (el.classList.contains('no-3d')) return false;
      if (el.closest(EXCLUDE)) return false;
      if (el.querySelector('.card')) return false; // don't tilt big wrappers
      return true;
    });

  var MAX = 5; // degrees — keep it subtle/professional
  tiltCards.forEach(function (el) {
    el.classList.add('s3d-tilt');
    var raf = null;
    el.addEventListener('mousemove', function (e) {
      if (raf) return;
      raf = requestAnimationFrame(function () {
        raf = null;
        var r = el.getBoundingClientRect();
        var px = (e.clientX - r.left) / r.width - 0.5;
        var py = (e.clientY - r.top) / r.height - 0.5;
        el.classList.add('s3d-tilting');
        el.style.transform = 'perspective(900px) rotateX(' + (-py * MAX).toFixed(2) +
          'deg) rotateY(' + (px * MAX).toFixed(2) + 'deg) translateZ(0)';
      });
    });
    el.addEventListener('mouseleave', function () {
      el.classList.remove('s3d-tilting');
      el.style.transform = '';
    });
  });
})();
