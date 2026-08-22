(() => {
  'use strict';
  const API_BASE_URL = (window.TSCA_API_BASE_URL || 'http://127.0.0.1:8081/api/v1').replace(/\/+$/, '');
  const year = document.getElementById('year');
  if (year) year.textContent = new Date().getFullYear();
  const mobileBtn = document.getElementById('mobileBtn');
  const mobileMenu = document.getElementById('mobileMenu');
  if (mobileBtn && mobileMenu) {
    mobileBtn.addEventListener('click', () => {
      const hidden = mobileMenu.classList.toggle('hidden');
      mobileBtn.setAttribute('aria-expanded', String(!hidden));
      mobileBtn.innerHTML = hidden ? '<span class="text-xl text-[var(--text-dark)]">&#9776;</span>' : '<span class="text-xl text-[var(--text-dark)]">&#10005;</span>';
    });
    mobileMenu.querySelectorAll('a').forEach(link => { link.addEventListener('click', () => mobileMenu.classList.add('hidden')); });
  }
  const slider = document.querySelector('.hero-slider');
  const progressBar = document.getElementById('heroProgressBar');
  const SLIDE_INTERVAL = 6000;
  if (slider) {
    const slides = [...slider.querySelectorAll('.hero-slide')];
    const dots = [...slider.querySelectorAll('.hero-dot')];
    let current = 0, timer;
    const preload = () => slides.forEach(s => { const src = s.dataset.image; if (src) { new Image().src = src; } });
    const animateProgress = () => {
      if (!progressBar) return;
      progressBar.style.transition = 'none'; progressBar.style.width = '0%';
      requestAnimationFrame(() => { requestAnimationFrame(() => { progressBar.style.transition = 'width ' + SLIDE_INTERVAL + 'ms linear'; progressBar.style.width = '100%'; }); });
    };
    const showSlide = (index) => {
      current = (index + slides.length) % slides.length;
      slides.forEach((s, i) => s.classList.toggle('is-active', i === current));
      dots.forEach((d, i) => { d.classList.toggle('is-active', i === current); d.setAttribute('aria-current', i === current ? 'true' : 'false'); });
      animateProgress();
    };
    const start = () => {
      clearInterval(timer);
      if (slides.length > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) { showSlide(current); timer = setInterval(() => showSlide(current + 1), SLIDE_INTERVAL); }
    };
    dots.forEach(dot => dot.addEventListener('click', () => { showSlide(Number(dot.dataset.slide || 0)); start(); }));
    slider.addEventListener('mouseenter', () => clearInterval(timer));
    slider.addEventListener('mouseleave', start);
    slider.addEventListener('focusin', () => clearInterval(timer));
    slider.addEventListener('focusout', start);
    preload(); showSlide(0); start();
  }
  const form = document.getElementById('connectForm');
  const statusText = document.getElementById('formStatus');
  const submitBtn = document.getElementById('submitBtn');
  if (form && statusText && submitBtn) {
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (submitBtn.disabled) return;
      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Processing...';
      statusText.className = 'text-center text-sm mt-4 font-medium';
      statusText.textContent = 'Submitting your registration...';
      const payload = Object.fromEntries(new FormData(form).entries());
      try {
        const response = await fetch(API_BASE_URL + '/registry', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) });
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.success) throw new Error(result.error || 'We could not complete your registration.');
        statusText.className = 'text-center text-sm mt-4 font-medium status-success fade-in';
        statusText.innerHTML = 'Thank you! Your registration was received. <strong>Registry ID: ' + escapeHtml(result.data.registry_number) + '</strong>';
        form.reset();
      } catch (error) {
        statusText.className = 'text-center text-sm mt-4 font-medium status-error fade-in';
        statusText.textContent = error instanceof Error ? error.message : 'Something went wrong. Please try again.';
      } finally { submitBtn.disabled = false; submitBtn.textContent = 'SUBMIT REGISTRATION'; }
    });
  }
  const loadMoreBtn = document.getElementById('loadMoreBtn');
  const hiddenGalleryItems = document.querySelectorAll('.gallery-hidden.hidden');
  if (loadMoreBtn) {
    if (hiddenGalleryItems.length) {
      loadMoreBtn.addEventListener('click', () => {
        hiddenGalleryItems.forEach((item, i) => { setTimeout(() => { item.classList.remove('hidden'); item.classList.add('fade-in'); }, i * 120); });
        loadMoreBtn.style.display = 'none';
      });
    } else { loadMoreBtn.style.display = 'none'; }
  }
  const lightbox = document.getElementById('lightbox');
  const lightboxImg = document.getElementById('lightboxImg');
  const lightboxCaption = document.getElementById('lightboxCaption');
  const lightboxCounter = document.getElementById('lightboxCounter');
  const lightboxClose = document.getElementById('lightboxClose');
  const lightboxPrev = document.getElementById('lightboxPrev');
  const lightboxNext = document.getElementById('lightboxNext');
  let lightboxItems = [], lightboxIndex = 0;
  function openLightbox(index) {
    lightboxItems = [...document.querySelectorAll('.gallery-item:not(.hidden)')];
    if (!lightboxItems.length) return;
    lightboxIndex = index; updateLightbox();
    lightbox.classList.add('active'); document.body.style.overflow = 'hidden';
  }
  function closeLightbox() { lightbox.classList.remove('active'); document.body.style.overflow = ''; }
  function updateLightbox() {
    const item = lightboxItems[lightboxIndex]; if (!item) return;
    const img = item.querySelector('img');
    lightboxImg.src = img.src; lightboxImg.alt = img.alt;
    lightboxCaption.textContent = item.dataset.caption || '';
    lightboxCounter.textContent = (lightboxIndex + 1) + ' / ' + lightboxItems.length;
  }
  document.getElementById('gallery-grid')?.addEventListener('click', e => {
    const item = e.target.closest('.gallery-item'); if (!item) return;
    const items = [...document.querySelectorAll('.gallery-item:not(.hidden)')];
    const idx = items.indexOf(item); if (idx >= 0) openLightbox(idx);
  });
  if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
  if (lightboxPrev) lightboxPrev.addEventListener('click', () => { lightboxIndex = (lightboxIndex - 1 + lightboxItems.length) % lightboxItems.length; updateLightbox(); });
  if (lightboxNext) lightboxNext.addEventListener('click', () => { lightboxIndex = (lightboxIndex + 1) % lightboxItems.length; updateLightbox(); });
  if (lightbox) {
    lightbox.addEventListener('click', e => { if (e.target === lightbox) closeLightbox(); });
    document.addEventListener('keydown', e => {
      if (!lightbox.classList.contains('active')) return;
      if (e.key === 'Escape') closeLightbox();
      if (e.key === 'ArrowLeft') { lightboxIndex = (lightboxIndex - 1 + lightboxItems.length) % lightboxItems.length; updateLightbox(); }
      if (e.key === 'ArrowRight') { lightboxIndex = (lightboxIndex + 1) % lightboxItems.length; updateLightbox(); }
    });
  }
  document.querySelectorAll('.gallery-item').forEach(item => {
    item.addEventListener('mousemove', e => {
      const rect = item.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width - 0.5;
      const y = (e.clientY - rect.top) / rect.height - 0.5;
      item.style.transform = 'perspective(600px) rotateY(' + (x * 8) + 'deg) rotateX(' + (-y * 8) + 'deg) scale(1.02)';
    });
    item.addEventListener('mouseleave', () => { item.style.transform = 'perspective(600px) rotateY(0) rotateX(0) scale(1)'; });
  });
  const carouselTrack = document.getElementById('carouselTrack');
  const carouselDotsEl = document.getElementById('carouselDots');
  if (carouselTrack && carouselDotsEl) {
    const cSlides = [...carouselTrack.querySelectorAll('.carousel-slide')];
    let currentSlide = 0, autoTimer;
    cSlides.forEach((_, i) => {
      const dot = document.createElement('button');
      dot.className = 'carousel-dot' + (i === 0 ? ' active' : '');
      dot.setAttribute('aria-label', 'Go to slide ' + (i + 1));
      dot.addEventListener('click', () => { goToSlide(i); startAuto(); });
      carouselDotsEl.appendChild(dot);
    });
    function goToSlide(index) {
      currentSlide = (index + cSlides.length) % cSlides.length;
      carouselTrack.style.transform = 'translateX(-' + (currentSlide * 100) + '%)';
      carouselDotsEl.querySelectorAll('.carousel-dot').forEach((d, i) => d.classList.toggle('active', i === currentSlide));
    }
    function startAuto() {
      clearInterval(autoTimer);
      if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        autoTimer = setInterval(() => goToSlide(currentSlide + 1), 5000);
      }
    }
    document.getElementById('carouselPrev')?.addEventListener('click', () => { goToSlide(currentSlide - 1); startAuto(); });
    document.getElementById('carouselNext')?.addEventListener('click', () => { goToSlide(currentSlide + 1); startAuto(); });
    const wrapper = carouselTrack.closest('.carousel-wrapper');
    if (wrapper) {
      wrapper.addEventListener('mouseenter', () => clearInterval(autoTimer));
      wrapper.addEventListener('mouseleave', startAuto);
    }
    let touchStartX = 0;
    carouselTrack.addEventListener('touchstart', e => { touchStartX = e.changedTouches[0].clientX; }, { passive: true });
    carouselTrack.addEventListener('touchend', e => {
      const diff = touchStartX - e.changedTouches[0].clientX;
      if (Math.abs(diff) > 50) { diff > 0 ? goToSlide(currentSlide + 1) : goToSlide(currentSlide - 1); startAuto(); }
    }, { passive: true });
    startAuto();
  }
  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('is-visible'); revealObserver.unobserve(entry.target); } });
  }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
  document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-scale').forEach(el => revealObserver.observe(el));
  document.querySelectorAll('img.lazy-img').forEach(img => {
    if (img.complete) { img.classList.add('loaded'); } else {
      img.addEventListener('load', () => img.classList.add('loaded'));
    }
  });
  function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  }
})();
