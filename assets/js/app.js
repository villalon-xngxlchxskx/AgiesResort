// Progressive enhancement only: every form and link works without JavaScript.
(function () {
  // Mobile menu toggles
  document.querySelectorAll('.nav-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var nav = document.getElementById(btn.getAttribute('aria-controls'));
      var open = nav.classList.toggle('open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  // Confirmation for destructive / irreversible actions: <form data-confirm="...">
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.confirm(f.getAttribute('data-confirm'))) ev.preventDefault();
    });
  });

  // Prevent double submission
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) return;
      if (f.dataset.sent === '1' && f.method.toLowerCase() === 'post') { ev.preventDefault(); return; }
      f.dataset.sent = '1';
      setTimeout(function () { f.dataset.sent = ''; }, 4000);
    });
  });

  // Print buttons
  document.querySelectorAll('[data-print]').forEach(function (b) {
    b.addEventListener('click', function () { window.print(); });
  });

  // Booking estimate (display only — the server recalculates and is authoritative)
  var form = document.getElementById('booking-form');
  if (form) {
    var out = document.getElementById('estimate');
    var peso = function (n) { return '\u20B1' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var update = function () {
      var pick = form.querySelector('input[name=package_id]:checked');
      var guests = parseInt(form.querySelector('[name=num_guests]').value, 10) || 0;
      if (!pick || !out) { if (out) out.textContent = 'Choose a package to see the estimate.'; return; }
      var d = pick.dataset, rate = +d.rate, max = +d.max, extraAllowed = +d.extra, fee = +d.fee, dp = +d.dp;
      if (guests > max + extraAllowed) {
        out.textContent = 'This package allows up to ' + max + ' guests' + (extraAllowed ? ' plus ' + extraAllowed + ' extra' : '') + '.';
        return;
      }
      var extra = Math.max(0, guests - max), total = rate + extra * fee;
      out.textContent = 'Estimated total ' + peso(total) + (extra ? ' (includes ' + extra + ' extra guest' + (extra > 1 ? 's' : '') + ')' : '') +
        '. Down payment to confirm: ' + peso(Math.min(dp, total)) + '.';
      var g = form.querySelector('[name=num_guests]');
      g.max = String(max + extraAllowed);
    };
    form.addEventListener('change', update);
    form.addEventListener('input', update);
    update();
  }
  // ------------------------------------------------ staff sign-in page
  var login = document.getElementById('login-form');
  if (login) {
    var card = document.getElementById('auth-card');
    var user = document.getElementById('username');
    var pass = document.getElementById('password');
    var caps = document.getElementById('caps-warning');
    var btn = document.getElementById('signin-btn');
    var shake = function () {
      if (!card) return;
      card.classList.remove('shake'); void card.offsetWidth; card.classList.add('shake');
    };
    var setInvalid = function (input, bad) {
      var f = input.closest('.auth-field');
      if (f) f.classList.toggle('invalid', bad);
      input.setAttribute('aria-invalid', bad ? 'true' : 'false');
    };
    [user, pass].forEach(function (i) {
      i.addEventListener('input', function () { if (i.value !== '') setInvalid(i, false); });
    });
    var capsCheck = function (ev) {
      if (caps && ev.getModifierState) caps.classList.toggle('on', ev.getModifierState('CapsLock'));
    };
    pass.addEventListener('keyup', capsCheck);
    pass.addEventListener('keydown', capsCheck);
    user.addEventListener('keyup', capsCheck);
    var toggle = document.getElementById('pw-toggle');
    if (toggle) toggle.addEventListener('click', function () {
      var show = pass.type === 'password';
      pass.type = show ? 'text' : 'password';
      toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
      toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      pass.focus();
    });
    var forgot = document.getElementById('forgot-link');
    var help = document.getElementById('forgot-help');
    if (forgot && help) forgot.addEventListener('click', function (ev) { ev.preventDefault(); help.hidden = !help.hidden; });
    login.addEventListener('submit', function (ev) {
      var badUser = user.value.trim() === '', badPass = pass.value === '';
      setInvalid(user, badUser); setInvalid(pass, badPass);
      if (badUser || badPass) {
        ev.preventDefault();
        login.dataset.sent = ''; // let the corrected form be submitted right away
        shake(); (badUser ? user : pass).focus();
        return;
      }
      btn.classList.add('is-busy');
      btn.querySelector('.label').textContent = 'Signing in…';
    });
    // Back button / page cache: never leave the Sign In button stuck on "Signing in…".
    window.addEventListener('pageshow', function () {
      btn.classList.remove('is-busy');
      btn.querySelector('.label').textContent = 'Sign In';
      login.dataset.sent = '';
    });
  }

  // ------------------------------------------------ Export PDF with the native "Save as" dialog
  // Chrome/Edge on Windows: showSaveFilePicker opens the Windows Save dialog so staff choose the folder.
  // Other browsers fall back to a normal download (the browser's own "Save as" setting applies).
  document.querySelectorAll('a[data-pdf-export]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      if (!window.showSaveFilePicker || !window.fetch) return; // plain download
      ev.preventDefault();
      var name = a.getAttribute('data-filename') || 'report.pdf';
      var label = a.textContent;
      window.showSaveFilePicker({
        suggestedName: name,
        types: [{ description: 'PDF document', accept: { 'application/pdf': ['.pdf'] } }]
      }).then(function (handle) {
        a.classList.add('is-busy'); a.textContent = 'Saving…';
        return fetch(a.href, { credentials: 'same-origin' }).then(function (res) {
          var type = res.headers.get('Content-Type') || '';
          if (!res.ok || type.indexOf('application/pdf') !== 0) throw new Error('The server did not return a PDF (your session may have expired). Reload the page and try again.');
          return res.blob();
        }).then(function (blob) {
          return handle.createWritable().then(function (w) { return w.write(blob).then(function () { return w.close(); }); });
        }).then(function () {
          showNote('PDF saved as "' + handle.name + '".', 'success');
        });
      }).catch(function (err) {
        if (err && err.name === 'AbortError') return; // user cancelled the dialog
        showNote((err && err.message) ? err.message : 'The PDF could not be saved.', 'error');
      }).then(function () {
        a.classList.remove('is-busy'); a.textContent = label;
      });
    });
  });
  function showNote(msg, type) {
    var main = document.getElementById('main') || document.body;
    var d = document.createElement('div');
    d.className = 'flash flash-' + type;
    d.setAttribute('role', type === 'error' ? 'alert' : 'status');
    d.textContent = msg;
    main.insertBefore(d, main.firstChild);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  // ------------------------------------------------ image inputs: size check + previews before upload
  document.querySelectorAll('input[type=file][data-max-bytes]').forEach(function (input) {
    var max = parseInt(input.getAttribute('data-max-bytes'), 10) || 0;
    var strip = document.createElement('div');
    strip.className = 'preview-strip';
    input.insertAdjacentElement('afterend', strip);
    var zone = input.closest('.dropzone');
    if (zone) {
      ['dragenter', 'dragover'].forEach(function (t) { zone.addEventListener(t, function () { zone.classList.add('is-over'); }); });
      ['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function () { zone.classList.remove('is-over'); }); });
    }
    input.addEventListener('change', function () {
      strip.innerHTML = '';
      var tooBig = [], total = 0;
      Array.prototype.forEach.call(input.files || [], function (f) {
        total += f.size;
        if (max && f.size > max) tooBig.push(f.name);
        if (/^image\/(jpeg|png|webp)$/.test(f.type) && window.URL) {
          var img = document.createElement('img');
          img.alt = f.name; img.src = URL.createObjectURL(f);
          img.onload = function () { URL.revokeObjectURL(img.src); };
          strip.appendChild(img);
        }
      });
      var postMax = parseInt(input.getAttribute('data-post-max') || '0', 10);
      var msg = '';
      if (tooBig.length) msg = 'Too large (max ' + Math.round(max / 1048576) + ' MB each): ' + tooBig.join(', ');
      else if (postMax && total > postMax) msg = 'These files add up to ' + (total / 1048576).toFixed(1) + ' MB; the server accepts ' + Math.round(postMax / 1048576) + ' MB per upload. Upload them in smaller groups.';
      input.setCustomValidity(msg);
      if (msg) input.reportValidity();
    });
  });
})();
