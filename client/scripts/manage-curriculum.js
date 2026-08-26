if (getState().user && getState().user.role !== "admin") {
  window.location.href = "/client/pages/dashboard.html";
}

const gradeLevelList = document.getElementById("grade-level-list");
const gradeLevelItemTemplate = document.getElementById("grade-level-item-template");
const addGradeLevelForm = document.getElementById("add-grade-level-form");
const gradeLevelNameInput = document.getElementById("gradeLevelName");
const academicYearInput = document.getElementById("academicYear");

const importHeadingSpan = document.querySelector("#import-curriculum-heading span");
const importCurriculumForm = document.getElementById("import-curriculum-form");
const curriculumImageInput = document.getElementById("curriculumImage");
const extractCurriculumBtn = document.getElementById("extract-curriculum-btn");
const curriculumPreview = document.getElementById("curriculum-preview");
const curriculumPreviewCount = document.getElementById("curriculum-preview-count");
const curriculumPreviewBody = document.getElementById("curriculum-preview-body");
const curriculumPreviewRowTemplate = document.getElementById("curriculum-preview-row-template");
const curriculumSelectAll = document.getElementById("curriculum-select-all");
const importConfirmBtn = document.getElementById("import-curriculum-confirm-btn");
const importCancelBtn = document.getElementById("import-curriculum-cancel-btn");

const subjectHeadingSpan = document.querySelector("#subjects-heading span");
const subjectList = document.getElementById("subject-list");
const subjectItemTemplate = document.getElementById("subject-item-template");
const addSubjectForm = document.getElementById("add-subject-form");
const subjectNameInput = document.getElementById("subjectName");

const bookHeadingSpan = document.querySelector("#books-heading span");
const bookList = document.getElementById("book-list");
const bookItemTemplate = document.getElementById("book-item-template");
const addBookForm = document.getElementById("add-book-form");
const bookTitleInput = document.getElementById("bookTitle");
const bookAuthorInput = document.getElementById("bookAuthor");
const bookEditionInput = document.getElementById("bookEdition");
const bookIsbnInput = document.getElementById("bookIsbn");
const bookReferencePriceInput = document.getElementById("bookReferencePrice");
const bookCoverImageInput = document.getElementById("bookCoverImage");

let selectedGradeLevel = null;
let selectedSubject = null;

let editingGradeLevelId = null;
let editingSubjectId = null;
let editingBookId = null;

function loadGradeLevels() {
  listGrades().then(renderGradeLevels).catch(() => showMessage("Failed to load grade levels."));
}

function renderGradeLevels(grades) {
  gradeLevelList.innerHTML = "";
  grades.forEach((grade) => {
    const item = gradeLevelItemTemplate.content.cloneNode(true);
    item.querySelector("p").textContent = `${grade.name} (${grade.academic_year})`;

    const buttons = item.querySelectorAll("button");
    buttons[0].addEventListener("click", () => selectGradeLevel(grade));
    buttons[1].addEventListener("click", () => startEditGradeLevel(grade));
    buttons[2].addEventListener("click", () => handleDeleteGradeLevel(grade.id));

    gradeLevelList.appendChild(item);
  });
}

function selectGradeLevel(grade) {
  selectedGradeLevel = grade;
  selectedSubject = null;
  importHeadingSpan.textContent = `for ${grade.name}`;
  subjectHeadingSpan.textContent = `for ${grade.name}`;
  bookHeadingSpan.textContent = "for (select a subject)";
  bookList.innerHTML = "";
  handleImportCurriculumCancel();
  loadSubjects();
}

function startEditGradeLevel(grade) {
  editingGradeLevelId = grade.id;
  gradeLevelNameInput.value = grade.name;
  academicYearInput.value = grade.academic_year;
  addGradeLevelForm.querySelector("button").textContent = "Save Changes";
}

function resetGradeLevelForm() {
  editingGradeLevelId = null;
  addGradeLevelForm.reset();
  addGradeLevelForm.querySelector("button").textContent = "Add Grade Level";
}

function handleGradeLevelSubmit(event) {
  event.preventDefault();

  const name = gradeLevelNameInput.value.trim();
  const academicYear = academicYearInput.value.trim();
  if (name === "" || academicYear === "") {
    return;
  }

  const request = editingGradeLevelId
    ? updateGradeLevel(editingGradeLevelId, name, academicYear)
    : addGradeLevel(name, academicYear);

  request
    .then(() => {
      resetGradeLevelForm();
      loadGradeLevels();
    })
    .catch((err) => showMessage(err.response?.data?.error || "Failed to save grade level."));
}

function handleDeleteGradeLevel(id) {
  if (!confirm("Delete this grade level?")) {
    return;
  }

  deleteGradeLevel(id)
    .then(() => {
      if (selectedGradeLevel && selectedGradeLevel.id === id) {
        selectedGradeLevel = null;
        selectedSubject = null;
        subjectList.innerHTML = "";
        bookList.innerHTML = "";
      }
      loadGradeLevels();
    })
    .catch((err) => showMessage(err.response?.data?.error || "Failed to delete grade level."));
}

