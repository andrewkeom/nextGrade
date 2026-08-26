function createDefaultState() {
  const newState = {
    token: null,
    user: null,
  };

  localStorage.setItem("nextgrade_state", JSON.stringify(newState));

  return newState;
}

function getState() {
  const value = localStorage.getItem("nextgrade_state");
  if (value === null) {
    return createDefaultState();
  }
  return JSON.parse(value);
}

function setState(updates) {
  const currentState = getState();
  const mergedState = { ...currentState, ...updates };

  localStorage.setItem("nextgrade_state", JSON.stringify(mergedState));
  return mergedState;
}

function clearState() {
  localStorage.removeItem("nextgrade_state");
}

function isLoggedIn() {
  return getState().token !== null;
}
