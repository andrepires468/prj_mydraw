import { ref, watch } from 'vue'

const STORAGE_KEY = 'mydraw:edit-post-aside-summarized:v1'

function readStored(): boolean {
  if (typeof window === 'undefined') return false
  try {
    const raw = window.localStorage.getItem(STORAGE_KEY)
    if (raw === '1' || raw === 'true') return true
    if (raw === '0' || raw === 'false') return false
  } catch {
    /* storage indisponível */
  }
  return false
}

function writeStored(value: boolean): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0')
  } catch {
    /* quota / privado */
  }
}

/** Preferência de painel lateral resumido na tela de edição/criação de post. */
export function useEditPostAsideSummarized() {
  const asideSummarized = ref(readStored())

  watch(asideSummarized, (value) => writeStored(value))

  return { asideSummarized }
}
