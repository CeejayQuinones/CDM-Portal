import { Capacitor } from '@capacitor/core'

/** True when running inside the Capacitor Android/iOS shell. */
export const isNativeMobileApp = () => Capacitor.isNativePlatform()

/** Mobile builds are student-only. Web + Electron stay multi-role. */
export const isStudentMobileApp = () => isNativeMobileApp()

export const STUDENT_MOBILE_ONLY_MESSAGE =
  'The CDM mobile app is for student accounts only. Staff should use the web or desktop portal.'
