/* Shared StreetFix map helper: status-coloured pins, report popups, legend.
   Used by the Admin, Citizen and Road Maintenance pages. Loaded after Leaflet. */
window.StreetFixMap = (() => {
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
  const statusColors = {
    'Submitted': 'var(--me)', 'Under Review': 'var(--me)', 'Verified': 'var(--pu)',
    'Assigned': 'var(--bl)', 'In Progress': 'var(--bl)', 'Resolved': 'var(--lo)',
    'Rejected': 'var(--gray)', 'Duplicate': 'var(--gray)', 'Cancelled': 'var(--gray)'
  };
  const legend = [['Pending', 'var(--me)'], ['Verified', 'var(--pu)'], ['Assigned / in progress', 'var(--bl)'], ['Resolved', 'var(--lo)'], ['Rejected / duplicate', 'var(--gray)']];

  function popupHTML(pin, info) {
    const photos = info?.photos?.filter(Boolean) || [];
    const row = (label, value) => value ? `<div class="sf-row"><span>${label}</span>${esc(value)}</div>` : '';
    return `<div class="sf-pop">
      ${photos.length ? `<a href="${esc(photos[0])}" target="_blank" rel="noopener"><img src="${esc(photos[0])}" alt="Photo of ${esc(pin.road)}" loading="lazy"></a>` : ''}
      <div class="sf-head"><b>${esc(pin.code)}</b>${(pin.severities || [pin.severity]).map(s => `<span class="sev ${esc(s)}">${esc(s)}</span>`).join('–')}<span class="pill s-${esc(pin.status).replace(/ /g, '-')}">${esc(pin.status)}</span></div>
      <div class="sf-road">${esc(pin.road)}</div>
      ${pin.count > 1 ? `<div class="sf-row"><span>Reports</span>${pin.count} reports of this pothole</div>` : ''}
      ${row('Reported by', info ? info.reporter : 'Another citizen (identity private)')}
      ${row('Date', info?.date)}${row('Address', info?.address)}${row('Details', info?.description)}
      ${photos.length > 1 ? `<div class="sf-thumbs">${photos.map((p, i) => `<a href="${esc(p)}" target="_blank" rel="noopener">Photo ${i + 1}</a>`).join(' · ')}</div>` : ''}
      ${info?.action ? `<a class="btn sm" href="${esc(info.action.href)}">${esc(info.action.label)}</a>` : ''}
    </div>`;
  }

  /* create(map, infoFor): infoFor(code) returns {reporter,date,address,description,photos,action} or null,
     and is evaluated when a popup opens, so it always uses the latest loaded report data. */
  function create(map, infoFor) {
    const layer = L.layerGroup().addTo(map);
    if (window.ResizeObserver) new ResizeObserver(() => map.invalidateSize()).observe(map.getContainer());
    const markers = {};
    let signature = '', openCode = null, redrawing = false, fitted = false;
    map.on('popupopen', e => { openCode = e.popup._sfCode || null; });
    map.on('popupclose', () => { if (!redrawing) openCode = null; });
    map.getContainer().insertAdjacentHTML('afterend',
      `<ul class="map-legend" aria-label="Marker colours">${legend.map(([l, c]) => `<li><i style="background:${c}"></i>${l}</li>`).join('')}</ul>`);

    function draw(pins) {
      const next = JSON.stringify(pins);
      if (next === signature) return;            // unchanged data: keep any open popup
      signature = next; redrawing = true;
      layer.clearLayers(); Object.keys(markers).forEach(k => delete markers[k]);
      pins.forEach(pin => {
        const icon = L.divIcon({className: 'sf-pin', html: `<i style="--c:${statusColors[pin.status] || 'var(--gray)'}"></i>${pin.count > 1 ? `<span class="sf-count" aria-hidden="true">${pin.count > 99 ? '99+' : pin.count}</span>` : ''}`, iconSize: [22, 22], iconAnchor: [11, 21], popupAnchor: [0, -18]});
        const marker = L.marker([pin.lat, pin.lng], {icon, title: `${pin.code} · ${pin.status}${pin.count > 1 ? ' · ' + pin.count + ' reports' : ''}`, alt: `${pin.code} ${pin.status}${pin.count > 1 ? ', ' + pin.count + ' reports' : ''}`}).addTo(layer);
        marker.bindPopup(() => popupHTML(pin, infoFor(pin.code)), {maxWidth: 290, minWidth: 220});
        marker.getPopup()._sfCode = pin.code;
        markers[pin.code] = marker;
      });
      if (openCode && markers[openCode]) markers[openCode].openPopup();
      redrawing = false;
      if (!fitted && pins.length) { map.fitBounds(pins.map(p => [p.lat, p.lng]), {padding: [25, 25], maxZoom: 16}); fitted = true; }
    }
    return {draw, open: code => { const m = markers[code]; if (!m) return false; map.setView(m.getLatLng(), Math.max(map.getZoom(), 16)); m.openPopup(); return true; }};
  }
  return {create, statusColors};
})();
