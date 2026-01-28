// Modern JavaScript for Galaxy Night Club Management System

// Theme Management
function initTheme() {
  const savedTheme = localStorage.getItem("theme") || "light"
  document.documentElement.setAttribute("data-theme", savedTheme)
  updateThemeIcon(savedTheme)
}

function toggleTheme() {
  const currentTheme = document.documentElement.getAttribute("data-theme")
  const newTheme = currentTheme === "dark" ? "light" : "dark"

  document.documentElement.setAttribute("data-theme", newTheme)
  localStorage.setItem("theme", newTheme)
  updateThemeIcon(newTheme)
}

function updateThemeIcon(theme) {
  const themeIcons = document.querySelectorAll(".theme-icon")
  themeIcons.forEach((icon) => {
    icon.textContent = theme === "dark" ? "☀️" : "🌙"
  })
}

// Enhanced Mobile Sidebar Management - FIXED
function initSidebar() {
  const sidebar = document.querySelector(".sidebar")
  const toggleBtn = document.querySelector(".sidebar-toggle")
  const mainContent = document.querySelector(".main-content") // Select the main content

  // Create overlay for mobile if it doesn't exist
  let overlay = document.querySelector(".sidebar-overlay")
  if (!overlay) {
    overlay = document.createElement("div")
    overlay.className = "sidebar-overlay"
    document.body.appendChild(overlay)
  }

  if (toggleBtn) {
    toggleBtn.addEventListener("click", (e) => {
      e.preventDefault()
      e.stopPropagation()
      window.toggleSidebar()
    });
  }

  // Close sidebar when clicking overlay
  overlay.addEventListener("click", (e) => {
    e.preventDefault()
    e.stopPropagation()
    closeSidebar()
  })

  // Close sidebar when clicking outside on mobile
  document.addEventListener("click", (e) => {
    if (window.innerWidth <= 1024) {
      const sidebar = document.querySelector(".sidebar")
      const toggleBtn = document.querySelector(".sidebar-toggle")

      if (sidebar && !sidebar.contains(e.target) && !toggleBtn.contains(e.target)) {
        closeSidebar()
      }
    }
  })

  // Handle window resize
  window.addEventListener("resize", () => {
    if (window.innerWidth > 1024) {
      closeSidebar()
    }
  })

  // Close sidebar when navigating on mobile
  const navLinks = document.querySelectorAll(".nav-item")
  navLinks.forEach((link) => {
    link.addEventListener("click", () => {
      if (window.innerWidth <= 1024) {
        setTimeout(() => {
          closeSidebar()
        }, 100)
      }
    })
  })
}

// Global function for HTML onclick and JavaScript calls - FIXED
window.toggleSidebar = () => {
  const sidebar = document.querySelector(".sidebar")
  const overlay = document.querySelector(".sidebar-overlay")
  const body = document.body
  const mainContent = document.querySelector(".main-content")

  if (sidebar && overlay && mainContent) {
    const isOpen = sidebar.classList.contains("open")

    if (isOpen) {
      // Close sidebar
      sidebar.classList.remove("open")
      overlay.classList.remove("active")
      body.classList.remove("sidebar-open")
      body.style.overflow = ""
      mainContent.classList.remove("sidebar-closed")
    } else {
      // Open sidebar
      sidebar.classList.add("open")
      overlay.classList.add("active")
      body.classList.add("sidebar-open")
      body.style.overflow = "hidden"
      mainContent.classList.add("sidebar-closed")
    }
  }
}

function openSidebar() {
  const sidebar = document.querySelector(".sidebar")
  const overlay = document.querySelector(".sidebar-overlay")
  const body = document.body
  const mainContent = document.querySelector(".main-content")

  if (sidebar && overlay && mainContent) {
    sidebar.classList.add("open")
    overlay.classList.add("active")
    body.classList.add("sidebar-open")
    body.style.overflow = "hidden"
    mainContent.classList.add("sidebar-closed")
  }
}

