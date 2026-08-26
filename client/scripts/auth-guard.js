function requireAuth() {
  if (!isLoggedIn()) {
    window.location.href = "/client/pages/login.html";
    return;
  }

  checkSession()
    .then((data) => {
      if (!data.logged_in) {
        clearState();
        window.location.href = "/client/pages/login.html";
      } else {
        setState({ user: data.user });
      }
    })
    .catch(() => {
      window.location.href = "/client/pages/login.html";
    });
}

requireAuth();
