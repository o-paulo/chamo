(() => {
  const rows = document.querySelector("#dashboard-ticket-rows");
  const statusForRequester = (ticket) => ticket.status === "unassigned" ? "progress" : ticket.status;
  const statusLabels = {
    unassigned: ["Aguardando atribuição", "status-waiting"],
    progress: ["Em andamento", "status-progress"],
    waiting: ["Aguardando retorno", "status-waiting"],
    resolved: ["Resolvido", "status-done"]
  };

  function formatDate(value) {
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? "Data indisponível"
      : new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "short" }).format(date);
  }

  function cell(text, className = "") {
    const element = document.createElement("td");
    element.textContent = text;
    if (className) element.className = className;
    return element;
  }

  function renderTickets(tickets) {
    const active = tickets.filter((ticket) => statusForRequester(ticket) === "progress").length;
    const waiting = tickets.filter((ticket) => ticket.status === "waiting").length;
    const resolved = tickets.filter((ticket) => ticket.status === "resolved").length;
    document.querySelector("[data-ticket-total]").textContent = String(tickets.length);
    document.querySelector("[data-ticket-progress]").textContent = String(active);
    document.querySelector("[data-ticket-waiting]").textContent = String(waiting);
    document.querySelector("[data-ticket-resolved]").textContent = String(resolved);

    rows.replaceChildren();
    for (const ticket of tickets.slice(0, 5)) {
      const row = document.createElement("tr");
      const [label, className] = statusLabels[ticket.status] || ["Indisponível", "status-waiting"];
      const statusCell = cell("");
      const badge = document.createElement("span");
      badge.className = `status ${className}`;
      badge.textContent = label;
      statusCell.append(badge);
      const linkCell = cell("");
      const link = document.createElement("a");
      link.className = "row-arrow";
      link.href = `TelaTickets.html?ticket=${encodeURIComponent(ticket.id)}`;
      link.setAttribute("aria-label", `Ver ticket ${ticket.id}`);
      link.textContent = "›";
      linkCell.append(link);
      row.append(
        cell(`#${ticket.id}`, "ticket-id"),
        cell(ticket.title, "subject-cell"),
        cell(ticket.category, "category-cell"),
        statusCell,
        cell(formatDate(ticket.updatedAt), "date-cell"),
        linkCell
      );
      rows.append(row);
    }
    if (tickets.length === 0) {
      const row = document.createElement("tr");
      const message = cell("Você ainda não abriu nenhum ticket.");
      message.colSpan = 6;
      row.append(message);
      rows.append(row);
    }
  }

  const dateLabel = document.querySelector(".welcome-copy .eyebrow");
  if (dateLabel) {
    dateLabel.textContent = new Intl.DateTimeFormat("pt-BR", {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric"
    }).format(new Date()).toLocaleUpperCase("pt-BR");
  }

  window.ChamoApi.tickets().then(renderTickets).catch((error) => {
    console.error("Não foi possível carregar o resumo dos tickets.", error);
    const panel = document.querySelector(".tickets-panel");
    const message = document.createElement("p");
    message.className = "empty-state";
    message.setAttribute("role", "alert");
    message.textContent = error.message;
    panel.append(message);
  });

  window.ChamoApi.profile().then((profile) => {
    const heading = document.querySelector("#welcome-heading");
    if (heading) heading.textContent = `Olá, ${profile.name}!`;
  }).catch((error) => {
    console.error("Não foi possível carregar o nome do perfil.", error);
  });
})();
