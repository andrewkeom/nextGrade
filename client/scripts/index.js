const heroButtons = document.querySelectorAll(".hero button");
const getStartedBtn = heroButtons[0];
const heroLoginBtn = heroButtons[1];

getStartedBtn.addEventListener("click", () => {
  window.location.href = isLoggedIn()
    ? "/client/pages/dashboard.html"
    : "/client/pages/signup.html";
});

heroLoginBtn.addEventListener("click", () => {
  window.location.href = isLoggedIn()
    ? "/client/pages/dashboard.html"
    : "/client/pages/login.html";
});

document.getElementById("cta-create-account").addEventListener("click", () => {
  window.location.href = isLoggedIn()
    ? "/client/pages/dashboard.html"
    : "/client/pages/signup.html";
});

if (isLoggedIn()) {
  getStartedBtn.textContent = "Go to Dashboard";
  heroLoginBtn.hidden = true;
}
