(() => {
  const storageKey = "chamo-ticket-drafts";
  const legacyStorageKey = "chamo-new-ticket-draft";
  const legacyIdKey = "chamo-legacy-draft-migration-id";

  function createId() {
    return `draft-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
  }

  function isDraft(value) {
    return value
      && typeof value === "object"
      && typeof value.id === "string"
      && value.values
      && typeof value.values === "object";
  }

  function readLocalDrafts() {
    const storedDrafts = localStorage.getItem(storageKey);
    if (storedDrafts !== null) {
      const drafts = JSON.parse(storedDrafts);
      if (!Array.isArray(drafts) || !drafts.every(isDraft)) {
        throw new Error("A lista de rascunhos salva está em um formato inválido.");
      }
      return drafts;
    }

    const legacyDraft = localStorage.getItem(legacyStorageKey);
    if (!legacyDraft) return [];

    const parsedDraft = JSON.parse(legacyDraft);
    if (!parsedDraft || typeof parsedDraft !== "object" || !parsedDraft.values) {
      throw new Error("O rascunho salvo está em um formato inválido.");
    }

    const id = localStorage.getItem(legacyIdKey) || createId();
    return [{ id, ...parsedDraft }];
  }

  async function migrateLocalDrafts() {
    const storedDrafts = localStorage.getItem(storageKey);
    const legacyDraft = localStorage.getItem(legacyStorageKey);
    if (storedDrafts === null && !legacyDraft) return;

    if (legacyDraft && !localStorage.getItem(legacyIdKey)) {
      localStorage.setItem(legacyIdKey, createId());
    }
    const drafts = readLocalDrafts();
    if (storedDrafts !== null && legacyDraft) {
      const parsedLegacy = JSON.parse(legacyDraft);
      if (!parsedLegacy || typeof parsedLegacy !== "object" || !parsedLegacy.values) {
        throw new Error("O rascunho salvo está em um formato inválido.");
      }
      const legacyId = localStorage.getItem(legacyIdKey);
      if (!drafts.some((draft) => draft.id === legacyId)) {
        drafts.push({ id: legacyId, ...parsedLegacy });
      }
    }
    if (storedDrafts === null) {
      localStorage.setItem(storageKey, JSON.stringify(drafts));
    }
    for (const draft of drafts) {
      await window.ChamoApi.saveDraft(draft);
    }
    localStorage.removeItem(storageKey);
    localStorage.removeItem(legacyStorageKey);
    localStorage.removeItem(legacyIdKey);
  }

  window.ChamoDrafts = {
    async list() {
      await migrateLocalDrafts();
      return (await window.ChamoApi.drafts())
        .sort((first, second) => (second.savedAt ?? "").localeCompare(first.savedAt ?? ""));
    },

    async save(draft) {
      await migrateLocalDrafts();
      const id = draft.id || createId();
      return window.ChamoApi.saveDraft({ ...draft, id });
    },

    async remove(id) {
      await migrateLocalDrafts();
      await window.ChamoApi.removeDraft(id);
    }
  };
})();
