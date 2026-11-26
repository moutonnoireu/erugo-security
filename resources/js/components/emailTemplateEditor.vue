<script setup>
import { computed, ref } from 'vue'
import { previewEmailTemplate } from '../api'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true
  }
})

// Create a computed property for the template to support v-model
const template = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

const emit = defineEmits(['update:template'])

// Preview of the fields as typed, saved or not, with sample data
const previewHtml = ref('')
const previewError = ref('')
const previewLoading = ref(false)
const previewNarrow = ref(false)
const previewFrame = ref(null)

const showPreview = async () => {
  previewLoading.value = true
  previewError.value = ''
  try {
    const data = await previewEmailTemplate(template.value.id, { ...template.value.variables })
    previewHtml.value = data.html
  } catch (error) {
    previewHtml.value = ''
    previewError.value = error.message
  } finally {
    previewLoading.value = false
  }
}

const closePreview = () => {
  previewHtml.value = ''
  previewError.value = ''
}

const fitPreview = () => {
  const frame = previewFrame.value
  const body = frame?.contentDocument?.body
  if (body) frame.style.height = `${body.scrollHeight}px`
}

const toggleWidth = () => {
  previewNarrow.value = !previewNarrow.value
  setTimeout(fitPreview, 50)
}

</script>

<template>
  <div>

    <!-- Subject -->
    <div class="setting-group-body-item">
      <label :for="`subject-${template.id}`">{{ $t('settings.emailTemplates.sections.subject') }}</label>
      <input type="text" :id="`subject-${template.id}`" v-model="template.subject" @input="emit('update:template', template)" />
    </div>

    <!-- Header -->
    <div class="setting-group-body-item">
      <label :for="`header-${template.id}`">{{ $t('settings.emailTemplates.sections.header') }}</label>
      <input type="text" :id="`header-${template.id}`" v-model="template.variables.header" @input="emit('update:template', template)" />
    </div>
    <!-- Action Text -->
    <div class="setting-group-body-item mt-2">
      <label :for="`action-text-${template.id}`">{{ $t('settings.emailTemplates.sections.action_text') }}</label>
      <input type="text" :id="`action-text-${template.id}`" v-model="template.variables.action_text" @input="emit('update:template', template)" />
    </div>
    <!-- Action URL -->
    <div class="setting-group-body-item mt-2">
      <label :for="`action-url-${template.id}`">{{ $t('settings.emailTemplates.sections.action_url') }}</label>
      <input type="text" :id="`action-url-${template.id}`" v-model="template.variables.action_url" readonly/>
    </div>
     <!-- Content -->
     <div class="setting-group-body-item mt-2">
      <label :for="`content-${template.id}`">{{ $t('settings.emailTemplates.sections.content') }}</label>
      <textarea :id="`content-${template.id}`" v-model="template.variables.content" rows="10" @input="emit('update:template', template)"></textarea>
    </div>
    <!-- Footer -->
    <div class="setting-group-body-item mt-2">
      <label :for="`footer-${template.id}`">{{ $t('settings.emailTemplates.sections.footer') }}</label>
      <textarea :id="`footer-${template.id}`" v-model="template.variables.footer" rows="4" @input="emit('update:template', template)"></textarea>
      <small>{{ $t('settings.emailTemplates.sections.footerDescription') }}</small>
    </div>
    <!-- Preview -->
    <div class="setting-group-body-item mt-2">
      <div class="preview-actions">
        <button type="button" :disabled="previewLoading" @click="showPreview">
          {{ $t('settings.emailTemplates.preview.show') }}
        </button>
        <template v-if="previewHtml">
          <button type="button" class="secondary" @click="toggleWidth">
            {{ previewNarrow ? $t('settings.emailTemplates.preview.wide') : $t('settings.emailTemplates.preview.narrow') }}
          </button>
          <button type="button" class="secondary" @click="closePreview">
            {{ $t('settings.emailTemplates.preview.close') }}
          </button>
        </template>
      </div>
      <p v-if="previewError" class="preview-error">{{ previewError }}</p>
      <template v-if="previewHtml">
        <small>{{ $t('settings.emailTemplates.preview.note') }}</small>
        <!-- No scripts in the sandbox; same origin only to size the frame -->
        <iframe
          ref="previewFrame"
          class="preview-frame"
          :class="{ narrow: previewNarrow }"
          sandbox="allow-same-origin"
          :srcdoc="previewHtml"
          :title="$t('settings.emailTemplates.preview.show')"
          @load="fitPreview"
        ></iframe>
      </template>
    </div>
    
  </div>
</template>

<style scoped>
.preview-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.preview-error {
  color: var(--color-danger, #c0392b);
}

.preview-frame {
  display: block;
  width: 100%;
  min-height: 300px;
  margin-top: 8px;
  border: 1px solid var(--input-border-color);
  background: #ffffff;
}

.preview-frame.narrow {
  max-width: 360px;
}
</style>
