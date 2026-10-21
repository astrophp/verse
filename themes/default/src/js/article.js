// Article page enhancements: current section in the table of contents and the
// reading-progress bar. Both are no-ops on pages without the elements.

const headings = [...document.querySelectorAll('.prose h2[id], .prose h3[id]')]
const tocLinks = [...document.querySelectorAll('[data-toc]')]
const prose = document.querySelector('.prose')
const bar = document.getElementById('reading-progress')

function updateToc() {
  if (!headings.length || !tocLinks.length) return
  const header = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--header'), 10) || 88
  const offset = header + 48

  let current = headings[0]
  for (const heading of headings) {
    if (heading.getBoundingClientRect().top - offset <= 0) current = heading
    else break
  }
  if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) {
    current = headings[headings.length - 1]
  }

  for (const link of tocLinks) {
    if (link.dataset.toc === current.id) link.setAttribute('aria-current', 'true')
    else link.removeAttribute('aria-current')
  }
}

function updateProgress() {
  if (!prose || !bar) return
  const top = prose.getBoundingClientRect().top + window.scrollY - 120
  const total = Math.max(1, prose.offsetHeight - window.innerHeight + 160)
  const percent = Math.max(0, Math.min(100, ((window.scrollY - top) / total) * 100))
  bar.style.width = `${percent}%`
}

function update() {
  updateToc()
  updateProgress()
}

if ((headings.length && tocLinks.length) || (prose && bar)) {
  window.addEventListener('scroll', update, { passive: true })
  window.addEventListener('resize', update, { passive: true })
  update()
}
