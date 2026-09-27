import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import { useAuth } from '../../context/AuthContext'
import { BRAND_FONT_OPTIONS, brandingCssVariables } from '../../utils/branding'

const fallback = {
  system_name: 'YODAMEN Management System',
  business_display_name: 'YODAMEN',
  subtitle: 'Grooming Lounge',
  logo_source: 'NONE',
  logo_url: '',
  external_logo_url: '',
  font_family: 'INTER',
  primary_color: '#c8a64b',
  accent_color: '#c8a64b',
  sidebar_color: '#141414',
  login_label: 'Operating System',
}

export default function BrandingPage() {
  const { loadBootstrap, hasPermission } = useAuth()
  const [form, setForm] = useState(fallback)
  const [fontOptions, setFontOptions] = useState(BRAND_FONT_OPTIONS)
  const [logoFile, setLogoFile] = useState(null)
  const [localLogoPreview, setLocalLogoPreview] = useState('')
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    const response = await api.get('/v1/admin/branding')
    const branding = response.data.branding || {}
    setForm({
      ...fallback,
      ...branding,
      logo_source: branding.logo_source || (branding.logo_url ? 'URL' : 'NONE'),
      logo_url: branding.logo_url || '',
      external_logo_url: branding.external_logo_url || '',
    })
    if (Array.isArray(response.data.font_options) && response.data.font_options.length) {
      setFontOptions(response.data.font_options)
    }
    setLogoFile(null)
    setLocalLogoPreview('')
  }, [])

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      void load().catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load branding.')))
    }, 0)

    return () => window.clearTimeout(timeoutId)
  }, [load])

  function selectLogoFile(event) {
    const file = event.target.files?.[0] || null
    setLogoFile(file)
    if (localLogoPreview) URL.revokeObjectURL(localLogoPreview)
    setLocalLogoPreview(file ? URL.createObjectURL(file) : '')
  }

  async function save(event) {
    event.preventDefault()
    setBusy(true)
    setMessage('')

    try {
      if (form.logo_source === 'UPLOAD' && logoFile) {
        const payload = new FormData()
        payload.append('logo', logoFile)
        await api.post('/v1/admin/branding/logo', payload)
      }

      await api.put('/v1/admin/branding', {
        system_name: form.system_name,
        business_display_name: form.business_display_name || null,
        subtitle: form.subtitle || null,
        logo_source: form.logo_source,
        logo_url: form.logo_source === 'URL' ? (form.external_logo_url || null) : null,
        font_family: form.font_family,
        primary_color: form.primary_color,
        accent_color: form.accent_color,
        sidebar_color: form.sidebar_color,
        login_label: form.login_label,
      })

      await load()
      await loadBootstrap()
      setMessage('Branding updated.')
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to update branding.'))
    } finally {
      setBusy(false)
    }
  }

  const previewLogo = localLogoPreview || form.logo_url
  const previewStyle = {
    ...brandingCssVariables(form),
    '--preview-primary': form.primary_color,
    '--preview-accent': form.accent_color,
    '--preview-sidebar': form.sidebar_color,
  }

  return <div className="page-stack">
    <div className="page-heading">
      <div>
        <p className="eyebrow">Administration</p>
        <h1 className="live-page-title">Branding & Appearance <PageRefreshButton label="Refresh branding" onRefresh={load} /></h1>
        <p>Control the system identity, logo, typography, and interface colors.</p>
      </div>
    </div>

    {message ? <div className={message.includes('updated') ? 'form-alert success' : 'form-alert error'}>{message}</div> : null}

    <section className="content-card branding-settings-layout">
      <form className="branding-form" onSubmit={save}>
        <div className="branding-form-section">
          <div className="branding-section-heading">
            <h2>Brand Identity</h2>
            <p>Used on the login screen, sidebar, and system headings.</p>
          </div>
          <div className="admin-settings-grid">
            <label className="form-field"><span>System Name</span><input required value={form.system_name} onChange={(e) => setForm((current) => ({ ...current, system_name: e.target.value }))} /></label>
            <label className="form-field"><span>Business Display Name</span><input value={form.business_display_name} onChange={(e) => setForm((current) => ({ ...current, business_display_name: e.target.value }))} /></label>
            <label className="form-field"><span>Subtitle</span><input value={form.subtitle || ''} onChange={(e) => setForm((current) => ({ ...current, subtitle: e.target.value }))} /></label>
            <label className="form-field"><span>Login Footer Label</span><input required value={form.login_label} onChange={(e) => setForm((current) => ({ ...current, login_label: e.target.value }))} /></label>
          </div>
        </div>

        <div className="branding-form-section">
          <div className="branding-section-heading">
            <h2>Logo</h2>
            <p>Recommended: 800 × 200 px (4:1 horizontal), transparent PNG or WebP, maximum 5 MB.</p>
          </div>
          <div className="admin-settings-grid">
            <label className="form-field">
              <span>Logo Source</span>
              <SearchableSelect value={form.logo_source} onChange={(e) => setForm((current) => ({ ...current, logo_source: e.target.value }))}>
                <option value="UPLOAD">Upload Image</option>
                <option value="URL">Logo URL</option>
                <option value="NONE">No Logo</option>
              </SearchableSelect>
            </label>

            {form.logo_source === 'UPLOAD' ? <label className="form-field">
              <span>Upload Logo</span>
              <input type="file" accept="image/png,image/jpeg,image/webp" onChange={selectLogoFile} />
              <small>PNG, JPG, or WebP. Maximum 5 MB.</small>
            </label> : null}

            {form.logo_source === 'URL' ? <label className="form-field full-span">
              <span>Logo URL</span>
              <input type="url" value={form.external_logo_url || ''} onChange={(e) => setForm((current) => ({ ...current, external_logo_url: e.target.value }))} placeholder="https://..." />
            </label> : null}
          </div>
          {previewLogo && form.logo_source !== 'NONE' ? <div className="branding-logo-preview-strip"><img src={previewLogo} alt="Current brand logo" /></div> : null}
        </div>

        <div className="branding-form-section">
          <div className="branding-section-heading">
            <h2>Typography</h2>
            <p>Select the font family used throughout the system.</p>
          </div>
          <label className="form-field">
            <span>Font Style</span>
            <SearchableSelect value={form.font_family} onChange={(e) => setForm((current) => ({ ...current, font_family: e.target.value }))}>
              {fontOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
            </SearchableSelect>
          </label>
        </div>

        <div className="branding-form-section">
          <div className="branding-section-heading">
            <h2>Colors</h2>
            <p>Primary controls main actions, Accent controls active/focus highlights, and Sidebar controls the navigation panel.</p>
          </div>
          <div className="branding-color-grid">
            <ColorField label="Primary Color" value={form.primary_color} onChange={(value) => setForm((current) => ({ ...current, primary_color: value }))} />
            <ColorField label="Accent Color" value={form.accent_color} onChange={(value) => setForm((current) => ({ ...current, accent_color: value }))} />
            <ColorField label="Sidebar Color" value={form.sidebar_color} onChange={(value) => setForm((current) => ({ ...current, sidebar_color: value }))} />
          </div>
        </div>

        {hasPermission('branding', 'EDIT') ? <div className="modal-actions branding-save-actions"><button className="primary-button" disabled={busy}>{busy ? 'Saving...' : 'Save Branding'}</button></div> : null}
      </form>

      <aside className="branding-preview" style={previewStyle}>
        <span>Live Preview</span>
        <div className="branding-preview-sidebar">
          <div className="branding-preview-sidebar-logo">
            {previewLogo && form.logo_source !== 'NONE' ? <img src={previewLogo} alt="Brand preview" /> : <div className="branding-preview-mark">{String(form.business_display_name || 'Y').charAt(0).toUpperCase()}</div>}
          </div>
          <div className="branding-preview-nav active">Dashboard</div>
          <div className="branding-preview-nav">Operations</div>
          <div className="branding-preview-nav">Sales</div>
        </div>
        <div className="branding-preview-content">
          <strong>{form.business_display_name || 'YODAMEN'}</strong>
          <small>{form.subtitle || form.system_name}</small>
          <button type="button">Primary Action</button>
          <span className="branding-preview-accent">Accent highlight</span>
        </div>
      </aside>
    </section>
  </div>
}

function ColorField({ label, value, onChange }) {
  return <label className="form-field">
    <span>{label}</span>
    <div className="branding-color-field">
      <input type="color" value={value} onChange={(e) => onChange(e.target.value)} />
      <input value={value} pattern="#[0-9A-Fa-f]{6}" onChange={(e) => onChange(e.target.value)} />
    </div>
  </label>
}
