const ORIGIN = "http://localhost/nextGrade";
const BASE_URL = ORIGIN + "/server/api/";

function authHeaders() {
  const token = getState().token;
  return token ? { Authorization: "Bearer " + token } : {};
}

function login(email, password) {
  return axios
    .post(BASE_URL + "auth.php?action=login", { email, password })
    .then((res) => {
      setState({ token: res.data.token, user: res.data.user });
      return res.data;
    });
}

function signup(name, email, password, confirmPassword, children) {
  return axios
    .post(BASE_URL + "auth.php?action=signup", {
      name: name,
      email: email,
      password: password,
      confirmPassword: confirmPassword,
      children: children,
    })
    .then((res) => {
      setState({ token: res.data.token, user: res.data.user });
      return res.data;
    });
}

function checkSession() {
  const token = getState().token;
  if (!token) {
    return Promise.resolve({ logged_in: false });
  }

  return axios
    .get(BASE_URL + "auth.php?action=check_session", { headers: authHeaders() })
    .then((res) => res.data);
}

function showMessage(text) {
  alert(text);
}

function logout() {
  return axios
    .post(BASE_URL + "auth.php?action=logout", {}, { headers: authHeaders() })
    .then(() => clearState());
}

function updateProfile(name, email) {
  return axios
    .post(BASE_URL + "auth.php?action=update_profile", { name, email }, { headers: authHeaders() })
    .then((res) => {
      setState({ user: res.data.user });
      return res.data;
    });
}

function changePassword(currentPassword, newPassword, confirmNewPassword) {
  return axios
    .post(
      BASE_URL + "auth.php?action=change_password",
      { currentPassword, newPassword, confirmNewPassword },
      { headers: authHeaders() }
    )
    .then((res) => res.data);
}

function listChildren() {
  return axios
    .get(BASE_URL + "children.php?action=list_children", { headers: authHeaders() })
    .then((res) => res.data.children);
}

function addChild(name, gradeLevelId) {
  return axios
    .post(
      BASE_URL + "children.php?action=add_child",
      { name: name, grade_level_id: gradeLevelId },
      { headers: authHeaders() }
    )
    .then((res) => res.data.child);
}

function updateChild(id, name, gradeLevelId) {
  return axios
    .post(
      BASE_URL + "children.php?action=update_child",
      { id: id, name: name, grade_level_id: gradeLevelId },
      { headers: authHeaders() }
    )
    .then((res) => res.data.child);
}

function deleteChild(id) {
  return axios
    .post(BASE_URL + "children.php?action=delete_child", { id }, { headers: authHeaders() })
    .then((res) => res.data);
}

function listGrades() {
  return axios.get(BASE_URL + "curriculum.php?action=list_grades").then((res) => res.data.grades);
}

function listSubjectsByGrade(gradeLevelId) {
  return axios
    .get(BASE_URL + "curriculum.php?action=list_subjects", { params: { grade_level_id: gradeLevelId } })
    .then((res) => res.data.subjects);
}

function listBooksBySubject(subjectId) {
  return axios
    .get(BASE_URL + "curriculum.php?action=list_books", { params: { subject_id: subjectId } })
    .then((res) => res.data.books);
}

function suggestPrice(bookId, condition) {
  return axios
    .get(BASE_URL + "ai_pricing.php?action=suggest_price", {
      params: { book_id: bookId, condition: condition },
      headers: authHeaders(),
    })
    .then((res) => res.data);
}

function listListings(q) {
  return axios
    .get(BASE_URL + "listings.php?action=list_listings", { params: { q: q } })
    .then((res) => res.data.listings);
}

function getListing(id) {
  return axios
    .get(BASE_URL + "listings.php?action=get_listing", { params: { id: id } })
    .then((res) => res.data.listing);
}

function createListing(formData) {
  return axios
    .post(BASE_URL + "listings.php?action=create_listing", formData, { headers: authHeaders() })
    .then((res) => res.data.listing);
}

function updateListing(formData) {
  return axios
    .post(BASE_URL + "listings.php?action=update_listing", formData, { headers: authHeaders() })
    .then((res) => res.data.listing);
}

function markSold(id) {
  return axios
    .post(BASE_URL + "listings.php?action=mark_sold", { id }, { headers: authHeaders() })
    .then((res) => res.data);
}

function deleteListing(id) {
  return axios
    .post(BASE_URL + "listings.php?action=delete_listing", { id }, { headers: authHeaders() })
    .then((res) => res.data);
}

function sendMessage(listingId, receiverId, content) {
  return axios
    .post(
      BASE_URL + "messages.php?action=send_message",
      { listing_id: listingId, receiver_id: receiverId, content: content },
      { headers: authHeaders() }
    )
    .then((res) => res.data.message);
}

