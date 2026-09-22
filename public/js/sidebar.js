/**
 * Sidebar Component for Alpine.js
 * Handles sidebar collapse/expand with separated desktop/mobile logic
 */

// CRITICAL: Initialize sidebar state BEFORE Alpine loads to prevent flicker
(function() {
    const isCollapsed = localStorage.getItem('sidebarCollapsed') === '1';
    document.documentElement.style.setProperty('--sidebar-width', isCollapsed ? '64px' : '215px');
    document.documentElement.classList.toggle('sidebar-collapsed', isCollapsed);
})();

/**
 * Alpine.js Sidebar Component
 * Usage: x-data="sidebarComponent()" on the main container
 */
function sidebarComponent() {
    return {
        // Desktop state: controlled via CSS class on <html>
        isCollapsed: document.documentElement.classList.contains('sidebar-collapsed'),
        
        // Mobile state: controlled via Alpine
        mobileOpen: false,
        
        init() {
            // Sync state from localStorage (already set by inline script above)
            this.isCollapsed = localStorage.getItem('sidebarCollapsed') === '1';
        },
        
        toggle() {
            if (window.innerWidth >= 1024) {
                // Desktop: toggle collapsed state via CSS class AND CSS variable
                this.isCollapsed = !this.isCollapsed;
                document.documentElement.classList.toggle('sidebar-collapsed', this.isCollapsed);
                document.documentElement.style.setProperty('--sidebar-width', this.isCollapsed ? '64px' : '215px');
                localStorage.setItem('sidebarCollapsed', this.isCollapsed ? '1' : '0');
                
                // Dispatch event for responsive components
                document.dispatchEvent(new CustomEvent('sidebar-toggled'));
            } else {
                // Mobile: toggle open/close
                this.mobileOpen = !this.mobileOpen;
            }
        },
        
        getToggleTitle() {
            if (window.innerWidth >= 1024) {
                return this.isCollapsed ? 'Expand sidebar' : 'Collapse sidebar';
            }
            return this.mobileOpen ? 'Close menu' : 'Open menu';
        }
    }
}
