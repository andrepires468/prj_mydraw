<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

onMounted(() => {
  if (auth.isAuthenticated) {
    void auth.fetchMe()
  }
})
</script>

<template>
  <div class="app-shell">
    <header class="topbar">
      <RouterLink to="/" class="brand">mydraw</RouterLink>
      <nav class="nav">
        <RouterLink to="/">Início</RouterLink>
        <RouterLink to="/gallery">Galeria</RouterLink>
      </nav>
      <div class="auth-info">
        <template v-if="auth.user">
          <span>{{ auth.user.name }}</span>
          <button class="btn-ghost" @click="auth.logout()">sair</button>
        </template>
        <template v-else>
          <RouterLink to="/login" class="btn-login">Entrar</RouterLink>
        </template>
      </div>
    </header>

    <main class="main">
      <RouterView />
    </main>

    <footer class="footer">
      <span>mydraw &copy; {{ new Date().getFullYear() }}</span>
    </footer>
  </div>
</template>

<style scoped>
.app-shell {
  min-height: 100vh;
  display: grid;
  grid-template-rows: auto 1fr auto;
}

.topbar {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid #2a2a2a;
  background: #111;
}

.brand {
  font-weight: 700;
  font-size: 1.25rem;
  color: #e3e3e3;
  text-decoration: none;
  letter-spacing: 0.5px;
}

.nav {
  display: flex;
  gap: 1rem;
  flex: 1;
}

.nav a {
  color: #9c9c9c;
  text-decoration: none;
  padding: 0.25rem 0;
  border-bottom: 2px solid transparent;
}

.nav a.router-link-active {
  color: #e3e3e3;
  border-bottom-color: #6ea8fe;
}

.auth-info {
  display: flex;
  gap: 0.75rem;
  align-items: center;
  color: #ccc;
  font-size: 0.9rem;
}

.muted {
  color: #666;
}

.btn-ghost {
  background: transparent;
  border: 1px solid #444;
  color: #ccc;
  border-radius: 6px;
  padding: 0.25rem 0.6rem;
  font-size: 0.8rem;
  cursor: pointer;
}

.btn-ghost:hover {
  background: #1a1a1a;
}

.btn-login {
  padding: 0.35rem 0.9rem;
  border-radius: 6px;
  background: linear-gradient(90deg, #6ea8fe, #b388ff);
  color: #0b0b0b;
  text-decoration: none;
  font-weight: 600;
  font-size: 0.85rem;
  transition: opacity 0.15s ease;
}

.btn-login:hover {
  opacity: 0.9;
}

.main {
  padding: 2rem 1.5rem;
  max-width: 1100px;
  margin: 0 auto;
  width: 100%;
}

.footer {
  padding: 1rem 1.5rem;
  text-align: center;
  color: #666;
  border-top: 1px solid #2a2a2a;
  font-size: 0.85rem;
}
</style>
