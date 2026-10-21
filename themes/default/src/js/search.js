// Search dialog (command palette). The server renders the header button with the `hidden` attribute and
// the URL of the index in `data-search-index`; this module reveals the button, fetches the index on first
// open and builds the dialog itself, so without JavaScript the page has neither button nor dialog.

const SEARCH_ICON = '<circle cx="10.7" cy="10.7" r="6.7"/><path d="m16 16 4.5 4.5"/>'
const CLOSE_ICON = '<path d="m6 6 12 12M6 18 18 6"/>'
const EMPTY_LIMIT = 10
const RESULT_LIMIT = 50
// Only a drafts preview's index carries `state`; a production index has no such key.
const STATE_LABELS = { draft: 'Draft', scheduled: 'Scheduled' }

const icon = (paths) => `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">${paths}</svg>`

// Case- and accent-insensitive: "Éléphant" and "elephant" are the same word.
function normalize(text) {
  return text.toLocaleLowerCase('en').normalize('NFD').replace(/\p{M}/gu, '')
}

function isWebUrl(value) {
  try {
    const { protocol } = new URL(value, location.href)
    return protocol === 'http:' || protocol === 'https:'
  } catch (_) {
    return false
  }
}

function init() {
  const trigger = document.querySelector('[data-open-search]')
  if (!trigger || typeof HTMLDialogElement !== 'function') return

  const indexUrl = trigger.dataset.searchIndex
  let dialog
  let input
  let results
  let entries = null // null until the index has been fetched
  let loading = null
  let failed = false
  let opener = null

  function load() {
    if (entries || loading || !indexUrl) return
    failed = false
    loading = fetch(indexUrl, { headers: { Accept: 'application/json' } })
      .then((response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        return response.json()
      })
      .then((data) => {
        if (!Array.isArray(data)) throw new Error('invalid index')
        entries = data
          .filter((entry) => entry && typeof entry.title === 'string' && typeof entry.url === 'string' && isWebUrl(entry.url))
          .map((entry) => {
            const tags = Array.isArray(entry.tags) ? entry.tags.filter((tag) => typeof tag === 'string') : []
            const description = typeof entry.description === 'string' ? entry.description : ''
            return {
              title: entry.title,
              description,
              tags,
              date: typeof entry.dateLabel === 'string' ? entry.dateLabel : '',
              url: entry.url,
              external: entry.external === true,
              state: typeof entry.state === 'string' ? STATE_LABELS[entry.state] || '' : '',
              haystack: normalize([entry.title, description, ...tags].join('\n')),
            }
          })
      })
      .catch(() => {
        failed = true
      })
      .finally(() => {
        loading = null
        if (dialog?.open) render(input.value)
      })
  }

  function message(text) {
    const empty = document.createElement('div')
    empty.className = 'search-empty'
    empty.textContent = text
    return empty
  }

  function resultLink(entry) {
    const link = document.createElement('a')
    link.className = 'search-result'
    link.href = entry.url
    if (entry.external) {
      link.target = '_blank'
      link.rel = 'noopener noreferrer'
    }

    const meta = document.createElement('div')
    meta.className = 'search-result-meta'
    meta.textContent = [entry.date, ...entry.tags, entry.state].filter(Boolean).join(' · ')
    const title = document.createElement('h3')
    title.textContent = entry.title
    link.append(meta, title)

    if (entry.description) {
      const description = document.createElement('p')
      description.textContent = entry.description
      link.append(description)
    }

    return link
  }

  function render(query) {
    if (!entries) {
      results.replaceChildren(message(failed ? 'Search is unavailable right now. Try again later.' : 'Loading…'))
      return
    }

    const terms = normalize(query).split(/\s+/).filter(Boolean)
    const found = entries.filter((entry) => terms.every((term) => entry.haystack.includes(term)))
    if (found.length === 0) {
      results.replaceChildren(message(terms.length === 0 ? 'Nothing to search yet.' : `No results for “${query.trim()}”.`))
      return
    }
    results.replaceChildren(...found.slice(0, terms.length === 0 ? EMPTY_LIMIT : RESULT_LIMIT).map(resultLink))
  }

  function open(from) {
    if (!dialog) create()
    opener = from || document.activeElement
    input.value = ''
    load()
    render('')
    dialog.showModal()
    document.body.classList.add('modal-open')
    input.focus()
  }

  function close() {
    if (!dialog?.open) return
    dialog.close()
    document.body.classList.remove('modal-open')
    if (opener?.isConnected) opener.focus({ preventScroll: true })
  }

  function create() {
    dialog = document.createElement('dialog')
    dialog.className = 'search-dialog'
    dialog.id = 'search-dialog'
    dialog.setAttribute('aria-label', 'Search')
    // static markup only; nothing from the index or the page is interpolated here
    dialog.innerHTML =
      `<div class="dialog-top">${icon(SEARCH_ICON)}<label class="sr-only" for="global-search">Search</label>` +
      '<input type="search" id="global-search" placeholder="Search…" autocomplete="off" spellcheck="false">' +
      `<button class="dialog-close" type="button" aria-label="Close search">${icon(CLOSE_ICON)}</button></div>` +
      '<div class="search-results" id="search-results" aria-live="polite"></div>' +
      '<div class="dialog-bottom"><span>Search by title, topic, or idea</span><span><kbd>esc</kbd> to close</span></div>'
    document.body.append(dialog)

    input = dialog.querySelector('input')
    results = dialog.querySelector('.search-results')

    input.addEventListener('input', () => render(input.value))
    dialog.querySelector('.dialog-close').addEventListener('click', close)
    dialog.addEventListener('cancel', (event) => {
      event.preventDefault()
      close()
    })
    dialog.addEventListener('click', (event) => {
      if (event.target === dialog) {
        const box = dialog.getBoundingClientRect()
        if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) close()
      } else if (event.target.closest('.search-result')) {
        close() // the navigation (or the new tab) still happens
      }
    })
    dialog.addEventListener('keydown', (event) => {
      // Keys that confirm or move inside an input method's composition belong to the IME, not to the results.
      if (event.isComposing || event.keyCode === 229) return
      const list = [...results.querySelectorAll('.search-result')]
      const index = list.indexOf(document.activeElement)
      if (event.key === 'ArrowDown' && (document.activeElement === input || index !== -1)) {
        event.preventDefault()
        list[document.activeElement === input ? 0 : Math.min(index + 1, list.length - 1)]?.focus()
      } else if (event.key === 'ArrowUp' && index !== -1) {
        event.preventDefault()
        ;(index === 0 ? input : list[index - 1]).focus()
      } else if (event.key === 'Enter' && document.activeElement === input && list[0]) {
        event.preventDefault()
        list[0].click()
      }
    })
  }

  trigger.hidden = false
  trigger.addEventListener('click', () => open(trigger))
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault()
      if (dialog?.open) close()
      else open()
    }
  })
}

init()
