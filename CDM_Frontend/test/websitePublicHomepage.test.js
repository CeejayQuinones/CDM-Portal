import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'
import { compileScript, compileStyle, parse } from '@vue/compiler-sfc'

const source = path => readFile(new URL(`../${path}`, import.meta.url), 'utf8')

test('website homepage compiles and uses the supplied building photograph with a readable overlay', async () => {
  const [homepage, styles, image] = await Promise.all([
    source('src/components/public/WebsitePublicHome.vue'),
    source('src/assets/styles/website-public-home.css'),
    readFile(new URL('../src/assets/styles/images/CDM-bdng.png', import.meta.url)),
  ])
  const { descriptor, errors } = parse(homepage)
  assert.deepEqual(errors, [])
  assert.doesNotThrow(() => compileScript(descriptor, { id: 'website-public-home', inlineTemplate: true }))
  assert.deepEqual(compileStyle({ source: styles, filename: 'website-public-home.css', id: 'website-public-home' }).errors, [])
  assert.match(homepage, /import buildingUrl from '\.\.\/\.\.\/assets\/styles\/images\/CDM-bdng\.png'/)
  assert.match(homepage, /'--campus-image': `url\(\$\{buildingUrl\}\)`/)
  assert.match(styles, /linear-gradient\(90deg, rgba\(4, 32, 23, \.94\), rgba\(6, 78, 59, \.72\), rgba\(4, 32, 23, \.25\)\), var\(--campus-image\)/)
  assert.match(styles, /background-size: cover/)
  assert.match(styles, /background-position:/)
  assert.equal(image.subarray(0, 8).toString('hex'), '89504e470d0a1a0a')
  assert.equal(image.readUInt32BE(16), 409)
  assert.equal(image.readUInt32BE(20), 230)
})

test('homepage contains only the supplied institution, history, program, mission, and vision content', async () => {
  const content = await source('src/config/publicHomepage.js')
  for (const required of [
    'Pamantasan ng Montalban', 'September 25, 2003', 'Kasiglahan Village',
    'Municipal Ordinance No. 03-24', 'Municipal Ordinance No. 14-15',
    'BS Information Technology', 'BS Computer Engineering', 'Elementary Education',
    'Secondary Education', 'Early Childhood Education', 'Technology and Livelihood Education',
    'BS Business Administration major in Human Resource Management', 'BS Entrepreneurship',
    'globally competitive professionals', 'responsible, productive, and globally competitive citizens',
  ]) assert.match(content, new RegExp(required.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
  assert.doesNotMatch(content, /tuition|ranking|award|testimonial|available slots|enrollment count/i)
})

test('public navigation and calls to action use supported anchors, login, and Guest registration', async () => {
  const [homepage, login] = await Promise.all([
    source('src/components/public/WebsitePublicHome.vue'),
    source('src/views/LoginView.vue'),
  ])
  for (const label of ['Home', 'About', 'Academic Programs', 'Portal Services', 'Log In', 'Admission / Get Started']) assert.match(homepage, new RegExp(`>${label.replace('/', '\\/')}<`))
  assert.match(homepage, /@click="scrollToSection\('login'\)"/)
  assert.match(homepage, /const startAdmission = \(\) =>[\s\S]*emit\('start-admission'\)/)
  assert.match(login, /@start-admission="showRegistration = true"/)
  assert.match(login, /<RegistrationWizard v-if="showRegistration"/)
  assert.doesNotMatch(homepage, /router\.push|apiClient|fetch\(|axios/)
})

test('portal services and role entries describe real gated workflows without public records', async () => {
  const [content, homepage] = await Promise.all([
    source('src/config/publicHomepage.js'),
    source('src/components/public/WebsitePublicHome.vue'),
  ])
  for (const service of ['Admission', 'Enrollment', 'Student subjects', 'Class schedules', 'Academic grades', 'Document Requests']) assert.match(content, new RegExp(service))
  for (const role of ['Applicants', 'Students', 'Professors']) assert.match(content, new RegExp(role))
  assert.match(homepage, /Private academic records never appear on this public page/)
  assert.match(content, /officially published/)
  assert.doesNotMatch(`${content}\n${homepage}`, /student_number|contact_number|currentUser|dashboard\.identity|grade_point|GWA/)
})

test('homepage is responsive, theme-aware, reduced-motion safe, and isolated from non-web clients', async () => {
  const [styles, homepage, login, dashboard] = await Promise.all([
    source('src/assets/styles/website-public-home.css'),
    source('src/components/public/WebsitePublicHome.vue'),
    source('src/views/LoginView.vue'),
    source('src/layouts/DashboardLayout.vue'),
  ])
  for (const breakpoint of ['1080px', '900px', '720px', '430px', '340px']) assert.match(styles, new RegExp(`max-width: ${breakpoint}`))
  assert.match(styles, /data-student-theme='dark'/)
  assert.match(styles, /data-student-theme='system'/)
  assert.match(styles, /prefers-color-scheme: dark/)
  assert.match(styles, /prefers-reduced-motion: reduce/)
  assert.match(styles, /overflow-x: clip/)
  assert.match(homepage, /aria-controls="website-home-navigation"/)
  assert.match(homepage, /:aria-expanded="mobileMenuOpen"/)
  assert.match(homepage, /typeof window\.IntersectionObserver !== 'function'/)
  assert.match(login, /import\.meta\.env\.VITE_CDM_CLIENT === 'web'[\s\S]*defineAsyncComponent\(\(\) => import\('\.\.\/components\/public\/WebsitePublicHome\.vue'\)\)/)
  assert.match(login, /<WebsitePublicHome v-if="clientPlatform === 'web'"/)
  assert.match(login, /<div v-else class="public-page">/)
  assert.match(dashboard, /<WebsiteAppShell v-if="isWebsitePresentation" \/>/)
})
