/*
 * Shared behaviour for the Broadband forms (rollout register, pre-assessment).
 * Each helper is a no-op when its elements are missing, so pages opt in by markup:
 *   BroadbandForms.municipalities(structure, {province, district, local})  - cascading selects
 *   BroadbandForms.toggleOnRadio(radioName, value, elementId)              - show element only for one radio value
 *   BroadbandForms.geolocate(buttonId, latId, lngId, statusId)              - "Use current location"
 *   BroadbandForms.signaturePads(redrawn)                                   - every .bb-sig-pad on the page
 *   BroadbandForms.imageSizeGuard(selector, maxBytes)                       - reject oversized uploads early
 */
var BroadbandForms = (function () {
    function fill(select, items, selected, emptyText, readyText, labelFn) {
        select.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = items.length ? readyText : emptyText;
        select.appendChild(ph);
        var found = false;
        items.forEach(function (item) {
            var o = document.createElement('option');
            o.value = typeof item === 'string' ? item : item.district;
            o.textContent = labelFn ? labelFn(item) : o.value;
            if (o.value === selected) { o.selected = true; found = true; }
            select.appendChild(o);
        });
        if (selected && !found) {
            var fb = document.createElement('option');
            fb.value = selected; fb.textContent = selected; fb.selected = true;
            select.appendChild(fb);
        }
    }

    function municipalities(structure, current) {
        var p = document.getElementById('province');
        var d = document.getElementById('district_municipality');
        var l = document.getElementById('local_municipality');
        if (!p || !d || !l) return;
        function districtsFor(province) { return structure[province] || []; }
        function populateDistricts(province, selected) {
            fill(d, districtsFor(province), selected, 'Select province first…', 'Select district…', function (row) {
                return row.type === 'metro' ? row.district + ' (Metropolitan Municipality)' : row.district;
            });
        }
        function populateLocals(province, district, selected) {
            var row = districtsFor(province).filter(function (r) { return r.district === district; })[0];
            fill(l, row ? row.locals : [], selected, 'Select district first…', 'Select local municipality…');
        }
        p.addEventListener('change', function () { populateDistricts(p.value, ''); populateLocals(p.value, '', ''); });
        d.addEventListener('change', function () { populateLocals(p.value, d.value, ''); });
        populateDistricts(current.province || '', current.district || '');
        populateLocals(current.province || '', current.district || '', current.local || '');
    }

    function toggleOnRadio(name, value, elementId) {
        var el = document.getElementById(elementId);
        var radios = document.querySelectorAll('input[name="' + name + '"]');
        if (!el || !radios.length) return;
        function sync() {
            var c = document.querySelector('input[name="' + name + '"]:checked');
            el.style.display = c && c.value === value ? '' : 'none';
        }
        radios.forEach(function (r) { r.addEventListener('change', sync); });
        sync();
    }

    function geolocate(buttonId, latId, lngId, statusId) {
        var btn = document.getElementById(buttonId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            var status = document.getElementById(statusId);
            if (!navigator.geolocation) { status.textContent = 'Location is not supported by this browser.'; return; }
            status.textContent = 'Getting location…';
            navigator.geolocation.getCurrentPosition(function (pos) {
                document.getElementById(latId).value = pos.coords.latitude.toFixed(6);
                document.getElementById(lngId).value = pos.coords.longitude.toFixed(6);
                status.textContent = 'Location captured (±' + Math.round(pos.coords.accuracy) + ' m).';
            }, function (err) {
                status.textContent = 'Could not get location: ' + err.message;
            }, { enableHighAccuracy: true, timeout: 15000 });
        });
    }

    function signaturePads(redrawn) {
        redrawn = redrawn || {};
        document.querySelectorAll('.bb-sig-pad').forEach(function (pad) {
            var canvas = pad.querySelector('canvas');
            var hidden = pad.querySelector('input[type=hidden]');
            var ctx = canvas.getContext('2d');
            var drawing = false, last = null;
            ctx.strokeStyle = '#1d2730'; ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round';

            function pos(e) {
                var rect = canvas.getBoundingClientRect();
                var t = e.touches && e.touches.length ? e.touches[0] : e;
                return [(t.clientX - rect.left) * canvas.width / rect.width, (t.clientY - rect.top) * canvas.height / rect.height];
            }
            function start(e) { drawing = true; last = pos(e); e.preventDefault(); }
            function move(e) {
                if (!drawing) return;
                var p = pos(e);
                ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.lineTo(p[0], p[1]); ctx.stroke();
                last = p;
                e.preventDefault();
            }
            function end() {
                if (!drawing) return;
                drawing = false;
                hidden.value = canvas.toDataURL('image/png');
            }
            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            window.addEventListener('mouseup', end);
            canvas.addEventListener('touchstart', start, { passive: false });
            canvas.addEventListener('touchmove', move, { passive: false });
            canvas.addEventListener('touchend', end);

            var clear = pad.querySelector('[data-action="clear"]');
            if (clear) {
                clear.addEventListener('click', function () { ctx.clearRect(0, 0, canvas.width, canvas.height); hidden.value = ''; });
            }

            var who = pad.getAttribute('data-who');
            if (redrawn[who] && redrawn[who].indexOf('data:image/png;base64,') === 0) {
                var img = new Image();
                img.onload = function () { ctx.drawImage(img, 0, 0, canvas.width, canvas.height); hidden.value = redrawn[who]; };
                img.src = redrawn[who];
                pad.style.display = '';
            }
        });

        document.querySelectorAll('[data-action="resign"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var pad = document.querySelector('.bb-sig-pad[data-who="' + btn.getAttribute('data-who') + '"]');
                if (pad) pad.style.display = '';
                btn.style.display = 'none';
            });
        });
    }

    function imageSizeGuard(selector, maxBytes) {
        document.querySelectorAll(selector).forEach(function (input) {
            input.addEventListener('change', function () {
                var f = input.files && input.files[0];
                if (f && f.size > maxBytes) {
                    alert('That image is larger than ' + Math.round(maxBytes / 1048576) + ' MB — please choose a smaller one.');
                    input.value = '';
                }
            });
        });
    }

    return { municipalities: municipalities, toggleOnRadio: toggleOnRadio, geolocate: geolocate, signaturePads: signaturePads, imageSizeGuard: imageSizeGuard };
})();
