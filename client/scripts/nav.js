const profileLink = document.getElementById("profile");
const loginLink = document.getElementById("login");
const signupLink = document.getElementById("signup");
const adminPanelLink = document.getElementById("admin-panel");

if (isLoggedIn()) {
  loginLink.hidden = true;
  signupLink.hidden = true;
  if (adminPanelLink && getState().user && getState().user.role === "admin") {
    adminPanelLink.hidden = false;
  }
} else {
  profileLink.hidden = true;
}
