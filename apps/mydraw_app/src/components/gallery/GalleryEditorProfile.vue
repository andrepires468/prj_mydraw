<script setup lang="ts">
import { computed } from 'vue'
import type { EditorProfile } from '@/api/editorProfile'

const props = defineProps<{
  profile: EditorProfile | null
}>()

const displayName = computed(() => {
  const n = props.profile?.name?.trim()
  return n && n.length > 0 ? n : 'Editor'
})

const initials = computed(() => {
  const n = displayName.value
  const parts = n.split(/[\s_-]+/).filter(Boolean)
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase().slice(0, 2)
  }
  return n.slice(0, 2).toUpperCase()
})
</script>

<template>
  <header class="gallery-editor-profile">
    <div class="gallery-editor-profile__avatar-wrap">
      <img
        v-if="profile?.avatar_url"
        :src="profile.avatar_url"
        alt=""
        class="gallery-editor-profile__avatar-img"
        width="112"
        height="112"
      />
      <span
        v-else
        class="gallery-editor-profile__avatar-fallback"
        aria-hidden="true"
      >
        {{ initials }}
      </span>
    </div>
    <div class="gallery-editor-profile__text">
      <p class="gallery-editor-profile__label">Editor</p>
      <h1 class="gallery-editor-profile__title">{{ displayName }}</h1>
      <p class="gallery-editor-profile__subtitle">
        Curator and editor of mydraw — showcasing AI-assisted artwork from the community.
      </p>
    </div>
  </header>
</template>
