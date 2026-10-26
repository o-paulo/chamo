(() => {
  const currentPath = window.location.pathname.toLocaleLowerCase("pt-BR");
  const isRolePortal = currentPath.endsWith("/moduloacesso.html");
  const isRoleSettings = currentPath.endsWith("/configuracoesacesso.html");

  window.ChamoApi.session().then((session) => {
    if (!session) {
      window.location.replace("TelaLogin.html");
      return;
    }

    const role = session.role;
    if (isRoleSettings && role === "user") {
      window.location.replace("TelaConfiguracoes.html");
      return;
    }
    if (isRolePortal && role === "user") {
      window.location.replace("TelaInicial.html");
      return;
    }
    if (!isRolePortal && !isRoleSettings && role !== "user") {
      window.location.replace("ModuloAcesso.html");
    }
  }).catch((error) => {
    console.error("Não foi possível validar a sessão no backend.", error);
    window.location.replace("TelaLogin.html?error=backend");
  });
})();
