<script setup>
import { ref, onMounted } from 'vue'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import { getEmailBranding, uploadEmailLogo, deleteEmailLogo, saveEmailLogoPlate } from '../../api'

const { t } = useTranslate()
const toast = useToast()

const branding = ref(null)
const plate = ref('#ffffff')
const busy = ref(false)
const fileInput = ref(null)

const load = async () => {
  try {
    branding.value = await getEmailBranding()
    plate.value = branding.value.plate
  } catch (error) {
    console.error(error)
    toast.error(t.value('settings.emailBranding.error_loading'))
  }
}
onMounted(load)

const run = async (action, success) => {
  busy.value = true
  try {
    branding.value = await action()
    plate.value = branding.value.plate
    toast.success(t.value(success))
  } catch (error) {
    toast.error(error.message || t.value('settings.emailBranding.error_saving'))
  } finally {
    busy.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

const onFile = (event) => {
  const file = event.target.files?.[0]
  if (file) run(() => uploadEmailLogo(file), 'settings.emailBranding.logo_saved')
}
const removeLogo = () => run(() => deleteEmailLogo(), 'settings.emailBranding.logo_removed')
const savePlate = () => run(() => saveEmailLogoPlate(plate.value), 'settings.emailBranding.plate_saved')
</script>

<template>
  <div class="setting-group" id="emailLogo">
    <div class="setting-group-header">
      <h3>{{ $t('settings.emailBranding.title') }}</h3>
    </div>
    <div class="setting-group-body" v-if="branding">
      <div class="setting-group-body-item">
        <label>{{ $t('settings.emailBranding.logo') }}</label>
        <div class="email-logo-plate" :style="{ background: plate }">
          <img v-if="branding.logo_url" :src="branding.logo_url" :alt="$t('settings.emailBranding.logo_alt')" />
          <span v-else>{{ $t('settings.emailBranding.no_logo') }}</span>
        </div>
        <div class="email-logo-actions">
          <input ref="fileInput" id="emailLogoFile" type="file" accept="image/png,image/jpeg" class="d-none" :disabled="busy" @change="onFile" />
          <button type="button" :disabled="busy" @click="$refs.fileInput.click()">{{ $t('settings.emailBranding.upload') }}</button>
          <button type="button" v-if="branding.logo_url" :disabled="busy" @click="removeLogo">{{ $t('settings.emailBranding.remove') }}</button>
        </div>
        <small>{{ $t('settings.emailBranding.logo_help') }}</small>
      </div>
      <div class="setting-group-body-item">
        <label for="emailLogoPlate">{{ $t('settings.emailBranding.plate') }}</label>
        <div class="email-logo-actions">
          <input id="emailLogoPlate" type="color" v-model="plate" :disabled="busy" />
          <button type="button" :disabled="busy || plate === branding.plate" @click="savePlate">{{ $t('settings.emailBranding.save_plate') }}</button>
        </div>
        <small>{{ $t('settings.emailBranding.plate_help') }}</small>
      </div>
      <div class="setting-group-body-item">
        <small>{{ $t('settings.emailBranding.accent_help') }}</small>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.email-logo-plate {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 90px;
  padding: 16px;
  border: 1px solid var(--input-border-color);
  border-radius: var(--panel-border-radius);
  color: #555;
  img {
    max-height: 56px;
    max-width: 100%;
  }
}
.email-logo-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
}
small {
  display: block;
  margin-top: 8px;
}
</style>
