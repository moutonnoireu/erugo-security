<script setup>
// Mandatory two-factor setup during sign-in (admin policy). The session is
// only opened once a first factor works; recovery codes are shown before
// continuing.
import { ref, computed } from 'vue'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import qrcode from 'qrcode-generator'
import { ShieldCheck, Smartphone, Fingerprint, Copy, Download, ArrowLeft } from 'lucide-vue-next'
import { enrolStartTotp, enrolConfirmTotp, enrolPasskeyOptions, enrolPasskey } from '../api'
import { passkeysSupported, createPasskey } from '../webauthn'

const props = defineProps({ enrolToken: { type: String, required: true } })
const emit = defineEmits(['done', 'cancel'])

const { t } = useTranslate()
const toast = useToast()

const busy = ref(false)
const method = ref(null) // 'totp' | 'passkey'
const totp = ref(null)
const code = ref('')
const result = ref(null) // { auth, recoveryCodes }
const canUsePasskeys = passkeysSupported()

const qrSvg = computed(() => {
  if (!totp.value) return ''
  const qr = qrcode(0, 'M')
  qr.addData(totp.value.otpauth_uri)
  qr.make()
  return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true })
})

const handleError = (error) => {
  if (error.code === 'mfa_enrolment_expired') {
    toast.error(t.value('auth.mfa.enrol_expired'))
    emit('cancel')
  } else if (error?.name === 'NotAllowedError' || error?.name === 'AbortError') {
    toast.error(t.value('auth.mfa.passkey_cancelled'))
  } else if (error.code === 'mfa_invalid_code') {
    toast.error(t.value('auth.mfa.invalid_code'))
  } else {
    toast.error(t.value('settings.two_factor.action_failed'))
  }
}

const chooseTotp = async () => {
  busy.value = true
  try {
    totp.value = await enrolStartTotp(props.enrolToken)
    method.value = 'totp'
  } catch (error) {
    handleError(error)
  } finally {
    busy.value = false
  }
}

const confirmTotp = async () => {
  if (!code.value) return
  busy.value = true
  try {
    result.value = await enrolConfirmTotp(props.enrolToken, code.value)
  } catch (error) {
    code.value = ''
    handleError(error)
  } finally {
    busy.value = false
  }
}

const addPasskey = async () => {
  busy.value = true
  try {
    const options = await enrolPasskeyOptions(props.enrolToken)
    const credential = await createPasskey(options)
    result.value = await enrolPasskey(props.enrolToken, t.value('settings.two_factor.default_passkey_name'), credential)
  } catch (error) {
    handleError(error)
  } finally {
    busy.value = false
  }
}

const copyCodes = async () => {
  try {
    await navigator.clipboard.writeText(result.value.recoveryCodes.join('\n'))
    toast.success(t.value('settings.two_factor.codes_copied'))
  } catch (error) {
    toast.error(t.value('settings.two_factor.action_failed'))
  }
}

const downloadCodes = () => {
  const blob = new Blob([result.value.recoveryCodes.join('\n') + '\n'], { type: 'text/plain' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = 'recovery-codes.txt'
  link.click()
  URL.revokeObjectURL(link.href)
}
</script>

<template>
  <div class="enrolment">
    <h1>{{ $t('auth.mfa.enrol_title') }}</h1>

    <!-- done: show recovery codes once -->
    <template v-if="result">
      <p>{{ $t('settings.two_factor.recovery_codes_save') }}</p>
      <ul class="codes" v-if="result.recoveryCodes">
        <li v-for="c in result.recoveryCodes" :key="c"><code>{{ c }}</code></li>
      </ul>
      <div class="actions">
        <button class="secondary" @click="copyCodes"><Copy />{{ $t('settings.two_factor.copy') }}</button>
        <button class="secondary" @click="downloadCodes"><Download />{{ $t('settings.two_factor.download') }}</button>
      </div>
      <button class="block mt-3" @click="emit('done', result.auth)">
        <ShieldCheck />
        {{ $t('auth.mfa.enrol_continue') }}
      </button>
    </template>

    <!-- authenticator app -->
    <template v-else-if="method === 'totp'">
      <p>{{ $t('settings.two_factor.scan_qr') }}</p>
      <div class="qr" v-html="qrSvg"></div>
      <p class="small">{{ $t('settings.two_factor.manual_key') }} <code class="secret">{{ totp.secret }}</code></p>
      <div class="input-container">
        <label for="enrol_code">{{ $t('settings.two_factor.enter_code') }}</label>
        <input
          id="enrol_code"
          type="text"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          class="verification-code-input"
          v-model="code"
          placeholder="123456"
          @keyup.enter="confirmTotp"
        />
      </div>
      <button class="block mt-3" :disabled="busy || !code" @click="confirmTotp">
        <ShieldCheck />
        {{ $t('settings.two_factor.activate') }}
      </button>
      <a href="" class="mt-2 d-inline-block" @click.prevent="method = null">{{ $t('auth.mfa.enrol_other_method') }}</a>
    </template>

    <!-- choose a method -->
    <template v-else>
      <p>{{ $t('auth.mfa.enrol_description') }}</p>
      <button class="block mt-2" :disabled="busy" @click="chooseTotp">
        <Smartphone />
        {{ $t('settings.two_factor.set_up_app') }}
      </button>
      <button class="block secondary mt-2" v-if="canUsePasskeys" :disabled="busy" @click="addPasskey">
        <Fingerprint />
        {{ $t('settings.two_factor.add_passkey') }}
      </button>
    </template>

    <a href="" class="mt-3 d-inline-block" v-if="!result" @click.prevent="emit('cancel')">
      <ArrowLeft style="width: 14px; height: 14px; margin-top: -2px" />
      {{ $t('auth.back_to_login') }}
    </a>
  </div>
</template>

<style scoped>
.enrolment {
  width: 100%;
}
.qr {
  width: 200px;
  max-width: 100%;
  background: #fff;
  border-radius: 8px;
  padding: 6px;
  margin: 0 auto 0.75rem;
}
.qr :deep(svg) {
  display: block;
  width: 100%;
  height: auto;
}
.secret {
  word-break: break-all;
  user-select: all;
}
.codes {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.25rem 1rem;
  padding-left: 0;
  list-style: none;
  font-family: monospace;
}
.actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}
</style>
