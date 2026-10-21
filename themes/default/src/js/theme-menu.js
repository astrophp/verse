// Light / Dark / System menu. The server renders the button with the `hidden`
// attribute; this module reveals it, so the control only exists when it works.

const STORAGE_KEY = 'verse-theme'
const THEME_COLORS = { light: '#fcfcfc', dark: '#121315' }
const ICONS = {
  light:
    '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/>',
  dark: '<path d="M20.8 13.1A8.7 8.7 0 0 1 10.9 3.2a8.8 8.8 0 1 0 9.9 9.9Z"/>',
  system: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8m-4-4v4"/>',
}
const LABELS = { light: 'Light', dark: 'Dark', system: 'System' }

function themePreference() {
  try {
    const stored = localStorage.getItem(STORAGE_KEY)
    return stored === 'light' || stored === 'dark' ? stored : 'system'
  } catch (_) {
    return 'system'
  }
}

// The current preference lives in module state, read from storage once; storage is only
// best-effort persistence, so a storage failure never overwrites an explicit choice.
let currentPreference = 'system'

function applyTheme(preference, save = true) {
  const pref = preference === 'light' || preference === 'dark' ? preference : 'system'
  currentPreference = pref
  const theme = pref === 'system' ? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : pref
  document.documentElement.dataset.theme = theme

  const button = document.getElementById('theme-button')
  if (button) {
    button.innerHTML = `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">${ICONS[pref]}</svg>`
    button.setAttribute('aria-label', `Color theme: ${LABELS[pref]}`)
  }
  for (const option of document.querySelectorAll('[data-theme-option]')) {
    option.setAttribute('aria-checked', String(option.dataset.themeOption === pref))
  }

  const meta = document.querySelector('meta[name="theme-color"]')
  if (meta) meta.content = THEME_COLORS[theme]

  if (!save) return
  try {
    if (pref === 'system') localStorage.removeItem(STORAGE_KEY)
    else localStorage.setItem(STORAGE_KEY, pref)
  } catch (_) {}
}

function init() {
  const button = document.getElementById('theme-button')
  const menu = document.getElementById('theme-options')
  if (!button || !menu) return

  const items = () => [...menu.querySelectorAll('[data-theme-option]')]

  const open = () => {
    menu.hidden = false
    button.setAttribute('aria-expanded', 'true')
    ;(items().find((item) => item.getAttribute('aria-checked') === 'true') || items()[0]).focus()
  }
  const close = (focusButton = true) => {
    if (menu.hidden) return
    menu.hidden = true
    button.setAttribute('aria-expanded', 'false')
    if (focusButton) button.focus()
  }

  button.hidden = false
  currentPreference = themePreference()
  applyTheme(currentPreference, false)

  button.addEventListener('click', () => (menu.hidden ? open() : close()))
  button.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault()
      open()
    }
  })

  menu.addEventListener('click', (event) => {
    const option = event.target.closest('[data-theme-option]')
    if (!option) return
    applyTheme(option.dataset.themeOption)
    close()
  })
  menu.addEventListener('keydown', (event) => {
    const list = items()
    const index = list.indexOf(document.activeElement)
    if (event.key === 'ArrowDown') {
      event.preventDefault()
      list[(index + 1) % list.length].focus()
    } else if (event.key === 'ArrowUp') {
      event.preventDefault()
      list[(index - 1 + list.length) % list.length].focus()
    } else if (event.key === 'Home') {
      event.preventDefault()
      list[0].focus()
    } else if (event.key === 'End') {
      event.preventDefault()
      list[list.length - 1].focus()
    } else if (event.key === 'Escape') {
      event.preventDefault()
      close()
    } else if (event.key === 'Tab') {
      close(false)
    }
  })

  document.addEventListener('pointerdown', (event) => {
    if (!menu.hidden && !event.target.closest('.theme-menu')) close(false)
  })

  try {
    matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (currentPreference === 'system') applyTheme('system', false)
    })
  } catch (_) {}
}

init()
