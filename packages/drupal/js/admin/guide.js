(function () {
  var tabs = document.querySelectorAll('[data-phpclaw-tab]');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function (e) {
      e.preventDefault();
      tabs.forEach(function (t) { t.classList.remove('active'); });
      document.querySelectorAll('.tab-pane').forEach(function (p) { p.classList.remove('active'); });
      tab.classList.add('active');
      var target = document.getElementById(tab.getAttribute('data-phpclaw-tab'));
      if (target) target.classList.add('active');
    });
  });
})();
