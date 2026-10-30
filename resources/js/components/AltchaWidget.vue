<script setup>
// Self-hosted ALTCHA proof-of-work widget (https://altcha.org).
// Rendered only when an administrator enabled ALTCHA; the widget script is
// loaded on demand so it does not weigh on the main bundle.
import { ref, onMounted } from 'vue'
import { useTolgee } from '@tolgee/vue'
import { getApiUrl } from '../utils'

const tolgee = useTolgee(['language'])
const widget = ref(null)
const ready = ref(false)
const challengeUrl = `${getApiUrl()}/api/altcha/challenge`
let payload = null

// Erugo language -> ALTCHA translation key
const ALTCHA_LANGUAGES = { de: 'de', fr: 'fr-fr', it: 'it', nl: 'nl', pt: 'pt-pt' }

onMounted(async () => {
  await import('altcha')
  await Promise.all([
    import('altcha/i18n/de'),
    import('altcha/i18n/fr-fr'),
    import('altcha/i18n/it'),
    import('altcha/i18n/nl'),
    import('altcha/i18n/pt-pt')
  ]).catch(() => {})
  ready.value = true
})

const language = () => {
  const lang = (tolgee.value.getLanguage() || 'en').slice(0, 2)
  return ALTCHA_LANGUAGES[lang] ?? 'en'
}

const onStateChange = (event) => {
  const { state, payload: newPayload } = event.detail || {}
  payload = state === 'verified' ? newPayload : null
}

// Solution to send with the form; solves the challenge first if needed.
const getPayload = async () => {
  if (payload) return payload
  if (!widget.value?.verify) return null
  const result = await widget.value.verify()
  return result?.payload ?? null
}

// A solution is accepted once: call after each submission.
const reset = () => {
  payload = null
  widget.value?.reset?.()
}

defineExpose({ getPayload, reset })
</script>

<template>
  <div class="altcha-container">
    <altcha-widget
      v-if="ready"
      ref="widget"
      :challenge="challengeUrl"
      :language="language()"
      type="checkbox"
      auto="onfocus"
      @statechange="onStateChange"
    ></altcha-widget>
  </div>
</template>

<style scoped>
.altcha-container {
  width: 100%;
  margin-top: 0.5rem;
}
</style>
