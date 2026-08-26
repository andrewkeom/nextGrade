const gradeSelect = document.getElementById("gradeLevel");
const subjectsContainer = document.getElementById("subjects-container");
const noSubjectsMessage = document.getElementById("no-subjects-message");
const subjectSectionTemplate = document.getElementById("subject-section-template");
const bookCardTemplate = document.getElementById("book-card-template");

function renderBookCard(book) {
  const card = bookCardTemplate.content.cloneNode(true);

  const coverImg = card.querySelector(".book-cover");
  if (book.cover_image) {
    coverImg.src = ORIGIN + book.cover_image;
    coverImg.alt = book.title;
    coverImg.hidden = false;
  }

  card.querySelector("h3").textContent = book.author
    ? `${book.title}, ${book.author}`
    : book.title;
  card.querySelector("p span").textContent = `${book.reference_price}$`;

  card.querySelector("a").href =
    "/client/pages/marketplace.html?q=" + encodeURIComponent(book.title);

  return card;
}

function renderSubjects(subjects) {
  subjectsContainer.innerHTML = "";
  noSubjectsMessage.hidden = subjects.length > 0;

  subjects.forEach((subject) => {
    const section = subjectSectionTemplate.content.cloneNode(true);
    section.querySelector("h2").textContent = subject.name;
    const bookGrid = section.querySelector(".subject-book-grid");
    const noBooksMessage = section.querySelector(".no-books-message");

    subjectsContainer.appendChild(section);

    listBooksBySubject(subject.id)
      .then((books) => {
        noBooksMessage.hidden = books.length > 0;
        books.forEach((book) => bookGrid.appendChild(renderBookCard(book)));
      })
      .catch(() => showMessage("Failed to load books."));
  });
}

function loadSubjectsForGrade(gradeLevelId) {
  listSubjectsByGrade(gradeLevelId)
    .then(renderSubjects)
    .catch(() => showMessage("Failed to load subjects."));
}

function populateGradeOptions() {
  listGrades()
    .then((grades) => {
      grades.forEach((grade) => {
        const option = document.createElement("option");
        option.value = grade.id;
        option.textContent = grade.name;
        gradeSelect.appendChild(option);
      });

      if (gradeSelect.value) {
        loadSubjectsForGrade(gradeSelect.value);
      }
    })
    .catch(() => showMessage("Failed to load grade levels."));
}

gradeSelect.addEventListener("change", () => {
  loadSubjectsForGrade(gradeSelect.value);
});

populateGradeOptions();
