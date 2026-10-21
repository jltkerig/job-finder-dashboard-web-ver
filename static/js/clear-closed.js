// Dashboard: "Clear closed jobs" deletes every saved job whose posting has closed, after an "Are you sure?" prompt.
(() => {
  const button = document.getElementById("clear-closed");
  if (!button) return;
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";

  button.addEventListener("click", async () => {
    if (!window.confirm("Are you sure? This permanently deletes every saved job marked Closed. This cannot be undone.")) return;
    button.disabled = true;
    try {
      const response = await fetch("/delete-closed-kept", { method: "POST", headers: { "X-CSRF-Token": csrfToken } });
      if (!response.ok) throw new Error();
      window.location.reload();
    } catch {
      button.textContent = "Could not clear. Try again.";
      button.disabled = false;
    }
  });
})();
