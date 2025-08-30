// Unified boat class detection + helpers for both live + archive

// RNLI class -> reference page
const BOAT_CLASS_LINKS = {
  'D-class Inshore':             'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/d-class-lifeboat',
  'Atlantic (B-class) Inshore':  'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/b-class-lifeboat',
  'E-class (Thames) Inshore':    'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/e-class-lifeboat',
  'Hovercraft':                  'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/rescue-hovercraft',
  'XP-class':                    'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/inshore-rescue-boat',
  'Shannon class All-Weather':   'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/shannon-class-lifeboat',
  'Severn class All-Weather':    'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/severn-class-lifeboat',
  'Tamar class All-Weather':     'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/tamar-class-lifeboat',
  'Trent class All-Weather':     'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/trent-class-lifeboat',
  'Mersey class All-Weather':    'https://rnli.org/what-we-do/lifeboats-and-stations/our-lifeboat-fleet/mersey-class-lifeboat',
  // Older/retired classes (useful for historic items)
  'Tyne class All-Weather':      'https://rnli.org/about-us/our-history/previous-lifeboats/tyne-class-lifeboat',
  'Arun class All-Weather':      'https://rnli.org/about-us/our-history/previous-lifeboats/arun-class-lifeboat',
  'Waveney class All-Weather':   'https://rnli.org/about-us/our-history/previous-lifeboats/waveney-class-lifeboat'
};

// Order matters: first match wins
const BOAT_PATTERNS = [
  { name: 'D-class Inshore',            re: /^D-\d+$/i },
  // B or BB (e.g. B-877, BB-683)
  { name: 'Atlantic (B-class) Inshore', re: /^B{1,2}-\d+$/i },
  { name: 'E-class (Thames) Inshore',   re: /^E-\d+$/i },
  { name: 'Hovercraft',                 re: /^H-\d+$/i },
  // XP-class small boats (XP-142 or X-142)
  { name: 'XP-class',                   re: /^(XP|X)-?\d+$/i },

  // Current all-weather classes (two-digit prefix)
  { name: 'Shannon class All-Weather',  re: /^13-\d+$/i },
  { name: 'Severn class All-Weather',   re: /^17-\d+$/i },
  { name: 'Tamar class All-Weather',    re: /^16-\d+$/i },
  { name: 'Trent class All-Weather',    re: /^14-\d+$/i },
  { name: 'Mersey class All-Weather',   re: /^12-\d+$/i },

  // Historic (seen in older datasets)
  { name: 'Tyne class All-Weather',     re: /^47-\d+$/i },
  { name: 'Arun class All-Weather',     re: /^52-\d+$/i },
  { name: 'Waveney class All-Weather',  re: /^44-\d+$/i }
];

/** Returns a class name string or null if unknown */
function boatClassFromId(op) {
  if (!op) return null;
  const s = String(op).trim().toUpperCase();
  for (const {name, re} of BOAT_PATTERNS) {
    if (re.test(s)) return name;
  }
  return null;
}

/** Convenience: extract id, class, and link from a live/archive entry */
window.boatInfo = function(entry) {
  const idNo = entry?.lifeboat_IdNo || null;
  const className = idNo ? boatClassFromId(idNo) : null;
  const classLink = className ? (BOAT_CLASS_LINKS[className] || null) : null;
  return { idNo, className, classLink };
};

/** Stats helper: count classes across a list (returns {className: count, ...}; Unknown if none) */
window.tallyBoatClasses = function(items) {
  const counts = {};
  (items || []).forEach(e => {
    const { className } = boatInfo(e);
    const key = className || 'Unknown';
    counts[key] = (counts[key] || 0) + 1;
  });
  return counts;
};

/** Debug helper: log entries where class is unknown (no OpNo or unmatched pattern) */
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

// Expose
window.BOAT_CLASS_LINKS = BOAT_CLASS_LINKS;
window.boatClassFromId  = boatClassFromId;