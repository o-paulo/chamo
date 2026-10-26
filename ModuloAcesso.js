(async () => {
  const content = document.querySelector("#module-content");
  const session = await window.ChamoApi.session();
  if (!session) {
    window.location.replace("TelaLogin.html");
    return;
  }
  if (!["attendant", "admin"].includes(session.role)) {
    window.location.replace("TelaInicial.html");
    return;
  }
  let ticketCache = [];
  const params = new URLSearchParams(window.location.search);
  const requestedPage = params.get("page") || (session.role === "admin" ? "admin" : "inicio");
  const adminPages = ["admin", "admin-users", "admin-sla", "admin-audit"];
  const currentPage = session.role === "admin"
    ? (adminPages.includes(requestedPage) ? requestedPage : "admin")
    : requestedPage;
  const priorityLabels = { very_high: "Muito alta", high: "Alta", medium: "Média", low: "Baixa" };
  const statusLabels = {
    unassigned: "Sem atribuição",
    progress: "Em atendimento",
    waiting: "Pendente de resposta",
    resolved: "Concluído"
  };

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (character) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[character]);
  }

  function loadTickets() {
    return ticketCache;
  }

  function formatDate(value, options = { dateStyle: "medium", timeStyle: "short" }) {
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? "Data indisponível"
      : new Intl.DateTimeFormat("pt-BR", options).format(date);
  }

  function isToday(value) {
    if (!value) return false;
    const date = new Date(value);
    const now = new Date();
    return !Number.isNaN(date.getTime()) &&
      date.getFullYear() === now.getFullYear() &&
      date.getMonth() === now.getMonth() &&
      date.getDate() === now.getDate();
  }

  function route(page, values = {}) {
    const query = new URLSearchParams({ page, ...values });
    return `ModuloAcesso.html?${query.toString()}`;
  }

  function assignedTickets(tickets) {
    return tickets.filter((ticket) => ticket.assignedTo === session.email);
  }

  function prioritySelect(value = "all") {
    return `<select data-priority-filter aria-label="Filtrar por prioridade">
      <option value="all"${value === "all" ? " selected" : ""}>Todas as prioridades</option>
      <option value="very_high"${value === "very_high" ? " selected" : ""}>Muito alta</option>
      <option value="high"${value === "high" ? " selected" : ""}>Alta</option>
      <option value="medium"${value === "medium" ? " selected" : ""}>Média</option>
      <option value="low"${value === "low" ? " selected" : ""}>Baixa</option>
    </select>`;
  }

  function pageHeading(title, subtitle, id = "") {
    return `<header class="module-heading">
      <nav class="breadcrumbs" aria-label="Trilha de navegação"><a href="${route(session.role === "admin" ? "admin" : "inicio")}">Início</a><span aria-hidden="true">/</span><span aria-current="page">${escapeHtml(title)}</span></nav>
      <h1${id ? ` id="${escapeHtml(id)}"` : ""}>${escapeHtml(title)}</h1><p>${escapeHtml(subtitle)}</p>
    </header>`;
  }

  function dataNotice() {
    return `<p class="demo-notice" role="note"><strong>Chamô:</strong> tickets e atualizações sincronizados pelo servidor.</p>`;
  }

  function renderShell() {
    const nav = document.querySelector("#module-nav");
    const roleLabel = session.role === "admin" ? "Administrador" : "Atendente";
    const links = session.role === "admin"
      ? [
          { title: "Início", page: "admin", icon: '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>' },
          { title: "Cadastro/criação de usuários", page: "admin-users", icon: '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6m-3-3h6"/>' },
          { title: "Primeira resposta", page: "admin-sla", icon: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>' },
          { title: "Auditoria recente", page: "admin-audit", icon: '<path d="M3 3v5h5M4 16a8 8 0 1 0 1-8L3 8"/><path d="M12 7v5l4 2"/>' },
          { title: "Configurações", page: "settings", icon: '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.5 7.5 0 0 0 0-2l2-1.5-2-3.5-2.4 1a7.5 7.5 0 0 0-1.7-1L15 3h-6l-.4 3a7.5 7.5 0 0 0-1.7 1l-2.4-1-2 3.5 2 1.5a7.5 7.5 0 0 0 0 2l-2 1.5 2 3.5 2.4-1a7.5 7.5 0 0 0 1.7 1L9 21h6l.4-3a7.5 7.5 0 0 0 1.7-1l2.4 1 2-3.5z"/>' }
        ]
      : [
          { title: "Início", page: "inicio", icon: '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>' },
          { title: "Minha Fila", page: "fila", icon: '<path d="M4 5h16M4 12h16M4 19h16"/><circle cx="2.5" cy="5" r=".5"/><circle cx="2.5" cy="12" r=".5"/><circle cx="2.5" cy="19" r=".5"/>' },
          { title: "Chamados sem Atribuição", page: "disponiveis", icon: '<circle cx="12" cy="12" r="9"/><path d="M12 8v8m-4-4h8"/>' },
          { title: "Meus Chamados Concluídos", page: "concluidos", icon: '<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>' },
          { title: "Configurações", page: "settings", icon: '<circle cx="12" cy="12" r="3"/><path d="M19.4 13a7.5 7.5 0 0 0 0-2l2-1.5-2-3.5-2.4 1a7.5 7.5 0 0 0-1.7-1L15 3h-6l-.4 3a7.5 7.5 0 0 0-1.7 1l-2.4-1-2 3.5 2 1.5a7.5 7.5 0 0 0 0 2l-2 1.5 2 3.5 2.4-1a7.5 7.5 0 0 0 1.7 1L9 21h6l.4-3a7.5 7.5 0 0 0 1.7-1l2.4 1 2-3.5z"/>' }
        ];
    nav.innerHTML = links.map((link) => {
      const href = link.page === "settings"
        ? "ConfiguracoesAcesso.html"
        : route(link.page);
      const active = link.page === "settings"
        ? window.location.pathname.toLocaleLowerCase("pt-BR").endsWith("/configuracoesacesso.html")
        : currentPage === link.page;
      return `<a class="nav-link${active ? " is-active" : ""}" href="${href}"${active ? ' aria-current="page"' : ""}><svg class="module-nav-icon" viewBox="0 0 24 24" aria-hidden="true">${link.icon}</svg><span>${escapeHtml(link.title)}</span></a>`;
    }).join("");
    const roleLabelNodes = document.querySelectorAll("[data-module-role]");
    roleLabelNodes.forEach((node) => { node.textContent = roleLabel; });
    document.querySelector("[data-module-name]").textContent = session.name;
    document.querySelector("[data-module-avatar]").textContent = getInitials(session.name);
  }

  function getInitials(name) {
    const words = String(name).trim().split(/\s+/).filter(Boolean);
    return words.length > 1
      ? `${Array.from(words[0])[0]}${Array.from(words[words.length - 1])[0]}`.toLocaleUpperCase("pt-BR")
      : Array.from(words[0] || "?").slice(0, 2).join("").toLocaleUpperCase("pt-BR");
  }

  function statCard(label, value, detail, color) {
    return `<article class="summary-card"><span class="summary-label">${escapeHtml(label)}</span><strong class="summary-value ${color}">${escapeHtml(value)}</strong><span class="summary-detail">${escapeHtml(detail)}</span></article>`;
  }

  function renderHome() {
    const tickets = loadTickets();
    const mine = assignedTickets(tickets);
    const activeCount = mine.filter((ticket) => ticket.status === "progress").length;
    const waitingCount = mine.filter((ticket) => ticket.status === "waiting").length;
    const resolvedCount = tickets.filter((ticket) => ticket.resolvedBy === session.email && isToday(ticket.resolvedAt)).length;
    content.innerHTML = `${dataNotice()}<section class="welcome-card module-welcome">
      <div class="welcome-copy"><p class="eyebrow">ÁREA DE ATENDIMENTO</p><h1>Olá, ${escapeHtml(session.name)}!</h1>
      <p class="welcome-lead">Sua fila começa por aqui.</p><p>Acompanhe os chamados atribuídos, mantenha a comunicação com os solicitantes e atualize cada atendimento.</p></div>
      <div class="welcome-art welcome-art-attendant" aria-hidden="true"></div>
    </section>
    <section class="ticket-summary" aria-label="Resumo da minha fila">
      ${statCard("Em atendimento", activeCount, "Chamados atribuídos em andamento", "summary-active")}
      ${statCard("Pendentes de resposta", waitingCount, "Aguardando retorno do solicitante", "summary-waiting")}
      ${statCard("Resolvidos hoje", resolvedCount, "Concluídos por você hoje", "summary-resolved")}
    </section>
    <div class="module-columns">
      <section class="panel module-panel"><h2>Responsabilidades</h2><ul class="responsibility-list">
        <li>Assumir e atender os chamados atribuídos a você.</li><li>Manter a comunicação com os solicitantes.</li>
        <li>Acompanhar os chamados pendentes de resposta.</li><li>Resolver os problemas e manter os registros atualizados.</li>
        <li>Buscar uma experiência de atendimento eficiente.</li>
      </ul><a class="button button-dark" href="${route("fila")}">Abrir Minha Fila <span aria-hidden="true">→</span></a></section>
      <section class="panel module-panel satisfaction-card"><p class="eyebrow">QUALIDADE DO ATENDIMENTO</p><h2>Satisfação dos usuários</h2>
        <p class="empty-state">Ainda não há avaliações suficientes.</p><small>Não há avaliações conectadas a este protótipo.</small></section>
    </div>`;
  }

  function ticketSearchText(ticket) {
    return [ticket.id, ticket.title, ticket.description, ticket.requester, ticket.department]
      .filter(Boolean).join(" ").toLocaleLowerCase("pt-BR");
  }

  function ticketRow(ticket, action, href, extra = "") {
    const priority = priorityLabels[ticket.priority] || "Não definida";
    const status = statusLabels[ticket.status] || "Status indisponível";
    return `<tr data-filter-row data-priority="${escapeHtml(ticket.priority)}" data-search="${escapeHtml(ticketSearchText(ticket))}">
      <td class="ticket-id">${escapeHtml(ticket.id)}</td><td class="subject-cell">${escapeHtml(ticket.title)}</td>
      <td>${escapeHtml(ticket.requester)}<small class="row-secondary">${escapeHtml(ticket.department || "Setor não informado")}</small></td>
      <td><span class="priority priority-${escapeHtml(ticket.priority)}">${escapeHtml(priority)}</span></td>
      <td><span class="status status-${escapeHtml(ticket.status)}">${escapeHtml(status)}</span></td>
      ${extra}<td><a class="button button-light row-action" href="${escapeHtml(href)}">${escapeHtml(action)}</a></td>
    </tr>`;
  }

  function listPanel(title, rows, emptyText, filters = true) {
    return `<section class="panel module-panel">
      <div class="ticket-list-heading"><div><h2>${escapeHtml(title)}</h2><p data-visible-count>${rows.length} ${rows.length === 1 ? "chamado" : "chamados"}</p></div></div>
      ${filters ? `<form class="module-filters" data-ticket-filters>
        <label class="module-search"><span class="visually-hidden">Pesquisar chamados</span><input type="search" data-search-input placeholder="Buscar por número, assunto, descrição, solicitante ou setor..." value="${escapeHtml(params.get("q") || "")}"></label>
        <label class="module-priority"><span class="visually-hidden">Filtrar prioridade</span>${prioritySelect(params.get("priority") || "all")}</label>
      </form>` : ""}
      <div class="table-wrap"><table class="tickets-table module-table"><thead><tr><th>Chamado</th><th>Assunto</th><th>Solicitante / setor</th><th>Prioridade</th><th>Status</th>${rows.some((ticket) => ticket.resolvedAt) ? "<th>Concluído em</th>" : ""}<th><span class="visually-hidden">Ação</span></th></tr></thead>
      <tbody>${rows.map((ticket) => {
        const from = currentPage === "disponiveis"
          ? "disponiveis"
          : currentPage === "concluidos"
            ? "concluidos"
            : "fila";
        const carry = { from, priority: params.get("priority") || "all", q: params.get("q") || "" };
        const href = route("chamado", { id: ticket.id, ...carry });
        const extra = ticket.resolvedAt ? `<td>${escapeHtml(formatDate(ticket.resolvedAt))}</td>` : "";
        return ticketRow(ticket, currentPage === "disponiveis" ? "Assumir" : currentPage === "concluidos" ? "Ver detalhes" : "Continuar", href, extra);
      }).join("")}</tbody></table></div>
      ${rows.length ? `<p class="empty-state filtered-empty" data-filter-empty hidden>Nenhum chamado corresponde aos filtros.</p>` : `<p class="empty-state initial-empty">${escapeHtml(emptyText)}</p>`}
    </section>`;
  }

  function bindFilters() {
    const form = content.querySelector("[data-ticket-filters]");
    if (!form) return;
    form.addEventListener("submit", (event) => event.preventDefault());
    const search = form.querySelector("[data-search-input]");
    const priority = form.querySelector("[data-priority-filter]");
    const rows = Array.from(content.querySelectorAll("[data-filter-row]"));
    const initialEmpty = content.querySelector(".initial-empty");
    const filterEmpty = content.querySelector("[data-filter-empty]");
    const countLabel = content.querySelector("[data-visible-count]");
    const update = () => {
      const query = search.value.trim().toLocaleLowerCase("pt-BR");
      let visible = 0;
      for (const row of rows) {
        const matchesQuery = row.dataset.search.includes(query);
        const matchesPriority = priority.value === "all" || row.dataset.priority === priority.value;
        row.hidden = !matchesQuery || !matchesPriority;
        if (!row.hidden) visible += 1;
        const action = row.querySelector(".row-action");
        if (action) {
          const ticketId = row.querySelector(".ticket-id").textContent;
          const from = currentPage === "disponiveis" ? "disponiveis" : currentPage === "concluidos" ? "concluidos" : "fila";
          action.href = route("chamado", {
            id: ticketId,
            from,
            priority: priority.value,
            q: search.value.trim()
          });
        }
      }
      if (countLabel) countLabel.textContent = `${visible} ${visible === 1 ? "chamado" : "chamados"}`;
      if (filterEmpty) filterEmpty.hidden = visible > 0 || rows.length === 0;
      if (initialEmpty) initialEmpty.hidden = rows.length > 0;
    };
    search.addEventListener("input", update);
    priority.addEventListener("change", update);
  }

  function renderQueue() {
    const tickets = assignedTickets(loadTickets());
    const active = tickets.filter((ticket) => ["progress", "waiting"].includes(ticket.status));
    const metrics = `<section class="ticket-summary" aria-label="Indicadores da fila">
      ${statCard("Em atendimento", tickets.filter((ticket) => ticket.status === "progress").length, "Chamados atribuídos em andamento", "summary-active")}
      ${statCard("Pendentes de resposta", tickets.filter((ticket) => ticket.status === "waiting").length, "Aguardando retorno do solicitante", "summary-waiting")}
      ${statCard("Resolvidos hoje", tickets.filter((ticket) => ticket.resolvedBy === session.email && isToday(ticket.resolvedAt)).length, "Concluídos por você hoje", "summary-resolved")}
    </section>`;
    content.innerHTML = `${dataNotice()}${pageHeading("Minha Fila", "Acompanhe os chamados que estão sob sua responsabilidade.")}${metrics}
      ${listPanel("Chamados atribuídos", active, "Sua fila está vazia no momento.")}`;
    bindFilters();
  }

  function renderUnassigned() {
    const tickets = loadTickets().filter((ticket) => ticket.status === "unassigned" && !ticket.assignedTo);
    content.innerHTML = `${dataNotice()}${pageHeading("Chamados sem Atribuição", "Consulte os chamados disponíveis antes de iniciar um atendimento.")}
      ${listPanel("Disponíveis para atendimento", tickets, "Não há chamados disponíveis para atribuição.")}`;
    bindFilters();
  }

  function renderResolved() {
    const tickets = loadTickets().filter((ticket) =>
      ticket.status === "resolved" && ticket.resolvedBy === session.email
    ).sort((first, second) => new Date(second.resolvedAt) - new Date(first.resolvedAt));
    content.innerHTML = `${dataNotice()}${pageHeading("Meus Chamados Concluídos", "Consulte os chamados finalizados por você.")}
      ${listPanel("Histórico de conclusão", tickets, "Você ainda não concluiu chamados.")}`;
    bindFilters();
  }

  function renderAdminHeader(title, subtitle) {
    content.innerHTML = `${pageHeading(title, subtitle)}${dataNotice()}`;
  }

  async function renderAdminHome() {
    const data = await window.ChamoApi.request("users");
    const tickets = loadTickets();
    const pendingStaff = data.users.filter((account) =>
      ["attendant", "admin"].includes(account.role) && Number(account.isActive) === 0
    ).length;
    const activeTickets = tickets.filter((ticket) => ["progress", "waiting"].includes(ticket.status)).length;
    const unassignedTickets = tickets.filter((ticket) => ticket.status === "unassigned").length;
    const resolvedToday = tickets.filter((ticket) => ticket.status === "resolved" && isToday(ticket.resolvedAt)).length;
    const firstName = session.name.trim().split(/\s+/)[0] || "Administrador";
    const hour = new Date().getHours();
    const greetingText = hour < 12 ? "Bom dia" : hour < 18 ? "Boa tarde" : "Boa noite";

    content.innerHTML = `${dataNotice()}
      <section class="admin-welcome" aria-labelledby="admin-welcome-title">
        <div class="admin-welcome-copy">
          <p class="admin-welcome-eyebrow">PAINEL ADMINISTRATIVO</p>
          <h1 id="admin-welcome-title">${greetingText}, ${escapeHtml(firstName)}!</h1>
          <p>Bom ter você por aqui. Aqui está um resumo da operação de atendimento.</p>
        </div>
        <div class="admin-welcome-art" aria-hidden="true"></div>
      </section>
      <section class="admin-overview" aria-label="Resumo da operação">
        ${statCard("Chamados em andamento", activeTickets, "Em atendimento ou aguardando retorno", "summary-active")}
        ${statCard("Sem atribuição", unassignedTickets, "Aguardando um responsável", "summary-waiting")}
        ${statCard("Acessos pendentes", pendingStaff, "Atendentes e administradores para aprovar", "summary-pending")}
        ${statCard("Concluídos hoje", resolvedToday, "Chamados finalizados hoje", "summary-resolved")}
      </section>`;
    content.insertAdjacentHTML("beforeend", `<section class="panel module-panel admin-shortcuts" aria-labelledby="admin-shortcuts-title">
      <div class="ticket-list-heading"><div><h2 id="admin-shortcuts-title">O que você gostaria de fazer?</h2><p>Atalhos para as tarefas administrativas mais comuns.</p></div></div>
      <div class="admin-shortcut-grid">
        <a class="admin-shortcut" href="${route("admin-users")}"><span class="admin-shortcut-icon" aria-hidden="true">+</span><span><strong>Criar ou gerenciar usuários</strong><small>Cadastre contas e aprove solicitações de acesso.</small></span><span class="admin-shortcut-arrow" aria-hidden="true">→</span></a>
        <a class="admin-shortcut" href="${route("admin-sla")}"><span class="admin-shortcut-icon" aria-hidden="true">◷</span><span><strong>Configurar primeira resposta</strong><small>Ajuste os prazos úteis de cada nível de prioridade.</small></span><span class="admin-shortcut-arrow" aria-hidden="true">→</span></a>
        <a class="admin-shortcut" href="${route("admin-audit")}"><span class="admin-shortcut-icon" aria-hidden="true">≡</span><span><strong>Consultar auditoria</strong><small>Confira as atividades administrativas mais recentes.</small></span><span class="admin-shortcut-arrow" aria-hidden="true">→</span></a>
      </div>
    </section>`);
  }

  async function renderAdminUsers() {
    const data = await window.ChamoApi.request("users");
    const pendingStaff = data.users.filter((account) =>
      ["attendant", "admin"].includes(account.role) && Number(account.isActive) === 0
    ).length;
    const usersMarkup = data.users.map((account) => {
      const pendingApproval = ["attendant", "admin"].includes(account.role) && Number(account.isActive) === 0;
      return `<tr data-user-id="${account.id}">
      <td>${escapeHtml(account.name)}<span class="row-secondary">${escapeHtml(account.email)}</span>${pendingApproval ? '<span class="row-secondary">Aguardando autorização</span>' : ""}</td>
      <td><select data-user-role aria-label="Perfil de ${escapeHtml(account.name)}">
        <option value="user"${account.role === "user" ? " selected" : ""}>Solicitante</option>
        <option value="attendant"${account.role === "attendant" ? " selected" : ""}>Atendente</option>
        <option value="admin"${account.role === "admin" ? " selected" : ""}>Administrador</option>
      </select></td>
      <td><label class="admin-active"><input data-user-active type="checkbox"${Number(account.isActive) === 1 ? " checked" : ""}> ${pendingApproval ? "Aprovar acesso" : "Ativo"}</label></td>
      <td><button class="button button-light row-action" type="button" data-user-save>Salvar</button></td>
    </tr>`;
    }).join("");
    renderAdminHeader("Cadastro/criação de usuários", "Crie contas, gerencie acessos e aprove solicitações pendentes.");
    content.insertAdjacentHTML("beforeend", `<section class="panel module-panel">
      <div class="ticket-list-heading"><div><h2>Usuários</h2><p>${data.users.length} contas cadastradas · ${pendingStaff} solicitações aguardando aprovação</p></div></div>
      <form class="admin-create-form" id="admin-create-user">
        <label>Nome<input name="name" maxlength="100" required></label>
        <label>E-mail<input name="email" type="email" maxlength="254" required></label>
        <label>Perfil<select name="role"><option value="user">Solicitante</option><option value="attendant">Atendente</option><option value="admin">Administrador</option></select></label>
        <label>Senha inicial<input name="password" type="password" minlength="12" maxlength="200" autocomplete="new-password" required></label>
        <button class="button button-dark" type="submit">Criar conta</button>
      </form>
      <p class="admin-help">Use pelo menos 12 caracteres. Contas de atendente e administrador cadastradas publicamente aguardam autorização nesta lista.</p>
      <div class="table-scroll"><table class="data-table module-table admin-table"><thead><tr><th>Conta</th><th>Perfil</th><th>Acesso</th><th>Ação</th></tr></thead>
        <tbody>${usersMarkup || '<tr><td colspan="4">Nenhum usuário cadastrado.</td></tr>'}</tbody></table></div>
    </section>`);

    content.querySelector("#admin-create-user")?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const submit = form.querySelector("button[type=submit]");
      submit.disabled = true;
      try {
        const fields = Object.fromEntries(new FormData(form));
        await window.ChamoApi.request("users", { method: "POST", body: fields });
        await renderAdmin();
      } catch (error) {
        operationError(error);
      } finally {
        submit.disabled = false;
      }
    });

    content.querySelectorAll("[data-user-save]").forEach((button) => {
      button.addEventListener("click", async () => {
        const row = button.closest("[data-user-id]");
        button.disabled = true;
        try {
          await window.ChamoApi.request("user", {
            method: "PUT",
            id: row.dataset.userId,
            body: {
              role: row.querySelector("[data-user-role]").value,
              isActive: row.querySelector("[data-user-active]").checked
            }
          });
          await renderAdmin();
        } catch (error) {
          operationError(error);
        } finally {
          button.disabled = false;
        }
      });
    });

  }

  async function renderAdminSla() {
    const data = await window.ChamoApi.request("users");
    renderAdminHeader("Primeira resposta", "Configure os prazos de primeira resposta por prioridade.");
    content.insertAdjacentHTML("beforeend", `<section class="panel module-panel">
      <h2>Primeira resposta (horas úteis)</h2>
      <p class="admin-help">Padrão inicial: muito alta 2h, alta 4h, média 8h e baixa 24h. Ajuste conforme a política da equipe.</p>
      <form class="admin-sla-form" id="admin-sla-form">
        <label>Muito alta<input name="very_high" type="number" min="1" max="240" value="${Number(data.sla.very_high) || 2}" required></label>
        <label>Alta<input name="high" type="number" min="1" max="240" value="${Number(data.sla.high) || 4}" required></label>
        <label>Média<input name="medium" type="number" min="1" max="240" value="${Number(data.sla.medium) || 8}" required></label>
        <label>Baixa<input name="low" type="number" min="1" max="240" value="${Number(data.sla.low) || 24}" required></label>
        <button class="button button-dark" type="submit">Salvar SLA</button>
      </form>
    </section>`);
    content.querySelector("#admin-sla-form")?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const form = event.currentTarget;
      const submit = form.querySelector("button[type=submit]");
      submit.disabled = true;
      try {
        await window.ChamoApi.request("sla", {
          method: "PUT",
          body: Object.fromEntries(new FormData(form))
        });
        await renderAdminSla();
      } catch (error) {
        operationError(error);
      } finally {
        submit.disabled = false;
      }
    });
  }

  async function renderAdminAudit() {
    const data = await window.ChamoApi.request("users");
    const auditMarkup = data.audit.map((entry) => `<tr>
      <td>${escapeHtml(formatDate(entry.createdAt))}</td>
      <td>${escapeHtml(entry.actor || "Sistema")}</td>
      <td>${escapeHtml(entry.action)}</td>
      <td>${escapeHtml(`${entry.targetType}:${entry.targetId}`)}</td>
    </tr>`).join("");
    renderAdminHeader("Auditoria recente", "Acompanhe as atividades administrativas mais recentes.");
    content.insertAdjacentHTML("beforeend", `<section class="panel module-panel">
      <h2>Auditoria recente</h2>
      <div class="table-scroll"><table class="data-table module-table"><thead><tr><th>Data</th><th>Responsável</th><th>Ação</th><th>Alvo</th></tr></thead>
        <tbody>${auditMarkup || '<tr><td colspan="4">Nenhuma ação registrada ainda.</td></tr>'}</tbody></table></div>
    </section>`);
  }

  async function renderAdmin() {
    if (currentPage === "admin-users") {
      await renderAdminUsers();
    } else if (currentPage === "admin-sla") {
      await renderAdminSla();
    } else if (currentPage === "admin-audit") {
      await renderAdminAudit();
    } else {
      await renderAdminHome();
    }
  }

  function noticeMarkup(notice) {
    const notices = {
      assumed: ["success", "Chamado assumido com sucesso no servidor."],
      resolved: ["success", "Chamado concluído com sucesso no servidor."],
      message: ["info", "Mensagem adicionada ao histórico do chamado."]
    };
    const message = notices[notice];
    if (!message) return "";
    return `<div class="operation-notice operation-${message[0]}" role="status"><p>${message[1]}</p>
      ${notice === "assumed" ? `<div><a class="button button-dark" href="${route("chamado", { id: params.get("id") })}">Iniciar atendimento</a><a class="button button-light" href="${route("disponiveis")}">Fechar</a></div>` : ""}
      ${notice === "resolved" ? `<div><a class="button button-dark" href="${route("concluidos")}">Ver chamados concluídos</a><a class="button button-light" href="${route("fila")}">Voltar para Minha Fila</a></div>` : ""}
    </div>`;
  }

  function detailBackUrl(from) {
    if (from === "disponiveis") {
      return route("disponiveis", { priority: params.get("priority") || "all", q: params.get("q") || "" });
    }
    if (from === "concluidos") {
      return route("concluidos", {
        priority: params.get("priority") || "all",
        q: params.get("q") || ""
      });
    }
    return route("fila");
  }

  function renderDetail() {
    const ticket = loadTickets().find((item) => item.id === params.get("id"));
    if (!ticket) {
      content.innerHTML = `${pageHeading("Chamado não encontrado", "O chamado pode ter sido removido dos dados locais.")}${dataNotice()}<a class="button button-light" href="${route("fila")}">Voltar para Minha Fila</a>`;
      return;
    }

    const from = params.get("from") || "";
    const canClaim = from === "disponiveis" && ticket.status === "unassigned" && !ticket.assignedTo;
    const isOwner = ticket.assignedTo === session.email || ticket.resolvedBy === session.email;
    if (!canClaim && !isOwner) {
      content.innerHTML = `${dataNotice()}${pageHeading("Acesso indisponível", "Este chamado não está atribuído à sua conta.")}<a class="button button-light" href="${detailBackUrl(from)}">Voltar</a>`;
      return;
    }

    const readOnly = ticket.status === "resolved";
    const attachmentMarkup = ticket.attachments.length
      ? `<ul class="attachment-list">${ticket.attachments.map((item) => `<li>${escapeHtml(typeof item === "string" ? item : item.name)}</li>`).join("")}</ul>`
      : `<p class="detail-muted">Nenhum anexo disponível.</p>`;
    const messageMarkup = ticket.messages.length
      ? ticket.messages.map((message) => `<article class="message-item"><div><strong>${escapeHtml(message.sender || "Participante")}</strong><time>${escapeHtml(formatDate(message.sentAt))}</time></div><p>${escapeHtml(message.text)}</p></article>`).join("")
      : `<p class="detail-muted">Ainda não há mensagens neste chamado.</p>`;
    const actions = canClaim
      ? `<div class="detail-actions"><button class="button button-dark" type="button" data-assume>Assumir e iniciar atendimento</button><a class="button button-light" href="${detailBackUrl(from)}">Cancelar</a></div>`
      : readOnly
        ? `<div class="detail-actions"><p class="detail-muted">Este chamado está concluído e disponível somente para consulta.</p><a class="button button-light" href="${detailBackUrl(from)}">${from === "concluidos" ? "Voltar para chamados concluídos" : "Voltar para Minha Fila"}</a></div>`
        : `<form class="reply-form" id="reply-form"><label for="reply-message">Responder ao solicitante</label><textarea id="reply-message" name="message" rows="4" maxlength="2000" required placeholder="Escreva uma atualização para o solicitante..."></textarea><button class="button button-dark" type="submit">Enviar mensagem</button></form>
          <div class="detail-actions"><button class="button button-danger" type="button" data-resolve>Concluir chamado</button><a class="button button-light" href="${route("fila")}">Voltar para Minha Fila</a></div>`;

    const openedAt = formatDate(ticket.createdAt);
    const closedAt = ticket.resolvedAt ? formatDate(ticket.resolvedAt) : "";
    const sla = ticket.sla;
    const slaLabel = ticket.firstResponseAt
      ? (sla.firstResponseBreached ? "Respondido fora do prazo" : "Respondido dentro do prazo")
      : (sla.firstResponseBreached ? "Prazo excedido" : "Aguardando primeira resposta");
    const notice = noticeMarkup(params.get("notice"));
    content.innerHTML = `${dataNotice()}${pageHeading(ticket.id, ticket.title)}${notice}
      <article class="panel module-panel ticket-detail">
        <div class="detail-top"><div><p class="eyebrow">DETALHES DO CHAMADO</p><h2>${escapeHtml(ticket.title)}</h2></div>
          <span class="priority priority-${escapeHtml(ticket.priority)}">${escapeHtml(priorityLabels[ticket.priority] || "Não definida")}</span></div>
        <p class="detail-description">${escapeHtml(ticket.description || "Descrição não informada.")}</p>
        <dl class="detail-grid">
          <div><dt>Solicitante</dt><dd>${escapeHtml(ticket.requester || "Não informado")}</dd></div>
          <div><dt>Setor</dt><dd>${escapeHtml(ticket.department || "Não informado")}</dd></div>
          <div><dt>Status</dt><dd>${escapeHtml(statusLabels[ticket.status] || "Indisponível")}</dd></div>
          <div><dt>Data de abertura</dt><dd>${escapeHtml(openedAt)}</dd></div>
          <div><dt>SLA da primeira resposta</dt><dd>${escapeHtml(`${slaLabel} · ${sla.firstResponseTargetHours}h úteis · até ${formatDate(sla.firstResponseDueAt)}`)}</dd></div>
          <div><dt>Atendente responsável</dt><dd>${escapeHtml(ticket.assignedName || (ticket.resolvedBy ? session.name : "Sem atribuição"))}</dd></div>
          ${closedAt ? `<div><dt>Data de conclusão</dt><dd>${escapeHtml(closedAt)}</dd></div>` : ""}
        </dl>
        <section class="detail-section"><h3>Anexos</h3>${attachmentMarkup}</section>
        <section class="detail-section"><h3>Histórico de mensagens</h3><div class="message-list">${messageMarkup}</div></section>
        ${actions}
      </article>`;
    bindDetailActions(ticket.id, from, canClaim, readOnly);
  }

  function operationError(error) {
    console.error("Não foi possível concluir a operação no servidor.", error);
    const feedback = document.createElement("p");
    feedback.className = "operation-notice operation-error";
    feedback.setAttribute("role", "alert");
    feedback.textContent = error instanceof Error ? error.message : "Não foi possível atualizar o ticket.";
    content.prepend(feedback);
  }

  function bindDetailActions(ticketId, from, canClaim, readOnly) {
    const assumeButton = content.querySelector("[data-assume]");
    assumeButton?.addEventListener("click", async () => {
      if (!window.confirm("Deseja assumir este chamado e iniciar o atendimento?")) return;
      try {
        await window.ChamoApi.claimTicket(ticketId);
        window.location.assign(route("chamado", { id: ticketId, notice: "assumed" }));
      } catch (error) {
        operationError(error);
      }
    });

    const resolveButton = content.querySelector("[data-resolve]");
    if (!resolveButton || readOnly || canClaim) return;
    resolveButton.addEventListener("click", async () => {
      if (!window.confirm("Tem certeza de que deseja concluir este chamado?")) return;
      try {
        await window.ChamoApi.resolveTicket(ticketId);
        window.location.assign(route("chamado", { id: ticketId, notice: "resolved" }));
      } catch (error) {
        operationError(error);
      }
    });

    const replyForm = content.querySelector("#reply-form");
    replyForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (!replyForm.reportValidity()) return;
      const submitButton = replyForm.querySelector("button[type=submit]");
      submitButton.disabled = true;
      try {
        const messageText = new FormData(replyForm).get("message");
        await window.ChamoApi.sendMessage(ticketId, messageText);
        window.location.assign(route("chamado", { id: ticketId, notice: "message" }));
      } catch (error) {
        operationError(error);
        submitButton.disabled = false;
      }
    });
  }

  async function render() {
    renderShell();
    try {
      ticketCache = await window.ChamoApi.tickets();
      if (session.role === "admin") {
        await renderAdmin();
      } else if (currentPage === "inicio") {
        renderHome();
      } else if (currentPage === "fila") {
        renderQueue();
      } else if (currentPage === "disponiveis") {
        renderUnassigned();
      } else if (currentPage === "concluidos") {
        renderResolved();
      } else if (currentPage === "chamado") {
        renderDetail();
      } else if (currentPage === "settings") {
        window.location.replace("ConfiguracoesAcesso.html");
      } else {
        window.location.replace(route("inicio"));
      }
    } catch (error) {
      console.error("Não foi possível carregar o módulo de atendimento.", error);
      content.innerHTML = `${pageHeading("Não foi possível carregar os dados", "O servidor não conseguiu carregar os tickets.")}<p class="operation-notice operation-error" role="alert">${escapeHtml(error instanceof Error ? error.message : "Tente novamente mais tarde.")}</p>`;
    }
  }

  render();
})().catch((error) => {
  console.error("Não foi possível inicializar o módulo de atendimento.", error);
  window.location.replace("TelaLogin.html?error=backend");
});
