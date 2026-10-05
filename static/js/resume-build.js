// Writing a cover letter: choosing a saved reference fills in its fields.
(function () {
  var pick = document.getElementById("pick-reference");
  if (!pick) return;
  var saved = JSON.parse(document.getElementById("saved-references").textContent);
  pick.addEventListener("change", function () {
    var ref = saved[pick.value];
    if (!ref) return;
    document.querySelectorAll("#new-reference input").forEach(function (input) {
      var field = input.name.replace(/^ref\d+_/, "");
      if (field in ref) input.value = ref[field];
    });
  });
})();
