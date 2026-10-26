const ticketRows = document.querySelector("#ticket-rows");
const filterTabs = Array.from(document.querySelectorAll("[data-ticket-status]"));
const queryInput = document.querySelector("#ticket-query");
const categoryFilter = document.querySelector("#category-filter");
const resetButton = document.querySelector("#reset-filters");
const emptyMessage = document.querySelector("#tickets-empty");
const visibleCount = document.querySelector("#visible-ticket-count");
const visibleUnit = document.querySelector("#visible-ticket-unit");
const announcement = document.querySelector("#filter-announcement");
const ticketsTable = document.querySelector(".tickets-table-wrap");
const draftsView = document.querySelector("#drafts-view");
const draftRows = document.querySelector("#draft-rows");
const draftCount = document.querySelector("#draft-count");
const draftsError = document.querySelector("#drafts-error");
const ticketDetail = document.querySelector("#ticket-detail-panel");

let tickets = [];
let activeStatus = "all";
let savedDrafts = [];
let draftsLoadFailed = false;
const pageParameters = new URLSearchParams(window.location.search);
const requestedStatus = pageParameters.get("status");
const requestedCategory = pageParameters.get("category");
const requestedTicket = pageParameters.get("ticket");
const allowedStatuses = new Set(filterTabs.map((tab) => tab.dataset.ticketStatus));
const allowedCategories = new Set(Array.from(categoryFilter.options, (option) => option.value));
const categorySlugs = {
  "Sistemas e Aplicações": "systems",
  "Acesso e Permissões": "access",
  "Equipamentos": "equipment",
  "Solicitações": "requests",
  "Outros": "other",
  "Hardware": "hardware"
};

if (allowedStatuses.has(requestedStatus)) activeStatus = requestedStatus;
if (allowedCategories.has(requestedCategory)) categoryFilter.value = requestedCategory;
queryInput.value = pageParameters.get("query") || "";

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[character]);
}

function formatDate(value) {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "Data indisponível"
    : new Intl.DateTimeFormat("pt-BR", { dateStyle: "medium", timeStyle: "short" }).format(date);
}

function statusForRequester(ticket) {
  return ticket.status === "unassigned" ? "progress" : ticket.status;
}

function getTicketStatus(ticket) {
  if (ticket.status === "unassigned") return ["Aguardando atribuição", "status-waiting"];
  if (ticket.status === "waiting") return ["Aguardando retorno", "status-waiting"];
  if (ticket.status === "resolved") return ["Resolvido", "status-done"];
  return ["Em andamento", "status-progress"];
}

function makeCell(text, className = "") {
  const cell = document.createElement("td");
  if (className) cell.className = className;
  cell.textContent = text;
  return cell;
}

function renderTicketRows() {
  ticketRows.replaceChildren();
  for (const ticket of tickets) {
    const row = document.createElement("tr");
    row.dataset.status = statusForRequester(ticket);
    row.dataset.category = categorySlugs[ticket.category] || "";

    const status = getTicketStatus(ticket);
    const statusCell = makeCell("");
    const statusBadge = document.createElement("span");
    statusBadge.className = `status ${status[1]}`;
    statusBadge.textContent = status[0];
    statusCell.append(statusBadge);

    const linkCell = makeCell("");
    const link = document.createElement("a");
    link.className = "row-arrow";
    link.href = `TelaTickets.html?ticket=${encodeURIComponent(ticket.id)}`;
    link.setAttribute("aria-label", `Ver ticket ${ticket.id}`);
    link.textContent = "›";
    linkCell.append(link);

    row.append(
      makeCell(`#${ticket.id}`, "ticket-id"),
      makeCell(ticket.title, "subject-cell"),
      makeCell(ticket.category, "category-cell"),
      statusCell,
      makeCell(formatDate(ticket.createdAt), "date-cell"),
      makeCell(formatDate(ticket.updatedAt), "date-cell"),
      linkCell
    );
    ticketRows.append(row);
  }
}

function getDraftCategory(draft) {
  return categorySlugs[draft.values.category] ?? draft.values.category?.toLocaleLowerCase("pt-BR") ?? "";
}

