export const BRAND_FONT_OPTIONS = [
  { value: 'INTER', label: 'Inter / Modern Sans' },
  { value: 'SYSTEM', label: 'System UI' },
  { value: 'ARIAL', label: 'Arial' },
  { value: 'HELVETICA', label: 'Helvetica' },
  { value: 'TREBUCHET_MS', label: 'Trebuchet MS' },
  { value: 'VERDANA', label: 'Verdana' },
  { value: 'TAHOMA', label: 'Tahoma' },
  { value: 'GEORGIA', label: 'Georgia' },
  { value: 'TIMES_NEW_ROMAN', label: 'Times New Roman' },
]

const FONT_STACKS = {
  INTER: 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
  SYSTEM: 'system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif',
  ARIAL: 'Arial, Helvetica, sans-serif',
  HELVETICA: 'Helvetica, Arial, sans-serif',
  TREBUCHET_MS: '"Trebuchet MS", Arial, sans-serif',
  VERDANA: 'Verdana, Geneva, sans-serif',
  TAHOMA: 'Tahoma, Verdana, sans-serif',
  GEORGIA: 'Georgia, "Times New Roman", serif',
  TIMES_NEW_ROMAN: '"Times New Roman", Times, serif',
}

export function brandFontStack(value) {
  return FONT_STACKS[String(value || 'INTER').toUpperCase()] || FONT_STACKS.INTER
}

export function brandingCssVariables(branding) {
  const primary = branding?.primary_color || branding?.accent_color || '#c8a64b'
  const accent = branding?.accent_color || primary
  const sidebar = branding?.sidebar_color || '#141414'

  return {
    '--gold': primary,
    '--gold-dark': primary,
    '--accent': accent,
    '--sidebar': sidebar,
    '--app-font': brandFontStack(branding?.font_family),
  }
}
