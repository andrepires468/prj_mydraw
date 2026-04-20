<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { listDrawings, type Drawing, type Paginated } from '@/api/drawings'

const loading = ref(false)
const error = ref<string | null>(null)
const page = ref<Paginated<Drawing> | null>(null)

async function load(p = 1) {
  loading.value = true
  error.value = null
  try {
    page.value = await listDrawings(p)
  } catch (err: unknown) {
    error.value = err instanceof Error ? err.message : 'Erro desconhecido'
  } finally {
    loading.value = false
  }
}

onMounted(() => load())
</script>

<template>
  <section>
    <header class="header">
      <h1>Galeria</h1>
      <p class="muted">
        Lista pública de desenhos postados. Endpoint:
        <code>GET /api/drawings</code>
      </p>
    </header>

    <div v-if="loading" class="empty">Carregando…</div>
    <div v-else-if="error" class="empty err">Erro: {{ error }}</div>
    <div v-else-if="page && page.data.length === 0" class="empty">
      Nenhum desenho ainda. Faça login e publique o primeiro!
    </div>

    <div v-else-if="page" class="grid">
      <article v-for="d in page.data" :key="d.id" class="tile">
        <div class="thumb">
          <img v-if="d.url" :src="d.url" :alt="d.title" />
          <span v-else class="placeholder">sem preview</span>
        </div>
        <div class="meta">
          <h3>{{ d.title }}</h3>
          <p v-if="d.description" class="desc">{{ d.description }}</p>
          <p class="byline">
            por <strong>{{ d.user?.name ?? `#${d.user_id}` }}</strong>
            <span v-if="d.model"> · {{ d.model }}</span>
          </p>
        </div>
      </article>
    </div>

    <footer v-if="page && page.last_page > 1" class="pager">
      <button :disabled="page.current_page <= 1" @click="load(page.current_page - 1)">
        ← anterior
      </button>
      <span>{{ page.current_page }} / {{ page.last_page }}</span>
      <button :disabled="page.current_page >= page.last_page" @click="load(page.current_page + 1)">
        próxima →
      </button>
    </footer>
  </section>
</template>

<style scoped>
.header { margin-bottom: 1.5rem; }
.header h1 { margin: 0 0 0.25rem; font-size: 2rem; }
.muted { color: #888; margin: 0; font-size: 0.9rem; }
code { background: #1a1a1a; padding: 2px 6px; border-radius: 4px; font-size: 0.85em; }

.empty {
  padding: 3rem 1rem;
  text-align: center;
  color: #777;
  border: 1px dashed #2a2a2a;
  border-radius: 10px;
}
.empty.err { color: #f85149; border-color: #5a1f1f; background: #1a0e0e; }

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 1rem;
}

.tile {
  border: 1px solid #2a2a2a;
  border-radius: 10px;
  overflow: hidden;
  background: #141414;
  transition: transform .15s ease, border-color .15s ease;
}
.tile:hover {
  transform: translateY(-2px);
  border-color: #3a3a3a;
}

.thumb {
  aspect-ratio: 1 / 1;
  background: #0f0f0f;
  display: grid;
  place-items: center;
}
.thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.placeholder { color: #555; font-size: 0.85rem; }

.meta { padding: 0.75rem 0.9rem; display: grid; gap: 0.25rem; }
.meta h3 { margin: 0; font-size: 0.95rem; }
.desc { margin: 0; color: #aaa; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.byline { margin: 0.25rem 0 0; color: #777; font-size: 0.8rem; }

.pager {
  display: flex;
  gap: 1rem;
  align-items: center;
  justify-content: center;
  margin-top: 2rem;
  color: #888;
}
.pager button {
  padding: 0.4rem 0.9rem;
  border-radius: 6px;
  border: 1px solid #333;
  background: #1c1c1c;
  color: #ddd;
  cursor: pointer;
}
.pager button:disabled { opacity: 0.4; cursor: not-allowed; }
</style>