function closeSidebar() {
  const sidebar = document.querySelector(".sidebar")
  const overlay = document.querySelector(".sidebar-overlay")
  const body = document.body
  const mainContent = document.querySelector(".main-content")

  if (sidebar && overlay && mainContent) {
    sidebar.classList.remove("open")
    overlay.classList.remove("active")
    body.classList.remove("sidebar-open")
    body.style.overflow = ""
    mainContent.classList.remove("sidebar-closed")
  }
}

// New universal password toggle function
function togglePassword(event) {
  const button = event.currentTarget;
  const targetId = button.getAttribute('data-target');
  const targetInput = document.getElementById(targetId);

  if (targetInput) {
    const icon = button.querySelector('.icon');
    if (targetInput.type === 'password') {
      targetInput.type = 'text';
      icon.textContent = '🙈';
      button.setAttribute('aria-label', 'Sembunyikan Kata Sandi');
      button.classList.add('active');
    } else {
      targetInput.type = 'password';
      icon.textContent = '👁️';
      button.setAttribute('aria-label', 'Tampilkan Kata Sandi');
      button.classList.remove('active');
    }
  }
}

// Form Validation
function validateForm(formId) {
  const form = document.getElementById(formId)
  if (!form) return true

  const requiredFields = form.querySelectorAll("[required]")
  let isValid = true

  requiredFields.forEach((field) => {
    if (!field.value.trim()) {
      showFieldError(field, "Field ini wajib diisi")
      isValid = false
    } else {
      clearFieldError(field)
    }
  })

  return isValid
}

function showFieldError(field, message) {
  clearFieldError(field)

  const errorDiv = document.createElement("div")
  errorDiv.className = "field-error"
  errorDiv.textContent = message
  errorDiv.style.color = "var(--danger-color)"
  errorDiv.style.fontSize = "0.875rem"
  errorDiv.style.marginTop = "0.25rem"

  field.parentNode.appendChild(errorDiv)
  field.style.borderColor = "var(--danger-color)"
}

function clearFieldError(field) {
  const existingError = field.parentNode.querySelector(".field-error")
  if (existingError) {
    existingError.remove()
  }
  field.style.borderColor = ""
}

// Real-time Clock for On Duty Status
function updateDutyClock() {
  const dutyClocks = document.querySelectorAll(".duty-clock")

  dutyClocks.forEach((clock) => {
    const startTime = clock.dataset.startTime
    if (startTime) {
      const start = new Date(startTime)
      const now = new Date()
      const diff = now - start

      const hours = Math.floor(diff / (1000 * 60 * 60))
      const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60))
      const seconds = Math.floor((diff % (1000 * 60)) / 1000)

      clock.textContent = `${hours.toString().padStart(2, "0")}:${minutes.toString().padStart(2, "0")}:${seconds.toString().padStart(2, "0")}`
    }
  })
}

// New function to update the 'open since' timer on the login page
function updateOpenSinceTimer() {
    const openSinceTimer = document.querySelector(".open-since-timer .since-time");
    const timerContainer = document.querySelector(".open-since-timer");
    const startTime = timerContainer?.dataset.startTime;
    
    if (openSinceTimer && startTime) {
        const start = new Date(startTime);
        const now = new Date();
        const diff = now - start;

        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
        
        const formattedDate = start.toLocaleDateString('id-ID', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric'
        });
        const formattedTime = `${hours.toString().padStart(2, "0")}:${minutes.toString().padStart(2, "0")}:${seconds.toString().padStart(2, "0")}`;

        openSinceTimer.textContent = `${formattedDate} ${formattedTime}`;
    }
}


