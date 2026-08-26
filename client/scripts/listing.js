const mainImage = document.getElementById("listing-main-image");
const thumbnailsContainer = document.getElementById("listing-thumbnails");
const thumbnailTemplate = document.getElementById("thumbnail-template");

const titleH1 = document.querySelector("main h1");
const sections = document.querySelectorAll("main > section");
const gallerySection = sections[0];
const detailSection = sections[1];
const pricingSection = sections[2];
const sellerSection = sections[3];

const visitorActions = document.getElementById("visitor-actions");
const ownerActions = document.getElementById("owner-actions");
const editLink = ownerActions.querySelector("a");
const markSoldBtn = document.getElementById("mark-sold-btn");
const deleteBtn = document.getElementById("delete-listing-btn");

const messageForm = document.getElementById("message-seller-form");
const messageTextarea = messageForm.querySelector("textarea");

const reportBtn = document.getElementById("report-price-btn");
const reportReasonInput = document.getElementById("report-price-reason");

let currentListing = null;

function renderDetails(listing) {
  titleH1.textContent = `${listing.title}, ${listing.author}`;

  const detailParagraphs = detailSection.querySelectorAll("p");
  detailParagraphs[0].firstChild.textContent = `${listing.subject_name} - Grade `;
  detailParagraphs[0].querySelector("span").textContent =
    listing.grade_level_name.match(/\d+/)[0];
  detailParagraphs[1].querySelector("span").textContent =
    listing.edition || "N/A";
  detailParagraphs[2].querySelector("span").textContent =
    `${listing.reference_price}$`;
  detailParagraphs[3].querySelector("span").textContent = listing.condition;
  detailParagraphs[4].querySelector("span").textContent =
    listing.listing_type.charAt(0).toUpperCase() +
    listing.listing_type.slice(1);
  detailParagraphs[6].textContent =
    listing.description || "No description provided.";

  const pricingParagraphs = pricingSection.querySelectorAll("p");
  pricingParagraphs[0].querySelector("span").textContent =
    listing.asking_price !== null ? `${listing.asking_price}$` : "N/A";
  pricingParagraphs[1].querySelector("span").textContent =
    `${listing.ai_suggested_price}$`;
  pricingParagraphs[2].querySelector("span").textContent =
    listing.ai_justification;

  const sellerSpans = sellerSection.querySelectorAll("span");
  sellerSpans[0].textContent = listing.seller_name;
  sellerSpans[1].textContent = new Date(listing.created_at).toLocaleDateString(
    undefined,
    {
      year: "numeric",
      month: "short",
      day: "numeric",
    },
  );
}

function renderImages(images, bookCoverImage) {
  thumbnailsContainer.innerHTML = "";
  gallerySection.hidden = images.length === 0 && !bookCoverImage;

  if (images.length > 0) {
    mainImage.src = ORIGIN + images[0].image_path;
  } else if (bookCoverImage) {
    mainImage.src = ORIGIN + bookCoverImage;
  }

  images.forEach((image) => {
    const thumb = thumbnailTemplate.content.cloneNode(true);
    const imgEl = thumb.querySelector("img");
    imgEl.src = ORIGIN + image.image_path;
    imgEl.addEventListener("click", () => {
      mainImage.src = ORIGIN + image.image_path;
    });
    thumbnailsContainer.appendChild(imgEl);
  });
}

function toggleActionSections(listing) {
  const user = getState().user;
  const isOwner = user && listing.seller_id === user.id;

  ownerActions.hidden = !isOwner;
  visitorActions.hidden = !user || isOwner;
}

function handleMessageSubmit(event) {
  event.preventDefault();

  const content = messageTextarea.value.trim();
  if (content === "") {
    return;
  }

  sendMessage(currentListing.id, currentListing.seller_id, content)
    .then(() => {
      messageTextarea.value = "";
      showMessage("Message sent.");
    })
    .catch((err) =>
      showMessage(err.response?.data?.error || "Failed to send message."),
    );
}

function handleReportClick() {
  const reason = reportReasonInput.value.trim();

  reportPrice(currentListing.id, reason === "" ? null : reason)
    .then(() => {
      reportReasonInput.value = "";
      showMessage("Price reported. An admin will review it.");
    })
    .catch((err) =>
      showMessage(err.response?.data?.error || "Failed to report price."),
    );
}

function handleMarkSoldClick() {
  markSold(currentListing.id)
    .then(() => {
      showMessage("Listing marked as sold.");
      window.location.reload();
    })
    .catch((err) =>
      showMessage(err.response?.data?.error || "Failed to update listing."),
    );
}

function handleDeleteClick() {
  if (!confirm("Delete this listing?")) {
    return;
  }
  deleteListing(currentListing.id)
    .then(() => {
      window.location.href = "/client/pages/my-listings.html";
    })
    .catch((err) =>
      showMessage(err.response?.data?.error || "Failed to delete listing."),
    );
}

function loadListing() {
  const id = new URLSearchParams(window.location.search).get("id");
  if (!id) {
    showMessage("No listing specified.");
    return;
  }

  getListing(id)
    .then((listing) => {
      currentListing = listing;
      renderDetails(listing);
      renderImages(listing.images, listing.book_cover_image);
      toggleActionSections(listing);
      editLink.href = `/client/pages/edit-listing.html?id=${listing.id}`;
    })
    .catch(() => showMessage("Failed to load listing."));
}

messageForm.addEventListener("submit", handleMessageSubmit);
reportBtn.addEventListener("click", handleReportClick);
markSoldBtn.addEventListener("click", handleMarkSoldClick);
deleteBtn.addEventListener("click", handleDeleteClick);

loadListing();
