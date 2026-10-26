(() => {
  let csrfToken = sessionStorage.getItem("chamo-csrf-token") || "";
  const accountKey = "chamo-demo-session";
  const apiUrl = "api.php";

  function setUser(user) {
    sessionStorage.setItem(accountKey, JSON.stringify(user));
    window.dispatchEvent(new CustomEvent("chamo:session", { detail: user }));
  }

  function clearSession() {
    csrfToken = "";
    sessionStorage.removeItem("chamo-csrf-token");
    sessionStorage.removeItem(accountKey);
    sessionStorage.removeItem("chamo-demo-authenticated");
  }

  function storeResponse(data) {
    if (typeof data.csrfToken === "string") {
      csrfToken = data.csrfToken;
      sessionStorage.setItem("chamo-csrf-token", csrfToken);
    }
    if (data.user) {
      setUser(data.user);
      sessionStorage.setItem("chamo-demo-authenticated", "true");
    }
    if (data.profile) setUser(data.profile);
  }

  async function send(action, options = {}) {
    const method = options.method || "GET";
    const publicActions = ["login", "register", "password-reset-request", "password-reset"];
    const headers = { Accept: "application/json", ...(options.headers || {}) };
    let body;
    if (options.body instanceof FormData) {
      body = options.body;
    } else if (options.body !== undefined) {
      headers["Content-Type"] = "application/json";
      body = JSON.stringify(options.body);
    }
    if (!["GET", "HEAD"].includes(method) && !publicActions.includes(action)) {
      if (!csrfToken) {
        const sessionResponse = await fetch(`${apiUrl}?action=session`, {
          credentials: "same-origin",
          headers: { Accept: "application/json" }
        });
        const sessionData = await sessionResponse.json();
        if (!sessionResponse.ok) {
          const error = new Error(sessionData.error || "Sua sessão expirou.");
          error.status = sessionResponse.status;
          throw error;
        }
        storeResponse(sessionData);
      }
      headers["X-CSRF-Token"] = csrfToken;
    }

    const query = new URLSearchParams({ action });
    if (options.id) query.set("id", options.id);
    const response = await fetch(`${apiUrl}?${query}`, {
      method,
      credentials: "same-origin",
      headers,
      body
    });
    const data = await response.json();
    if (!response.ok) {
      if (response.status === 401 && action !== "login") clearSession();
      const error = new Error(data.error || "Não foi possível concluir a solicitação.");
      error.status = response.status;
      throw error;
    }
    storeResponse(data);
    return data;
  }

  window.ChamoApi = {
    request: send,
    async session() {
      try {
        const data = await send("session");
        return data.user;
      } catch (error) {
        if (error instanceof Error && error.status === 401) return null;
        throw error;
      }
    },
    async login(email, password) {
      const data = await send("login", { method: "POST", body: { email, password } });
      return data.user;
    },
    async register(account) {
      return send("register", { method: "POST", body: account });
    },
    async logout() {
      await send("logout", { method: "POST" });
      clearSession();
    },
    async profile() {
      return (await send("profile")).profile;
    },
    async saveProfile(profile) {
      return (await send("profile", { method: "PUT", body: profile })).profile;
    },
    async tickets() {
      return (await send("tickets")).tickets;
    },
    async drafts() {
      return (await send("drafts")).drafts;
    },
    async saveDraft(draft) {
      return (await send("drafts", { method: "POST", body: draft })).draft;
    },
    async removeDraft(id) {
      await send("drafts", { method: "DELETE", id });
    },
    async createTicket(formData) {
      return (await send("tickets", { method: "POST", body: formData })).ticket;
    },
    async claimTicket(id) {
      return (await send("claim", { method: "POST", id, body: {} })).ticket;
    },
    async sendMessage(id, message) {
      return (await send("message", { method: "POST", id, body: { message } })).ticket;
    },
    async resolveTicket(id) {
      return (await send("resolve", { method: "POST", id, body: {} })).ticket;
    },
    async rateTicket(id, rating, comment) {
      return (await send("rate", { method: "POST", id, body: { rating, comment } })).ticket;
    },
    clearSession
  };
})();
