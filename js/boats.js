// Unified boat class detection + helpers

const BOAT_CLASS_LINKS = {
  'D-class Inshore': 'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/d-class-lifeboat',
  'Atlantic (B-class) Inshore': 'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/b-class-lifeboat',
  'E-class (Thames) Inshore': 'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/e-class-lifeboat',
  'Hovercraft': 'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/rescue-hovercraft',
  'Shannon class All-Weather': 'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/shannon-class-lifeboat',
  'Severn class All-Weather':  'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/severn-class-lifeboat',
  'Tamar class All-Weather':   'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/tamar-class-lifeboat',
  'Trent class All-Weather':   'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/trent-class-lifeboat',
  'Mersey class All-Weather':  'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/mersey-class-lifeboat',
  'XP-class':                  'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/inshore-rescue-boat'
};

// Returns a class name string or null if unknown
function boatClassFromId(op) {
  if (!op) return null;
  const s = String(op).trim().toUpperCase();
  if (/^D-\d+/.test(s))  return 'D-class Inshore';
  if (/^B-\d+/.test(s))  return 'Atlantic (B-class) Inshore';
  if (/^E-\d+/.test(s))  return 'E-class (Thames) Inshore';
  if (/^H-\d+/.test(s))  return 'Hovercraft';
  if (/^(XP|X)-?\d+/.test(s)) return 'XP-class'; // catches "XP-142" and "X-142"
  if (/^13-\d+/.test(s)) return 'Shannon class All-Weather';
  if (/^17-\d+/.test(s)) return 'Severn class All-Weather';
  if (/^16-\d+/.test(s)) return 'Tamar class All-Weather';
  if (/^14-\d+/.test(s)) return 'Trent class All-Weather';
  if (/^12-\d+/.test(s)) return 'Mersey class All-Weather';
  return null;
}

// Convenience: extract id, class, and link from a live/archive entry
window.boatInfo = function(entry) {
  const idNo = entry?.lifeboat_IdNo || null;
  const className = idNo ? boatClassFromId(idNo) : null;
  const classLink = className ? (BOAT_CLASS_LINKS[className] || null) : null;
  return { idNo, className, classLink };
};

// Stats helper: count classes across a list of entries (returns {className: count, ...})
window.tallyBoatClasses = function(items) {
  const counts = {};
  (items || []).forEach(e => {
    const { className } = boatInfo(e);
    const key = className || 'Unknown';
    counts[key] = (counts[key] || 0) + 1;
  });
  return counts;
};

// Debug helper: log entries where class is unknown (no operational number or pattern)
window.logUnknownBoatClasses = function(items) {
  const unknown = (items || []).filter(e => !boatInfo(e).className);
  if (!unknown.length) {
    console.log('All entries have a known boat class ✔️');
    return;
  }
  console.group('Unknown boat class entries (' + unknown.length + ')');
  unknown.forEach(e => {
    console.log(
      'Station:', e.shortName || e.stationName || '(none)',
      '| OpNo:', e.lifeboat_IdNo || '(none)',
      '| Title:', e.title || e.description || '(none)'
    );
  });
  console.groupEnd();
};

// Expose links too (optional for UI)
window.BOAT_CLASS_LINKS = BOAT_CLASS_LINKS;
window.boatClassFromId  = boatClassFromId;