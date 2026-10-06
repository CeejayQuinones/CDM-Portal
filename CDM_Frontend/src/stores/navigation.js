import { defineStore } from 'pinia'
import { NAVIGATION_ITEMS, canAccess } from '../config/accessControl'
import { clientPlatform } from '../config/clientPlatform'

const canAccessEventClient = (role) =>
  clientPlatform === 'web' ||
  (['Admin', 'Professor'].includes(role) && clientPlatform === 'desktop') ||
  (['Professor', 'Student'].includes(role) && clientPlatform === 'mobile')

export const useNavigationStore = defineStore('navigation', {
  state: () => ({ menuItems: NAVIGATION_ITEMS }),
  getters: {
    menuItemsForRole: (state) => (role) =>
      state.menuItems
        .filter((item) => canAccess(role, item.roles) && (item.name !== 'event-attendance' || canAccessEventClient(role)))
        .map((item) =>
          item.children
            ? {
                ...item,
                children: item.children.filter((child) => canAccess(role, child.roles) && (!child.desktopOnly || clientPlatform === 'desktop')),
              }
            : item,
        ),
  },
})
