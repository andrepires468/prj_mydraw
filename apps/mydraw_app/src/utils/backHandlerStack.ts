/** Handlers LIFO para overlays (modais/alerts) interceptarem o back do Android antes da rota. */

type BackHandler = () => void

const stack: BackHandler[] = []

export function registerBackHandler(handler: BackHandler): () => void {
  stack.push(handler)
  return () => {
    const index = stack.lastIndexOf(handler)
    if (index >= 0) stack.splice(index, 1)
  }
}

/** Executa o handler do topo, se existir. Retorna true quando consumiu o evento. */
export function consumeBackHandler(): boolean {
  const handler = stack.at(-1)
  if (!handler) return false
  handler()
  return true
}
