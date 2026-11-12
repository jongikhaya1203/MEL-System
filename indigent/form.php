<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin', 'manager', 'field_worker', 'indigent_capturer']);

$editing = null;
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM indigent_applications WHERE id = ?');
    $stmt->execute([(int)$_GET['id']]);
    $editing = $stmt->fetch() ?: null;
    if (!$editing) {
        flash('error', 'Application not found.');
        redirect('/indigent/list.php');
    }
}

$suggestedRef = $editing['reference_code'] ?? next_application_reference_code($pdo);
$provinces = sa_provinces();

$pageTitle = $editing ? 'Edit application' : 'Capture indigent application';
include __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1><?= $editing ? 'Edit application' : 'Capture indigent application' ?></h1>
        <p class="subtitle">SABC TV licence concession / set-top-box subsidy — household data capture</p>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= APP_BASE_URL ?>/indigent/save.php" enctype="multipart/form-data" id="indigentForm">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

        <div class="form-grid">
            <div class="field">
                <label for="reference_code">Reference</label>
                <input type="text" id="reference_code" name="reference_code" required value="<?= e($suggestedRef) ?>" <?= $editing ? 'readonly' : '' ?>>
            </div>
            <div class="field"></div>

            <div class="field">
                <label for="first_name">Name</label>
                <input type="text" id="first_name" name="first_name" required value="<?= e($editing['first_name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="surname">Surname</label>
                <input type="text" id="surname" name="surname" required value="<?= e($editing['surname'] ?? '') ?>">
            </div>

            <div class="field full">
                <label for="id_number">South African ID number</label>
                <div style="display:flex; gap:10px; align-items:flex-start;">
                    <input type="text" inputmode="numeric" id="id_number" name="id_number" required maxlength="13"
                           style="flex:1;" value="<?= e($editing['id_number'] ?? '') ?>" placeholder="13-digit ID number">
                    <button type="button" class="btn btn-secondary" id="scanBtn" style="white-space:nowrap;">Scan barcode</button>
                </div>
                <input type="hidden" id="id_capture_method" name="id_capture_method" value="<?= e($editing['id_capture_method'] ?? 'manual') ?>">
                <p class="hint" id="scanHint">Scan the barcode on the applicant's ID card/book, or type the number in manually. Scanning uses this browser's camera and barcode detection — if either isn't supported, type the number instead.</p>
                <video id="scanVideo" style="display:none; width:100%; max-width:420px; margin-top:10px; border-radius:6px;" playsinline muted></video>
                <button type="button" class="btn btn-secondary btn-sm" id="cancelScanBtn" style="display:none; margin-top:8px;">Cancel scan</button>
                <p class="hint" id="idCheckResult"></p>
            </div>

            <div class="field full">
                <label>GPS coordinate (optional)</label>
                <div class="form-grid" style="margin:0;">
                    <div class="field">
                        <label for="latitude" style="font-weight:400;">Latitude</label>
                        <input type="text" inputmode="decimal" id="latitude" name="latitude" value="<?= e($editing['latitude'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="longitude" style="font-weight:400;">Longitude</label>
                        <input type="text" inputmode="decimal" id="longitude" name="longitude" value="<?= e($editing['longitude'] ?? '') ?>">
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" id="useLocationBtn">Use current location</button>
                <span class="hint" id="locationStatus"></span>
            </div>

            <div class="field full">
                <label for="household_photo">Photo of the household</label>
                <?php if (!empty($editing['household_photo_filename'])): ?>
                    <div style="margin-bottom:8px;">
                        <img src="<?= APP_BASE_URL ?>/indigent/view_file.php?id=<?= (int)$editing['id'] ?>&type=photo" alt="Current household photo" style="max-height:140px; border-radius:6px; display:block;">
                    </div>
                <?php endif; ?>
                <input type="file" id="household_photo" name="household_photo" accept=".png,.jpg,.jpeg,.gif">
                <p class="hint">PNG, JPG or GIF, max <?= INDIGENT_PHOTO_MAX_BYTES / 1024 / 1024 ?> MB. <?= $editing ? 'Leave blank to keep the current photo.' : '' ?></p>
            </div>

            <div class="field">
                <label for="province">Province</label>
                <select id="province" name="province" required>
                    <option value="">Select province…</option>
                    <?php foreach ($provinces as $p): ?>
                        <option value="<?= e($p) ?>" <?= ($editing['province'] ?? '') === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="district_municipality">District Municipality</label>
                <select id="district_municipality" name="district_municipality" required>
                    <option value="">Select province first…</option>
                </select>
            </div>
            <div class="field">
                <label for="local_municipality">Local Municipality</label>
                <select id="local_municipality" name="local_municipality" required>
                    <option value="">Select district first…</option>
                </select>
            </div>
            <div class="field">
                <label for="village_name">Village / area name</label>
                <input type="text" id="village_name" name="village_name" required value="<?= e($editing['village_name'] ?? '') ?>">
            </div>
            <div class="field full">
                <label for="physical_address">Physical address (optional detail)</label>
                <textarea id="physical_address" name="physical_address"><?= e($editing['physical_address'] ?? '') ?></textarea>
            </div>

            <div class="field full">
                <label>Village distance to Local Municipality office (km), for planning purposes</label>
                <div class="form-grid" style="margin:0;">
                    <div class="field">
                        <label for="distance_north_km" style="font-weight:400;">North</label>
                        <input type="text" inputmode="decimal" id="distance_north_km" name="distance_north_km" value="<?= e($editing['distance_north_km'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="distance_south_km" style="font-weight:400;">South</label>
                        <input type="text" inputmode="decimal" id="distance_south_km" name="distance_south_km" value="<?= e($editing['distance_south_km'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="distance_east_km" style="font-weight:400;">East</label>
                        <input type="text" inputmode="decimal" id="distance_east_km" name="distance_east_km" value="<?= e($editing['distance_east_km'] ?? '') ?>">
                    </div>
                    <div class="field">
                        <label for="distance_west_km" style="font-weight:400;">West</label>
                        <input type="text" inputmode="decimal" id="distance_west_km" name="distance_west_km" value="<?= e($editing['distance_west_km'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="field">
                <label for="number_of_pupils">Number of pupils in the household</label>
                <input type="text" inputmode="numeric" id="number_of_pupils" name="number_of_pupils" required value="<?= e($editing['number_of_pupils'] ?? '0') ?>">
            </div>
            <div class="field">
                <label for="monthly_income">Monthly household income (R)</label>
                <input type="text" inputmode="decimal" id="monthly_income" name="monthly_income" required value="<?= e($editing['monthly_income'] ?? '') ?>">
            </div>

            <div class="field full">
                <label style="display:flex; align-items:center; gap:6px;">
                    <input type="checkbox" name="has_functional_tv" value="1" style="width:auto;" <?= !empty($editing['has_functional_tv']) ? 'checked' : '' ?>>
                    Household has a functional television
                </label>
            </div>
        </div>

        <div class="card" style="background:#f8f8f6; margin-top:10px;">
            <h2>Declaration</h2>
            <p class="hint" style="margin-top:0;">The applicant declares, under signature below, that the household:</p>
            <div class="field" style="margin-bottom:10px;">
                <label style="display:flex; align-items:flex-start; gap:8px; font-weight:400;">
                    <input type="checkbox" name="declares_south_african" value="1" style="width:auto; margin-top:3px;" required <?= !empty($editing['declares_south_african']) ? 'checked' : '' ?>>
                    Is a South African citizen.
                </label>
            </div>
            <div class="field" style="margin-bottom:10px;">
                <label style="display:flex; align-items:flex-start; gap:8px; font-weight:400;">
                    <input type="checkbox" name="declares_income_under_3200" value="1" style="width:auto; margin-top:3px;" required <?= !empty($editing['declares_income_under_3200']) ? 'checked' : '' ?>>
                    Is a household earning less than R3,200 per month.
                </label>
            </div>
            <div class="field" style="margin-bottom:10px;">
                <label style="display:flex; align-items:flex-start; gap:8px; font-weight:400;">
                    <input type="checkbox" name="declares_sabc_concession" value="1" style="width:auto; margin-top:3px;" required <?= !empty($editing['declares_sabc_concession']) ? 'checked' : '' ?>>
                    Is a household covered by a SABC concessionary TV licence.
                </label>
            </div>
            <div class="field" style="margin-bottom:10px;">
                <label style="display:flex; align-items:flex-start; gap:8px; font-weight:400;">
                    <input type="checkbox" name="declares_grant_with_proof" value="1" style="width:auto; margin-top:3px;" required <?= !empty($editing['declares_grant_with_proof']) ? 'checked' : '' ?>>
                    Is a recipient of a social grant, and has provided proof of such.
                </label>
            </div>

            <div class="field">
                <label>Applicant signature</label>
                <canvas id="signatureCanvas" width="600" height="160" style="border:1px solid #c3ccd3; border-radius:4px; background:#fff; width:100%; max-width:600px; height:160px; touch-action:none;"></canvas>
                <input type="hidden" id="signature_data" name="signature_data" value="">
                <div class="actions-row">
                    <button type="button" class="btn btn-secondary btn-sm" id="clearSignatureBtn">Clear signature</button>
                </div>
                <p class="hint">The applicant signs here on this device to confirm the declaration above.</p>
            </div>
        </div>

        <div style="margin-top:20px;">
            <button type="submit" class="btn"><?= $editing ? 'Save changes' : 'Capture application' ?></button>
            <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/indigent/list.php">Cancel</a>
        </div>
    </form>
