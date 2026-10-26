(() => {
  let session = readAccountSession();
  let role = session?.role || "user";
  const fallbackName = "Arthur Holanda";
  const supportedImageTypes = new Set(["image/png", "image/jpeg", "image/gif", "image/webp"]);
  const maxImageSize = 1024 * 1024;
  const profileForm = document.querySelector("#profile-form");
  const profileNameInput = document.querySelector("#profile-name");
  const profileEmailInput = document.querySelector("#profile-email");
  const profilePhoneInput = document.querySelector("#profile-phone");
  const photoInput = document.querySelector("#profile-photo");
  const photoPreview = document.querySelector("#profile-photo-preview");
  const photoError = document.querySelector("#profile-photo-error");
  const profileFeedback = document.querySelector("#profile-feedback");
  const currentPasswordInput = document.querySelector("#profile-current-password");
  const newPasswordInput = document.querySelector("#profile-new-password");
  const confirmPasswordInput = document.querySelector("#profile-confirm-password");
  let profileImage = "";
  let pendingPhotoRead = Promise.resolve();

  function readAccountSession() {
    try {
      const account = JSON.parse(sessionStorage.getItem("chamo-demo-session") || "null");
      return account && typeof account === "object" ? account : null;
    } catch (error) {
      console.error("Não foi possível ler o perfil da sessão.", error);
      return null;
    }
  }

  function getInitials(name) {
    const words = name.trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return "AH";
    return words.length === 1
      ? Array.from(words[0]).slice(0, 2).join("").toLocaleUpperCase("pt-BR")
      : `${Array.from(words[0])[0]}${Array.from(words[words.length - 1])[0]}`.toLocaleUpperCase("pt-BR");
  }

  function renderAvatar(avatar, name, image) {
    avatar.replaceChildren();
    if (image) {
      const img = document.createElement("img");
      img.src = image;
      img.alt = "";
      avatar.append(img);
    } else {
      avatar.textContent = getInitials(name);
    }
    avatar.setAttribute("aria-label", `Foto de perfil de ${name}`);
  }

  function applyProfile(profile) {
    const name = typeof profile.name === "string" && profile.name.trim()
      ? profile.name.trim()
      : fallbackName;
    const email = typeof profile.email === "string" ? profile.email : "";
    const phone = typeof profile.phone === "string" ? profile.phone : "";
    const image = typeof profile.photoDataUrl === "string" ? profile.photoDataUrl : "";
    const roleLabel = role === "attendant" ? "Atendente" : role === "admin" ? "Administrador" : "Usuário";

    for (const label of document.querySelectorAll("[data-profile-name]")) label.textContent = name;
    for (const label of document.querySelectorAll("[data-module-name]")) label.textContent = name;
    for (const label of document.querySelectorAll("[data-module-role]")) label.textContent = roleLabel;
    for (const button of document.querySelectorAll(".profile-button")) {
      button.setAttribute("aria-label", `Abrir opções do perfil de ${name}`);
    }
    for (const avatar of document.querySelectorAll("[data-profile-avatar]")) {
      renderAvatar(avatar, name, image);
      if (avatar.classList.contains("avatar-top")) avatar.setAttribute("aria-label", `Perfil de ${name}`);
    }
    for (const avatar of document.querySelectorAll("[data-module-avatar]")) renderAvatar(avatar, name, image);
    if (profileNameInput) profileNameInput.value = name;
    if (profileEmailInput) profileEmailInput.value = email;
    if (profilePhoneInput) profilePhoneInput.value = phone;
    if (photoPreview) renderAvatar(photoPreview, name, image);

    profileImage = image;
    return { name, email, phone, photoDataUrl: image };
  }

  applyProfile({
    name: session?.name || fallbackName,
    email: session?.email || "",
    phone: ""
  });
  window.ChamoApi.profile().then((profile) => {
    session = profile;
    role = profile.role;
    applyProfile(profile);
  }).catch((error) => {
    console.error("Não foi possível carregar o perfil do servidor.", error);
    if (profileFeedback) profileFeedback.textContent = error.message;
  });

  const profileMenuToggles = Array.from(document.querySelectorAll("[data-profile-menu-toggle]"));
  if (profileMenuToggles.length) {
    const profileMenu = document.createElement("div");
    profileMenu.className = "profile-menu";
    profileMenu.id = "profile-actions-menu";
    profileMenu.setAttribute("role", "menu");
    profileMenu.setAttribute("aria-label", "Opções do perfil");
    profileMenu.hidden = true;

    const manageProfileLink = document.createElement("a");
    manageProfileLink.className = "profile-menu-item";
    manageProfileLink.href = role === "user"
      ? "TelaConfiguracoes.html?tab=profile"
      : "ConfiguracoesAcesso.html?tab=profile";
    manageProfileLink.setAttribute("role", "menuitem");
    manageProfileLink.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0M19 5v6m-3-3h6"/></svg><span>Gerenciar perfil</span>';

    const logoutButton = document.createElement("button");
    logoutButton.className = "profile-menu-item profile-menu-logout";
    logoutButton.type = "button";
    logoutButton.setAttribute("role", "menuitem");
    logoutButton.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h5M16 16l5-4-5-4m5 4H9"/></svg><span>Sair</span>';
    profileMenu.append(manageProfileLink, logoutButton);
    document.body.append(profileMenu);

    let activeMenuToggle = null;

    function closeProfileMenu({ restoreFocus = false } = {}) {
      profileMenu.hidden = true;
      profileMenuToggles.forEach((toggle) => toggle.setAttribute("aria-expanded", "false"));
      const previousToggle = activeMenuToggle;
      activeMenuToggle = null;
      if (restoreFocus) previousToggle?.focus();
    }

    function positionProfileMenu(toggle) {
      const bounds = toggle.getBoundingClientRect();
      const isSidebarToggle = toggle.classList.contains("profile-button");
      const isSidebarCollapsed = document.querySelector(".app-shell")?.classList.contains("sidebar-collapsed") ?? false;
      profileMenu.classList.toggle("profile-menu-sidebar", isSidebarToggle);
      profileMenu.classList.toggle("profile-menu-collapsed", isSidebarToggle && isSidebarCollapsed);
      const sidebar = isSidebarToggle ? toggle.closest(".sidebar") : null;
      const sidebarBounds = sidebar?.getBoundingClientRect();
      if (sidebarBounds) {
        profileMenu.style.width = `${Math.max(isSidebarCollapsed ? 52 : 120, sidebarBounds.width - 16)}px`;
      } else {
        profileMenu.style.width = "";
      }
      const menuBounds = profileMenu.getBoundingClientRect();
      const left = sidebarBounds
        ? sidebarBounds.left + 8
        : isSidebarToggle
          ? bounds.left
          : bounds.right - menuBounds.width;
      const above = bounds.top - menuBounds.height - 8 >= 8;
      const top = isSidebarToggle && above
        ? bounds.top - menuBounds.height - 8
        : above && !isSidebarToggle
          ? bounds.top - menuBounds.height - 8
          : bounds.bottom + 8;

      profileMenu.style.left = `${Math.max(8, Math.min(left, window.innerWidth - menuBounds.width - 8))}px`;
      profileMenu.style.top = `${Math.max(8, Math.min(top, window.innerHeight - menuBounds.height - 8))}px`;
    }

    for (const toggle of profileMenuToggles) {
      toggle.setAttribute("aria-controls", profileMenu.id);
      toggle.addEventListener("click", () => {
        if (!profileMenu.hidden && activeMenuToggle === toggle) {
          closeProfileMenu();
          return;
        }

        profileMenu.hidden = false;
        profileMenuToggles.forEach((item) => item.setAttribute("aria-expanded", String(item === toggle)));
        activeMenuToggle = toggle;
        positionProfileMenu(toggle);
      });
    }

    document.addEventListener("click", (event) => {
      if (!profileMenu.hidden && !profileMenu.contains(event.target) && !profileMenuToggles.some((toggle) => toggle.contains(event.target))) {
        closeProfileMenu();
      }
    });

    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !profileMenu.hidden) closeProfileMenu({ restoreFocus: true });
    });

    window.addEventListener("resize", () => {
      if (!profileMenu.hidden && activeMenuToggle) positionProfileMenu(activeMenuToggle);
    });

    window.addEventListener("scroll", () => {
      if (!profileMenu.hidden && activeMenuToggle) positionProfileMenu(activeMenuToggle);
    }, true);

    manageProfileLink.addEventListener("click", () => closeProfileMenu());
    logoutButton.addEventListener("click", async () => {
      if (!window.confirm("Tem certeza de que deseja sair da sua conta?")) return;
      try {
        await window.ChamoApi.logout();
        window.location.assign("TelaLogin.html");
      } catch (error) {
        console.error("Não foi possível encerrar a sessão no servidor.", error);
        window.alert(error.message);
      }
    });
  }

  const tabs = Array.from(document.querySelectorAll('[role="tab"][aria-controls]'));

  function activateTab(selectedTab, moveFocus = false) {
    for (const tab of tabs) {
      const isSelected = tab === selectedTab;
      tab.classList.toggle("is-active", isSelected);
      tab.setAttribute("aria-selected", String(isSelected));
      tab.tabIndex = isSelected ? 0 : -1;
      const panel = document.getElementById(tab.getAttribute("aria-controls"));
      if (panel) panel.hidden = !isSelected;
    }
    if (moveFocus) selectedTab.focus();
  }

  for (const tab of tabs) {
    tab.addEventListener("click", () => activateTab(tab));
    tab.addEventListener("keydown", (event) => {
      const currentIndex = tabs.indexOf(tab);
      let nextIndex = currentIndex;
      if (event.key === "ArrowRight") nextIndex = (currentIndex + 1) % tabs.length;
      else if (event.key === "ArrowLeft") nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
      else if (event.key === "Home") nextIndex = 0;
      else if (event.key === "End") nextIndex = tabs.length - 1;
      else return;
      event.preventDefault();
      activateTab(tabs[nextIndex], true);
    });
  }
  if (tabs.length) {
    const requestedTab = new URLSearchParams(window.location.search).get("tab");
    const initialTab = tabs.find((tab) => tab.id === `${requestedTab}-tab`) ?? tabs[0];
    activateTab(initialTab);
  }

  if (!profileForm) return;

  function updatePhotoPreview() {
    renderAvatar(photoPreview, profileNameInput.value || fallbackName, profileImage);
  }

  profileNameInput.addEventListener("input", updatePhotoPreview);
  function updatePasswordRequirements() {
    const changingPassword = Boolean(currentPasswordInput.value || newPasswordInput.value || confirmPasswordInput.value);
    currentPasswordInput.required = changingPassword;
    newPasswordInput.required = changingPassword;
    confirmPasswordInput.required = changingPassword;
    confirmPasswordInput.setCustomValidity(
      confirmPasswordInput.value && confirmPasswordInput.value !== newPasswordInput.value
        ? "As senhas não coincidem."
        : ""
    );
  }

  for (const input of [currentPasswordInput, newPasswordInput, confirmPasswordInput]) {
    input.addEventListener("input", updatePasswordRequirements);
  }

  photoInput.addEventListener("change", () => {
    const [file] = photoInput.files;
    photoError.textContent = "";
    if (!file) return;
    if (!supportedImageTypes.has(file.type)) {
      photoInput.value = "";
      photoError.textContent = "Escolha uma imagem PNG, JPG, GIF ou WebP.";
      return;
    }
    if (file.size > maxImageSize) {
      photoInput.value = "";
      photoError.textContent = "A imagem deve ter no máximo 1 MB.";
      return;
    }

    pendingPhotoRead = new Promise((resolve) => {
      const reader = new FileReader();
      reader.addEventListener("load", () => {
        if (typeof reader.result !== "string") {
          photoError.textContent = "Não foi possível ler a imagem selecionada.";
          resolve();
          return;
        }
        profileImage = reader.result;
        updatePhotoPreview();
        resolve();
      }, { once: true });
      reader.addEventListener("error", () => {
        console.error("Não foi possível ler a imagem de perfil.", reader.error);
        photoError.textContent = "Não foi possível ler a imagem selecionada.";
        resolve();
      }, { once: true });
      reader.readAsDataURL(file);
    });
  });

  document.querySelector("#remove-profile-photo").addEventListener("click", () => {
    photoInput.value = "";
    profileImage = "";
    photoError.textContent = "";
    updatePhotoPreview();
  });

  profileForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!profileForm.reportValidity()) return;
    await pendingPhotoRead;
    if (photoError.textContent) return;

    const profile = {
      name: profileNameInput.value.trim(),
      email: profileEmailInput.value.trim() || session?.email || "",
      phone: profilePhoneInput.value.trim(),
      photoDataUrl: profileImage,
      currentPassword: currentPasswordInput.value,
      newPassword: newPasswordInput.value
    };
    try {
      const updatedProfile = await window.ChamoApi.saveProfile(profile);
      session = updatedProfile;
      role = updatedProfile.role;
      applyProfile(updatedProfile);
      profileFeedback.textContent = newPasswordInput.value
        ? "Perfil salvo e senha alterada."
        : "Perfil salvo e aplicado às páginas do portal.";
      currentPasswordInput.value = "";
      newPasswordInput.value = "";
      confirmPasswordInput.value = "";
      updatePasswordRequirements();
    } catch (error) {
      console.error("Não foi possível salvar as informações do perfil ou atualizar a senha.", error);
      profileFeedback.textContent = error instanceof Error
        ? error.message
        : "Não foi possível salvar as alterações. Tente novamente.";
    }
  });
})();
