(function ($) {
  'use strict';

  var $bgImage = $('.bg-layer__image');
  var $bgVeil = $('.bg-layer__veil');
  var $gridOverlay = $('.grid-overlay');
  var $heroContent = $('.hero-content');
  var $header = $('.site-header');
  var $progressBar = $('.scroll-progress__bar');
  var $coordX = $('[data-coord="x"]');
  var $coordY = $('[data-coord="y"]');
  var $coordScroll = $('[data-coord="scroll"]');

  function syncHeaderOffset() {
    var height = $header.outerHeight();
    document.documentElement.style.setProperty('--header-offset', height + 'px');
    ScrollTrigger.refresh();
  }

  syncHeaderOffset();
  $(window).on('load', syncHeaderOffset);

  /* ── Lenis smooth scroll ─────────────────────────────────────── */
  var lenis = null;
  if (typeof Lenis !== 'undefined') {
    lenis = new Lenis({
      duration: 1.1,
      easing: function (t) { return Math.min(1, 1.001 - Math.pow(2, -10 * t)); },
      smoothWheel: true
    });

    lenis.on('scroll', ScrollTrigger.update);

    gsap.ticker.add(function (time) {
      lenis.raf(time * 1000);
    });
    gsap.ticker.lagSmoothing(0);
  }

  gsap.registerPlugin(ScrollTrigger);

  /* ── Intro animations ────────────────────────────────────────── */
  var headerTl = gsap.timeline({ defaults: { ease: 'power3.out' } });
  headerTl
    .from('[data-animate="header"]', { y: -20, opacity: 0, duration: 0.7 })
    .from('.brand-logo', { scale: 0.6, opacity: 0, rotate: -8, duration: 0.55, ease: 'back.out(1.6)' }, '-=0.45')
    .from('.brand-name', { x: -10, opacity: 0, duration: 0.4 }, '-=0.25')
    .from('.user-menu > li', { y: 12, opacity: 0, stagger: 0.04, duration: 0.45 }, '-=0.3')
    .from('.private-menu > li', { y: 12, opacity: 0, stagger: 0.04, duration: 0.45 }, '-=0.35')
    .from('.admin-menu > li', { y: 12, opacity: 0, stagger: 0.04, duration: 0.45 }, '-=0.35')
    .from('.btn-login', { scale: 0.85, opacity: 0, duration: 0.4 }, '-=0.2');

  gsap.from('.bg-layer__image', {
    scale: 1.15,
    opacity: 0,
    duration: 1.6,
    ease: 'power2.out'
  });

  gsap.from('.title-line', {
    y: 60,
    opacity: 0,
    rotateX: -12,
    stagger: 0.12,
    duration: 0.9,
    ease: 'power3.out',
    delay: 0.35
  });

  gsap.from('.hero-lede, .hero-specs li', {
    y: 24,
    opacity: 0,
    stagger: 0.08,
    duration: 0.7,
    ease: 'power2.out',
    delay: 0.7
  });

  /* ── Full-screen background scroll parallax ──────────────────── */
  gsap.to($bgImage, {
    yPercent: 28,
    scale: 1.18,
    ease: 'none',
    scrollTrigger: {
      trigger: 'body',
      start: 'top top',
      end: 'bottom bottom',
      scrub: 1.2
    }
  });

  gsap.to($bgImage, {
    filter: 'saturate(0.65) contrast(1.15) brightness(0.75)',
    ease: 'none',
    scrollTrigger: {
      trigger: '.site-main',
      start: 'top top',
      end: 'bottom bottom',
      scrub: true
    }
  });

  gsap.set($bgVeil, { opacity: 0.55 });

  gsap.to($bgVeil, {
    opacity: 0.92,
    ease: 'none',
    scrollTrigger: {
      trigger: '.site-main',
      start: 'top top',
      end: 'bottom bottom',
      scrub: true
    }
  });

  /* ── Grid overlay drift ──────────────────────────────────────── */
  gsap.to($gridOverlay, {
    y: 120,
    opacity: 0.25,
    ease: 'none',
    scrollTrigger: {
      trigger: 'body',
      start: 'top top',
      end: 'bottom bottom',
      scrub: 1.5
    }
  });

  /* ── Hero content scroll-out ─────────────────────────────────── */
  gsap.to($heroContent, {
    y: -140,
    opacity: 0,
    scale: 0.94,
    filter: 'blur(6px)',
    ease: 'power2.in',
    scrollTrigger: {
      trigger: '.hero',
      start: 'top top',
      end: 'bottom top',
      scrub: 1
    }
  });

  /* ── Scroll progress bar ─────────────────────────────────────── */
  gsap.to($progressBar, {
    width: '100%',
    ease: 'none',
    scrollTrigger: {
      trigger: 'body',
      start: 'top top',
      end: 'bottom bottom',
      scrub: 0.3,
      onUpdate: function (self) {
        $coordScroll.text(Math.round(self.progress * 100));
      }
    }
  });

  /* ── Header densifies on scroll ──────────────────────────────── */
  ScrollTrigger.create({
    trigger: '.site-main',
    start: 'top top',
    end: '+=200',
    onEnter: function () { $header.addClass('is-scrolled'); },
    onLeaveBack: function () { $header.removeClass('is-scrolled'); }
  });

  /* ── Content panels rise into view ─────────────────────────────── */
  $('.content-panel').each(function () {
    gsap.from(this, {
      scrollTrigger: {
        trigger: this,
        start: 'top 92%',
        end: 'top 55%',
        scrub: 1
      },
      y: 80,
      opacity: 0.4,
      ease: 'power2.out'
    });
  });

  /* ── Section headers slide on scroll ─────────────────────────── */
  $('.section-header').each(function () {
    gsap.from(this, {
      scrollTrigger: {
        trigger: this,
        start: 'top 90%',
        toggleActions: 'play none none reverse'
      },
      x: -40,
      opacity: 0,
      duration: 0.7,
      ease: 'power2.out'
    });

    gsap.to(this, {
      scrollTrigger: {
        trigger: this.closest('section'),
        start: 'top bottom',
        end: 'bottom top',
        scrub: 1.5
      },
      x: 30,
      ease: 'none'
    });
  });

  /* ── Project cards ─────────────────────────────────────────────── */
  $('ul.project-list > li.project-card').each(function (i, el) {
    gsap.from(el, {
      scrollTrigger: {
        trigger: el,
        start: 'top 88%',
        toggleActions: 'play none none reverse'
      },
      y: 50,
      opacity: 0,
      rotateX: 8,
      duration: 0.65,
      delay: i * 0.08,
      ease: 'power2.out'
    });
  });

  /* ── Capability rows ─────────────────────────────────────────── */
  $('ol.capability-list > li').each(function (i, el) {
    gsap.from(el, {
      scrollTrigger: {
        trigger: el,
        start: 'top 90%',
        toggleActions: 'play none none reverse'
      },
      x: -40,
      opacity: 0,
      duration: 0.55,
      delay: i * 0.06,
      ease: 'power2.out'
    });
  });

  /* ── Footer fade-in ──────────────────────────────────────────── */
  gsap.from('.site-footer', {
    scrollTrigger: {
      trigger: '.site-footer',
      start: 'top 95%',
      toggleActions: 'play none none reverse'
    },
    y: 30,
    opacity: 0,
    duration: 0.6,
    ease: 'power2.out'
  });

  /* ── Mouse parallax on full-screen background ────────────────── */
  var mouseX = 0;
  var mouseY = 0;

  $(window).on('mousemove', function (e) {
    mouseX = (e.clientX / window.innerWidth - 0.5) * 2;
    mouseY = (e.clientY / window.innerHeight - 0.5) * 2;

    gsap.to($bgImage, {
      x: mouseX * 22,
      duration: 1.2,
      ease: 'power2.out',
      overwrite: 'auto'
    });

    gsap.to($gridOverlay, {
      x: mouseX * -8,
      y: mouseY * -6,
      duration: 1.4,
      ease: 'power2.out',
      overwrite: 'auto'
    });

    $coordX.text(mouseX.toFixed(3));
    $coordY.text(mouseY.toFixed(3));
  });

  /* ── Project card magnetic tilt ──────────────────────────────── */
  $('ul.project-list > li.project-card').on('mousemove', function (e) {
    var rect = this.getBoundingClientRect();
    var x = e.clientX - rect.left - rect.width / 2;
    var y = e.clientY - rect.top - rect.height / 2;

    gsap.to(this, {
      rotateY: x / 25,
      rotateX: -y / 25,
      transformPerspective: 800,
      duration: 0.4,
      ease: 'power2.out'
    });
  }).on('mouseleave', function () {
    gsap.to(this, { rotateY: 0, rotateX: 0, duration: 0.6, ease: 'power2.out' });
  });

  /* ── Menu hover accent line ──────────────────────────────────── */
  $('.menu-cluster > ul').each(function () {
    var $ul = $(this);
    var color = $ul.hasClass('user-menu') ? '#78ffc8'
      : $ul.hasClass('private-menu') ? '#8b9dff'
      : '#ff6b9d';

    $ul.on('mouseenter', 'li > a', function () {
      gsap.to(this, { boxShadow: 'inset 0 -1px 0 ' + color, duration: 0.2 });
    }).on('mouseleave', 'li > a', function () {
      gsap.to(this, { boxShadow: 'inset 0 -1px 0 transparent', duration: 0.2 });
    });
  });

  $('#login-btn').on('click', function () {
    window.location.href = '/login';
  });

  /* ── Live telemetry readout ──────────────────────────────────── */
  var frameCount = 0;
  var lastFpsTime = performance.now();
  var $fpsEl = $('[data-telemetry="fps"]');
  var $timeEl = $('[data-telemetry="time"]');

  function updateTelemetry(now) {
    frameCount++;
    if (now - lastFpsTime >= 1000) {
      $fpsEl.text('fps ' + frameCount);
      frameCount = 0;
      lastFpsTime = now;
    }

    var d = new Date();
    var pad = function (n) { return String(n).padStart(2, '0'); };
    $timeEl.text(
      pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds())
    );

    requestAnimationFrame(updateTelemetry);
  }
  requestAnimationFrame(updateTelemetry);

  $('#year').text(new Date().getFullYear());

  var resizeTimer;
  $(window).on('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      syncHeaderOffset();
    }, 200);
  });

})(jQuery);
