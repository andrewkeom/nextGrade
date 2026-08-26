const profileForm = document.getElementById("profile-form");
const nameInput = document.getElementById("name");
const emailInput = document.getElementById("email");

const passwordForm = document.getElementById("change-password-form");
const currentPasswordInput = document.getElementById("currentPassword");
const newPasswordInput = document.getElementById("newPassword");
const confirmNewPasswordInput = document.getElementById("confirmNewPassword");

const childGrid = document.getElementById("child-grid");
const childCardTemplate = document.getElementById("child-card-template");
const addChildBtn = document.getElementById("add-child-btn");
const logoutBtn = document.getElementById("logout-btn");

let cachedGrades = null;

function getGrades() {
  if (cachedGrades) {
    return Promise.resolve(cachedGrades);
  }
  return listGrades().then((grades) => {
    cachedGrades = grades;
    return grades;
  });
}

function buildGradeSelect(grades, selectedId) {
  const select = document.createElement("select");
  grades.forEach((g) => {
    const option = document.createElement("option");
    option.value = g.id;
    option.textContent = g.name;
    if (selectedId && g.id === selectedId) {
      option.selected = true;
    }
    select.appendChild(option);
  });
  return select;
}

function fillProfileForm() {
  const user = getState().user;
  if (user) {
    nameInput.value = user.name;
    emailInput.value = user.email;
  }
}

function handleProfileSubmit(event) {
  event.preventDefault();
  updateProfile(nameInput.value.trim(), emailInput.value.trim())
    .then(() => showMessage("Profile updated."))
    .catch((err) =>
      showMessage(err.response?.data?.error || "Failed to update profile."),
    );
}

function handlePasswordSubmit(event) {
  event.preventDefault();
  changePassword(
    currentPasswordInput.value,
    newPasswordInput.value,
    confirmNewPasswordInput.value,
  )
    .then(() => {
      showMessage("Password updated.");
      passwordForm.reset();
    })
    .catch((err) =>
      showMessage(err.response?.data?.error || "Failed to update password."),
    );
}

function loadChildren() {
  listChildren()
    .then((children) => {
      childGrid.innerHTML = "";
      children.forEach((child) =>
        childGrid.appendChild(renderChildCard(child)),
      );
    })
    .catch(() => showMessage("Failed to load children."));
}

function renderChildCard(child) {
  const card = childCardTemplate.content.cloneNode(true);
  const wrapper = card.querySelector("div");
  const paragraphs = card.querySelectorAll("p");
  paragraphs[0].textContent = child.name;
  paragraphs[1].textContent = child.grade_level_name;

  const buttons = card.querySelectorAll("button");
  buttons[0].addEventListener("click", () => showEditChildForm(wrapper, child));
  buttons[1].addEventListener("click", () => handleRemoveChild(child.id));

  return card;
}

function showEditChildForm(cardElement, child) {
  getGrades().then((grades) => {
    cardElement.innerHTML = "";
    cardElement.classList.add("form-narrow");

    const nameField = document.createElement("input");
    nameField.type = "text";
    nameField.value = child.name;

    const gradeSelect = buildGradeSelect(grades, child.grade_level_id);

    const actions = document.createElement("div");
    actions.className = "card-actions";

    const saveBtn = document.createElement("button");
    saveBtn.type = "button";
    saveBtn.textContent = "Save";
    saveBtn.addEventListener("click", () => {
      updateChild(child.id, nameField.value.trim(), Number(gradeSelect.value))
        .then(loadChildren)
        .catch((err) =>
          showMessage(err.response?.data?.error || "Failed to update child."),
        );
    });

    const cancelBtn = document.createElement("button");
    cancelBtn.type = "button";
    cancelBtn.textContent = "Cancel";
    cancelBtn.addEventListener("click", loadChildren);

    actions.append(saveBtn, cancelBtn);
    cardElement.append(nameField, gradeSelect, actions);
  });
}

function handleRemoveChild(childId) {
  if (!confirm("Remove this child?")) {
    return;
  }

  deleteChild(childId)
    .then(loadChildren)
    .catch((err) =>
      showMessage(err.response?.data?.error || "Failed to remove child."),
    );
}

function handleAddChildClick() {
  getGrades().then((grades) => {
    const wrapper = document.createElement("div");
    wrapper.className = "card form-narrow";

    const nameField = document.createElement("input");
    nameField.type = "text";
    nameField.placeholder = "Child's name";

    const gradeSelect = buildGradeSelect(grades, null);

    const actions = document.createElement("div");
    actions.className = "card-actions";

    const saveBtn = document.createElement("button");
    saveBtn.type = "button";
    saveBtn.textContent = "Save";
    saveBtn.addEventListener("click", () => {
      addChild(nameField.value.trim(), Number(gradeSelect.value))
        .then(loadChildren)
        .catch((err) =>
          showMessage(err.response?.data?.error || "Failed to add child."),
        );
    });

    const cancelBtn = document.createElement("button");
    cancelBtn.type = "button";
    cancelBtn.textContent = "Cancel";
    cancelBtn.addEventListener("click", () => wrapper.remove());

    actions.append(saveBtn, cancelBtn);
    wrapper.append(nameField, gradeSelect, actions);
    childGrid.appendChild(wrapper);
  });
}

function handleLogoutClick() {
  logout().then(() => {
    window.location.href = "/client/pages/login.html";
  });
}

fillProfileForm();
loadChildren();

profileForm.addEventListener("submit", handleProfileSubmit);
passwordForm.addEventListener("submit", handlePasswordSubmit);
addChildBtn.addEventListener("click", handleAddChildClick);
logoutBtn.addEventListener("click", handleLogoutClick);
