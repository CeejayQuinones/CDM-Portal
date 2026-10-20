import { defineStore } from 'pinia'
import { NAVIGATION_ITEMS, canAccess } from '../config/accessControl'
import { clientPlatform } from '../config/clientPlatform'

const canAccessEventClient = (role) =>
  (['Guest', 'Student', 'Professor'].includes(role) && clientPlatform === 'web') ||
  (role === 'Admin' && clientPlatform === 'desktop') ||
  (['Professor', 'Student'].includes(role) && clientPlatform === 'mobile')

export const useNavigationStore = defineStore('navigation', {
  state: () => ({ menuItems: NAVIGATION_ITEMS }),
  getters: {
    menuItemsForRole: (state) => (role, admission = {}) => {
      const items = state.menuItems
        .filter((item) =>
          canAccess(role, item.roles) &&
          !(item.name === 'admission-menu' && (role === 'Student' || (role === 'Guest' && admission.completed))) &&
          !(item.name === 'enrollment' && role === 'Guest' && !admission.admitted_guest) &&
          (item.name !== 'event-attendance' || canAccessEventClient(role)),
        )
        .map((item) =>
          item.children
            ? {
                ...item,
                children: item.children.filter((child) => canAccess(role, child.roles) && (!child.desktopOnly || clientPlatform === 'desktop') && (!child.eventOperational || clientPlatform !== 'web')),
              }
            : item,
        )

      if (role !== 'Student') return items

      const studentJourney = [
        'student-dashboard',
        'enrollment',
        'grading',
        'monitoring',
        'event-attendance',
        'document-requests-menu',
        'admission-menu',
        'settings',
      ]
      const order = new Map(studentJourney.map((name, index) => [name, index]))

      return items.sort((left, right) => (order.get(left.name) ?? 99) - (order.get(right.name) ?? 99))
    },
  },
})