// Notification System
function showNotification(message, type = "info") {
  const notification = document.createElement("div")
  notification.className = `notification notification-${type}`
  notification.innerHTML = `
        <div class="notification-content">
            <span class="notification-icon">${getNotificationIcon(type)}</span>
            <span class="notification-message">${message}</span>
            <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
        </div>
    `

  // Add styles
  notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        padding: 1rem;
        box-shadow: var(--shadow-lg);
        z-index: 1000;
        max-width: 400px;
        animation: slideInRight 0.3s ease-out;
    `

  document.body.appendChild(notification)

  // Auto remove after 5 seconds
  setTimeout(() => {
    if (notification.parentNode) {
      notification.style.animation = "slideOutRight 0.3s ease-in"
      setTimeout(() => notification.remove(), 300)
    }
  }, 5000)
}

function getNotificationIcon(type) {
  const icons = {
    success: "✅",
    error: "❌",
    warning: "⚠️",
    info: "ℹ️",
  }
  return icons[type] || icons.info
}

// Loading States
function showLoading(element) {
  if (typeof element === "string") {
    element = document.querySelector(element)
  }

  if (element) {
    element.classList.add("loading")
    const originalText = element.textContent
    element.dataset.originalText = originalText
    element.innerHTML = `<span class="spinner"></span> Loading...`
  }
}

function hideLoading(element) {
  if (typeof element === "string") {
    element = document.querySelector(element)
  }

  if (element) {
    element.classList.remove("loading")
    const originalText = element.dataset.originalText
    if (originalText) {
      element.textContent = originalText
    }
  }
}

// Confirmation Dialogs
function confirmAction(message, callback) {
  const modal = document.createElement("div")
  modal.className = "confirmation-modal"
  modal.innerHTML = `
        <div class="modal-overlay">
            <div class="modal-content">
                <h3>Konfirmasi</h3>
                <p>${message}</p>
                <div class="modal-actions">
                    <button class="btn btn-outline" onclick="this.closest('.confirmation-modal').remove()">Batal</button>
                    <button class="btn btn-danger" onclick="confirmCallback(); this.closest('.confirmation-modal').remove()">Ya, Lanjutkan</button>
                </div>
            </div>
        </div>
    `

  // Add styles
  modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        z-index: 2000;
    `

  // Store callback globally (not ideal but works for this use case)
  window.confirmCallback = callback

  document.body.appendChild(modal)
}

// Auto-save functionality for forms
function initAutoSave(formId) {
  const form = document.getElementById(formId)
  if (!form) return

  const inputs = form.querySelectorAll("input, textarea, select")

  inputs.forEach((input) => {
    input.addEventListener(
      "input",
      debounce(() => {
        const formData = new FormData(form)
        const data = Object.fromEntries(formData)
        localStorage.setItem(`autosave_${formId}`, JSON.stringify(data))

        // Show auto-save indicator
        showAutoSaveIndicator()
      }, 1000),
    )
  })

  // Restore auto-saved data
  const savedData = localStorage.getItem(`autosave_${formId}`)
  if (savedData) {
    const data = JSON.parse(savedData)
    Object.keys(data).forEach((key) => {
      const input = form.querySelector(`[name="${key}"]`)
      if (input) {
        input.value = data[key]
      }
    })
  }
}