function renderDrafts() {
  draftRows.replaceChildren();
  for (const draft of savedDrafts) {
    const row = document.createElement("tr");
    row.className = "draft-row";
    row.dataset.category = getDraftCategory(draft);

    const titleCell = makeCell("");
    const titleLink = document.createElement("a");
    titleLink.className = "draft-title-link";
    titleLink.href = `TelaNovoTicket.html?draftId=${encodeURIComponent(draft.id)}`;
    titleLink.textContent = draft.values.title?.trim() || "Ticket sem título";
    titleCell.append(titleLink);

    const actionCell = makeCell("");
    const editLink = document.createElement("a");
    editLink.className = "draft-edit-link";
    editLink.href = titleLink.href;
    editLink.textContent = "Continuar edição";
    actionCell.append(editLink);

    row.append(
      titleCell,
      makeCell(draft.values.category || "Não definida", "category-cell"),
      makeCell(formatDate(draft.savedAt), "date-cell"),
      actionCell
    );
    draftRows.append(row);
  }
}

function filterTickets() {
  const query = queryInput.value.trim().toLocaleLowerCase("pt-BR");
  const category = categoryFilter.value;
  let count = 0;

  ticketsTable.hidden = activeStatus === "drafts";
  draftsView.hidden = activeStatus !== "drafts";
  draftsError.hidden = activeStatus !== "drafts" || !draftsLoadFailed;
  ticketDetail.hidden = true;

  if (activeStatus === "drafts") {
    for (const row of draftRows.rows) {
      const matchesCategory = category === "all" || row.dataset.category === category;
      const matchesQuery = row.textContent.toLocaleLowerCase("pt-BR").includes(query);
      row.hidden = !matchesCategory || !matchesQuery;
      if (!row.hidden) count += 1;
    }
    emptyMessage.textContent = savedDrafts.length
      ? "Nenhum rascunho encontrado com esses filtros. Tente alterar a busca ou limpar os filtros."
      : "Você ainda não tem tickets em rascunho. Salve um ticket sem enviar para encontrá-lo aqui.";
  } else {
    for (const row of ticketRows.rows) {
      const matchesStatus = activeStatus === "all" || row.dataset.status === activeStatus;
      const matchesCategory = category === "all" || row.dataset.category === category;
      const matchesQuery = row.textContent.toLocaleLowerCase("pt-BR").includes(query);
      row.hidden = !matchesStatus || !matchesCategory || !matchesQuery;
      if (!row.hidden) count += 1;
    }
    emptyMessage.textContent = "Nenhum ticket encontrado com esses filtros. Tente alterar a busca ou limpar os filtros.";
  }

  visibleCount.textContent = String(count);
  visibleUnit.textContent = activeStatus === "drafts" ? (count === 1 ? "rascunho" : "rascunhos") : "tickets";
  emptyMessage.hidden = count !== 0 || (activeStatus === "drafts" && draftsLoadFailed);
  announcement.textContent = activeStatus === "drafts"
    ? `${count} ${count === 1 ? "rascunho encontrado" : "rascunhos encontrados"}.`
    : `${count} ${count === 1 ? "ticket encontrado" : "tickets encontrados"}.`;
}

function updateSummary() {
  const counts = {
    all: tickets.length,
    progress: tickets.filter((ticket) => statusForRequester(ticket) === "progress").length,
    waiting: tickets.filter((ticket) => ticket.status === "waiting").length,
    resolved: tickets.filter((ticket) => ticket.status === "resolved").length
  };
  document.querySelector("#ticket-total-summary").textContent = String(counts.all);
  document.querySelector("#ticket-progress-summary").textContent = String(counts.progress);
  document.querySelector("#ticket-waiting-summary").textContent = String(counts.waiting);
  document.querySelector("#ticket-resolved-summary").textContent = String(counts.resolved);
  for (const [status, count] of Object.entries(counts)) {
    document.querySelector(`[data-ticket-status-count="${status}"]`).textContent = String(count);
  }
  draftCount.textContent = String(savedDrafts.length);
}

