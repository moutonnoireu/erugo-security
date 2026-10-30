// Helpers to pass WebAuthn options/credentials between the server (JSON,
// base64url) and navigator.credentials (ArrayBuffers).

const fromBase64Url = (value) => {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/')
  const padded = base64 + '='.repeat((4 - (base64.length % 4)) % 4)
  const binary = atob(padded)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
  return bytes.buffer
}

const toBase64Url = (buffer) => {
  if (!buffer) return null
  const bytes = new Uint8Array(buffer)
  let binary = ''
  for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i])
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

const descriptors = (list = []) => list.map((item) => ({ ...item, id: fromBase64Url(item.id) }))

export const passkeysSupported = () =>
  typeof window !== 'undefined' && !!window.PublicKeyCredential && !!navigator.credentials

export const createPasskey = async (options) => {
  const publicKey = {
    ...options,
    challenge: fromBase64Url(options.challenge),
    user: { ...options.user, id: fromBase64Url(options.user.id) },
    excludeCredentials: descriptors(options.excludeCredentials)
  }
  const credential = await navigator.credentials.create({ publicKey })
  return {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: toBase64Url(credential.response.clientDataJSON),
      attestationObject: toBase64Url(credential.response.attestationObject),
      transports: credential.response.getTransports ? credential.response.getTransports() : []
    },
    clientExtensionResults: credential.getClientExtensionResults ? credential.getClientExtensionResults() : {}
  }
}

export const getPasskeyAssertion = async (options) => {
  const publicKey = {
    ...options,
    challenge: fromBase64Url(options.challenge),
    allowCredentials: descriptors(options.allowCredentials)
  }
  const credential = await navigator.credentials.get({ publicKey })
  return {
    id: credential.id,
    rawId: toBase64Url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: toBase64Url(credential.response.clientDataJSON),
      authenticatorData: toBase64Url(credential.response.authenticatorData),
      signature: toBase64Url(credential.response.signature),
      userHandle: toBase64Url(credential.response.userHandle)
    },
    clientExtensionResults: credential.getClientExtensionResults ? credential.getClientExtensionResults() : {}
  }
}
