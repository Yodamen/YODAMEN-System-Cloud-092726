import { SUPPORTING_DOCUMENT_ACCEPT, supportingFileNames } from '../../utils/supportingDocuments'

export default function SupportingDocumentField({
  files = [],
  onChange,
  required = false,
  label = 'Supporting Document',
  hint = 'PDF, image, Word, Excel or CSV. Up to 10 MB per file.',
  multiple = true,
}) {
  const names = supportingFileNames(files)
  return (
    <label className="form-field supporting-document-field">
      <span>{label}{required ? ' *' : ''}</span>
      <input
        type="file"
        accept={SUPPORTING_DOCUMENT_ACCEPT}
        multiple={multiple}
        required={required && names.length === 0}
        onChange={(event) => onChange?.(Array.from(event.target.files || []))}
      />
      <small>{hint}</small>
      {names.length ? <div className="supporting-document-selection">{names.map((name, index) => <span key={`${name}-${index}`}>{name}</span>)}</div> : null}
    </label>
  )
}
