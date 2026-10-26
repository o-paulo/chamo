(() => {
  const nav = document.querySelector("#module-nav");
  if (!nav) return;

  window.ChamoApi.session().then((session) => {
    if (!session || !["attendant", "admin"].includes(session.role)) return;

    const icons = {
    home: '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
    users: '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6m-3-3h6"/>',
    response: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    audit: '<path d="M3 3v5h5M4 16a8 8 0 1 0 1-8L3 8"/><path d="M12 7v5l4 2"/>',
    queue: '<path d="M4 5h16M4 12h16M4 19h16"/><circle cx="2.5" cy="5" r=".5"/><circle cx="2.5" cy="12" r=".5"/><circle cx="2.5" cy="19" r=".5"/>',
    available: '<circle cx="12" cy="12" r="9"/><path d="M12 8v8m-4-4h8"/>',
    done: '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>',
    admin: '<path d="M12 3 3 8l9 5 9-5-9-5Z"/><path d="m3 12 9 5 9-5m-18 5 9 5 9-5"/>',
    settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.5 7.5 0 0 0 0-2l2-1.5-2-3.5-2.4 1a7.5 7.5 0 0 0-1.7-1L15 3h-6l-.4 3a7.5 7.5 0 0 0-1.7 1l-2.4-1-2 3.5 2 1.5a7.5 7.5 0 0 0 0 2l-2 1.5 2 3.5 2.4-1a7.5 7.5 0 0 0 1.7 1L9 21h6l.4-3a7.5 7.5 0 0 0 1.7-1l2.4 1 2-3.5z"/>'
  };
    const links = session.role === "admin"
    ? [
        { label: "Início", href: "ModuloAcesso.html?page=admin", icon: icons.home },
        { label: "Cadastro/criação de usuários", href: "ModuloAcesso.html?page=admin-users", icon: icons.users },
        { label: "Primeira resposta", href: "ModuloAcesso.html?page=admin-sla", icon: icons.response },
        { label: "Auditoria recente", href: "ModuloAcesso.html?page=admin-audit", icon: icons.audit },
        { label: "Configurações", href: "ConfiguracoesAcesso.html", icon: icons.settings, active: true }
      ]
    : [
        { label: "Início", href: "ModuloAcesso.html?page=inicio", icon: icons.home },
        { label: "Minha Fila", href: "ModuloAcesso.html?page=fila", icon: icons.queue },
        { label: "Chamados sem Atribuição", href: "ModuloAcesso.html?page=disponiveis", icon: icons.available },
        { label: "Meus Chamados Concluídos", href: "ModuloAcesso.html?page=concluidos", icon: icons.done },
        { label: "Configurações", href: "ConfiguracoesAcesso.html", icon: icons.settings, active: true }
      ];

    nav.innerHTML = links.map((link) =>
      `<a class="nav-link${link.active ? " is-active" : ""}" href="${link.href}"${link.active ? ' aria-current="page"' : ""}><svg viewBox="0 0 24 24" aria-hidden="true">${link.icon}</svg><span>${link.label}</span></a>`
    ).join("");
  }).catch((error) => {
    console.error("Não foi possível validar o perfil para montar a navegação.", error);
  });
})();
