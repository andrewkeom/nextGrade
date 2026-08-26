const nameSpan = document.querySelector("main h1 span");
const childGrid = document.getElementById("child-grid");
const childCardTemplate = document.getElementById("child-card-template");
const activeCountSpan = document.getElementById("active-listings-count");
const soldCountSpan = document.getElementById("sold-listings-count");
const pendingCountSpan = document.getElementById("pending-listings-count");
const unreadCountSpan = document.getElementById("unread-messages-count");
const pendingReportsSpan = document.getElementById(
  "pending-price-reports-count",
);
const noPendingReportsMsg = document.getElementById("no-pending-reports");
const pendingReportsText = document.getElementById("pending-reports-text");
const resolvedReportsList = document.getElementById("resolved-reports-list");
const resolvedReportTemplate = document.getElementById(
  "resolved-report-template",
);
const noResolvedReportsMsg = document.getElementById("no-resolved-reports");

function renderWelcomeName() {
  const user = getState().user;
  nameSpan.textContent = user ? user.name : "";
}

function renderChildren(children) {
  childGrid.innerHTML = "";
  children.forEach((child) => {
    const card = childCardTemplate.content.cloneNode(true);
    const paragraphs = card.querySelectorAll("p");
    paragraphs[0].textContent = child.name;
    paragraphs[1].textContent = child.grade_level_name;

    const buttons = card.querySelectorAll("button");
    buttons[0].addEventListener("click", () => {
      window.location.href = "/client/pages/curriculum.html";
    });
    buttons[1].addEventListener("click", () => {
      window.location.href = "/client/pages/marketplace.html";
    });

    childGrid.appendChild(card);
  });
}

function renderListingCounts(listings) {
  activeCountSpan.textContent = listings.filter(
    (l) => l.status === "active",
  ).length;
  soldCountSpan.textContent = listings.filter(
    (l) => l.status === "sold",
  ).length;
}

function renderUnreadCount(conversations) {
  unreadCountSpan.textContent = conversations.reduce(
    (sum, c) => sum + c.unread_count,
    0,
  );
}

function renderPendingReports(reports) {
  pendingCountSpan.textContent = reports.length;
  pendingReportsSpan.textContent = reports.length;
  noPendingReportsMsg.hidden = reports.length > 0;
  pendingReportsText.hidden = reports.length === 0;
}

function renderResolvedReports(reports) {
  resolvedReportsList.innerHTML = "";
  noResolvedReportsMsg.hidden = reports.length > 0;

  reports.forEach((report) => {
    const card = resolvedReportTemplate.content.cloneNode(true);
    card.querySelector(".resolved-report-book").textContent = report.title;
    card.querySelector(".resolved-report-reason").textContent =
      `You reported: ${report.reason || "No reason given."}`;
    card.querySelector(".resolved-report-response").textContent =
      report.admin_response ||
      `An admin updated this listing's price to ${report.asking_price}$.`;
    card.querySelector("a").href =
      `/client/pages/edit-listing.html?id=${report.listing_id}`;
    resolvedReportsList.appendChild(card);
  });
}

renderWelcomeName();
listChildren()
  .then(renderChildren)
  .catch(() => showMessage("Failed to load children."));
listMyListings("all")
  .then(renderListingCounts)
  .catch(() => showMessage("Failed to load listings."));
listConversations()
  .then(renderUnreadCount)
  .catch(() => showMessage("Failed to load messages."));
listMyReports()
  .then(renderPendingReports)
  .catch(() => showMessage("Failed to load price reports."));
listMyReports("resolved")
  .then(renderResolvedReports)
  .catch(() => showMessage("Failed to load price reports."));

document.getElementById("view-messages-btn").addEventListener("click", () => {
  window.location.href = "/client/pages/messages.html";
});

document.getElementById("qa-marketplace").addEventListener("click", () => {
  window.location.href = "/client/pages/marketplace.html";
});

document.getElementById("qa-curriculum").addEventListener("click", () => {
  window.location.href = "/client/pages/curriculum.html";
});
