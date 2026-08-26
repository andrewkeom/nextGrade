if (getState().user && getState().user.role !== "admin") {
  window.location.href = "/client/pages/dashboard.html";
}

const totalChildrenSpan = document.getElementById("total-children-count");
const activeListingsSpan = document.getElementById("active-listings-count");
const soldListingsSpan = document.getElementById("sold-listings-count");
const pendingReportsSpan = document.getElementById("pending-reports-count");

dashboardSummary()
  .then((data) => {
    totalChildrenSpan.textContent = data.total_children;
    activeListingsSpan.textContent = data.active_listings;
    soldListingsSpan.textContent = data.sold_listings;
  })
  .catch(() => showMessage("Failed to load dashboard stats."));

listReports("pending")
  .then((reports) => {
    pendingReportsSpan.textContent = reports.length;
  })
  .catch(() => showMessage("Failed to load pending reports."));
