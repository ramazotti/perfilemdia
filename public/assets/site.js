(function () {
  var btn = document.querySelector('[data-act="theme"]');
  function label() {
    if (!btn) return;
    var t = localStorage.getItem('pd_theme');
    btn.textContent = t === 'dark' ? 'Tema: escuro' : t === 'light' ? 'Tema: claro' : 'Tema: automático';
  }
  label();
  if (btn) {
    btn.addEventListener('click', function () {
      var t = localStorage.getItem('pd_theme');
      var next = t === 'light' ? 'dark' : t === 'dark' ? '' : 'light';
      if (next) {
        localStorage.setItem('pd_theme', next);
        document.documentElement.setAttribute('data-theme', next);
      } else {
        localStorage.removeItem('pd_theme');
        document.documentElement.removeAttribute('data-theme');
      }
      label();
    });
  }

  document.querySelectorAll('[data-mask="phone"]').forEach(function (input) {
    input.addEventListener('input', function () {
      var d = input.value.replace(/\D/g, '');
      if ((d.length === 12 || d.length === 13) && d.slice(0, 2) === '55') d = d.slice(2);
      d = d.slice(0, 11);
      if (d.length > 10) input.value = '(' + d.slice(0, 2) + ') ' + d.slice(2, 7) + '-' + d.slice(7);
      else if (d.length > 6) input.value = '(' + d.slice(0, 2) + ') ' + d.slice(2, 6) + '-' + d.slice(6);
      else if (d.length > 2) input.value = '(' + d.slice(0, 2) + ') ' + d.slice(2);
      else input.value = d;
    });
  });

  document.querySelectorAll('[data-mask="document"]').forEach(function (input) {
    input.addEventListener('input', function () {
      var raw = input.value.toUpperCase().replace(/[^0-9A-Z]/g, '').slice(0, 14);
      if (/[A-Z]/.test(raw) || raw.length > 11) {
        var a = raw.slice(0, 2), b = raw.slice(2, 5), c = raw.slice(5, 8), d = raw.slice(8, 12), e = raw.slice(12, 14);
        input.value = a + (b ? '.' + b : '') + (c ? '.' + c : '') + (d ? '/' + d : '') + (e ? '-' + e : '');
      } else {
        var x = raw.slice(0, 3), y = raw.slice(3, 6), z = raw.slice(6, 9), w = raw.slice(9, 11);
        input.value = x + (y ? '.' + y : '') + (z ? '.' + z : '') + (w ? '-' + w : '');
      }
    });
  });

  document.querySelectorAll('[data-mask="card"]').forEach(function (input) {
    input.addEventListener('input', function () {
      var d = input.value.replace(/\D/g, '').slice(0, 19);
      input.value = d.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
      var brand = document.getElementById('brand');
      if (!brand) return;
      var name = 'Cartão';
      if (d.charAt(0) === '4') name = 'Visa';
      else if (/^3[47]/.test(d)) name = 'American Express';
      else if (/^(5[1-5]|2[2-7])/.test(d)) name = 'Mastercard';
      brand.textContent = d.length >= 2 ? name : '';
    });
  });

  document.querySelectorAll('[data-mask="expiry"]').forEach(function (input) {
    input.addEventListener('input', function () {
      var d = input.value.replace(/\D/g, '').slice(0, 4);
      input.value = d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
    });
  });

  document.querySelectorAll('[data-act="copy"]').forEach(function (button) {
    button.addEventListener('click', function () {
      var el = document.getElementById(button.getAttribute('data-target'));
      if (!el) return;
      navigator.clipboard.writeText(el.value).then(function () {
        button.textContent = 'Copiado';
      });
    });
  });

  var poll = document.querySelector('[data-act="poll"]');
  if (poll) {
    poll.addEventListener('click', function () {
      fetch(poll.getAttribute('data-status'), { headers: { 'Accept': 'application/json' } })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.status === 'pago') location.href = poll.getAttribute('data-next');
          else poll.textContent = 'Ainda não confirmado';
        });
    });
  }

  var timer = document.querySelector('[data-expires]');
  if (timer) {
    var end = new Date(String(timer.getAttribute('data-expires')).replace(' ', 'T')).getTime();
    setInterval(function () {
      var left = Math.max(0, Math.floor((end - Date.now()) / 1000));
      var m = String(Math.floor(left / 60)).padStart(2, '0');
      var s = String(left % 60).padStart(2, '0');
      timer.textContent = left === 0 ? 'Pix expirado' : 'Expira em ' + m + ':' + s;
    }, 1000);
  }

  document.querySelectorAll('.field input, .field textarea').forEach(function (input) {
    input.addEventListener('input', function () {
      var field = input.closest('.field');
      if (field) field.classList.remove('bad');
    });
  });
})();
