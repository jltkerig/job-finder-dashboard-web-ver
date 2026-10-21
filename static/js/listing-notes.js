// Search results: the Notes box under a listing's Details. Edit shows a text box, Save stores it with the listing.
(() => {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || "";

  document.addEventListener("click", async (event) => {
    const button = event.target.closest(".notes-edit, .notes-save");
    if (!button) return;
    const section = button.closest(".details-notes");
    const view = section.querySelector(".notes-view");
    const box = section.querySelector(".notes-input");
    const edit = section.querySelector(".notes-edit");
    const save = section.querySelector(".notes-save");
    const status = section.querySelector(".notes-status");
    if (button === edit) {
      view.hidden = true; edit.hidden = true;
      box.hidden = false; save.hidden = false;
      box.focus();
      return;
    }
    save.disabled = true;
    status.textContent = "Saving…";
    try {
      const response = await fetch(`/save-notes/${section.dataset.companyId}`, {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken },
        body: JSON.stringify({ notes: box.value }),
      });
      if (!response.ok) throw new Error();
      const saved = await response.json();
      view.innerHTML = saved.html || "<em>No notes yet.</em>";
      box.value = saved.notes;
      box.hidden = true; save.hidden = true;
      view.hidden = false; edit.hidden = false;
      status.textContent = "Saved";
    } catch {
      status.textContent = "Could not save the notes. Try again.";
    }
    save.disabled = false;
  });
})();
