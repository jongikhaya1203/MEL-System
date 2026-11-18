<?php
/**
 * Broadband > New Sites Awaiting Rollout > map. Plots every listed site that has GPS coordinates,
 * coloured by deployment state: red = New (not deployed), green = Online (deployed), orange = Offline.
 * Uses the same filters as the list (rollout_filters.php). Editors can change a site's state from its popup.
 * Map library: Leaflet 1.9.4 (unpkg CDN); tiles: OpenStreetMap streets / Esri World Imagery — needs internet access.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/functions.php';
require_login();

$canChange = can_change_connectivity(current_user()['role']);
require __DIR__ . '/rollout_filters.php';

$sql = "SELECT r.id, r.reference_code, r.site_name, r.site_type, r.province, r.district_municipality, r.local_municipality,
               r.town_village, r.contact_details, r.latitude, r.longitude, r.status, r.connectivity_status, r.connectivity_changed_at,
               p.project_name
        FROM broadband_rollout_sites r JOIN projects p ON p.id = r.project_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$all = $stmt->fetchAll();

$points = [];
$noGps = 0;
$noGpsSites = [];
foreach ($all as $r) {
    if ($r['latitude'] === null || $r['longitude'] === null || !is_numeric($r['latitude']) || !is_numeric($r['longitude'])) {
        $noGps++;
        $noGpsSites[] = $r;
        continue;
    }
    $points[] = [
        'id' => (int)$r['id'], 'ref' => $r['reference_code'], 'name' => $r['site_name'], 'type' => $r['site_type'],
        'lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude'],
        'muni' => trim(($r['local_municipality'] ?? '') . ($r['province'] ? ', ' . $r['province'] : ''), ', '),
        'town' => $r['town_village'], 'contact' => $r['contact_details'], 'project' => $r['project_name'],
        'status' => $r['status'], 'conn' => $r['connectivity_status'],
        'changed' => $r['connectivity_changed_at'] ? format_datetime($r['connectivity_changed_at']) : null,
    ];
}
$statuses = connectivity_statuses();

$pageTitle = 'Rollout sites map';
include __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<style>
    #siteMap { height: calc(100vh - 290px); min-height: 460px; border: 1px solid var(--border); border-radius: 6px; z-index: 0; }
    .map-bar { display: flex; flex-wrap: wrap; gap: 10px 18px; align-items: center; margin-bottom: 12px; }
    .map-legend label { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-weight: 600; font-size: 13px; cursor: pointer; }
    .map-legend .dot { width: 13px; height: 13px; border-radius: 50%; display: inline-block; box-shadow: 0 0 0 1.5px #fff, 0 0 0 2.5px rgba(0,0,0,.25); }
    .map-search { display: flex; gap: 6px; margin-left: auto; }
    .map-search input { width: 230px; }
    .site-pop { font-size: 13px; line-height: 1.45; min-width: 220px; }
    .site-pop h4 { margin: 0 0 2px; font-size: 14px; }
    .site-pop .ref { font-family: monospace; color: #5b6b78; font-size: 12px; }
    .site-pop dl { display: grid; grid-template-columns: auto 1fr; gap: 1px 8px; margin: 8px 0; }
    .site-pop dt { color: #6b7a85; }
    .site-pop dd { margin: 0; }
    .site-pop .pop-actions { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 6px; }
    .site-pop select { width: auto; padding: 2px 4px; }
    @media (max-width: 760px) { .map-search { margin-left: 0; width: 100%; } .map-search input { flex: 1; width: auto; } #siteMap { height: 70vh; } }
</style>

<div class="page-header">
    <div>
        <h1>Rollout sites map</h1>
        <p class="subtitle"><?= count($points) ?> site<?= count($points) === 1 ? '' : 's' ?> plotted
            <?php if ($noGps): ?>&middot; <?= $noGps ?> listed site<?= $noGps === 1 ? ' has' : 's have' ?> no GPS coordinates and can't be shown<?php endif; ?></p>
    </div>
    <a class="btn btn-secondary" href="<?= APP_BASE_URL ?>/broadband/rollout_list.php<?= $filterQuery !== '' ? '?' . e($filterQuery) : '' ?>">Back to the list</a>
</div>

<div class="card">
    <div class="map-bar">
        <div class="map-legend map-bar" style="margin:0;">
            <?php foreach ($statuses as $key => $c): ?>
                <label><input type="checkbox" class="legend-toggle" value="<?= $key ?>" checked>
                    <span class="dot" style="background:<?= $c['color'] ?>"></span><?= e($c['label']) ?>
                    (<span data-count="<?= $key ?>">0</span>)</label>
            <?php endforeach; ?>
        </div>
        <form class="map-search" id="mapSearch" onsubmit="return false;">
            <input type="search" id="mapSearchInput" list="siteNames" placeholder="Find a site…" aria-label="Find a site on the map">
            <datalist id="siteNames"></datalist>
            <button type="submit" class="btn btn-secondary btn-sm">Go</button>
        </form>
    </div>
    <?php if ($filterQuery !== ''): ?>
        <p class="hint" style="margin:-4px 0 10px;">Showing the sites that match the list's current filters.
            <a href="<?= APP_BASE_URL ?>/broadband/rollout_map.php?all=1">Show every site</a></p>
    <?php endif; ?>
    <div id="siteMap" role="region" aria-label="Map of rollout sites"></div>
    <?php if (!$points): ?>
        <p class="hint" style="margin-top:10px;">No listed sites have GPS coordinates yet. Add coordinates on the site's edit form or through an import.</p>
    <?php endif; ?>
</div>

<?php if ($noGpsSites): ?>
<?php
    // Changed sites first, so a status that was just set but isn't on the map is easy to find.
    usort($noGpsSites, fn($a, $b) => strcmp((string)$b['connectivity_changed_at'], (string)$a['connectivity_changed_at']));
    $canEdit = in_array(current_user()['role'], ['admin', 'manager', 'planner'], true);
?>
<div class="card">
    <details>
        <summary style="cursor:pointer; font-weight:600;">&#9888; <?= $noGps ?> site<?= $noGps === 1 ? '' : 's' ?> not on the map &mdash; no GPS coordinates</summary>
        <p class="hint">Their deployment status is saved, but there is nowhere to plot them. Add latitude and longitude on the site's Edit form (or re-import with GPS) and they will appear in their colour.</p>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Reference</th><th>Site</th><th>Deployment</th><th>Province / Local Mun</th><th>Status changed</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($noGpsSites as $r): $c = $statuses[$r['connectivity_status']] ?? $statuses['New']; ?>
                <tr>
                    <td><?= e($r['reference_code']) ?></td>
                    <td><?= e($r['site_name']) ?></td>
                    <td style="white-space:nowrap;"><span class="dot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?= $c['color'] ?>"></span> <?= e($r['connectivity_status']) ?></td>
                    <td><?= e($r['province'] ?? '—') ?><?= $r['local_municipality'] ? ' / ' . e($r['local_municipality']) : '' ?></td>
                    <td><?= $r['connectivity_changed_at'] ? e(format_datetime($r['connectivity_changed_at'])) : '—' ?></td>
                    <td><?php if ($canEdit): ?><a href="<?= APP_BASE_URL ?>/broadband/rollout_form.php?id=<?= (int)$r['id'] ?>">Add GPS</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </details>
</div>
<?php endif; ?>

<?= $canChange ? '<form id="tokenForm" hidden>' . csrf_field() . '</form>' : '' ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script>
// A copy restored from the back/forward cache would show markers from before a status change.
window.addEventListener('pageshow', function (e) { if (e.persisted) { location.reload(); } });
(function () {
    var SITES = <?= json_encode($points, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var STATUSES = <?= json_encode($statuses, JSON_UNESCAPED_UNICODE) ?>;
    var CAN_CHANGE = <?= $canChange ? 'true' : 'false' ?>;
    var BASE = <?= json_encode(APP_BASE_URL) ?>;
    var mapEl = document.getElementById('siteMap');

    if (typeof L === 'undefined') {
        mapEl.innerHTML = '<p style="padding:20px;">The map could not load — it needs an internet connection to fetch the map library and tiles.</p>';
        return;
    }

    var streets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap contributors'
    });
    var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        maxZoom: 19, attribution: 'Imagery &copy; Esri, Maxar, Earthstar Geographics'
    });
    var map = L.map(mapEl, { layers: [streets] }).setView([-29.0, 25.0], 5); // South Africa
    L.control.layers({ 'Streets': streets, 'Satellite': satellite }, null, { position: 'topright' }).addTo(map);
    L.control.scale({ imperial: false }).addTo(map);

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    var groups = {};
    Object.keys(STATUSES).forEach(function (k) { groups[k] = L.featureGroup().addTo(map); });
    var markers = {};

    function popupHtml(s) {
        var html = '<div class="site-pop"><h4>' + esc(s.name) + '</h4><div class="ref">' + esc(s.ref) + '</div><dl>' +
            '<dt>Deployment</dt><dd><span class="dot" style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' +
                STATUSES[s.conn].color + '"></span> ' + esc(STATUSES[s.conn].label) + (s.changed ? '<br><small>since ' + esc(s.changed) + '</small>' : '') + '</dd>' +
            (s.type ? '<dt>Type</dt><dd>' + esc(s.type) + '</dd>' : '') +
            (s.muni ? '<dt>Municipality</dt><dd>' + esc(s.muni) + '</dd>' : '') +
            (s.town ? '<dt>Village/Town</dt><dd>' + esc(s.town) + '</dd>' : '') +
            (s.contact ? '<dt>Contact</dt><dd>' + esc(s.contact) + '</dd>' : '') +
            '<dt>Rollout</dt><dd>' + esc(s.status) + '</dd>' +
            '<dt>GPS</dt><dd>' + s.lat.toFixed(5) + ', ' + s.lng.toFixed(5) + '</dd></dl><div class="pop-actions">';
        if (CAN_CHANGE) {
            html += '<select data-site="' + s.id + '" class="pop-conn" aria-label="Deployment status">';
            Object.keys(STATUSES).forEach(function (k) {
                html += '<option value="' + k + '"' + (k === s.conn ? ' selected' : '') + '>' + k + '</option>';
            });
            html += '</select>';
        }
        html += '<a href="' + BASE + '/broadband/pre_assessment.php?id=' + s.id + '">Pre-assessment</a>' +
            '<a href="https://www.google.com/maps/dir/?api=1&destination=' + s.lat + ',' + s.lng + '" target="_blank" rel="noopener">Directions</a></div></div>';
        return html;
    }

    function styleFor(conn) {
        return { radius: 8, color: '#ffffff', weight: 2, fillColor: STATUSES[conn].color, fillOpacity: 0.95 };
    }

    SITES.forEach(function (s) {
        var m = L.circleMarker([s.lat, s.lng], styleFor(s.conn));
        m.bindTooltip(esc(s.name));
        m.bindPopup(function () { return popupHtml(s); });
        m.site = s;
        m.addTo(groups[s.conn]);
        markers[s.id] = m;
    });

    function updateCounts() {
        Object.keys(STATUSES).forEach(function (k) {
            var el = document.querySelector('[data-count="' + k + '"]');
            if (el) { el.textContent = groups[k].getLayers().length; }
        });
    }
    updateCounts();

    var all = L.featureGroup(Object.keys(groups).map(function (k) { return groups[k]; }));
    if (SITES.length) { map.fitBounds(all.getBounds().pad(0.08), { maxZoom: 14 }); }

    document.querySelectorAll('.legend-toggle').forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (cb.checked) { groups[cb.value].addTo(map); } else { map.removeLayer(groups[cb.value]); }
        });
    });

    // Find a site by name or reference.
    var list = document.getElementById('siteNames');
    SITES.forEach(function (s) { var o = document.createElement('option'); o.value = s.name + ' — ' + s.ref; list.appendChild(o); });
    document.getElementById('mapSearch').addEventListener('submit', function () {
        var q = document.getElementById('mapSearchInput').value.toLowerCase().trim();
        if (!q) { return; }
        var hit = SITES.filter(function (s) { return (s.name + ' — ' + s.ref).toLowerCase() === q; })[0] ||
                  SITES.filter(function (s) { return (s.name + ' ' + s.ref).toLowerCase().indexOf(q) !== -1; })[0];
        if (!hit) { alert('No plotted site matches "' + q + '".'); return; }
        var m = markers[hit.id];
        var cb = document.querySelector('.legend-toggle[value="' + hit.conn + '"]');
        if (cb && !cb.checked) { cb.checked = true; groups[hit.conn].addTo(map); }
        map.setView(m.getLatLng(), 15);
        m.openPopup();
    });

    // Change New / Online / Offline from a popup: saved immediately, marker recoloured.
    if (CAN_CHANGE) {
        var token = document.querySelector('#tokenForm input[name="csrf_token"]').value;
        map.on('popupopen', function (e) {
            var sel = e.popup.getElement().querySelector('.pop-conn');
            if (!sel) { return; }
            sel.addEventListener('change', function () {
                var id = +sel.getAttribute('data-site'), value = sel.value, m = markers[id], old = m.site.conn;
                var body = new FormData();
                body.append('csrf_token', token); body.append('ids[]', id); body.append('connectivity_status', value);
                sel.disabled = true;
                fetch(BASE + '/broadband/rollout_connectivity.php', { method: 'POST', body: body, headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res.ok) { throw new Error(res.message); }
                        groups[old].removeLayer(m);
                        m.site.conn = value;
                        m.site.changed = 'just now';
                        m.setStyle(styleFor(value));
                        m.addTo(groups[value]);
                        var cb = document.querySelector('.legend-toggle[value="' + value + '"]');
                        if (cb && !cb.checked) { map.removeLayer(m); }
                        updateCounts();
                        m.setPopupContent(popupHtml(m.site));
                    })
                    .catch(function (err) { sel.value = old; alert('Could not change the status: ' + (err.message || 'network error')); })
                    .finally(function () { sel.disabled = false; });
            });
        });
    }
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
