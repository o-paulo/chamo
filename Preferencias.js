const themePreferenceKey = "ticketflow-theme";
const sidebarPreferenceKey = "ticketflow-sidebar-collapsed";
const fontStylePreferenceKey = "ticketflow-font-style";
const appShell = document.querySelector(".app-shell");
const sidebarToggle = document.querySelector(".sidebar-toggle");
const sidebarSettingToggle = document.querySelector("#sidebar-setting-toggle");
const themeInputs = document.querySelectorAll('input[name="theme"]');
const fontStyleInputs = document.querySelectorAll('input[name="font-style"]');

function applyTheme(theme, persist = false) {
  document.documentElement.dataset.theme = theme;
  if (persist) localStorage.setItem(themePreferenceKey, theme);

  for (const input of themeInputs) input.checked = input.value === theme;
}

function applyFontStyle(fontStyle, persist = false) {
  document.documentElement.dataset.fontStyle = fontStyle;
  if (persist) localStorage.setItem(fontStylePreferenceKey, fontStyle);

  for (const input of fontStyleInputs) {
    input.checked = input.value === fontStyle;
  }
}

function applySidebarState(isCollapsed) {
  appShell?.classList.toggle("sidebar-collapsed", isCollapsed);
  if (sidebarToggle) {
    sidebarToggle.setAttribute("aria-expanded", String(!isCollapsed));
    sidebarToggle.setAttribute(
      "aria-label",
      isCollapsed ? "Expandir barra lateral" : "Recolher barra lateral"
    );
    sidebarToggle.title = isCollapsed ? "Expandir barra lateral" : "Recolher barra lateral";
  }
  if (sidebarSettingToggle) {
    sidebarSettingToggle.setAttribute("aria-pressed", String(isCollapsed));
    sidebarSettingToggle.querySelector(".sidebar-setting-state").textContent =
      isCollapsed ? "Desativar" : "Ativar";
  }
  localStorage.setItem(sidebarPreferenceKey, String(isCollapsed));

  for (const link of document.querySelectorAll(".nav-link")) {
    const label = link.querySelector("span:first-of-type")?.textContent.trim();
    if (label) {
      link.setAttribute("aria-label", label);
      link.title = label;
    }
  }
}

applyTheme(localStorage.getItem(themePreferenceKey) === "dark" ? "dark" : "light");
applyFontStyle(localStorage.getItem(fontStylePreferenceKey) === "tech" ? "tech" : "creative");
applySidebarState(localStorage.getItem(sidebarPreferenceKey) === "true");

const visualFeedback = document.querySelector("#visual-feedback");
const visualSaveButton = document.querySelector("#save-visual-preferences");

function announcePendingVisualChanges() {
  if (visualFeedback) visualFeedback.textContent = "Prévia atualizada. Confirme as alterações para salvá-las.";
}

sidebarToggle?.addEventListener("click", () => {
  applySidebarState(!appShell.classList.contains("sidebar-collapsed"));
});

sidebarSettingToggle?.addEventListener("click", () => {
  applySidebarState(!appShell.classList.contains("sidebar-collapsed"));
});

for (const input of themeInputs) {
  input.addEventListener("change", () => {
    if (input.checked) {
      applyTheme(input.value);
      announcePendingVisualChanges();
    }
  });
}

for (const input of fontStyleInputs) {
  input.addEventListener("change", () => {
    if (input.checked) {
      applyFontStyle(input.value);
      announcePendingVisualChanges();
    }
  });
}

visualSaveButton?.addEventListener("click", () => {
  const selectedTheme = document.querySelector('input[name="theme"]:checked')?.value;
  const selectedFontStyle = document.querySelector('input[name="font-style"]:checked')?.value;
  if (!selectedTheme || !selectedFontStyle) {
    if (visualFeedback) visualFeedback.textContent = "Selecione um tema e um estilo de fonte antes de confirmar.";
    return;
  }

  applyTheme(selectedTheme, true);
  applyFontStyle(selectedFontStyle, true);
  if (visualFeedback) visualFeedback.textContent = "Personalização visual salva neste navegador.";
});
