import ibeLogo from '../assets/styles/IBE/ibe_logo.png'
import icsLogo from '../assets/styles/ICS/ics_logo.jpg'
import iteLogo from '../assets/styles/ITE/ite_logo.jpg'

export const INSTITUTE_BRANDS = Object.freeze({
  ICS: Object.freeze({
    code: 'ICS', logo: icsLogo, tone: 'technology', accent: '#167c8c', tint: '#dff5f6', secondary: '#c39b1d',
    watermarkScale: '1.04', watermarkPosition: '50% 50%',
  }),
  ITE: Object.freeze({
    code: 'ITE', logo: iteLogo, tone: 'education', accent: '#2563a6', tint: '#e5f1fb', secondary: '#c58b24',
    watermarkScale: '1.02', watermarkPosition: '50% 50%',
  }),
  IBE: Object.freeze({
    code: 'IBE', logo: ibeLogo, tone: 'business', accent: '#14633f', tint: '#e4f3e9', secondary: '#ad7d22',
    watermarkScale: '1.38', watermarkPosition: '50% 16%',
  }),
})

export const instituteBrandFor = code => INSTITUTE_BRANDS[String(code || '').toUpperCase()] || null

export function instituteThemeStyle(code) {
  const brand = instituteBrandFor(code)
  if (!brand) return {}
  return {
    '--institute-accent': brand.accent,
    '--institute-tint': brand.tint,
    '--institute-secondary': brand.secondary,
    '--institute-watermark-scale': brand.watermarkScale,
    '--institute-watermark-position': brand.watermarkPosition,
  }
}

