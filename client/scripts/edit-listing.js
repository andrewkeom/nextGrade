const form = document.getElementById("edit-listing-form");
const bookGradeLevelSelect = document.getElementById("bookGradeLevel");
const bookSubjectSelect = document.getElementById("bookSubject");
const bookSelectEl = document.getElementById("bookSelect");
const conditionSelect = document.getElementById("condition");
const askingPriceInput = document.getElementById("askingPrice");
const statusSelect = document.getElementById("status");
const descriptionInput = document.getElementById("description");
const imagesInput = document.getElementById("listingImages");
const aiPricePreview = document.getElementById("ai-price-preview");
const aiSuggestedPriceSpan = aiPricePreview.querySelectorAll("span")[0];
const aiJustificationSpan = aiPricePreview.querySelectorAll("span")[1];
const deleteBtn = document.getElementById("delete-listing-btn");

let currentListingId = null;

function addGradeOptions(grades) {
  grades.forEach((grade) => {
    const option = document.createElement("option");
    option.value = grade.id;
    option.textContent = grade.name;
    bookGradeLevelSelect.appendChild(option);
  });
}

function addSubjectOptions(subjects) {
  subjects.forEach((subject) => {
    const option = document.createElement("option");
    option.value = subject.id;
    option.textContent = subject.name;
    bookSubjectSelect.appendChild(option);
  });
}

function addBookOptions(books) {
  books.forEach((book) => {
    const option = document.createElement("option");
    option.value = book.id;
    option.textContent = `${book.title}, ${book.author}`;
    bookSelectEl.appendChild(option);
  });
}

function fillNonBookFields(listing) {
  conditionSelect.value = listing.condition;
  form.querySelector(`input[name="listingType"][value="${listing.listing_type}"]`).checked = true;
  if (listing.asking_price !== null) {
    askingPriceInput.value = listing.asking_price;
  }
  statusSelect.value = listing.status;
  descriptionInput.value = listing.description || "";
  aiSuggestedPriceSpan.textContent = `${listing.ai_suggested_price}$`;
  aiJustificationSpan.textContent = listing.ai_justification;
}

function loadAndPrefill() {
  const listingId = new URLSearchParams(window.location.search).get("id");
  if (!listingId) {
    showMessage("No listing specified.");
    return;
  }

  getListing(listingId)
    .then((listing) => {
      const user = getState().user;
      if (!user || listing.seller_id !== user.id) {
        showMessage("You don't have permission to edit this listing.");
        window.location.href = "/client/pages/my-listings.html";
        return;
      }

      currentListingId = listing.id;
      fillNonBookFields(listing);

      listGrades().then((grades) => {
        addGradeOptions(grades);
        bookGradeLevelSelect.value = listing.grade_level_id;

        listSubjectsByGrade(listing.grade_level_id).then((subjects) => {
          addSubjectOptions(subjects);
          bookSubjectSelect.value = listing.subject_id;

          listBooksBySubject(listing.subject_id).then((books) => {
            addBookOptions(books);
            bookSelectEl.value = listing.book_id;
          });
        });
      });
    })
    .catch(() => showMessage("Failed to load listing."));
}

function handleGradeChange() {
  bookSubjectSelect.innerHTML = '<option value="">Select a subject</option>';
  bookSelectEl.innerHTML = '<option value="">Select a book</option>';
  if (!bookGradeLevelSelect.value) {
    return;
  }
  listSubjectsByGrade(bookGradeLevelSelect.value).then(addSubjectOptions);
}

function handleSubjectChange() {
  bookSelectEl.innerHTML = '<option value="">Select a book</option>';
  if (!bookSubjectSelect.value) {
    return;
  }
  listBooksBySubject(bookSubjectSelect.value).then(addBookOptions);
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
  formData.append("id", currentListingId);
  formData.append("book_id", bookSelectEl.value);
  formData.append("condition", conditionSelect.value);
  formData.append("listingType", listingType);
  if (listingType === "sell") {
    formData.append("askingPrice", askingPriceInput.value);
  }
  formData.append("status", statusSelect.value);
  formData.append("description", descriptionInput.value.trim());
  for (const file of imagesInput.files) {
    formData.append("listingImages", file);
  }

  updateListing(formData)
    .then(() => {
      window.location.href = "/client/pages/my-listings.html";
    })
    .catch((err) => showMessage(err.response?.data?.error || "Failed to update listing."));
}

function handleDeleteClick() {
  if (!confirm("Delete this listing?")) {
    return;
  }
  deleteListing(currentListingId)
    .then(() => {
      window.location.href = "/client/pages/my-listings.html";
    })
    .catch((err) => showMessage(err.response?.data?.error || "Failed to delete listing."));
}

loadAndPrefill();

bookGradeLevelSelect.addEventListener("change", handleGradeChange);
bookSubjectSelect.addEventListener("change", handleSubjectChange);
bookSelectEl.addEventListener("change", updateAiPreview);
conditionSelect.addEventListener("change", updateAiPreview);
form.addEventListener("submit", handleFormSubmit);
deleteBtn.addEventListener("click", handleDeleteClick);
