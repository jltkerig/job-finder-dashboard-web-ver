// "If rejecting, why?": the pop-up every Reject button opens (a listing, a Top 10 pick, a saved job on the Dashboard), so the
// reason is chosen in the same step that confirms the rejection. window.askRejectReason(name) resolves to
// the reason, or null when you cancel.
(() => {
  const REASONS = [["other", "Other"], ["wrong_role", "Wrong role"], ["wrong_location", "Wrong location"],
    ["bad_job_fit", "Bad Job Fit"], ["not_a_job", "Not a job"], ["duplicate", "Duplicate"], ["applied_before", "Applied Before"], ["closed", "Job is closed"]];
  let dialog, title, select;

  function button(text, value, classes) {
    const element = document.createElement("button");
    element.type = "submit";
    element.value = value;
    element.className = classes;
    element.textContent = text;
    return element;
  }

  function build() {
    dialog = document.createElement("dialog");
    dialog.className = "review-dialog reject-reason-dialog";
    const form = document.createElement("form");
    form.method = "dialog";
    title = document.createElement("h2");
    const label = document.createElement("label");
    label.className = "reject-reason-label";
    select = document.createElement("select");
    select.className = "reject-reason";
    REASONS.forEach(([value, text]) => select.append(new Option(text, value)));
    label.append("If rejecting, why? ", select);
    const actions = document.createElement("div");
    actions.className = "review-actions";
    actions.append(button("Cancel", "cancel", "bordered-button"), button("Reject", "reject", "primary-button"));
    form.append(title, label, actions);
    dialog.append(form);
    document.body.append(dialog);
  }

  window.askRejectReason = (name) => new Promise((resolve) => {
    if (typeof HTMLDialogElement === "undefined") { resolve("other"); return; }
    if (!dialog) build();
    if (dialog.open) { resolve(null); return; }  // one question at a time
    title.textContent = `Reject ${name || "this listing"}?`;
    select.value = "other";
    dialog.returnValue = "";
    dialog.addEventListener("close", () => resolve(dialog.returnValue === "reject" ? select.value : null), { once: true });
    dialog.showModal();
    select.focus();
  });
})();