function handleImportCurriculumSubmit(event) {
  event.preventDefault();

  if (!selectedGradeLevel) {
    showMessage("Select a grade level first.");
    return;
  }
  if (!curriculumImageInput.files[0]) {
    showMessage("Choose a photo first.");
    return;
  }
  if (
    curriculumPreviewBody.children.length > 0 &&
    !confirm("Replace the current preview? Unsaved edits will be lost.")
  ) {
    return;
  }

  const formData = new FormData();
  formData.append("grade_level_id", selectedGradeLevel.id);
  formData.append("curriculumImage", curriculumImageInput.files[0]);

  extractCurriculumBtn.disabled = true;
  extractCurriculumBtn.textContent = "Extracting...";

  extractCurriculum(formData)
    .then(renderCurriculumPreview)
    .catch((err) => showMessage(err.response?.data?.error || "Failed to extract curriculum from photo."))
    .finally(() => {
      extractCurriculumBtn.disabled = false;
      extractCurriculumBtn.textContent = "Extract from Photo";
    });
}

function renderCurriculumPreview(rows) {
  curriculumPreviewBody.innerHTML = "";

  rows.forEach((row) => {
    const item = curriculumPreviewRowTemplate.content.cloneNode(true);
    item.querySelector(".row-subject").value = row.subject || "";
    item.querySelector(".row-title").value = row.title || "";
    item.querySelector(".row-author").value = row.author || "";
    item.querySelector(".row-edition").value = row.edition || "";
    item.querySelector(".row-isbn").value = row.isbn || "";
    item.querySelector(".row-price").value = row.reference_price ?? 0;
    item.querySelector(".row-note").textContent = row.note || "";
    item.querySelector(".row-remove").addEventListener("click", (event) => {
      event.target.closest("tr").remove();
    });
    curriculumPreviewBody.appendChild(item);
  });

  curriculumPreviewCount.textContent = `${rows.length} rows extracted — review and edit before importing.`;
  curriculumSelectAll.checked = true;
  curriculumPreview.hidden = false;
}

function handleCurriculumSelectAll() {
  curriculumPreviewBody.querySelectorAll(".row-select").forEach((checkbox) => {
    checkbox.checked = curriculumSelectAll.checked;
  });
}

function handleImportCurriculumConfirm() {
  const rows = Array.from(curriculumPreviewBody.querySelectorAll("tr"))
    .filter((row) => row.querySelector(".row-select").checked)
    .map((row) => ({
      subject: row.querySelector(".row-subject").value.trim(),
      title: row.querySelector(".row-title").value.trim(),
      author: row.querySelector(".row-author").value.trim(),
      edition: row.querySelector(".row-edition").value.trim(),
      isbn: row.querySelector(".row-isbn").value.trim(),
      reference_price: row.querySelector(".row-price").value,
    }));

  if (rows.length === 0) {
    showMessage("Select at least one row to import.");
    return;
  }
  if (rows.some((row) => !row.subject || !row.title)) {
    showMessage("Each selected row needs a subject and title.");
    return;
  }

  importConfirmBtn.disabled = true;
  importConfirmBtn.textContent = "Importing...";

  importCurriculum(selectedGradeLevel.id, rows)
    .then((result) => {
      showMessage(
        `Imported ${result.subjects_created} new subject(s) and ${result.books_created} book(s).`
      );
      handleImportCurriculumCancel();
      loadSubjects();
    })
    .catch((err) => showMessage(err.response?.data?.error || "Failed to import curriculum."))
    .finally(() => {
      importConfirmBtn.disabled = false;
      importConfirmBtn.textContent = "Import Selected Rows";
    });
}

function handleImportCurriculumCancel() {
  curriculumPreview.hidden = true;
  curriculumPreviewBody.innerHTML = "";
  curriculumImageInput.value = "";
}

function loadSubjects() {
  if (!selectedGradeLevel) {
    return;
  }
  listSubjectsByGrade(selectedGradeLevel.id)
    .then(renderSubjects)
    .catch(() => showMessage("Failed to load subjects."));
}

function renderSubjects(subjects) {
  subjectList.innerHTML = "";
  subjects.forEach((subject) => {
    const item = subjectItemTemplate.content.cloneNode(true);
    item.querySelector("p").textContent = subject.name;

    const buttons = item.querySelectorAll("button");
    buttons[0].addEventListener("click", () => selectSubject(subject));
    buttons[1].addEventListener("click", () => startEditSubject(subject));
    buttons[2].addEventListener("click", () => handleDeleteSubject(subject.id));

    subjectList.appendChild(item);
  });
}

function selectSubject(subject) {
  selectedSubject = subject;
  bookHeadingSpan.textContent = `for ${subject.name}`;
  loadBooks();
}

