if (isLoggedIn()) {
  window.location.href = "/client/pages/dashboard.html";
}

const form = document.getElementById("login-form");
const emailInput = document.getElementById("email");
const passwordInput = document.getElementById("password");
const errorMessage = document.getElementById("error-message");

function handleLoginSubmit(event) {
  event.preventDefault();
  errorMessage.hidden = true;

  login(emailInput.value.trim(), passwordInput.value)
    .then(() => {
      window.location.href = "/client/pages/dashboard.html";
    })
    .catch((err) => {
      errorMessage.textContent =
        err.response?.data?.error || "Login failed. Please try again.";
      errorMessage.hidden = false;
    });
}

form.addEventListener("submit", handleLoginSubmit);
