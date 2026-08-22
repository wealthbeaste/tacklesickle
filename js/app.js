(() => {
  'use strict';

  const API_BASE_URL = (window.TSCA_API_BASE_URL || 'http://127.0.0.1:8081/api/v1').replace(/\/$/, '');

  // Dynamic year
  const year = document.getElementById('year');
  if (year) year.textContent = new Date().getFullYear();

  // Mobile navigation
  const mobileBtn = document.getElementById('mobileBtn');
  const mobileMenu = document.getElementById('mobileMenu');
  if (mobileBtn && mobileMenu) {
    mobileBtn.addEventListener('click', () => {
      const hidden = mobileMenu.classList.toggle('hidden');
      mobileBtn.setAttribute('aria-expanded', String(!hidden));
      mobileBtn.innerHTML = hidden
        ? '<span class="text-xl text-[var(--text-dark)]" aria-hidden="true">☰</span>'
        : '<span class="text-xl text-[var(--text-dark)]" aria-hidden="true">✕</span>';
    });

    mobileMenu.querySelectorAll('a').forEach(link => {
      link.addEventListener('click', () => mobileMenu.classList.add('hidden'));
    });
  }

  // Accessible, cross-fade hero slideshow with manual controls.
  const slider = document.querySelector('.hero-slider');
  if (slider) {
    const slides = [...slider.querySelectorAll('.hero-slide')];
    const dots = [...slider.querySelectorAll('.hero-dot')];
    let current = 0;
    let timer;

    const preload = () => slides.forEach(slide => {
      const src = slide.dataset.image;
      if (src) {
        const image = new Image();
        image.src = src;
      }
    });

    const showSlide = (index) => {
      current = (index + slides.length) % slides.length;
      slides.forEach((slide, i) => slide.classList.toggle('is-active', i === current));
      dots.forEach((dot, i) => {
        dot.classList.toggle('is-active', i === current);
        dot.setAttribute('aria-current', i === current ? 'true' : 'false');
      });
    };

    const start = () => {
      clearInterval(timer);
      if (slides.length > 1 && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        timer = setInterval(() => showSlide(current + 1), 6000);
      }
    };

    dots.forEach(dot => dot.addEventListener('click', () => {
      showSlide(Number(dot.dataset.slide || 0));
      start();
    }));

    slider.addEventListener('mouseenter', () => clearInterval(timer));
    slider.addEventListener('mouseleave', start);
    slider.addEventListener('focusin', () => clearInterval(timer));
    slider.addEventListener('focusout', start);

    preload();
    showSlide(0);
    start();
  }

  // Registry form -> real PHP/PostgreSQL API.
  const form = document.getElementById('connectForm');
  const statusText = document.getElementById('formStatus');
  const submitBtn = document.getElementById('submitBtn');

  if (form && statusText && submitBtn) {
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (submitBtn.disabled) return;

      submitBtn.disabled = true;
      submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2" aria-hidden="true"></i> Processing...';
      statusText.className = 'text-center text-sm mt-4 font-medium';
      statusText.textContent = 'Submitting your registration...';

      const payload = Object.fromEntries(new FormData(form).entries());

      try {
        const response = await fetch(`${API_BASE_URL}/registry`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify(payload),
        });

        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.success) {
          throw new Error(result.error || 'We could not complete your registration.');
        }

        statusText.className = 'text-center text-sm mt-4 font-medium status-success fade-in';
        statusText.innerHTML = `Thank you! Your registration was received. <strong>Registry ID: ${escapeHtml(result.data.registry_number)}</strong>`;
        form.reset();
      } catch (error) {
        statusText.className = 'text-center text-sm mt-4 font-medium status-error fade-in';
        statusText.textContent = error instanceof Error ? error.message : 'Something went wrong. Please try again.';
      } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = 'SUBMIT REGISTRATION';
      }
    });
  }

  // Existing gallery load-more interaction.
  const loadMoreBtn = document.getElementById('loadMoreBtn');
  const hiddenGalleryItems = document.querySelectorAll('.gallery-hidden.hidden');
  if (loadMoreBtn) {
    if (hiddenGalleryItems.length) {
      loadMoreBtn.addEventListener('click', () => {
        hiddenGalleryItems.forEach(item => {
          item.classList.remove('hidden');
          item.classList.add('fade-in');
        });
        loadMoreBtn.style.display = 'none';
      });
    } else {
      loadMoreBtn.style.display = 'none';
    }
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, character => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    }[character]));
  }
})();
