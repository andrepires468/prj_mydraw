import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue'

/**
 * `compact` quando o wrapper sticky está colado sob a navbar (mesmo `top` que
 * `.create-post-page__dropzone-sticky { top: 4.5rem }`).
 * Medição por scroll/resize é mais confiável que IntersectionObserver no sentinel.
 */
const FALLBACK_TOP_PX = 72

export function useDropzoneScrollCompact(stickyWrapper: Ref<HTMLElement | null>) {
  const compact = ref(false)

  function update() {
    const el = stickyWrapper.value
    if (!el) {
      compact.value = false
      return
    }
    const topStr = getComputedStyle(el).top
    let offsetPx = FALLBACK_TOP_PX
    if (topStr.endsWith('px')) {
      offsetPx = parseFloat(topStr)
    } else if (topStr.endsWith('rem')) {
      const rem = parseFloat(topStr)
      const rootFont =
        parseFloat(getComputedStyle(document.documentElement).fontSize) || 16
      offsetPx = rem * rootFont
    }
    const rectTop = el.getBoundingClientRect().top
    compact.value = rectTop <= offsetPx + 1
  }

  function bindWindow() {
    if (typeof window === 'undefined') return
    window.addEventListener('scroll', update, { passive: true })
    window.addEventListener('resize', update, { passive: true })
  }

  function unbindWindow() {
    if (typeof window === 'undefined') return
    window.removeEventListener('scroll', update)
    window.removeEventListener('resize', update)
  }

  watch(
    stickyWrapper,
    (el) => {
      if (el) update()
      else compact.value = false
    },
    { flush: 'post' },
  )

  onMounted(() => {
    bindWindow()
    update()
  })

  onBeforeUnmount(() => {
    unbindWindow()
  })

  return { compact }
}
