const statusFilterSelect = document.getElementById("statusFilter");
const grid = document.getElementById("my-listings-grid");
const cardTemplate = document.getElementById("my-listing-card-template");
const emptyState = cardTemplate.nextElementSibling;

function renderListings(listings) {
  grid.innerHTML = "";
  emptyState.hidden = listings.length > 0;

  listings.forEach((listing) => {
    const card = cardTemplate.content.cloneNode(true);
    card.querySelector("h3").textContent = `${listing.title}, ${listing.author}`;

    const paragraphs = card.querySelectorAll("p");
    paragraphs[0].querySelector("span").textContent = listing.condition;
    paragraphs[1].querySelector("span").textContent =
      listing.listing_type.charAt(0).toUpperCase() + listing.listing_type.slice(1);
    paragraphs[2].querySelector("span").textContent =
      listing.asking_price !== null ? `${listing.asking_price}$` : "N/A";
    paragraphs[3].querySelector("span").textContent =
      listing.status.charAt(0).toUpperCase() + listing.status.slice(1);

    card.querySelector("a").href = `/client/pages/edit-listing.html?id=${listing.id}`;

    const buttons = card.querySelectorAll("button");
    buttons[1].addEventListener("click", () => handleMarkSold(listing.id));
    buttons[2].addEventListener("click", () => handleDelete(listing.id));

    grid.appendChild(card);
  });
}

function loadListings() {
  listMyListings(statusFilterSelect.value)
    .then(renderListings)
    .catch(() => showMessage("Failed to load listings."));
}

function handleMarkSold(id) {
  markSold(id)
    .then(loadListings)
    .catch((err) => showMessage(err.response?.data?.error || "Failed to update listing."));
}

function handleDelete(id) {
  if (!confirm("Delete this listing?")) {
    return;
  }
  deleteListing(id)
    .then(loadListings)
    .catch((err) => showMessage(err.response?.data?.error || "Failed to delete listing."));
}

statusFilterSelect.addEventListener("change", loadListings);
loadListings();
