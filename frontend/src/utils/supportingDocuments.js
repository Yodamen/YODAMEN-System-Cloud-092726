import { api } from '../api/client'

export const SUPPORTING_DOCUMENT_ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv'

export async function uploadSupportingDocuments(files, module, purpose = '') {
  const selected = Array.from(files || []).filter(Boolean)
  if (!selected.length) return []

  const uploaded = []
  for (const file of selected) {
    const body = new FormData()
    body.append('module', module)
    if (purpose) body.append('purpose', purpose)
    body.append('file', file)
    const response = await api.post('/v1/supporting-documents/temp', body, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    uploaded.push(response.data.document.token)
  }
  return uploaded
}

export function supportingFileNames(files) {
  return Array.from(files || []).map((file) => file.name)
}

export function isCashPaymentMethod(method) {
  if (!method) return false
  const code = String(method.code || '').trim().toUpperCase()
  const name = String(method.name || method.display_value || '').trim().toUpperCase()
  return ['CASH', 'CASH_PAYMENT', 'CASH_ON_HAND'].includes(code)
    || ['CASH', 'CASH PAYMENT', 'CASH ON HAND'].includes(name)
}

export function paymentMethodRequiresProof(paymentMethodId, paymentMethods = []) {
  const id = Number(paymentMethodId || 0)
  if (!id) return false
  const method = (paymentMethods || []).find((row) => Number(row.id) === id)
  return method ? !isCashPaymentMethod(method) : true
}

export function anyPaymentMethodRequiresProof(paymentMethodIds = [], paymentMethods = []) {
  return (paymentMethodIds || []).some((id) => paymentMethodRequiresProof(id, paymentMethods))
}
