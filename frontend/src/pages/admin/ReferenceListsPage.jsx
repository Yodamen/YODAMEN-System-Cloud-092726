import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import { useAuth } from '../../context/AuthContext'

const emptyForm = {
  id: null,
  group_code: '',
  code: '',
  display_value: '',
  sort_order: 0,
  is_system: false,
}

export default function ReferenceListsPage() {
  const { hasPermission } = useAuth()
  const [categories, setCategories] = useState([])
  const [references, setReferences] = useState([])
  const [selectedGroup, setSelectedGroup] = useState('')
  const [form, setForm] = useState(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState('')

  const loadCategories = useCallback(async () => {
    const response = await api.get('/v1/admin/references/categories')
    const rows = response.data.categories || []
    setCategories(rows)
    setSelectedGroup((current) => current || rows[0]?.code || '')
  }, [])

  const loadReferences = useCallback(async (groupCode) => {
    if (!groupCode) {
      setReferences([])
      return
    }

    const response = await api.get('/v1/admin/references', {
      params: { group_code: groupCode },
    })
    setReferences(response.data.references || [])
  }, [])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      Promise.all([loadCategories()])
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load reference categories.')))
        .finally(() => setLoading(false))
    }, 0)

    return () => window.clearTimeout(timer)
  }, [loadCategories])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      loadReferences(selectedGroup).catch((error) => {
        setMessage(getApiErrorMessage(error, 'Unable to load reference options.'))
      })
    }, 0)

    return () => window.clearTimeout(timer)
  }, [loadReferences, selectedGroup])

  const selectedCategory = useMemo(
    () => categories.find((category) => category.code === selectedGroup),
    [categories, selectedGroup],
  )

  function openCreate() {
    setMessage('')
    setForm({ ...emptyForm, group_code: selectedGroup })
  }

  function openEdit(record) {
    setMessage('')
    setForm({
      id: record.id,
      group_code: record.group.code,
      code: record.code,
      display_value: record.display_value,
      sort_order: record.sort_order,
      is_system: record.is_system,
    })
  }

  async function save(event) {
    event.preventDefault()
    setSaving(true)
    setMessage('')

    try {
      const payload = {
        group_code: form.group_code,
        code: form.code.trim().toUpperCase(),
        display_value: form.display_value.trim(),
        sort_order: Number(form.sort_order || 0),
      }

      if (form.id) {
        await api.put(`/v1/admin/references/${form.id}`, payload)
      } else {
        await api.post('/v1/admin/references', payload)
      }

      setForm(null)
      await loadCategories()
      await loadReferences(selectedGroup)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to save the reference option.'))
    } finally {
      setSaving(false)
    }
  }

  async function toggle(record) {
    setMessage('')
    try {
      await api.patch(`/v1/admin/references/${record.id}/toggle`)
      await loadReferences(selectedGroup)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to change the reference status.'))
    }
  }

  return (
    <div className="page-stack">
      <section className="page-heading">
        <div>
          <span className="eyebrow gold">Administration</span>
          <h1>Reference Lists</h1>
          <p>Maintain stable dropdown options used by YODAMEN modules. System codes remain fixed while labels may be updated.</p>
        </div>
        {hasPermission('references', 'CREATE') && (
          <button className="primary-button" type="button" onClick={openCreate} disabled={!selectedGroup}>
            + Add Option
          </button>
        )}
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        <div className="toolbar-row">
          <label className="form-field compact-field">
            Reference Category
            <select value={selectedGroup} onChange={(event) => setSelectedGroup(event.target.value)}>
              {categories.map((category) => (
                <option key={category.code} value={category.code}>{category.name}</option>
              ))}
            </select>
          </label>
          <div className="toolbar-note">
            <strong>{selectedCategory?.name || 'Reference Category'}</strong>
            <span>{references.length} option{references.length === 1 ? '' : 's'}</span>
          </div>
        </div>

        {loading ? (
          <div className="empty-state">Loading reference data...</div>
        ) : (
          <div className="table-wrap">
            <table className="data-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Display Value</th>
                  <th>Sort</th>
                  <th>Status</th>
                  <th>Type</th>
                  <th className="actions-column">Action</th>
                </tr>
              </thead>
              <tbody>
                {references.map((record) => (
                  <tr key={record.id}>
                    <td><code>{record.code}</code></td>
                    <td><strong>{record.display_value}</strong></td>
                    <td>{record.sort_order}</td>
                    <td>
                      <span className={`status-pill ${record.active ? 'success' : 'neutral'}`}>
                        {record.active ? 'Active' : 'Inactive'}
                      </span>
                    </td>
                    <td>{record.is_system ? 'System' : 'Custom'}</td>
                    <td className="row-actions">
                      {hasPermission('references', 'EDIT') && (
                        <button className="link-button" type="button" onClick={() => openEdit(record)}>Edit</button>
                      )}
                      {hasPermission('references', 'TOGGLE') && (
                        <button className="link-button" type="button" onClick={() => toggle(record)}>
                          {record.active ? 'Deactivate' : 'Activate'}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
                {!references.length && (
                  <tr>
                    <td colSpan="6"><div className="empty-state">No options yet for this category.</div></td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {form && (
        <Modal
          title={form.id ? 'Edit Reference Option' : 'Add Reference Option'}
          subtitle="Codes are used by system logic. System codes cannot be changed."
          onClose={() => setForm(null)}
        >
          <form className="form-grid" onSubmit={save}>
            <label className="form-field">
              Category
              <select
                value={form.group_code}
                disabled={Boolean(form.id)}
                onChange={(event) => setForm((current) => ({ ...current, group_code: event.target.value }))}
              >
                {categories.map((category) => (
                  <option key={category.code} value={category.code}>{category.name}</option>
                ))}
              </select>
            </label>

            <label className="form-field">
              Code
              <input
                value={form.code}
                disabled={form.is_system}
                onChange={(event) => setForm((current) => ({ ...current, code: event.target.value }))}
                placeholder="EXAMPLE_CODE"
                required
              />
            </label>

            <label className="form-field form-span-2">
              Display Value
              <input
                value={form.display_value}
                onChange={(event) => setForm((current) => ({ ...current, display_value: event.target.value }))}
                required
              />
            </label>

            <label className="form-field">
              Sort Order
              <input
                type="number"
                min="0"
                value={form.sort_order}
                onChange={(event) => setForm((current) => ({ ...current, sort_order: event.target.value }))}
              />
            </label>

            <div className="modal-actions form-span-2">
              <button className="secondary-button" type="button" onClick={() => setForm(null)}>Cancel</button>
              <button className="primary-button" type="submit" disabled={saving}>
                {saving ? 'Saving...' : 'Save Option'}
              </button>
            </div>
          </form>
        </Modal>
      )}
    </div>
  )
}