function showAutoSaveIndicator() {
  let indicator = document.querySelector(".autosave-indicator")
  if (!indicator) {
    indicator = document.createElement("div")
    indicator.className = "autosave-indicator"
    indicator.textContent = "💾 Tersimpan otomatis"
    indicator.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--success-color);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            z-index: 1000;
            opacity: 0;
            transition: opacity 0.3s ease;
        `
    document.body.appendChild(indicator)
  }

  indicator.style.opacity = "1"
  setTimeout(() => {
    indicator.style.opacity = "0"
  }, 2000)
}

// Utility Functions
function debounce(func, wait) {
  let timeout
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout)
      func(...args)
    }
    clearTimeout(timeout)
    timeout = setTimeout(later, wait)
  }
}

function formatCurrency(amount) {
  return new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
  }).format(amount)
}

function formatDate(date) {
  return new Intl.DateTimeFormat("id-ID", {
    year: "numeric",
    month: "long",
    day: "numeric",
  }).format(new Date(date))
}

function formatTime(date) {
  return new Intl.DateTimeFormat("id-ID", {
    hour: "2-digit",
    minute: "2-digit",
  }).format(new Date(date))
}

// Add responsive table labels for mobile
function addMobileTableLabels() {
  const tables = document.querySelectorAll(".activities-table, .sales-table")

  tables.forEach((table) => {
    const headers = table.querySelectorAll(".table-header .table-cell")
    const rows = table.querySelectorAll(".table-row")

    rows.forEach((row) => {
      const cells = row.querySelectorAll(".table-cell")
      cells.forEach((cell, index) => {
        if (headers[index]) {
          cell.setAttribute("data-label", headers[index].textContent.trim())
        }
      })
    })
  })
}

// Initialize everything when DOM is loaded
document.addEventListener("DOMContentLoaded", () => {
  initTheme()
  initSidebar()
  
  // FIX: Run clock update on page load and every second
  updateDutyClock();
  setInterval(updateDutyClock, 1000);
  
  // FIX: Run open since timer on login page and every second
  updateOpenSinceTimer();
  setInterval(updateOpenSinceTimer, 1000);


  // Initialize auto-save for forms
  const forms = document.querySelectorAll("form[data-autosave]")
  forms.forEach((form) => {
    initAutoSave(form.id)
  })

  // Add smooth scrolling to anchor links
  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener("click", function (e) {
      e.preventDefault()
      const target = document.querySelector(this.getAttribute("href"))
      if (target) {
        target.scrollIntoView({
          behavior: "smooth",
        })
      }
    })
  })

  // Add loading states to form submissions
  document.querySelectorAll("form").forEach((form) => {
    form.addEventListener("submit", (e) => {
      const submitBtn = form.querySelector('button[type="submit"]')
      if (submitBtn) {
        showLoading(submitBtn)
      }
    })
  })

  // Add fade-in animation to main content
  const mainContent = document.querySelector(".main-content")
  if (mainContent) {
    mainContent.classList.add("fade-in")
  }

  addMobileTableLabels()

  setupEnhancedPageLoad()
  initRealTimeUpdates()
  setupStorageSync()
  setupCleanup()
  
  // FIX: Attach event listeners to all password toggle buttons
  document.querySelectorAll('.password-toggle').forEach(button => {
      button.addEventListener('click', togglePassword);
      
      const targetId = button.getAttribute('data-target');
      if (targetId) {
          const targetInput = document.getElementById(targetId);
          if (targetInput) {
              // Add a listener to enable/disable the toggle button
              targetInput.addEventListener('input', () => {
                  if (targetInput.value.length > 0) {
                      button.classList.add('enabled');
                  } else {
                      button.classList.remove('enabled');
                  }
              });
              // Initial state check
              if (targetInput.value.length > 0) {
                  button.classList.add('enabled');
              }
          }
      }
  });

  // Client-side form validation for change password
  const changePasswordForm = document.getElementById('changePasswordForm');
  if (changePasswordForm) {
      changePasswordForm.addEventListener('submit', function(e) {
          const newPassword = document.getElementById('new_password').value;
          const confirmPassword = document.getElementById('confirm_password').value;

          if (newPassword !== confirmPassword) {
              e.preventDefault();
              showNotification('Kata sandi baru dan konfirmasi kata sandi tidak cocok!', 'error');
          }
      });
  }
})

// FIXED: Login page specific functionality
function initLoginPage() {
  const passwordInput = document.getElementById("password")
  const toggleButton = document.querySelector(".password-toggle")
  const nameSelect = document.getElementById("name")

  // Password toggle functionality
  if (passwordInput && toggleButton) {
    // Show toggle button only when there's text in password field
    passwordInput.addEventListener("input", function () {
      if (this.value.length > 0) {
        toggleButton.classList.add("enabled")
      } else {
        toggleButton.classList.remove("enabled")
        // Reset to password type when empty
        this.type = "password"
        const toggleIcon = toggleButton.querySelector(".icon")
        if (toggleIcon) {
          toggleIcon.textContent = "👁️"
        }
        toggleButton.classList.remove("active")
      }
    })
    
    toggleButton.addEventListener('click', (e) => {
        e.preventDefault();
        if (passwordInput.value.length > 0) {
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleButton.querySelector('.icon').textContent = '🙈';
                toggleButton.classList.add('active');
            } else {
                passwordInput.type = 'password';
                toggleButton.querySelector('.icon').textContent = '👁️';
                toggleButton.classList.remove('active');
            }
        }
    });

    // Keyboard shortcut: Ctrl+Shift+P to toggle password
    passwordInput.addEventListener("keydown", function (e) {
      if (e.ctrlKey && e.shiftKey && e.key === "P") {
        e.preventDefault()
        if (this.value.length > 0) {
          if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleButton.querySelector('.icon').textContent = '🙈';
            toggleButton.classList.add('active');
          } else {
            passwordInput.type = 'password';
            toggleButton.querySelector('.icon').textContent = '👁️';
            toggleButton.classList.remove('active');
          }
        }
      }
    })
  }

  // FIXED: Prevent typing in name select
  if (nameSelect) {
    nameSelect.addEventListener("keydown", (e) => {
      // Allow only navigation keys
      const allowedKeys = ["Tab", "Escape", "Enter", "ArrowUp", "ArrowDown", "Home", "End"]
      if (!allowedKeys.includes(e.key)) {
        e.preventDefault()
      }
    })

    nameSelect.addEventListener("keypress", (e) => {
      e.preventDefault()
    })

    nameSelect.addEventListener("input", (e) => {
      e.preventDefault()
    })

    // Auto-focus on password when name is selected
    nameSelect.addEventListener("change", function () {
      if (this.value && passwordInput) {
        this.classList.add("filled")
        passwordInput.focus()
      } else {
        this.classList.remove("filled")
      }
    })
  }

  // Enhanced form validation
  if (passwordInput) {
    passwordInput.addEventListener("input", function () {
      if (this.value) {
        this.classList.add("filled")
      } else {
        this.classList.remove("filled")
      }
    })
  }

  // Form submission with loading state
  const loginForm = document.getElementById("loginForm")
  if (loginForm) {
    loginForm.addEventListener("submit", (e) => {
      const button = document.getElementById("loginButton")
      const btnText = button.querySelector(".btn-text")
      const btnLoading = button.querySelector(".btn-loading")

      // Show loading state
      if (btnText && btnLoading) {
        btnText.style.display = "none"
        btnLoading.style.display = "flex"
        button.disabled = true

        // Re-enable after 5 seconds as fallback
        setTimeout(() => {
          btnText.style.display = "block"
          btnLoading.style.display = "none"
          button.disabled = false
        }, 5000)
      }
    })
  }

  // Auto-focus on name field when page loads
  if (nameSelect) {
    nameSelect.focus()
  }
}

// Real-time Updates and Auto-refresh System
let updateInterval
let lastUpdateTime = Date.now()
let isPageVisible = true

// Initialize real-time updates
function initRealTimeUpdates() {
  // Check if we're on a page that needs real-time updates
  const needsUpdates = document.querySelector(".main-content, .dashboard-stats, .activities-table, .sales-table")

  if (needsUpdates) {
    startRealTimeUpdates()
    setupVisibilityHandling()
    setupConnectionMonitoring()
  }
}

// Start real-time updates
function startRealTimeUpdates() {
  // Update every 30 seconds when page is visible
  updateInterval = setInterval(() => {
    if (isPageVisible) {
      checkForUpdates()
    }
  }, 30000)

  // Initial update check
  setTimeout(() => {
    checkForUpdates()
  }, 2000)
}

// Check for updates from server
async function checkForUpdates() {
  try {
    const currentPage = getCurrentPageType()
    if (!currentPage) return

    const response = await fetch(`check-updates.php?page=${currentPage}&last_update=${lastUpdateTime}`, {
      method: "GET",
      headers: {
        "Cache-Control": "no-cache",
        Pragma: "no-cache",
      },
    })

    if (response.ok) {
      const data = await response.json()

      if (data.has_updates) {
        handleUpdates(data)
        lastUpdateTime = Date.now()
      }
    }
  } catch (error) {
    console.log("Update check failed:", error)
  }
}

// Handle different types of updates
function handleUpdates(data) {
  if (data.dashboard_stats) {
    updateDashboardStats(data.dashboard_stats)
  }

  if (data.activities) {
    updateActivitiesTable(data.activities)
  }

  if (data.sales) {
    updateSalesTable(data.sales)
  }

  if (data.notifications) {
    showUpdateNotifications(data.notifications)
  }

  // Show update indicator
  showUpdateIndicator()
}

// Update dashboard statistics
function updateDashboardStats(stats) {
  Object.keys(stats).forEach((key) => {
    const element = document.querySelector(`[data-stat="${key}"]`)
    if (element) {
      const currentValue = element.textContent
      const newValue = stats[key]

      if (currentValue !== newValue) {
        element.textContent = newValue
        element.classList.add("updated")
        setTimeout(() => element.classList.remove("updated"), 2000)
      }
    }
  })
}

// Update activities table
function updateActivitiesTable(activities) {
  const table = document.querySelector(".activities-table")
  if (!table) return

  const tbody = table.querySelector(".table-body") || table

  // Add new activities at the top
  activities.forEach((activity) => {
    const existingRow = tbody.querySelector(`[data-activity-id="${activity.id}"]`)
    if (!existingRow) {
      const newRow = createActivityRow(activity)
      tbody.insertBefore(newRow, tbody.firstChild)
      newRow.classList.add("new-row")
      setTimeout(() => newRow.classList.remove("new-row"), 3000)
    }
  })
}

// Update sales table
function updateSalesTable(sales) {
  const table = document.querySelector(".sales-table")
  if (!table) return

  const tbody = table.querySelector(".table-body") || table

  sales.forEach((sale) => {
    const existingRow = tbody.querySelector(`[data-sale-id="${sale.id}"]`)
    if (!existingRow) {
      const newRow = createSaleRow(sale)
      tbody.insertBefore(newRow, tbody.firstChild)
      newRow.classList.add("new-row")
      setTimeout(() => newRow.classList.remove("new-row"), 3000)
    }
  })
}

// Create activity row HTML
function createActivityRow(activity) {
  const row = document.createElement("div")
  row.className = "table-row"
  row.setAttribute("data-activity-id", activity.id)

  row.innerHTML = `
    <div class="table-cell" data-label="Nama">${activity.name}</div>
    <div class="table-cell" data-label="Aktivitas">${activity.activity}</div>
    <div class="table-cell" data-label="Waktu">${formatTime(activity.timestamp)}</div>
    <div class="table-cell" data-label="Status">
      <span class="status-badge status-active">${activity.status}</span>
    </div>
  `

  return row
}

// Create sale row HTML
function createSaleRow(sale) {
  const row = document.createElement("div")
  row.className = "table-row"
  row.setAttribute("data-sale-id", sale.id)

  row.innerHTML = `
    <div class="table-cell" data-label="Waktu">${formatTime(sale.timestamp)}</div>
    <div class="table-cell" data-label="Kasir">${sale.cashier}</div>
    <div class="table-cell" data-label="Total">${formatCurrency(sale.total)}</div>
    <div class="table-cell" data-label="Status">
      <span class="status-badge status-success">Selesai</span>
    </div>
  `

  return row
}

// Show update notifications
function showUpdateNotifications(notifications) {
  notifications.forEach((notification) => {
    showNotification(notification.message, notification.type)
  })
}

// Show update indicator
function showUpdateIndicator() {
  let indicator = document.querySelector(".update-indicator")
  if (!indicator) {
    indicator = document.createElement("div")
    indicator.className = "update-indicator"
    indicator.innerHTML = "🔄 Data diperbarui"
    indicator.style.cssText = `
      position: fixed;
      top: 80px;
      right: 20px;
      background: var(--success-color);
      color: white;
      padding: 0.5rem 1rem;
      border-radius: var(--radius-md);
      font-size: 0.875rem;
      z-index: 1000;
      opacity: 0;
      transition: opacity 0.3s ease;
      box-shadow: var(--shadow-md);
    `
    document.body.appendChild(indicator)
  }

  indicator.style.opacity = "1"
  setTimeout(() => {
    indicator.style.opacity = "0"
  }, 3000)
}

// Get current page type
function getCurrentPageType() {
  const path = window.location.pathname
  const filename = path.split("/").pop().split(".")[0]

  const pageMap = {
    dashboard: "dashboard",
    activities: "activities",
    sales: "sales",
    employees: "employees",
    "employee-activities": "employee-activities",
  }

  return pageMap[filename] || null
}

// Setup page visibility handling
function setupVisibilityHandling() {
  document.addEventListener("visibilitychange", () => {
    isPageVisible = !document.hidden

    if (isPageVisible) {
      // Page became visible, check for updates immediately
      setTimeout(() => {
        checkForUpdates()
      }, 1000)
    }
  })

  // Handle window focus/blur
  window.addEventListener("focus", () => {
    isPageVisible = true
    setTimeout(() => {
      checkForUpdates()
    }, 500)
  })

  window.addEventListener("blur", () => {
    isPageVisible = false
  })
}

// Setup connection monitoring
function setupConnectionMonitoring() {
  window.addEventListener("online", () => {
    showNotification("Koneksi internet tersambung kembali", "success")
    setTimeout(() => {
      checkForUpdates()
    }, 1000)
  })

  window.addEventListener("offline", () => {
    showNotification("Koneksi internet terputus", "warning")
  })
}

// Force refresh data
function forceRefreshData() {
  showLoading(".main-content")

  setTimeout(() => {
    window.location.reload()
  }, 500)
}

// Cache busting for critical resources
function setupCacheBusting() {
  // Add timestamp to critical requests
  const timestamp = Date.now()

  // Update any dynamic content URLs
  document.querySelectorAll("[data-dynamic-src]").forEach((element) => {
    const originalSrc = element.dataset.dynamicSrc
    element.src = `${originalSrc}?t=${timestamp}`
  })
}

// Auto-refresh on storage changes (for multi-tab sync)
function setupStorageSync() {
  window.addEventListener("storage", (e) => {
    if (e.key === "force_refresh" && e.newValue === "true") {
      localStorage.removeItem("force_refresh")
      setTimeout(() => {
        checkForUpdates()
      }, 500)
    }
  })
}

// Trigger refresh across tabs
function triggerCrossTabRefresh() {
  localStorage.setItem("force_refresh", "true")
  setTimeout(() => {
    localStorage.removeItem("force_refresh")
  }, 1000)
}

// Enhanced page load handling
function setupEnhancedPageLoad() {
  // Clear any stale data on page load
  if (performance.navigation.type === performance.navigation.TYPE_RELOAD) {
    // Page was refreshed
    sessionStorage.setItem("page_refreshed", "true")
  }

  // Check for stale data
  const lastVisit = localStorage.getItem("last_visit")
  const now = Date.now()

  if (!lastVisit || now - Number.parseInt(lastVisit) > 300000) {
    // 5 minutes
    // Force fresh data load
    setupCacheBusting()
  }

  localStorage.setItem("last_visit", now.toString())
}

// Cleanup on page unload
function setupCleanup() {
  window.addEventListener("beforeunload", () => {
    if (updateInterval) {
      clearInterval(updateInterval)
    }
  })
}

// Add CSS animations
const style = document.createElement("style")
style.textContent = `
    @keyframes slideInRight {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOutRight {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
    
    .modal-overlay {
        background: rgba(0, 0, 0, 0.5);
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        height: 100%;
    }
    
    .modal-content {
        background: var(--bg-primary);
        border-radius: var(--radius-lg);
        padding: 2rem;
        max-width: 400px;
        width: 90%;
        text-align: center;
    }
    
    .modal-actions {
        display: flex;
        gap: 1rem;
        justify-content: center;
        margin-top: 1.5rem;
    }
    
    .notification-content {
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .notification-close {
        background: none;
        border: none;
        font-size: 1.25rem;
        cursor: pointer;
        color: var(--text-secondary);
        margin-left: auto;
    }
    
    .notification-close:hover {
        color: var(--text-primary);
    }

    .sidebar-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      z-index: 950;
      display: none;
    }

    .sidebar-overlay.active {
      display: block;
    }
`
document.head.appendChild(style)