function listThread(listingId, otherUserId) {
  return axios
    .get(BASE_URL + "messages.php?action=list_thread", {
      params: { listing_id: listingId, other_user_id: otherUserId },
      headers: authHeaders(),
    })
    .then((res) => res.data.messages);
}

function reportPrice(listingId, reason) {
  return axios
    .post(
      BASE_URL + "price_report.php?action=report_price",
      { listing_id: listingId, reason: reason },
      { headers: authHeaders() }
    )
    .then((res) => res.data.price_report);
}

function listMyListings(statusFilter) {
  return axios
    .get(BASE_URL + "listings.php?action=list_my_listings", {
      params: { statusFilter: statusFilter },
      headers: authHeaders(),
    })
    .then((res) => res.data.listings);
}

function listConversations() {
  return axios
    .get(BASE_URL + "messages.php?action=list_conversations", { headers: authHeaders() })
    .then((res) => res.data.conversations);
}

function listMyReports(statusFilter = "pending") {
  return axios
    .get(BASE_URL + "price_report.php?action=list_my_reports", {
      params: { statusFilter: statusFilter },
      headers: authHeaders(),
    })
    .then((res) => res.data.reports);
}

function addGradeLevel(name, academicYear) {
  return axios
    .post(
      BASE_URL + "curriculum.php?action=add_grade_level",
      { gradeLevelName: name, academicYear: academicYear },
      { headers: authHeaders() }
    )
    .then((res) => res.data.grade_level);
}

function updateGradeLevel(id, name, academicYear) {
  return axios
    .post(
      BASE_URL + "curriculum.php?action=update_grade_level",
      { id: id, name: name, academic_year: academicYear },
      { headers: authHeaders() }
    )
    .then((res) => res.data.grade_level);
}

function deleteGradeLevel(id) {
  return axios
    .post(BASE_URL + "curriculum.php?action=delete_grade_level", { id }, { headers: authHeaders() })
    .then((res) => res.data);
}

function addSubject(gradeLevelId, name) {
  return axios
    .post(
      BASE_URL + "curriculum.php?action=add_subject",
      { grade_level_id: gradeLevelId, subjectName: name },
      { headers: authHeaders() }
    )
    .then((res) => res.data.subject);
}

function updateSubject(id, name) {
  return axios
    .post(
      BASE_URL + "curriculum.php?action=update_subject",
      { id: id, name: name },
      { headers: authHeaders() }
    )
    .then((res) => res.data.subject);
}

function deleteSubject(id) {
  return axios
    .post(BASE_URL + "curriculum.php?action=delete_subject", { id }, { headers: authHeaders() })
    .then((res) => res.data);
}

function addBook(formData) {
  return axios
    .post(BASE_URL + "curriculum.php?action=add_book", formData, { headers: authHeaders() })
    .then((res) => res.data.book);
}

function updateBook(formData) {
  return axios
    .post(BASE_URL + "curriculum.php?action=update_book", formData, { headers: authHeaders() })
    .then((res) => res.data.book);
}

function deleteBook(id) {
  return axios
    .post(BASE_URL + "curriculum.php?action=delete_book", { id }, { headers: authHeaders() })
    .then((res) => res.data);
}

function extractCurriculum(formData) {
  return axios
    .post(BASE_URL + "curriculum.php?action=extract_curriculum", formData, { headers: authHeaders() })
    .then((res) => res.data.rows);
}

function importCurriculum(gradeLevelId, rows) {
  return axios
    .post(
      BASE_URL + "curriculum.php?action=import_curriculum",
      { grade_level_id: gradeLevelId, rows: rows },
      { headers: authHeaders() }
    )
    .then((res) => res.data);
}

function listReports(statusFilter) {
  return axios
    .get(BASE_URL + "price_report.php?action=list_reports", {
      params: { statusFilter: statusFilter },
      headers: authHeaders(),
    })
    .then((res) => res.data.reports);
}

function overridePriceReport(id, overridePrice, adminResponse) {
  return axios
    .post(
      BASE_URL + "price_report.php?action=override_price_report",
      { id: id, override_price: overridePrice, admin_response: adminResponse },
      { headers: authHeaders() }
    )
    .then((res) => res.data.price_report);
}

function flagReport(id, adminResponse) {
  return axios
    .post(
      BASE_URL + "price_report.php?action=flag_report",
      { id: id, admin_response: adminResponse },
      { headers: authHeaders() }
    )
    .then((res) => res.data.price_report);
}

function dashboardSummary() {
  return axios
    .get(BASE_URL + "admin.php?action=dashboard_summary", { headers: authHeaders() })
    .then((res) => res.data);
}
