const newTicketForm = document.querySelector("#new-ticket-form");
const ticketFeedback = document.querySelector("#form-feedback");
const classificationError = document.querySelector("#classification-error");
const severityError = document.querySelector("#severity-error");
const descriptionInput = document.querySelector("#ticket-description");
const descriptionCount = document.querySelector("#description-count");
const attachmentInput = document.querySelector("#ticket-attachment");
const attachmentPreview = document.querySelector("#attachment-preview");
const attachmentThumbnail = document.querySelector("#attachment-thumbnail");
const attachmentName = document.querySelector("#attachment-name");
const attachmentSize = document.querySelector("#attachment-size");
const attachmentError = document.querySelector("#attachment-error");
const attachmentDropzone = document.querySelector(".attachment-dropzone");
const maxAttachmentSize = 8 * 1024 * 1024;
let currentDraftId = new URLSearchParams(window.location.search).get("draftId");
let attachmentPreviewUrl = "";

function getSelectedValue(name) {
  return newTicketForm.querySelector(`input[name="${name}"]:checked`)?.value ?? "";
}

function setFeedback(message, isError = false) {
  ticketFeedback.textContent = message;
  ticketFeedback.classList.toggle("is-error", isError);
}

function updateChoiceLabel(input) {
  const group = input.name;
  const label = document.querySelector(`[data-choice-label="${group === "errorType" ? "error-type" : group}"]`);
  if (label) label.textContent = input.value;
}

for (const input of newTicketForm.querySelectorAll('.choice-options input[type="radio"]')) {
  input.addEventListener("change", () => {
    updateChoiceLabel(input);
    input.closest(".choice-picker").open = false;
    classificationError.textContent = "";
    setFeedback("");
  });
}

for (const input of newTicketForm.querySelectorAll('input[name="severity"]')) {
  input.addEventListener("change", () => {
    severityError.textContent = "";
    setFeedback("");
  });
}

descriptionInput.addEventListener("input", () => {
  descriptionCount.textContent = String(descriptionInput.value.length);
});

function clearAttachment() {
  if (attachmentPreviewUrl) URL.revokeObjectURL(attachmentPreviewUrl);
  attachmentPreviewUrl = "";
  attachmentInput.value = "";
  attachmentThumbnail.removeAttribute("src");
  attachmentName.textContent = "";
  attachmentSize.textContent = "";
  attachmentPreview.hidden = true;
  attachmentError.textContent = "";
}

function showAttachment(file) {
  attachmentError.textContent = "";
  if (!file) {
    clearAttachment();
    return;
  }

  if (!file.type.startsWith("image/")) {
    clearAttachment();
    attachmentError.textContent = "Selecione um arquivo de imagem.";
    return;
  }

  if (file.size > maxAttachmentSize) {
    clearAttachment();
    attachmentError.textContent = "A imagem deve ter no máximo 8 MB.";
    return;
  }

  if (attachmentPreviewUrl) URL.revokeObjectURL(attachmentPreviewUrl);
  attachmentPreviewUrl = URL.createObjectURL(file);
  attachmentThumbnail.src = attachmentPreviewUrl;
  attachmentName.textContent = file.name;
  attachmentSize.textContent = `${(file.size / (1024 * 1024)).toFixed(2)} MB`;
  attachmentPreview.hidden = false;
}

attachmentInput.addEventListener("change", () => showAttachment(attachmentInput.files[0]));
document.querySelector("#remove-attachment").addEventListener("click", clearAttachment);

for (const eventName of ["dragenter", "dragover"]) {
  attachmentDropzone.addEventListener(eventName, (event) => {
    event.preventDefault();
    attachmentDropzone.classList.add("is-dragover");
  });
}

for (const eventName of ["dragleave", "drop"]) {
  attachmentDropzone.addEventListener(eventName, (event) => {
    event.preventDefault();
    attachmentDropzone.classList.remove("is-dragover");
  });
}

attachmentDropzone.addEventListener("drop", (event) => {
  const [file] = event.dataTransfer.files;
  if (!file) return;

  const transfer = new DataTransfer();
  transfer.items.add(file);
  attachmentInput.files = transfer.files;
  showAttachment(file);
});

