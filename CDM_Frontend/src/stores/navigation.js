import { defineStore } from 'pinia'
import { NAVIGATION_ITEMS, canAccess } from '../config/accessControl'
import { clientPlatform } from '../config/clientPlatform'

export const useNavigationStore = defineStore('navigation', {
  state: () => ({ menuItems: NAVIGATION_ITEMS }),
  getters: {
    menuItemsForRole: (state) => (role, admission = {}) => {
      const items = state.menuItems
        .filter((item) =>
          canAccess(role, item.roles) &&
          (!item.platforms || item.platforms.includes(clientPlatform)) &&
          !(item.name === 'admission-menu' && (role === 'Student' || (role === 'Guest' && admission.completed))) &&
          !(item.name === 'enrollment' && role === 'Guest' && !admission.admitted_guest),
        )
        .map((item) =>
          item.children
            ? {
                ...item,
                children: item.children.filter((child) => canAccess(role, child.roles) && (!child.platforms || child.platforms.includes(clientPlatform)) && (!child.desktopOnly || clientPlatform === 'desktop')),
              }
            : item,
        )

      if (role !== 'Student') return items

      const studentJourney = [
        'student-dashboard',
        'enrollment',
        'grading',
        'document-requests-menu',
        'admission-menu',
        'settings',
      ]
      const order = new Map(studentJourney.map((name, index) => [name, index]))

      return items.sort((left, right) => (order.get(left.name) ?? 99) - (order.get(right.name) ?? 99))
    },
  },
})
