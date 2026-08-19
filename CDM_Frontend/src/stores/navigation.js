import { defineStore } from 'pinia'
import { NAVIGATION_ITEMS, canAccess } from '../config/accessControl'

export const useNavigationStore = defineStore('navigation', {
  state: () => ({ menuItems: NAVIGATION_ITEMS }),
  getters: {
    menuItemsForRole: (state) => (role) => state.menuItems.filter((item) => canAccess(role, item.roles)),
  },
})
