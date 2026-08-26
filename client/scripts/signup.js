if (isLoggedIn()) {
  window.location.href = "/client/pages/dashboard.html";
}

const form = document.querySelector("form");
const nameInput = document.getElementById("name");
const emailInput = document.getElementById("email");
const passwordInput = document.getElementById("password");
const confirmPasswordInput = document.getElementById("confirmPassword");
const childrenContainer = document.getElementById("children-container");
const addChildBtn = document.getElementById("add-child-btn");

const firstChildName = document.getElementById("childName");
const firstChildGrade = document.getElementById("gradeLevel");
firstChildName.classList.add("child-name-input");
firstChildGrade.classList.add("child-grade-select");

let childBlockHTML = null;

function populateGradeOptions() {
  listGrades()
    .then((grades) => {
      grades.forEach((grade) => {
        const option = document.createElement("option");
        option.value = grade.id;
        option.textContent = grade.name;
        firstChildGrade.appendChild(option);
      });

      childBlockHTML = (firstChildName.outerHTML + firstChildGrade.outerHTML)
        .replace(' id="childName"', "")
        .replace(' id="gradeLevel"', "");
    })
    .catch(() => showMessage("Failed to load grade levels."));
}

function handleAddChildClick() {
  if (!childBlockHTML) {
    return;
  }
  const wrapper = document.createElement("div");
  wrapper.className = "child-entry";
  wrapper.innerHTML = childBlockHTML;
  childrenContainer.appendChild(wrapper);
}

function collectChildren() {
  const names = document.querySelectorAll(".child-name-input");
  const grades = document.querySelectorAll(".child-grade-select");

  const children = [];
  for (let i = 0; i < names.length; i++) {
    const name = names[i].value.trim();
    if (name !== "") {
      children.push({ name: name, grade_level_id: Number(grades[i].value) });
    }
  }
  return children;
}

function handleSignupSubmit(event) {
  event.preventDefault();

  const password = passwordInput.value;
  const confirmPassword = confirmPasswordInput.value;
  if (password !== confirmPassword) {
    showMessage("Passwords don't match.");
    return;
  }

  signup(
    nameInput.value.trim(),
    emailInput.value.trim(),
    password,
    confirmPassword,
    collectChildren()
  )
    .then(() => {
      window.location.href = "/client/pages/dashboard.html";
    })
    .catch((err) => {
      showMessage(err.response?.data?.error || "Signup failed. Please try again.");
    });
}

populateGradeOptions();

addChildBtn.addEventListener("click", handleAddChildClick);
form.addEventListener("submit", handleSignupSubmit);
