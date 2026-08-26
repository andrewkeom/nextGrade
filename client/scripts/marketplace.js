const searchInput = document.getElementById("listing-search");
const listingGrid = document.getElementById("listing-grid");
const listingCardTemplate = document.getElementById("listing-card-template");
const emptyState = listingCardTemplate.nextElementSibling;

function renderListings(listings) {
  listingGrid.innerHTML = "";
  emptyState.hidden = listings.length > 0;

  listings.forEach((listing) => {
    const card = listingCardTemplate.content.cloneNode(true);

    const img = card.querySelector("img");
    if (listing.thumbnail) {
      img.src = ORIGIN + listing.thumbnail;
    } else if (listing.book_cover_image) {
      img.src = ORIGIN + listing.book_cover_image;
    }

    const h3 = card.querySelector("h3");
    h3.firstChild.textContent = `${listing.subject_name} - Grade `;
    h3.querySelector("span").textContent =
      listing.grade_level_name.match(/\d+/)[0];

    const paragraphs = card.querySelectorAll("p");
    paragraphs[0].textContent = `${listing.title}, ${listing.author}`;
    paragraphs[1].querySelector("span").textContent =
      `${listing.reference_price}$`;

    card.querySelector("div > span").textContent = listing.condition;

    const listingTypeLabel =
      listing.listing_type.charAt(0).toUpperCase() +
      listing.listing_type.slice(1);
    paragraphs[2].querySelector("span").textContent = listingTypeLabel;

    paragraphs[3].querySelector("span").textContent =
      listing.asking_price !== null ? `${listing.asking_price}$` : "N/A";
    paragraphs[4].querySelector("span").textContent =
      `${listing.ai_suggested_price}$`;

    const freeParagraph = paragraphs[5];
    const tradeParagraph = paragraphs[6];
    const badgeWrapper = freeParagraph.parentElement;
    if (listing.listing_type === "donate") {
      badgeWrapper.hidden = false;
      freeParagraph.hidden = false;
      tradeParagraph.hidden = true;
    } else if (listing.listing_type === "trade") {
      badgeWrapper.hidden = false;
      freeParagraph.hidden = true;
      tradeParagraph.hidden = false;
    } else {
      badgeWrapper.hidden = true;
    }

    paragraphs[7].textContent = listing.seller_name;

    card.querySelector("a").href =
      "/client/pages/listing.html?id=" + listing.id;

    listingGrid.appendChild(card);
  });
}

function performSearch() {
  listListings(searchInput.value.trim())
    .then(renderListings)
    .catch(() => showMessage("Failed to load listings."));
}

searchInput.addEventListener("input", performSearch);

const initialQuery = new URLSearchParams(window.location.search).get("q");
if (initialQuery) {
  searchInput.value = initialQuery;
}
performSearch();
