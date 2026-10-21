// Adds a Copy button to every code block header. The label doubles as the status
// message (aria-live), so there is no separate toast.

const RESET_AFTER_MS = 1800

async function copyText(text) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text)
      return true
    }
  } catch (_) {}

  // Fallback: a hidden textarea has to take focus to be copied from. Remember what
  // had focus at that moment and give it back, unless something else took it since.
  const previous = document.activeElement
  const helper = document.createElement('textarea')
  helper.value = text
  helper.style.cssText = 'position:fixed;left:-9999px;top:0'
  document.body.append(helper)
  helper.select()
  let copied = false
  try {
    copied = document.execCommand('copy')
  } catch (_) {}
  const helperHadFocus = document.activeElement === helper
  helper.remove()
  if (helperHadFocus && previous instanceof HTMLElement && previous.isConnected) {
    previous.focus({ preventScroll: true })
  }
  return copied
}

function selectContents(node) {
  const selection = getSelection()
  const range = document.createRange()
  range.selectNodeContents(node)
  selection.removeAllRanges()
  selection.addRange(range)
}

for (const block of document.querySelectorAll('.code-block')) {
  const header = block.querySelector('.code-header')
  const code = block.querySelector('code')
  if (!header || !code || header.querySelector('.copy-code')) continue

  const button = document.createElement('button')
  button.className = 'copy-code'
  button.type = 'button'
  button.setAttribute('aria-live', 'polite')
  button.textContent = 'Copy'
  header.append(button)

  button.addEventListener('click', async () => {
    const copied = await copyText(code.textContent)
    if (copied) {
      button.textContent = 'Copied'
    } else {
      selectContents(code)
      button.textContent = 'Selected'
    }
    setTimeout(() => {
      if (button.isConnected) button.textContent = 'Copy'
    }, RESET_AFTER_MS)
  })
}