function renderTicketDetail(ticket) {
  const status = getTicketStatus(ticket);
  const sla = ticket.sla;
  const slaLabel = ticket.firstResponseAt
    ? (sla.firstResponseBreached ? "Respondido fora do prazo" : "Respondido dentro do prazo")
    : (sla.firstResponseBreached ? "Prazo excedido" : "Aguardando primeira resposta");
  const messageItems = ticket.messages.length
    ? ticket.messages.map((message) => `<article class="message-item"><div><strong>${escapeHtml(message.sender)}</strong><time>${escapeHtml(formatDate(message.sentAt))}</time></div><p>${escapeHtml(message.text)}</p></article>`).join("")
    : "<p class=\"detail-muted\">Ainda não há mensagens neste ticket.</p>";
  const attachments = ticket.attachments.length
    ? `<ul class="attachment-list">${ticket.attachments.map((file) => `<li><a href="${escapeHtml(file.url)}" target="_blank" rel="noopener">${escapeHtml(file.name)}</a></li>`).join("")}</ul>`
    : "<p class=\"detail-muted\">Nenhum anexo.</p>";
  const reply = ticket.status === "resolved"
    ? "<p class=\"detail-muted\">Este ticket está concluído e disponível somente para consulta.</p>"
    : `<form class="reply-form" id="requester-reply-form"><label for="requester-reply">Responder à equipe</label><textarea id="requester-reply" name="message" rows="4" maxlength="2000" required></textarea><button class="button button-dark" type="submit">Enviar resposta</button></form>`;
  const ratingOptions = Array.from({ length: 9 }, (_, index) => {
    const value = (index + 2) / 2;
    const label = value === 1
      ? "1 estrela"
      : Number.isInteger(value)
        ? `${value} estrelas`
        : `${value.toLocaleString("pt-BR")} estrelas`;
    return `<option value="${value}">${label}</option>`;
  }).join("");
  const ratingSection = ticket.status !== "resolved"
    ? ""
    : ticket.rating
      ? `<section class="detail-section ticket-rating"><h3>Sua avaliação</h3>
          <p class="rating-stars" aria-label="${escapeHtml(ticket.rating.stars)} de 5 estrelas">${"★".repeat(Math.floor(ticket.rating.stars))}${ticket.rating.stars % 1 ? "½" : ""}</p>
          <p class="detail-muted">Você avaliou ${escapeHtml(ticket.rating.attendant)} com ${escapeHtml(ticket.rating.stars)} de 5 estrelas.</p>
          ${ticket.rating.comment ? `<p class="rating-comment">${escapeHtml(ticket.rating.comment)}</p>` : ""}</section>`
      : `<section class="detail-section ticket-rating"><h3>Avalie o atendimento</h3>
          <p class="detail-muted">Conte como foi sua experiência com ${escapeHtml(ticket.assignedName || "o atendente")}.</p>
          <form class="rating-form" id="ticket-rating-form">
            <label for="ticket-rating">Nota (estrelas)</label>
            <select id="ticket-rating" name="rating" required>
              <option value="">Selecione uma nota</option>${ratingOptions}
            </select>
            <label for="ticket-rating-comment">Comentário <span>(opcional)</span></label>
            <textarea id="ticket-rating-comment" name="comment" rows="3" maxlength="1000" placeholder="Compartilhe sua experiência (opcional)"></textarea>
            <p class="rating-feedback" id="ticket-rating-feedback" role="alert" hidden></p>
            <button class="button button-dark" type="submit">Enviar avaliação</button>
          </form></section>`;

  ticketsTable.hidden = true;
  draftsView.hidden = true;
  emptyMessage.hidden = true;
  ticketDetail.hidden = false;
  ticketDetail.innerHTML = `
    <div class="ticket-list-heading"><div><a class="text-link" href="TelaTickets.html">← Voltar para Meus Tickets</a>
      <h2>${escapeHtml(ticket.id)} · ${escapeHtml(ticket.title)}</h2></div>
      <span class="status ${status[1]}">${status[0]}</span></div>
    <p>${escapeHtml(ticket.description)}</p>
    <dl class="detail-grid"><div><dt>Categoria</dt><dd>${escapeHtml(ticket.category)}</dd></div>
      <div><dt>Gravidade</dt><dd>${escapeHtml(ticket.severity)}</dd></div>
      <div><dt>Criado em</dt><dd>${escapeHtml(formatDate(ticket.createdAt))}</dd></div>
      <div><dt>Previsão de primeira resposta</dt><dd>${escapeHtml(`${sla.firstResponseTargetHours}h úteis · até ${formatDate(sla.firstResponseDueAt)} · ${slaLabel}`)}</dd></div>
      <div><dt>Última atualização</dt><dd>${escapeHtml(formatDate(ticket.updatedAt))}</dd></div>
      <div><dt>Atendente</dt><dd>${escapeHtml(ticket.assignedName || "Aguardando atribuição")}</dd></div></dl>
    <section class="detail-section"><h3>Anexos</h3>${attachments}</section>
    <section class="detail-section"><h3>Histórico de mensagens</h3><div class="message-list">${messageItems}</div></section>
    ${reply}${ratingSection}`;

  const replyForm = ticketDetail.querySelector("#requester-reply-form");
  replyForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!replyForm.reportValidity()) return;
    const button = replyForm.querySelector("button");
    button.disabled = true;
    try {
      await window.ChamoApi.sendMessage(ticket.id, new FormData(replyForm).get("message"));
      window.location.reload();
    } catch (error) {
      console.error("Não foi possível enviar a resposta ao ticket.", error);
      announcement.textContent = error.message;
      button.disabled = false;
    }
  });

  const ratingForm = ticketDetail.querySelector("#ticket-rating-form");
  ratingForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!ratingForm.reportValidity()) return;
    const button = ratingForm.querySelector("button");
    const feedback = ratingForm.querySelector("#ticket-rating-feedback");
    button.disabled = true;
    feedback.hidden = true;
    try {
      const formData = new FormData(ratingForm);
      const updatedTicket = await window.ChamoApi.rateTicket(
        ticket.id,
        Number(formData.get("rating")),
        formData.get("comment")
      );
      tickets = tickets.map((item) => item.id === updatedTicket.id ? updatedTicket : item);
      renderTicketDetail(updatedTicket);
    } catch (error) {
      console.error("Não foi possível enviar a avaliação do ticket.", error);
      feedback.textContent = error instanceof Error ? error.message : "Não foi possível enviar sua avaliação.";
      feedback.hidden = false;
      button.disabled = false;
    }
  });
}

