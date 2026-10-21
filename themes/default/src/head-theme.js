// Pre-paint theme resolution. A Blade layout inlines this file in <head> so the
// first paint already uses the visitor's theme. It is not a Vite entry.
try {
  const stored = localStorage.getItem('verse-theme')
  document.documentElement.dataset.theme =
    stored === 'light' || stored === 'dark'
      ? stored
      : matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light'
} catch (_) {}
