<script setup>
// Account settings: authenticator app (TOTP), passkeys and recovery codes.
import { ref, computed, onMounted } from 'vue'
import { useToast } from 'vue-toastification'
import { useTranslate } from '@tolgee/vue'
import qrcode from 'qrcode-generator'
import { ShieldCheck, Smartphone, Fingerprint, KeyRound, Trash, Plus, Copy, Download } from 'lucide-vue-next'
import {
  getTwoFactorStatus,
  startTotpSetup,
  confirmTotpSetup,
  disableTotp,
  regenerateRecoveryCodes,
  getPasskeyRegistrationOptions,
  registerPasskey,
  deletePasskey
} from '../../api'
import { passkeysSupported, createPasskey } from '../../webauthn'

const { t } = useTranslate()
const toast = useToast()

const status = ref(null)
const busy = ref(false)

// authenticator app setup
const totpSetup = ref(null)
const totpCode = ref('')
const totpQrSvg = computed(() => {
  if (!totpSetup.value) return ''
  const qr = qrcode(0, 'M')
  qr.addData(totpSetup.value.otpauth_uri)
  qr.make()
  return qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true })
})

// passkeys
const passkeyName = ref('')
const canUsePasskeys = passkeysSupported()

// recovery codes shown once
const recoveryCodes = ref(null)

const load = async () => {
  try {
    status.value = await getTwoFactorStatus()
  } catch (error) {
    toast.error(t.value('settings.two_factor.load_failed'))
  }
}

onMounted(load)

const errorKey = (error) =>
  ({
    invalid_password: 'settings.two_factor.wrong_password',
    mfa_required_by_policy: 'settings.two_factor.required_by_policy'
  })[error.code] ?? 'settings.two_factor.action_failed'

const askPassword = () => window.prompt(t.value('settings.two_factor.confirm_password'))

const showRecoveryCodes = (codes) => {
  if (codes && codes.length) recoveryCodes.value = codes
}

const beginTotpSetup = async () => {
  busy.value = true
  try {
    totpSetup.value = await startTotpSetup()
    totpCode.value = ''
  } catch (error) {
    toast.error(error.message)
  } finally {
    busy.value = false
  }
}

const finishTotpSetup = async () => {
  if (!totpCode.value) return
  busy.value = true
  try {
    const result = await confirmTotpSetup(totpCode.value)
    totpSetup.value = null
    toast.success(t.value('settings.two_factor.totp_enabled'))
    showRecoveryCodes(result.recovery_codes)
    await load()
  } catch (error) {
    toast.error(t.value(error.code === 'totp_setup_expired' ? 'settings.two_factor.setup_expired' : 'auth.mfa.invalid_code'))
  } finally {
    busy.value = false
  }
}

const handleDisableTotp = async () => {
  const password = askPassword()
  if (!password) return
  busy.value = true
  try {
    status.value = await disableTotp(password)
    toast.success(t.value('settings.two_factor.totp_disabled'))
  } catch (error) {
    toast.error(t.value(errorKey(error)))
  } finally {
    busy.value = false
  }
}

const handleAddPasskey = async () => {
  busy.value = true
  try {
    const options = await getPasskeyRegistrationOptions()
    const credential = await createPasskey(options)
    const result = await registerPasskey(passkeyName.value || t.value('settings.two_factor.default_passkey_name'), credential)
    passkeyName.value = ''
    toast.success(t.value('settings.two_factor.passkey_added'))
    showRecoveryCodes(result.recovery_codes)
    await load()
  } catch (error) {
    if (error?.name === 'NotAllowedError' || error?.name === 'AbortError') {
      toast.error(t.value('auth.mfa.passkey_cancelled'))
    } else if (error?.name === 'InvalidStateError') {
      toast.error(t.value('settings.two_factor.passkey_exists'))
    } else {
      toast.error(t.value('settings.two_factor.passkey_failed'))
    }
  } finally {
    busy.value = false
  }
}

const handleDeletePasskey = async (passkey) => {
  const password = askPassword()
  if (!password) return
  busy.value = true
  try {
    status.value = await deletePasskey(passkey.id, password)
    toast.success(t.value('settings.two_factor.passkey_removed'))
  } catch (error) {
    toast.error(t.value(errorKey(error)))
  } finally {
    busy.value = false
  }
}

const handleRegenerateCodes = async () => {
  const password = askPassword()
  if (!password) return
  busy.value = true
  try {
    const result = await regenerateRecoveryCodes(password)
    showRecoveryCodes(result.recovery_codes)
    await load()
  } catch (error) {
    toast.error(t.value(error.code === 'invalid_password' ? 'settings.two_factor.wrong_password' : 'settings.two_factor.action_failed'))
  } finally {
    busy.value = false
  }
}

const copyCodes = async () => {
  try {
    await navigator.clipboard.writeText(recoveryCodes.value.join('\n'))
    toast.success(t.value('settings.two_factor.codes_copied'))
  } catch (error) {
    toast.error(t.value('settings.two_factor.action_failed'))
  }
}

