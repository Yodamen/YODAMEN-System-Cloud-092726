import SearchableSelect from './SearchableSelect'

// Shared customer registration fields used by Customer List and Opening Balances.
export const emptyCustomerForm = () => ({
  first_name: '', last_name: '', mobile_number: '', email: '', birthday: '',
  gender_code: '', address: '', lead_source_code: '',
  registration_date: new Date().toLocaleDateString('en-CA'),
  notes: '', is_test_customer: false,
})

export default function CustomerRegistrationForm({ form, onChange, onSubmit, onCancel, saving = false, references = {}, canSetTestCustomer = false }) {
  return (
          <form className="form-grid" onSubmit={onSubmit}>
            <label className="form-field">First Name<input value={form.first_name} onChange={(e) => onChange((v) => ({ ...v, first_name: e.target.value }))} required /></label>
            <label className="form-field">Last Name<input value={form.last_name} onChange={(e) => onChange((v) => ({ ...v, last_name: e.target.value }))} required /></label>
            <label className="form-field">Mobile Number<input value={form.mobile_number} onChange={(e) => onChange((v) => ({ ...v, mobile_number: e.target.value }))} /></label>
            <label className="form-field">Email<input type="email" value={form.email} onChange={(e) => onChange((v) => ({ ...v, email: e.target.value }))} /></label>
            <label className="form-field">Birthday<input type="date" value={form.birthday} onChange={(e) => onChange((v) => ({ ...v, birthday: e.target.value }))} /></label>
            <label className="form-field">Gender<SearchableSelect value={form.gender_code} onChange={(e) => onChange((v) => ({ ...v, gender_code: e.target.value }))}><option value="">Select</option>{(references.genders || []).map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</SearchableSelect></label>
            <label className="form-field">Lead Source<SearchableSelect value={form.lead_source_code} onChange={(e) => onChange((v) => ({ ...v, lead_source_code: e.target.value }))}><option value="">Select</option>{(references.lead_sources || []).map((row) => <option key={row.code} value={row.code}>{row.value}</option>)}</SearchableSelect></label>
            <label className="form-field">Registration Date<input type="date" value={form.registration_date} onChange={(e) => onChange((v) => ({ ...v, registration_date: e.target.value }))} /></label>
            <label className="form-field form-span-2">Address<textarea rows="2" value={form.address} onChange={(e) => onChange((v) => ({ ...v, address: e.target.value }))} /></label>
            <label className="form-field form-span-2">Notes<textarea rows="3" value={form.notes} onChange={(e) => onChange((v) => ({ ...v, notes: e.target.value }))} /></label>
            {!form.id && canSetTestCustomer ? (
              <label className="checkbox-field form-span-2">
                <input type="checkbox" checked={Boolean(form.is_test_customer)} onChange={(e) => onChange((v) => ({ ...v, is_test_customer: e.target.checked }))} />
                <span>
                  <strong>Test Customer Only</strong>
                  <small>Marks this customer and all linked transactions as eligible for the test-only DevReset.</small>
                </span>
              </label>
            ) : null}
            <div className="field-helper form-span-2">Duplicate control applies when a mobile number is provided, or when both name and birthday match an existing customer.</div>
            <div className="modal-actions form-span-2"><button className="secondary-button" type="button" onClick={onCancel}>Cancel</button><button className="primary-button" type="submit" disabled={saving}>{saving ? 'Saving...' : 'Save Customer'}</button></div>
          </form>
  )
}