function startEditSubject(subject) {
  editingSubjectId = subject.id;
  subjectNameInput.value = subject.name;
  addSubjectForm.querySelector("button").textContent = "Save Changes";
}

function resetSubjectForm() {
  editingSubjectId = null;
  addSubjectForm.reset();
  addSubjectForm.querySelector("button").textContent = "Add Subject";
}

function handleSubjectSubmit(event) {
  event.preventDefault();

  if (!editingSubjectId && !selectedGradeLevel) {
    showMessage("Select a grade level first.");
    return;
  }

  const name = subjectNameInput.value.trim();
  if (name === "") {
    return;
  }

  const request = editingSubjectId
    ? updateSubject(editingSubjectId, name)
    : addSubject(selectedGradeLevel.id, name);

  request
    .then(() => {
      resetSubjectForm();
      loadSubjects();
    })
    .catch((err) => showMessage(err.response?.data?.error || "Failed to save subject."));
}

function handleDeleteSubject(id) {
  if (!confirm("Delete this subject?")) {
    return;
  }

  deleteSubject(id)
    .then(() => {
      if (selectedSubject && selectedSubject.id === id) {
        selectedSubject = null;
        bookList.innerHTML = "";
      }
      loadSubjects();
    })
    .catch((err) => showMessage(err.response?.data?.error || "Failed to delete subject."));
}

function loadBooks() {
  if (!selectedSubject) {
    return;
  }
  listBooksBySubject(selectedSubject.id).then(renderBooks).catch(() => showMessage("Failed to load books."));
}

function renderBooks(books) {
  bookList.innerHTML = "";
  books.forEach((book) => {
    const item = bookItemTemplate.content.cloneNode(true);

    const coverImg = item.querySelector(".book-cover-thumb");
    if (book.cover_image) {
      coverImg.src = ORIGIN + book.cover_image;
      coverImg.alt = book.title;
      coverImg.hidden = false;
    }

    const paragraphs = item.querySelectorAll("p");
    paragraphs[0].textContent = book.author ? `${book.title}, ${book.author}` : book.title;
    paragraphs[1].querySelector("span").textContent = book.edition || "N/A";
    paragraphs[2].querySelector("span").textContent = book.isbn || "N/A";
    paragraphs[3].querySelector("span").textContent = `${book.reference_price}$`;

    const buttons = item.querySelectorAll("button");
    buttons[0].addEventListener("click", () => startEditBook(book));
    buttons[1].addEventListener("click", () => handleDeleteBook(book.id));

    bookList.appendChild(item);
  });
}

function startEditBook(book) {
  editingBookId = book.id;
  bookTitleInput.value = book.title;
  bookAuthorInput.value = book.author || "";
  bookEditionInput.value = book.edition || "";
  bookIsbnInput.value = book.isbn || "";
  bookReferencePriceInput.value = book.reference_price;
  addBookForm.querySelector("button").textContent = "Save Changes";
}

function resetBookForm() {
  editingBookId = null;
  addBookForm.reset();
  addBookForm.querySelector("button").textContent = "Add Book";
}

function handleBookSubmit(event) {
  event.preventDefault();

  if (!editingBookId && !selectedSubject) {
    showMessage("Select a subject first.");
    return;
  }

  const formData = new FormData();
  if (editingBookId) {
    formData.append("id", editingBookId);
  } else {
    formData.append("subject_id", selectedSubject.id);
  }
  formData.append("bookTitle", bookTitleInput.value.trim());
  formData.append("bookAuthor", bookAuthorInput.value.trim());
  formData.append("bookEdition", bookEditionInput.value.trim());
  formData.append("bookIsbn", bookIsbnInput.value.trim());
  formData.append("bookReferencePrice", bookReferencePriceInput.value);
  if (bookCoverImageInput.files[0]) {
    formData.append("bookCoverImage", bookCoverImageInput.files[0]);
  }

  const request = editingBookId ? updateBook(formData) : addBook(formData);

  request
    .then(() => {
      resetBookForm();
      loadBooks();
    })
    .catch((err) => showMessage(err.response?.data?.error || "Failed to save book."));
}

function handleDeleteBook(id) {
  if (!confirm("Delete this book?")) {
    return;
  }
  deleteBook(id)
    .then(loadBooks)
    .catch((err) => showMessage(err.response?.data?.error || "Failed to delete book."));
}

addGradeLevelForm.addEventListener("submit", handleGradeLevelSubmit);
importCurriculumForm.addEventListener("submit", handleImportCurriculumSubmit);
curriculumSelectAll.addEventListener("change", handleCurriculumSelectAll);
importConfirmBtn.addEventListener("click", handleImportCurriculumConfirm);
importCancelBtn.addEventListener("click", handleImportCurriculumCancel);
addSubjectForm.addEventListener("submit", handleSubjectSubmit);
addBookForm.addEventListener("submit", handleBookSubmit);

loadGradeLevels();