const downloadCodes = () => {
  const blob = new Blob([recoveryCodes.value.join('\n') + '\n'], { type: 'text/plain' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = 'recovery-codes.txt'
  link.click()
  URL.revokeObjectURL(link.href)
}

const formatDate = (value) => (value ? new Date(value).toLocaleDateString() : t.value('settings.two_factor.never'))
</script>

<template>
  <div class="setting-group" id="two_factor">
    <div class="setting-group-header">
      <h3>
        <ShieldCheck />
        {{ $t('settings.two_factor.title') }}
      </h3>
    </div>

    <div class="setting-group-body" v-if="status">
      <p class="help-text">
        {{ status.enabled ? $t('settings.two_factor.status_on') : $t('settings.two_factor.status_off') }}
      </p>

      <!-- recovery codes, shown once -->
      <div class="recovery-codes" v-if="recoveryCodes">
        <h5>{{ $t('settings.two_factor.recovery_codes') }}</h5>
        <p class="help-text">{{ $t('settings.two_factor.recovery_codes_save') }}</p>
        <ul>
          <li v-for="code in recoveryCodes" :key="code"><code>{{ code }}</code></li>
        </ul>
        <div class="d-flex gap-2 flex-wrap">
          <button class="secondary" @click="copyCodes"><Copy />{{ $t('settings.two_factor.copy') }}</button>
          <button class="secondary" @click="downloadCodes"><Download />{{ $t('settings.two_factor.download') }}</button>
          <button @click="recoveryCodes = null">{{ $t('settings.two_factor.codes_saved') }}</button>
        </div>
      </div>

      <!-- authenticator app -->
      <h5 class="mt-3"><Smartphone class="inline-icon" /> {{ $t('settings.two_factor.authenticator_app') }}</h5>
      <template v-if="status.totp_enabled">
        <p class="help-text">{{ $t('settings.two_factor.totp_active') }}</p>
        <button class="secondary" :disabled="busy" @click="handleDisableTotp">
          <Trash />
          {{ $t('settings.two_factor.disable') }}
        </button>
      </template>
      <template v-else-if="totpSetup">
        <p class="help-text">{{ $t('settings.two_factor.scan_qr') }}</p>
        <div class="qr" v-html="totpQrSvg"></div>
        <p class="help-text">
          {{ $t('settings.two_factor.manual_key') }}
          <code class="secret">{{ totpSetup.secret }}</code>
        </p>
        <div class="setting-group-body-item">
          <label for="totp_confirm_code">{{ $t('settings.two_factor.enter_code') }}</label>
          <input
            id="totp_confirm_code"
            type="text"
            inputmode="numeric"
            autocomplete="one-time-code"
            maxlength="6"
            v-model="totpCode"
            placeholder="123456"
            @keyup.enter="finishTotpSetup"
          />
        </div>
        <div class="d-flex gap-2">
          <button :disabled="busy || !totpCode" @click="finishTotpSetup">
            <ShieldCheck />
            {{ $t('settings.two_factor.activate') }}
          </button>
          <button class="secondary" @click="totpSetup = null">{{ $t('settings.two_factor.cancel') }}</button>
        </div>
      </template>
      <template v-else>
        <p class="help-text">{{ $t('settings.two_factor.totp_description') }}</p>
        <button :disabled="busy" @click="beginTotpSetup">
          <Plus />
          {{ $t('settings.two_factor.set_up_app') }}
        </button>
      </template>

      <hr class="my-4" />

      <!-- passkeys -->
      <h5><Fingerprint class="inline-icon" /> {{ $t('settings.two_factor.passkeys') }}</h5>
      <p class="help-text">{{ $t('settings.two_factor.passkeys_description') }}</p>
      <ul class="passkeys" v-if="status.passkeys.length">
        <li v-for="passkey in status.passkeys" :key="passkey.id">
          <div>
            <strong>{{ passkey.name }}</strong>
            <small>
              {{ $t('settings.two_factor.added_on', { date: formatDate(passkey.created_at) }) }} ·
              {{ $t('settings.two_factor.last_used', { date: formatDate(passkey.last_used_at) }) }}
            </small>
          </div>
          <button class="secondary icon-only" :disabled="busy" @click="handleDeletePasskey(passkey)" :title="$t('settings.two_factor.remove')">
            <Trash />
          </button>
        </li>
      </ul>
      <template v-if="canUsePasskeys">
        <div class="setting-group-body-item">
          <label for="passkey_name">{{ $t('settings.two_factor.passkey_name') }}</label>
          <input id="passkey_name" type="text" maxlength="100" v-model="passkeyName" :placeholder="$t('settings.two_factor.passkey_name_placeholder')" />
        </div>
        <button :disabled="busy" @click="handleAddPasskey">
          <Plus />
          {{ $t('settings.two_factor.add_passkey') }}
        </button>
      </template>
      <p class="help-text" v-else>{{ $t('settings.two_factor.passkeys_unsupported') }}</p>

      <template v-if="status.enabled">
        <hr class="my-4" />
        <h5><KeyRound class="inline-icon" /> {{ $t('settings.two_factor.recovery_codes') }}</h5>
        <p class="help-text">
          {{ $t('settings.two_factor.recovery_codes_remaining', { count: status.recovery_codes_remaining }) }}
        </p>
        <button class="secondary" :disabled="busy" @click="handleRegenerateCodes">
          <KeyRound />
          {{ $t('settings.two_factor.regenerate_codes') }}
        </button>
      </template>
    </div>
  </div>
</template>

<style scoped>
.qr {
  width: 200px;
  max-width: 100%;
  background: #fff;
  border-radius: 8px;
  padding: 6px;
  margin-bottom: 0.75rem;
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
.inline-icon {
  width: 18px;
  height: 18px;
  margin-top: -3px;
}
.recovery-codes {
  border: 1px solid var(--panel-border-color, rgba(0, 0, 0, 0.15));
  border-radius: 8px;
  padding: 1rem;
  margin-bottom: 1rem;
}
.recovery-codes ul {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.25rem 1rem;
  padding-left: 0;
  list-style: none;
  font-family: monospace;
}
.passkeys {
  list-style: none;
  padding-left: 0;
}
.passkeys li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--panel-border-color, rgba(0, 0, 0, 0.1));
}
.passkeys small {
  display: block;
  opacity: 0.75;
}
</style>
