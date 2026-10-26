const notifications = Array.from(document.querySelectorAll(".notification-item"));
const filterButtons = Array.from(document.querySelectorAll("[data-notification-filter]"));
const markAllButton = document.querySelector("#mark-all-read");
const unreadCount = document.querySelector("#unread-notifications");
const sidebarUnreadCount = document.querySelector("#sidebar-unread-count");
const unreadFilterCount = document.querySelector("#unread-filter-count");
const visibleCount = document.querySelector("#visible-notification-count");
const totalCount = document.querySelector("#total-notifications");
const emptyMessage = document.querySelector("#notifications-empty");
const announcement = document.querySelector("#notification-announcement");
const notificationLink = document.querySelector(".notification-button");

let activeFilter = "all";

function updateNotifications() {
  const unread = notifications.filter(notification => notification.dataset.unread === "true").length;
  unreadCount.textContent = String(unread);
  sidebarUnreadCount.textContent = String(unread);
  sidebarUnreadCount.hidden = unread === 0;
  unreadFilterCount.textContent = String(unread);
  totalCount.textContent = String(notifications.length);
  markAllButton.disabled = unread === 0;

  notificationLink.setAttribute(
    "aria-label",
    unread === 0 ? "Notificações, nenhuma não lida" : `Notificações, ${unread} não lidas`
  );
  notificationLink.querySelector(".notification-dot").hidden = unread === 0;

  let visible = 0;
  for (const notification of notifications) {
    const matchesFilter =
      activeFilter === "all" ||
      (activeFilter === "unread" && notification.dataset.unread === "true") ||
      notification.dataset.notificationType === activeFilter;
    notification.hidden = !matchesFilter;
    if (matchesFilter) visible += 1;
  }

  for (const heading of document.querySelectorAll(".notification-date-heading")) {
    const hasVisibleNotifications = Array.from(heading.nextElementSibling?.matches(".notification-item")
      ? [heading.nextElementSibling]
      : []).some(item => !item.hidden);
    let next = heading.nextElementSibling;
    let groupHasVisible = hasVisibleNotifications;
    while (next && next.matches(".notification-item")) {
      if (!next.hidden) groupHasVisible = true;
      next = next.nextElementSibling;
    }
    heading.hidden = !groupHasVisible;
  }

  visibleCount.textContent = String(visible);
  emptyMessage.hidden = visible !== 0;
  announcement.textContent = `${unread} não lidas. ${visible} ${visible === 1 ? "notificação exibida" : "notificações exibidas"}.`;
}

function markAsRead(notification) {
  if (notification.dataset.unread !== "true") return;

  notification.dataset.unread = "false";
  notification.classList.remove("is-unread");
  notification.querySelector(".unread-indicator")?.remove();

  const markReadButton = notification.querySelector("[data-mark-read]");
  markReadButton.textContent = "Lida";
  markReadButton.disabled = true;
  updateNotifications();
}

for (const button of filterButtons) {
  button.addEventListener("click", () => {
    activeFilter = button.dataset.notificationFilter;

    for (const item of filterButtons) {
      const isSelected = item === button;
      item.classList.toggle("is-selected", isSelected);
      item.setAttribute("aria-pressed", String(isSelected));
    }

    updateNotifications();
  });
}

for (const button of document.querySelectorAll("[data-mark-read]")) {
  button.addEventListener("click", () => markAsRead(button.closest(".notification-item")));
}

markAllButton.addEventListener("click", () => {
  for (const notification of notifications) markAsRead(notification);
});

updateNotifications();
