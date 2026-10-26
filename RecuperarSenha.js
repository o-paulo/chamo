const resetForm = document.querySelector("#reset-form");
const resetFeedback = document.querySelector("#reset-feedback");
const resetToken = new URLSearchParams(window.location.search).get("token");
const resetHeading = document.querySelector("#reset-heading");
const resetDescription = document.querySelector("#reset-description");
const resetSubmit = document.querySelector("#reset-submit");
const emailField = document.querySelector("#email-field");
const passwordField = document.querySelector("#password-field");
const confirmField = document.querySelector("#confirm-field");

if (resetToken) {
  const cleanUrl = new URL(window.location.href);
  cleanUrl.searchParams.delete("token");
  window.history.replaceState({}, "", cleanUrl);
  emailField.hidden = true;
  passwordField.hidden = false;
  confirmField.hidden = false;
  resetForm.elements.namedItem("email").required = false;
  resetForm.elements.namedItem("password").required = true;
  resetForm.elements.namedItem("confirmPassword").required = true;
  resetHeading.textContent = "Definir nova senha";
  resetDescription.textContent = "Escolha uma senha com pelo menos 12 caracteres.";
  resetSubmit.textContent = "Alterar senha";
}

resetForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  resetFeedback.textContent = "";
  const submitButton = resetForm.querySelector("button[type=submit]");
  submitButton.disabled = true;
  try {
    if (resetToken) {
      const password = resetForm.elements.namedItem("password").value;
      const confirmation = resetForm.elements.namedItem("confirmPassword").value;
      if (password !== confirmation) {
        throw new Error("As senhas informadas não coincidem.");
      }
      const result = await window.ChamoApi.request("password-reset", {
        method: "POST",
        body: { token: resetToken, password }
      });
      resetFeedback.textContent = result.message;
      resetForm.reset();
      resetSubmit.textContent = "Senha alterada";
      resetSubmit.disabled = true;
      return;
    }

    const email = resetForm.elements.namedItem("email").value;
    const result = await window.ChamoApi.request("password-reset-request", {
      method: "POST",
      body: { email }
    });
    resetFeedback.textContent = result.message;
    resetForm.reset();
  } catch (error) {
    console.error("Não foi possível redefinir a senha.", error);
    resetFeedback.textContent = error instanceof Error ? error.message : "Não foi possível concluir a solicitação.";
  } finally {
    if (!resetToken || !resetFeedback.textContent.startsWith("Senha alterada")) {
      submitButton.disabled = false;
    }
  }
});
