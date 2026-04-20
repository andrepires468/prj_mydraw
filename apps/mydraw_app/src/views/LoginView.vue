<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import axios from 'axios'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const email = ref('')
const password = ref('')
const loading = ref(false)
const errorMsg = ref<string | null>(null)

async function submit() {
  if (loading.value) return
  errorMsg.value = null
  loading.value = true
  try {
    await auth.login(email.value, password.value)
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/'
    await router.replace(redirect)
  } catch (err: unknown) {
    if (axios.isAxiosError(err)) {
      const data = err.response?.data as
        | { message?: string; errors?: Record<string, string[]> }
        | undefined
      const firstFieldError = data?.errors
        ? Object.values(data.errors)[0]?.[0]
        : undefined
      errorMsg.value =
        firstFieldError ??
        data?.message ??
        (err.response?.status === 401
          ? 'Credenciais inválidas.'
          : 'Falha ao autenticar.')
    } else {
      errorMsg.value = err instanceof Error ? err.message : 'Erro desconhecido'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <section class="login">
    <div class="card">
      <h1>Entrar</h1>
      <p class="subtitle">Acesse sua conta para publicar desenhos.</p>

      <form class="form" @submit.prevent="submit">
        <label class="field">
          <span>E-mail</span>
          <input
            v-model="email"
            type="email"
            autocomplete="email"
            required
            placeholder="voce@exemplo.com"
            :disabled="loading"
          />
        </label>

        <label class="field">
          <span>Senha</span>
          <input
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
            minlength="4"
            placeholder="••••••••"
            :disabled="loading"
          />
        </label>

        <p v-if="errorMsg" class="err">{{ errorMsg }}</p>

        <button class="btn-primary" type="submit" :disabled="loading">
          {{ loading ? 'Entrando…' : 'Entrar' }}
        </button>
      </form>

      <p class="hint">
        Ainda não tem conta?
        <RouterLink to="/">voltar à home</RouterLink>
      </p>
    </div>
  </section>
</template>

<style scoped>
.login {
  display: grid;
  place-items: center;
  padding: 2rem 0;
}

.card {
  width: 100%;
  max-width: 420px;
  background: #141414;
  border: 1px solid #2a2a2a;
  border-radius: 12px;
  padding: 2rem 1.75rem;
}

h1 {
  margin: 0 0 0.25rem;
  font-size: 1.75rem;
  letter-spacing: -0.5px;
}

.subtitle {
  margin: 0 0 1.5rem;
  color: #888;
  font-size: 0.95rem;
}

.form {
  display: grid;
  gap: 1rem;
}

.field {
  display: grid;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: #bbb;
}

.field input {
  padding: 0.6rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #2e2e2e;
  background: #0f0f0f;
  color: #e3e3e3;
  font-size: 0.95rem;
  outline: none;
  transition: border-color 0.15s ease;
}

.field input:focus {
  border-color: #6ea8fe;
}

.field input:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-primary {
  margin-top: 0.25rem;
  padding: 0.65rem 1rem;
  border-radius: 6px;
  border: none;
  background: linear-gradient(90deg, #6ea8fe, #b388ff);
  color: #0b0b0b;
  font-weight: 600;
  font-size: 0.95rem;
  cursor: pointer;
  transition: opacity 0.15s ease;
}

.btn-primary:hover {
  opacity: 0.9;
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.err {
  margin: 0;
  padding: 0.55rem 0.75rem;
  border-radius: 6px;
  background: #2a0e0e;
  border: 1px solid #5a1f1f;
  color: #ff9b9b;
  font-size: 0.85rem;
}

.hint {
  margin: 1.25rem 0 0;
  text-align: center;
  font-size: 0.85rem;
  color: #777;
}

.hint a {
  color: #6ea8fe;
  text-decoration: none;
}

.hint a:hover {
  text-decoration: underline;
}
</style>
