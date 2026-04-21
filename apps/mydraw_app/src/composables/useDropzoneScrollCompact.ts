import { nextTick, onBeforeUnmount, ref, watch, type Ref } from 'vue'

/**
 * Alinha com `.create-post-page__dropzone-sticky { top: 4.5rem }` (barra superior sticky).
 * Margem do observer em px para coincidir com 4.5rem a 16px.
 */
const STICKY_TOP_PX = 72

export function useDropzoneScrollCompact(sentinel: Ref<HTMLElement | null>) {
  const compact = ref(false)
  let io: IntersectionObserver | null = null

  function disconnect() {
    io?.disconnect()
    io = null
  }

  function connect(el: HTMLElement) {
    disconnect()
    if (typeof IntersectionObserver === 'undefined') return
    io = new IntersectionObserver(
      ([entry]) => {
        compact.value = !entry.isIntersecting
      },
      {
        root: null,
        rootMargin: `-${STICKY_TOP_PX}px 0px 0px 0px`,
        threshold: 0,
      },
    )
    io.observe(el)
  }

  watch(
    sentinel,
    async (el) => {
      await nextTick()
      if (el) connect(el)
      else {
        compact.value = false
        disconnect()
      }
    },
    { immediate: true },
  )

  onBeforeUnmount(() => {
    disconnect()
  })

  return { compact }
}
