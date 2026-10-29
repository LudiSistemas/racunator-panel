// Merač jačine lozinke u čarobnjaku (isto pravilo kao Installer::passwordStrength; server ionako proverava).
(function () {
  'use strict';
  var input = document.getElementById('f-admin_password');
  var meter = document.getElementById('meter');
  var label = document.getElementById('meter-label');
  if (!input || !meter || !label) { return; }
  var labels = ['preslaba', 'slaba', 'dobra', 'jaka', 'vrlo jaka'];
  function score(pw) {
    var len = Array.from(pw).length;
    if (len < 12) { return 0; }
    var user = (document.getElementById('f-admin_username') || {}).value || '';
    if (user.length >= 3 && pw.toLowerCase().indexOf(user.toLowerCase()) !== -1) { return 0; }
    var classes = (/[a-zčćžšđ]/.test(pw) ? 1 : 0) + (/[A-ZČĆŽŠĐ]/.test(pw) ? 1 : 0) + (/\d/.test(pw) ? 1 : 0) + (/[^\p{L}\d]/u.test(pw) ? 1 : 0);
    return 1 + (classes >= 3 ? 1 : 0) + (len >= 16 ? 1 : 0) + ((len >= 20 || (len >= 14 && classes === 4)) ? 1 : 0);
  }
  function update() {
    var s = score(input.value);
    meter.setAttribute('data-score', String(s));
    label.textContent = input.value === '' ? 'najmanje 12 znakova' : 'Jačina: ' + labels[s];
  }
  input.addEventListener('input', update);
  update();
})();
