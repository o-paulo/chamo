const loginForm = document.querySelector("#login-form");
const loginFeedback = document.querySelector("#login-feedback");
const signupForm = document.querySelector("#signup-form");
const signupFeedback = document.querySelector("#signup-feedback");
const passwordInput = document.querySelector("#login-password");
const passwordToggle = document.querySelector(".password-toggle");
const signupEmail = signupForm.querySelector('[name="email"]');
const signupDomainHint = document.querySelector("#signup-domain-hint");
const loginError = new URLSearchParams(window.location.search).get("error");

function showSignup(showSignupForm) {
  loginForm.hidden = showSignupForm;
  signupForm.hidden = !showSignupForm;
  loginFeedback.textContent = "";
  loginFeedback.classList.remove("login-feedback-success");
  signupFeedback.textContent = "";
  (showSignupForm ? signupForm : loginForm).querySelector("input").focus();
}

if (loginError === "backend") {
  loginFeedback.textContent = "Não foi possível conectar ao backend. Inicie o servidor PHP e tente novamente.";
}

document.querySelector("[data-show-signup]").addEventListener("click", () => showSignup(true));
document.querySelector("[data-show-login]").addEventListener("click", () => showSignup(false));

loginForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const submitButton = loginForm.querySelector(".login-submit");
  submitButton.disabled = true;
  loginFeedback.textContent = "";
  try {
    const formData = new FormData(loginForm);
    const account = await window.ChamoApi.login(
      formData.get("email"),
      formData.get("password")
    );
    window.location.assign(account.role === "user" ? "TelaInicial.html" : "ModuloAcesso.html");
  } catch (error) {
    console.error("Não foi possível autenticar a conta.", error);
    loginFeedback.textContent = error instanceof Error
      ? error.message
      : "Não foi possível realizar o login. Tente novamente.";
  } finally {
    submitButton.disabled = false;
  }
});

signupForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const submitButton = signupForm.querySelector(".login-submit");
  submitButton.disabled = true;
  signupFeedback.textContent = "";
  try {
    const formData = new FormData(signupForm);
    const result = await window.ChamoApi.register(Object.fromEntries(formData));
    signupForm.reset();
    showSignup(false);
    loginFeedback.textContent = result.message;
    loginFeedback.classList.toggle("login-feedback-success", true);
  } catch (error) {
    console.error("Não foi possível criar a conta.", error);
    signupFeedback.textContent = error instanceof Error
      ? error.message
      : "Não foi possível concluir o cadastro. Tente novamente.";
  } finally {
    submitButton.disabled = false;
  }
});

signupForm.querySelector('[name="phone"]').addEventListener("input", (event) => {
  event.currentTarget.value = event.currentTarget.value.replace(/\D/g, "").slice(0, 15);
});

signupEmail.addEventListener("input", () => {
  const email = signupEmail.value.trim().toLowerCase();
  signupDomainHint.textContent = email.endsWith("@chamo.sup.com")
    ? "Perfil de atendente: o acesso será liberado após autorização de um administrador."
    : email.endsWith("@chamo.adm.com")
      ? "Perfil de administrador: o acesso será liberado após autorização de um administrador."
      : email.endsWith("@chamo.com")
        ? "Perfil de usuário: você poderá entrar assim que concluir o cadastro."
        : "O domínio do e-mail define seu perfil: @chamo.com, @chamo.sup.com ou @chamo.adm.com.";
});

passwordToggle.addEventListener("click", () => {
  const shouldShowPassword = passwordInput.type === "password";
  passwordInput.type = shouldShowPassword ? "text" : "password";
  passwordToggle.setAttribute("aria-pressed", String(shouldShowPassword));
  passwordToggle.setAttribute(
    "aria-label",
    shouldShowPassword ? "Ocultar senha" : "Mostrar senha"
  );
});

for (const action of document.querySelectorAll("[data-login-message]")) {
  action.addEventListener("click", () => {
    loginFeedback.textContent = action.dataset.loginMessage;
  });
}

loginForm.addEventListener("input", () => {
  loginFeedback.textContent = "";
  loginFeedback.classList.remove("login-feedback-success");
});