</div>

<script>
var SA_MUNICIPAL_STRUCTURE = <?= json_encode(sa_municipal_structure(), JSON_UNESCAPED_UNICODE) ?>;
(function () {
    // ---- Province -> District/Metro -> Local cascading dropdowns ----
    var existingProvince = <?= json_encode($editing['province'] ?? '') ?>;
    var existingDistrict = <?= json_encode($editing['district_municipality'] ?? '') ?>;
    var existingLocal = <?= json_encode($editing['local_municipality'] ?? '') ?>;

    var provinceSelect = document.getElementById('province');
    var districtSelect = document.getElementById('district_municipality');
    var localSelect = document.getElementById('local_municipality');

    function districtsFor(province) {
        return SA_MUNICIPAL_STRUCTURE[province] || [];
    }

    function populateDistricts(province, selectedDistrict) {
        var districts = districtsFor(province);
        districtSelect.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = districts.length ? 'Select district…' : 'Select province first…';
        districtSelect.appendChild(placeholder);
        var found = false;
        districts.forEach(function (row) {
            var o = document.createElement('option');
            o.value = row.district;
            o.textContent = row.type === 'metro' ? row.district + ' (Metropolitan Municipality)' : row.district;
            if (row.district === selectedDistrict) { o.selected = true; found = true; }
            districtSelect.appendChild(o);
        });
        if (selectedDistrict && !found) {
            var fallback = document.createElement('option');
            fallback.value = selectedDistrict;
            fallback.textContent = selectedDistrict + ' (previously captured — please reselect)';
            fallback.selected = true;
            districtSelect.appendChild(fallback);
        }
    }

    function populateLocals(province, district, selectedLocal) {
        var row = districtsFor(province).filter(function (r) { return r.district === district; })[0];
        var locals = row ? row.locals : [];
        localSelect.innerHTML = '';
        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = locals.length ? 'Select local municipality…' : 'Select district first…';
        localSelect.appendChild(placeholder);
        var found = false;
        locals.forEach(function (name) {
            var o = document.createElement('option');
            o.value = name;
            o.textContent = name;
            if (name === selectedLocal) { o.selected = true; found = true; }
            localSelect.appendChild(o);
        });
        if (selectedLocal && !found) {
            var fallback = document.createElement('option');
            fallback.value = selectedLocal;
            fallback.textContent = selectedLocal + ' (previously captured — please reselect)';
            fallback.selected = true;
            localSelect.appendChild(fallback);
        }
    }

    provinceSelect.addEventListener('change', function () {
        populateDistricts(provinceSelect.value, '');
        populateLocals(provinceSelect.value, '', '');
    });
    districtSelect.addEventListener('change', function () {
        populateLocals(provinceSelect.value, districtSelect.value, '');
    });

    populateDistricts(existingProvince, existingDistrict);
    populateLocals(existingProvince, existingDistrict, existingLocal);

    // ---- Signature pad ----
    var canvas = document.getElementById('signatureCanvas');
    var ctx = canvas.getContext('2d');
    var ratio = canvas.width / canvas.getBoundingClientRect().width || 1;
    ctx.strokeStyle = '#1d2730';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    var drawing = false, lastX = 0, lastY = 0, hasDrawn = false;

    function pos(e) {
        var rect = canvas.getBoundingClientRect();
        var t = e.touches && e.touches.length ? e.touches[0] : e;
        var scaleX = canvas.width / rect.width;
        var scaleY = canvas.height / rect.height;
        return [(t.clientX - rect.left) * scaleX, (t.clientY - rect.top) * scaleY];
    }
    function start(e) { drawing = true; var p = pos(e); lastX = p[0]; lastY = p[1]; e.preventDefault(); }
    function move(e) {
        if (!drawing) return;
        var p = pos(e);
        ctx.beginPath(); ctx.moveTo(lastX, lastY); ctx.lineTo(p[0], p[1]); ctx.stroke();
        lastX = p[0]; lastY = p[1]; hasDrawn = true;
        document.getElementById('signature_data').value = canvas.toDataURL('image/png');
        e.preventDefault();
    }
    function end() { drawing = false; }
    canvas.addEventListener('mousedown', start);
    canvas.addEventListener('mousemove', move);
    window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, { passive: false });
    canvas.addEventListener('touchmove', move, { passive: false });
    canvas.addEventListener('touchend', end);

    document.getElementById('clearSignatureBtn').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        document.getElementById('signature_data').value = '';
        hasDrawn = false;
    });

    document.getElementById('indigentForm').addEventListener('submit', function (e) {
        if (!hasDrawn) {
            e.preventDefault();
            alert('Please have the applicant sign before submitting.');
        }
    });

    // ---- ID number live checksum feedback ----
    var idInput = document.getElementById('id_number');
    var idResult = document.getElementById('idCheckResult');
    idInput.addEventListener('input', function () {
        var v = idInput.value.replace(/\D/g, '');
        if (v.length === 13) {
            idResult.textContent = 'ID number entered (13 digits). Checksum is validated on save.';
        } else if (v.length > 0) {
            idResult.textContent = v.length + ' of 13 digits entered.';
        } else {
            idResult.textContent = '';
        }
        if (document.getElementById('id_capture_method').value !== 'scanned') {
            document.getElementById('id_capture_method').value = 'manual';
        }
    });

    // ---- GPS ----
    document.getElementById('useLocationBtn').addEventListener('click', function () {
        var status = document.getElementById('locationStatus');
        if (!navigator.geolocation) {
            status.textContent = ' Geolocation is not supported in this browser.';
            return;
        }
        status.textContent = ' Getting location…';
        navigator.geolocation.getCurrentPosition(
            function (p) {
                document.getElementById('latitude').value = p.coords.latitude.toFixed(7);
                document.getElementById('longitude').value = p.coords.longitude.toFixed(7);
                status.textContent = ' Location captured.';
            },
            function (err) { status.textContent = ' Could not get location: ' + err.message; },
            { enableHighAccuracy: true, timeout: 10000 }
        );
    });

    // ---- Barcode scan (native Shape Detection API — no library) ----
    var scanBtn = document.getElementById('scanBtn');
    var cancelBtn = document.getElementById('cancelScanBtn');
    var video = document.getElementById('scanVideo');
    var scanHint = document.getElementById('scanHint');
    var stream = null, detectTimer = null;

    function stopScan() {
        if (detectTimer) { clearInterval(detectTimer); detectTimer = null; }
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        video.style.display = 'none';
        cancelBtn.style.display = 'none';
    }

    scanBtn.addEventListener('click', function () {
        if (!('BarcodeDetector' in window)) {
            scanHint.textContent = 'Barcode scanning is not supported in this browser — please type the ID number manually.';
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            scanHint.textContent = 'Camera access is not available in this browser — please type the ID number manually.';
            return;
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (s) {
            stream = s;
            video.srcObject = s;
            video.style.display = 'block';
            cancelBtn.style.display = 'inline-block';
            video.play();
            var detector = new BarcodeDetector({ formats: ['pdf417', 'code_39', 'code_128', 'ean_13', 'itf'] });
            detectTimer = setInterval(function () {
                detector.detect(video).then(function (codes) {
                    if (codes.length) {
                        var digits = (codes[0].rawValue || '').replace(/\D/g, '');
                        var match = digits.match(/\d{13}/);
                        if (match) {
                            idInput.value = match[0];
                            document.getElementById('id_capture_method').value = 'scanned';
                            idResult.textContent = 'ID number captured from barcode scan.';
                            stopScan();
                        }
                    }
                }).catch(function () {});
            }, 400);
        }).catch(function (err) {
            scanHint.textContent = 'Could not access the camera: ' + err.message + ' — please type the ID number manually.';
        });
    });
    cancelBtn.addEventListener('click', stopScan);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
