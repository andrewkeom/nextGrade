const form = document.getElementById("create-listing-form");
const bookGradeLevelSelect = document.getElementById("bookGradeLevel");
const bookSubjectSelect = document.getElementById("bookSubject");
const bookSelectEl = document.getElementById("bookSelect");
const conditionSelect = document.getElementById("condition");
const askingPriceInput = document.getElementById("askingPrice");
const descriptionInput = document.getElementById("description");
const imagesInput = document.getElementById("listingImages");
const aiPricePreview = document.getElementById("ai-price-preview");
const aiSuggestedPriceSpan = aiPricePreview.querySelectorAll("span")[0];
const aiJustificationSpan = aiPricePreview.querySelectorAll("span")[1];

function populateGradeOptions() {
  listGrades().then((grades) => {
    grades.forEach((grade) => {
      const option = document.createElement("option");
      option.value = grade.id;
      option.textContent = grade.name;
      bookGradeLevelSelect.appendChild(option);
    });
  });
}

function handleGradeChange() {
  bookSubjectSelect.innerHTML = '<option value="">Select a subject</option>';
  bookSelectEl.innerHTML = '<option value="">Select a book</option>';
  if (!bookGradeLevelSelect.value) {
    return;
  }

  listSubjectsByGrade(bookGradeLevelSelect.value).then((subjects) => {
    subjects.forEach((subject) => {
      const option = document.createElement("option");
      option.value = subject.id;
      option.textContent = subject.name;
      bookSubjectSelect.appendChild(option);
    });
  });
}

function handleSubjectChange() {
  bookSelectEl.innerHTML = '<option value="">Select a book</option>';
  if (!bookSubjectSelect.value) {
    return;
  }

  listBooksBySubject(bookSubjectSelect.value).then((books) => {
    books.forEach((book) => {
      const option = document.createElement("option");
      option.value = book.id;
      option.textContent = `${book.title}, ${book.author}`;
      bookSelectEl.appendChild(option);
    });
  });
}

function updateAiPreview() {
  if (!bookSelectEl.value || !conditionSelect.value) {
    return;
  }

  suggestPrice(bookSelectEl.value, conditionSelect.value).then((data) => {
    aiSuggestedPriceSpan.textContent = `${data.suggested_price}$`;
    aiJustificationSpan.textContent = data.justification;
  });
}

function handleFormSubmit(event) {
  event.preventDefault();

  if (!bookSelectEl.value) {
    showMessage("Please select a book.");
    return;
  }

  const listingType = form.querySelector('input[name="listingType"]:checked').value;

  const formData = new FormData();
  formData.append("book_id", bookSelectEl.value);
  formData.append("condition", conditionSelect.value);
  formData.append("listingType", listingType);
  if (listingType === "sell") {
    formData.append("askingPrice", askingPriceInput.value);
  }
  formData.append("description", descriptionInput.value.trim());
  for (const file of imagesInput.files) {
    formData.append("listingImages", file);
  }

  createListing(formData)
    .then(() => {
      window.location.href = "/client/pages/my-listings.html";
    })
    .catch((err) => showMessage(err.response?.data?.error || "Failed to create listing."));
}

populateGradeOptions();

bookGradeLevelSelect.addEventListener("change", handleGradeChange);
bookSubjectSelect.addEventListener("change", handleSubjectChange);
bookSelectEl.addEventListener("change", updateAiPreview);
conditionSelect.addEventListener("change", updateAiPreview);
form.addEventListener("submit", handleFormSubmit);
