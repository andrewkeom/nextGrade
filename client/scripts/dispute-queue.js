if (getState().user && getState().user.role !== "admin") {
  window.location.href = "/client/pages/dashboard.html";
}

const statusFilterSelect = document.getElementById("statusFilter");
const disputeList = document.getElementById("dispute-list");
const disputeCardTemplate = document.getElementById("dispute-card-template");
const emptyState = disputeCardTemplate.nextElementSibling;

function renderDisputes(reports) {
  disputeList.innerHTML = "";
  emptyState.hidden = reports.length > 0;

  reports.forEach((report) => {
    const card = disputeCardTemplate.content.cloneNode(true);

    const link = card.querySelector("a");
    link.textContent = `${report.title}, ${report.author}`;
    link.href = `/client/pages/listing.html?id=${report.listing_id}`;

    const paragraphs = card.querySelectorAll("p");
    paragraphs[1].querySelector("span").textContent = report.reporter_name;
    paragraphs[2].querySelector("span").textContent = report.reason || "No reason given.";
    paragraphs[3].querySelector("span").textContent =
      report.asking_price !== null ? `${report.asking_price}$` : "N/A";
    paragraphs[4].querySelector("span").textContent = `${report.ai_suggested_price}$`;

    const pendingControls = card.querySelector(".dispute-pending-controls");
    const resolutionMsg = card.querySelector(".dispute-resolution");

    if (report.status === "resolved") {
      pendingControls.hidden = true;
      resolutionMsg.hidden = false;
      resolutionMsg.querySelector("span").textContent =
        report.admin_response || "Price was overridden with no message.";
    } else {
      const adminResponseTextarea = card.querySelector("textarea");
      const overrideInput = card.querySelector('input[type="number"]');
      const buttons = card.querySelectorAll("button");
      const overrideBtn = buttons[0];
      const flagBtn = buttons[1];

      overrideBtn.addEventListener("click", () => {
        const overridePrice = overrideInput.value;
        if (overridePrice === "") {
          showMessage("Enter an override price.");
          return;
        }
        overridePriceReport(report.id, overridePrice, adminResponseTextarea.value.trim() || null)
          .then(loadDisputes)
          .catch((err) => showMessage(err.response?.data?.error || "Failed to override price."));
      });

      flagBtn.addEventListener("click", () => {
        const response = adminResponseTextarea.value.trim();
        if (response === "") {
          showMessage("Enter a response before flagging back to the seller.");
          return;
        }
        flagReport(report.id, response)
          .then(loadDisputes)
          .catch((err) => showMessage(err.response?.data?.error || "Failed to flag report."));
      });
    }

    disputeList.appendChild(card);
  });
}

function loadDisputes() {
  listReports(statusFilterSelect.value)
    .then(renderDisputes)
    .catch(() => showMessage("Failed to load disputes."));
}

statusFilterSelect.addEventListener("change", loadDisputes);
loadDisputes();