for (const tab of filterTabs) {
  tab.addEventListener("click", () => {
    activeStatus = tab.dataset.ticketStatus;
    for (const item of filterTabs) {
      const selected = item === tab;
      item.classList.toggle("is-selected", selected);
      item.setAttribute("aria-pressed", String(selected));
    }
    filterTickets();
  });
}

queryInput.addEventListener("input", filterTickets);
categoryFilter.addEventListener("change", filterTickets);
document.querySelector("#ticket-filters").addEventListener("submit", (event) => event.preventDefault());
resetButton.addEventListener("click", () => {
  activeStatus = "all";
  queryInput.value = "";
  categoryFilter.value = "all";
  for (const tab of filterTabs) {
    const selected = tab.dataset.ticketStatus === "all";
    tab.classList.toggle("is-selected", selected);
    tab.setAttribute("aria-pressed", String(selected));
  }
  filterTickets();
  queryInput.focus();
});

async function loadTicketsPage() {
  try {
    tickets = await window.ChamoApi.tickets();
    try {
      savedDrafts = await window.ChamoDrafts.list();
      renderDrafts();
    } catch (error) {
      console.error("Não foi possível carregar os rascunhos do servidor.", error);
      draftsLoadFailed = true;
      draftsError.textContent = error.message;
    }
    renderTicketRows();
    updateSummary();
    filterTickets();

    if (requestedTicket) {
      const ticket = tickets.find((item) => item.id === requestedTicket);
      if (ticket) renderTicketDetail(ticket);
      else {
        emptyMessage.textContent = "O ticket não foi encontrado ou não pertence à sua conta.";
        emptyMessage.hidden = false;
      }
    }
  } catch (error) {
    console.error("Não foi possível carregar os tickets do servidor.", error);
    emptyMessage.textContent = error instanceof Error ? error.message : "Não foi possível carregar os tickets.";
    emptyMessage.hidden = false;
    ticketRows.replaceChildren();
  }
}

loadTicketsPage();
