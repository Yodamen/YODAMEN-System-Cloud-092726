import { useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'

function sizeLabel(bytes) {
  const value = Number(bytes || 0)
  if (value < 1024) return `${value} B`
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`
  return `${(value / 1024 / 1024).toFixed(1)} MB`
}

export default function SupportingDocumentList({ entityType, entityId, title = 'Supporting Documents' }) {
  const [rows, setRows] = useState([])
  const [message, setMessage] = useState('')

  useEffect(() => {
    if (!entityType || !entityId) return
    let active = true
    api.get('/v1/supporting-documents', { params: { entity_type: entityType, entity_id: entityId } })
      .then((response) => { if (active) setRows(response.data.documents || []) })
      .catch((error) => { if (active) setMessage(getApiErrorMessage(error, 'Unable to load supporting documents.')) })
    return () => { active = false }
  }, [entityType, entityId])

  async function download(row) {
    setMessage('')
    try {
      const response = await api.get(`/v1/supporting-documents/${row.id}/download`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const anchor = document.createElement('a')
      anchor.href = url
      anchor.download = row.name || 'document'
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
      window.setTimeout(() => URL.revokeObjectURL(url), 60000)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to download document.'))
    }
  }

  if (!rows.length && !message) return null
  return <section className="supporting-document-list">
    <h3>{title}</h3>
    {message ? <div className="form-message error">{message}</div> : null}
    {rows.map((row) => <div className="supporting-document-row" key={row.id}>
      <div><strong>{row.name}</strong><span>{sizeLabel(row.size_bytes)}{row.purpose ? ` · ${row.purpose.replaceAll('_', ' ')}` : ''}</span></div>
      {row.can_download ? <button type="button" className="secondary-button compact-button" onClick={() => download(row)}>Download</button> : null}
    </div>)}
  </section>
}
