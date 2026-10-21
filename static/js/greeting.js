// Cover letter editor: the Greeting suggestions dropdown fills the Greeting box; you can still type your own.
(function () {
  "use strict";
  var pick = document.querySelector("select.greeting-pick");
  if (!pick) return;
  var box = pick.parentElement.querySelector('input[name="greeting"]');
  pick.addEventListener("change", function () {
    if (!pick.value) return;
    box.value = pick.value === "__none" ? "" : pick.value;
    pick.value = "";
    box.focus();
  });
})();
