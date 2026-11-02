<script setup>
import { ref, computed, onMounted } from 'vue'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import { getEmailLanguages, createEmailLanguage, saveEmailLanguage, deleteEmailLanguage } from '../../api'

const { t } = useTranslate()
const toast = useToast()

const data = ref(null)
const selected = ref('')
const edits = ref({}) // only the texts touched since the last save
const newCode = ref('')
const newName = ref('')
const busy = ref(false)

const load = async (keepSelection = true) => {
  data.value = await getEmailLanguages()
  if (!keepSelection || !data.value.languages.some((l) => l.code === selected.value)) {
    selected.value = data.value.active
  }
}

onMounted(async () => {
  try {
    await load(false)
  } catch (error) {
    console.error(error)
    toast.error(t.value('settings.emailLanguages.error_loading'))
  }
})

const language = computed(() => data.value?.languages.find((l) => l.code === selected.value))
const dirty = computed(() => Object.keys(edits.value).length > 0)

// Group keys by section so the screen reads like the e-mails: general texts, then one block per message.
const groups = computed(() => {
  if (!data.value) return []
  const groups = {}
  for (const key of data.value.keys) {
    const section = key.includes('.') ? key.split('.')[0] : 'general'
    ;(groups[section] ||= []).push(key)
  }
  return Object.entries(groups).map(([section, keys]) => ({ section, keys }))
})

const label = (key) => key.replace(/[._]/g, ' ')
const valueOf = (key) => (key in edits.value ? edits.value[key] : language.value?.lines[key] ?? '')
const setValue = (key, event) => {
  edits.value = { ...edits.value, [key]: event.target.value }
}
const isTranslated = (key) => !!language.value && language.value.code !== 'en' && valueOf(key) !== data.value.reference[key]

const select = (code) => {
  selected.value = code
  edits.value = {}
}

const run = async (action, success) => {
  busy.value = true
  try {
    data.value = await action()
    edits.value = {}
    if (!data.value.languages.some((l) => l.code === selected.value)) selected.value = data.value.active
    toast.success(t.value(success))
  } catch (error) {
    toast.error(error.message || t.value('settings.emailLanguages.error_saving'))
  } finally {
    busy.value = false
  }
}

const save = () => run(() => saveEmailLanguage(selected.value, edits.value), 'settings.emailLanguages.saved')
const create = async () => {
  const code = newCode.value.trim().toLowerCase()
  await run(() => createEmailLanguage(code, newName.value.trim()), 'settings.emailLanguages.created')
  if (data.value?.languages.some((l) => l.code === code)) {
    selected.value = code
    newCode.value = ''
    newName.value = ''
  }
}
const reset = () => run(() => deleteEmailLanguage(selected.value), 'settings.emailLanguages.reset_done')
</script>

<template>
  <div class="setting-group" id="emailLanguages">
    <div class="setting-group-header">
      <h3>{{ $t('settings.emailLanguages.title') }}</h3>
    </div>
    <div class="setting-group-body" v-if="data">
      <div class="setting-group-body-item">
        <label for="emailLanguageSelect">{{ $t('settings.emailLanguages.language') }}</label>
        <select id="emailLanguageSelect" :value="selected" :disabled="busy" @change="select($event.target.value)">
          <option v-for="l in data.languages" :key="l.code" :value="l.code">
            {{ l.name }} ({{ l.code }}){{ l.code === data.active ? ' – ' + $t('settings.emailLanguages.active') : '' }}
          </option>
        </select>
        <small v-if="language && language.missing > 0">{{ $t('settings.emailLanguages.missing', { count: language.missing }) }}</small>
        <small>{{ $t('settings.emailLanguages.active_help') }}</small>
      </div>

      <div class="setting-group-body-item" v-if="language">
        <div class="language-group" v-for="g in groups" :key="g.section">
          <h6>{{ label(g.section) }}</h6>
          <div class="language-row" v-for="key in g.keys" :key="key">
            <label :for="`lang-${key}`">{{ label(key) }}</label>
            <input :id="`lang-${key}`" type="text" maxlength="500" :value="valueOf(key)" :placeholder="data.reference[key]" :disabled="busy" @input="setValue(key, $event)" />
            <small v-if="language.code !== 'en'">{{ data.reference[key] }}</small>
          </div>
        </div>
        <div class="language-actions">
          <button type="button" :disabled="busy || !dirty" @click="save">{{ $t('settings.emailLanguages.save') }}</button>
          <button type="button" v-if="language.custom" :disabled="busy" @click="reset">
            {{ language.shipped ? $t('settings.emailLanguages.restore') : $t('settings.emailLanguages.delete') }}
          </button>
        </div>
      </div>

      <div class="setting-group-body-item">
        <label>{{ $t('settings.emailLanguages.add') }}</label>
        <div class="language-add">
          <input id="emailLanguageCode" type="text" maxlength="8" v-model="newCode" :placeholder="$t('settings.emailLanguages.code_placeholder')" :aria-label="$t('settings.emailLanguages.code')" />
          <input id="emailLanguageName" type="text" maxlength="40" v-model="newName" :placeholder="$t('settings.emailLanguages.name_placeholder')" :aria-label="$t('settings.emailLanguages.name')" />
          <button type="button" :disabled="busy || !newCode || !newName" @click="create">{{ $t('settings.emailLanguages.create') }}</button>
        </div>
        <small>{{ $t('settings.emailLanguages.add_help') }}</small>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.language-group {
  margin-bottom: 1.5rem;
  h6 {
    text-transform: capitalize;
    margin-bottom: 0.75rem;
  }
}
.language-row {
  margin-bottom: 0.9rem;
  label {
    text-transform: capitalize;
    font-weight: 400;
  }
  small {
    display: block;
    margin-top: 2px;
    opacity: 0.7;
  }
}
.language-actions,
.language-add {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 10px;
}
.language-add input:first-child {
  max-width: 120px;
}
small {
  display: block;
  margin-top: 6px;
}
</style>
