import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'

const source = readFileSync(
  new URL('../src/modules/student-management/StudentDocumentsView.vue', import.meta.url),
  'utf8',
)

test('Student Documents uses the private blob endpoint for image previews and revokes object URLs', () => {
  assert.match(source, /apiClient\.get\(`\/registrar\/student-documents\/\$\{document\.id\}\/view`/)
  assert.match(source, /responseType: 'blob'/)
  assert.match(source, /URL\.createObjectURL\(response\.data\)/)
  assert.match(source, /URL\.revokeObjectURL/)
  assert.doesNotMatch(source, /:src="document\.file_path"/)
})

test('document cards provide image, PDF, missing, loading, and recoverable error preview states', () => {
  assert.match(source, /class="document-preview"/)
  assert.match(source, /No document uploaded/)
  assert.match(source, /Loading preview…/)
  assert.match(source, /Failed to load preview/)
  assert.match(source, />Retry</)
  assert.match(source, /PDF Document/)
  assert.match(source, /object-fit: contain/)
})

test('image preview opens an accessible full-screen viewer with keyboard and backdrop closing', () => {
  assert.match(source, /role="dialog"/)
  assert.match(source, /aria-modal="true"/)
  assert.match(source, /@mousedown\.self="closeImageViewer"/)
  assert.match(source, /event\.key === 'Escape'/)
  assert.match(source, /viewerCloseButton\.value\?\.focus\(\)/)
  assert.match(source, /viewerTrigger\?\.focus\?\.\(\)/)
})

test('upload replacement refreshes preview and AI support copy names only current types', () => {
  assert.match(source, /await loadDocumentPreview\(data\.data, true\)/)
  assert.match(
    source,
    /Currently supports Form 137, Good Moral Certificate, and Certificate of Enrollment\. Missing and unsupported documents are skipped\./,
  )
  assert.doesNotMatch(source, /Currently supports Birth Certificate/)
})

test('document cards stack their left-side preview at narrow widths and stay overflow-safe', () => {
  assert.match(source, /grid-template-columns: clamp\(180px, 20vw, 230px\) minmax\(0, 1fr\)/)
  assert.match(source, /@media \(max-width: 700px\)[\s\S]*\.document-card \{ grid-template-columns: 1fr; \}/)
  assert.match(source, /\.documents \{[^}]*min-width: 0/)
})
