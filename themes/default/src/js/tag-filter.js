// Tag index filter. The server renders the filter field with the `hidden` attribute and a
// `data-tag-label` on every row; this module reveals the field and hides the rows that do not match,
// so without JavaScript the page is the plain list of all tags.

// Case- and accent-insensitive: "Éléphant" and "elephant" are the same word.
function normalize(text) {
  return text.toLocaleLowerCase('en').normalize('NFD').replace(/\p{M}/gu, '')
}

function init() {
  const field = document.querySelector('.tag-filter')
  const input = field?.querySelector('input')
  const rows = Array.from(document.querySelectorAll('.tag-index-row[data-tag-label]'))
  const empty = document.querySelector('.tag-filter-empty')
  if (!field || !input || rows.length === 0) return

  const labels = rows.map((row) => normalize(row.dataset.tagLabel ?? ''))

  function visibleRows() {
    return rows.filter((row) => !row.hidden)
  }

  function apply() {
    const terms = normalize(input.value).split(/\s+/).filter(Boolean)
    rows.forEach((row, index) => {
      row.hidden = !terms.every((term) => labels[index].includes(term))
    })
    if (empty) empty.hidden = visibleRows().length > 0
  }

  input.addEventListener('input', apply)
  input.addEventListener('keydown', (event) => {
    if (event.isComposing || event.keyCode === 229) return
    if (event.key === 'Escape') {
      if (input.value === '') return
      event.preventDefault()
      input.value = ''
      apply()
    } else if (event.key === 'Enter') {
      const visible = visibleRows()
      if (visible.length === 1) {
        event.preventDefault()
        location.assign(visible[0].href)
      }
    }
  })

  field.hidden = false
}

init()
