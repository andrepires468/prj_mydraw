<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { pingApi } from '@/api/drawings'

type PingResponse = {
  pong: boolean
  service: string
  env: string
  timestamp: string
}

const status = ref<'idle' | 'loading' | 'ok' | 'error'>('idle')
const data = ref<PingResponse | null>(null)
const errorMsg = ref<string | null>(null)

async function ping() {
  status.value = 'loading'
  errorMsg.value = null
  try {
    data.value = await pingApi()
    status.value = 'ok'
  } catch (err: unknown) {
    status.value = 'error'
    errorMsg.value = err instanceof Error ? err.message : 'Erro desconhecido'
  }
}

onMounted(ping)
</script>

<template>
  <section class="hero">
    <h1>mydraw</h1>
    <p class="subtitle">
      Uma galeria para postar desenhos feitos com IA.
    </p>

    <div class="card">
      <h2>Status da API</h2>

      <div v-if="status === 'loading'" class="status loading">
        <span class="dot" /> consultando <code>/api/ping</code>…
      </div>

      <div v-else-if="status === 'ok' && data" class="status ok">
        <span class="dot" /> conectado
        <dl>
          <dt>service</dt><dd>{{ data.service }}</dd>
          <dt>env</dt><dd>{{ data.env }}</dd>
          <dt>timestamp</dt><dd>{{ data.timestamp }}</dd>
        </dl>
      </div>

      <div v-else-if="status === 'error'" class="status error">
        <span class="dot" /> falha ao contatar a API
        <p class="err-msg">{{ errorMsg }}</p>
      </div>

      <button class="btn" @click="ping">Tentar novamente</button>
    </div>
  </section>
</template>

<style scoped>
.hero {
  display: grid;
  gap: 1.5rem;
}

h1 {
  font-size: 3rem;
  margin: 0;
  letter-spacing: -1px;
  background: linear-gradient(90deg, #6ea8fe, #b388ff);
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
}

.subtitle {
  color: #9c9c9c;
  margin: 0;
  font-size: 1.1rem;
}

.card {
  border: 1px solid #2a2a2a;
  border-radius: 10px;
  padding: 1.5rem;
  background: #141414;
  max-width: 520px;
}

.card h2 {
  margin: 0 0 1rem;
  font-size: 1rem;
  color: #ccc;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.status {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  font-size: 0.95rem;
  margin-bottom: 1rem;
}

.status .dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  margin-right: 0.5rem;
  vertical-align: middle;
}

.status.loading { color: #c9a227; }
.status.loading .dot { background: #c9a227; animation: pulse 1s infinite; }

.status.ok { color: #2ea043; }
.status.ok .dot { background: #2ea043; }

.status.error { color: #f85149; }
.status.error .dot { background: #f85149; }

.err-msg {
  color: #ff9b9b;
  font-size: 0.85rem;
  margin: 0.25rem 0 0;
  font-family: ui-monospace, monospace;
}

dl {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: 0.25rem 1rem;
  margin: 0.5rem 0 0;
  font-family: ui-monospace, monospace;
  font-size: 0.85rem;
  color: #bbb;
}
dt { color: #666; }
dd { margin: 0; word-break: break-all; }

.btn {
  padding: 0.5rem 1rem;
  border-radius: 6px;
  border: 1px solid #333;
  background: #1c1c1c;
  color: #ddd;
  cursor: pointer;
  font-size: 0.9rem;
}
.btn:hover { background: #262626; }

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%      { opacity: 0.3; }
}
</style>
