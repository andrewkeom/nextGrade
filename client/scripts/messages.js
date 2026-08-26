const messageList = document.getElementById("message-list");
const messageItemTemplate = document.getElementById("message-item-template");
const emptyState = messageItemTemplate.nextElementSibling;

const messageThread = document.getElementById("message-thread");
const messageBubbleTemplate = document.getElementById(
  "message-bubble-template",
);
const replyForm = document.getElementById("reply-form");
const replyTextarea = replyForm.querySelector("textarea");

let currentConversation = null;

function renderConversations(conversations) {
  messageList.innerHTML = "";
  emptyState.hidden = conversations.length > 0;

  conversations.forEach((conversation) => {
    const item = messageItemTemplate.content.cloneNode(true);
    const paragraphs = item.querySelectorAll("p");
    paragraphs[0].textContent = conversation.other_user_name;
    paragraphs[1].textContent = `Re: ${conversation.listing_title}`;
    paragraphs[2].textContent = conversation.last_message || "";

    const spans = item.querySelectorAll("span");
    spans[0].textContent = conversation.last_message_at
      ? new Date(conversation.last_message_at).toLocaleString()
      : "";
    spans[1].hidden = conversation.unread_count === 0;

    item.querySelector("div").addEventListener("click", () => {
      openConversation(
        conversation.listing_id,
        conversation.other_user_id,
        conversation.other_user_name,
      );
    });

    messageList.appendChild(item);
  });
}

function loadConversations() {
  return listConversations()
    .then(renderConversations)
    .catch(() => showMessage("Failed to load conversations."));
}

function renderThread(messages) {
  messageThread.innerHTML = "";
  const selfId = getState().user.id;

  messages.forEach((message) => {
    const bubble = messageBubbleTemplate.content.cloneNode(true);
    const paragraphs = bubble.querySelectorAll("p");
    paragraphs[0].textContent =
      message.sender_id === selfId ? "You" : currentConversation.otherUserName;
    paragraphs[1].textContent = message.content;
    bubble.querySelector("span").textContent = new Date(
      message.created_at,
    ).toLocaleString();

    messageThread.appendChild(bubble);
  });
}

function openConversation(listingId, otherUserId, otherUserName) {
  currentConversation = { listingId, otherUserId, otherUserName };

  listThread(listingId, otherUserId)
    .then((messages) => {
      renderThread(messages);
      loadConversations();
    })
    .catch(() => showMessage("Failed to load conversation."));
}

function handleReplySubmit(event) {
  event.preventDefault();

  if (!currentConversation) {
    showMessage("Select a conversation first.");
    return;
  }

  const content = replyTextarea.value.trim();
  if (content === "") {
    return;
  }

  sendMessage(
    currentConversation.listingId,
    currentConversation.otherUserId,
    content,
  )
    .then(() => {
      replyTextarea.value = "";
      openConversation(
        currentConversation.listingId,
        currentConversation.otherUserId,
        currentConversation.otherUserName,
      );
    })
    .catch((err) =>
      showMessage(err.response?.data?.error || "Failed to send message."),
    );
}

replyForm.addEventListener("submit", handleReplySubmit);
loadConversations();