function getDraftData() {
  const values = Object.fromEntries(new FormData(newTicketForm).entries());
  delete values.attachment;
  return {
    values,
    savedAt: new Date().toISOString()
  };
}

async function restoreDraft() {
  try {
    if (!currentDraftId) return;
    const parsed = (await window.ChamoDrafts.list()).find((draft) => draft.id === currentDraftId);
    if (!parsed) {
      setFeedback("Não foi possível encontrar este rascunho. Ele pode ter sido removido.", true);
      return;
    }
    for (const [name, value] of Object.entries(parsed.values ?? {})) {
      const field = newTicketForm.elements.namedItem(name);
      if (!field) continue;
      if (field instanceof RadioNodeList) {
        const option = Array.from(field).find((item) => item.value === value);
        if (option) {
          option.checked = true;
          updateChoiceLabel(option);
        }
      } else if (field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement) {
        field.value = value;
      }
    }
    descriptionCount.textContent = String(descriptionInput.value.length);
    setFeedback("Rascunho restaurado. Anexe novamente a imagem, se necessário.");
  } catch (error) {
    console.error("Não foi possível restaurar o rascunho do ticket.", error);
    setFeedback("Não foi possível restaurar o rascunho salvo.", true);
  }
}

document.querySelector("#save-draft").addEventListener("click", async () => {
  try {
    const savedDraft = await window.ChamoDrafts.save({ ...getDraftData(), id: currentDraftId });
    currentDraftId = savedDraft.id;
    const draftUrl = new URL(window.location.href);
    draftUrl.searchParams.set("draftId", currentDraftId);
    window.history.replaceState({}, "", draftUrl);
    const attachmentNotice = attachmentInput.files.length
      ? " O arquivo de imagem não é incluído no rascunho; anexe-o novamente ao retomar."
      : "";
    setFeedback(`Rascunho salvo neste navegador. Acesse Meus Tickets > Rascunhos para encontrá-lo.${attachmentNotice}`);
  } catch (error) {
    console.error("Não foi possível salvar o rascunho do ticket.", error);
    setFeedback("Não foi possível salvar o rascunho. Verifique o armazenamento do navegador.", true);
  }
});

newTicketForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  classificationError.textContent = "";
  severityError.textContent = "";
  setFeedback("");

  for (const field of [newTicketForm.elements.namedItem("title"), descriptionInput]) {
    if (!field.reportValidity()) return;
  }

  const missingPicker = Array.from(newTicketForm.querySelectorAll("[data-choice-picker]"))
    .find((picker) => !picker.querySelector('input[type="radio"]:checked'));
  if (missingPicker) {
    missingPicker.open = true;
    missingPicker.querySelector("summary").focus();
    classificationError.textContent = "Escolha uma opção em cada campo de classificação.";
    return;
  }

  if (!getSelectedValue("severity")) {
    severityError.textContent = "Selecione o nível de gravidade do problema.";
    newTicketForm.querySelector('input[name="severity"]').focus();
    return;
  }
  if (attachmentError.textContent) {
    attachmentInput.focus();
    setFeedback("Corrija o anexo antes de enviar o chamado.", true);
    return;
  }

  const submitButton = newTicketForm.querySelector(".submit-ticket-button");
  submitButton.disabled = true;
  setFeedback("Enviando ticket...");
  try {
    const ticket = await window.ChamoApi.createTicket(new FormData(newTicketForm));
    if (currentDraftId) {
      try {
        await window.ChamoDrafts.remove(currentDraftId);
      } catch (draftError) {
        console.error("O ticket foi criado, mas o rascunho não foi removido do servidor.", draftError);
      }
    }
    window.location.assign(`TelaTickets.html?ticket=${encodeURIComponent(ticket.id)}`);
  } catch (error) {
    console.error("Não foi possível enviar o ticket.", error);
    setFeedback(error instanceof Error ? error.message : "Não foi possível enviar o ticket.", true);
  } finally {
    submitButton.disabled = false;
  }
});

restoreDraft();
