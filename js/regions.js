// Unified region keywords (lowercase)
const REGIONS = {
  wales: [
    'anglesey','holyhead','cardiff','swansea','bridgend','pembrokeshire',
    'carmarthenshire','flintshire','wrexham','conwy','denbighshire',
    'porthcawl','the mumbles','tenby'
  ],
  scotland: [
  'edinburgh','glasgow','aberdeen','inverness','dundee','fife','ayrshire',
  'moray','highland','orkney','shetland','outer hebrides','western isles',
  'largs','strathclyde','portree','skye','isle of skye','campbeltown','argyll',
  'grampian'   
],
  england: [
    'london','cornwall','devon','dorset','kent','essex','sussex','norfolk',
    'yorkshire','lancashire','merseyside','northumberland','cumbria',
    'somerset','lincolnshire','hampshire','isle of wight','durham','tyne',
    'hartlepool','whitby','scarborough','redcar','blackpool','south shields',
    'jersey','guernsey','alderney','isle of man'
  ],
  ireland: [
    'dublin','galway','cork','belfast','londonderry','waterford',
    'dún laoghaire','dun laoghaire'
  ]
};

// One unified detector for both pages
function detectRegion(entry) {
  const hay = (
    (entry.stationName || entry.shortName || '') + ' ' +
    (entry.description || entry.title || '') + ' ' +
    (entry.location || '') + ' ' +
    (entry.website || '')
  ).toLowerCase();

  for (const [region, words] of Object.entries(REGIONS)) {
    if (words.some(w => hay.includes(w))) return region; // 'wales' | 'scotland' | 'england' | 'ireland'
  }
  return null;
}

// Adapters to keep existing code working without edits:

// index.html used entryRegion(...) and expects UPPERCASE like 'WALES'
window.entryRegion = (l) => {
  const r = detectRegion(l);
  return r ? r.toUpperCase() : null;
};

// archive.html used inRegion(s, 'wales') etc.
window.inRegion = (s, region) => {
  const r = detectRegion(s);
  return !!r && r === String(region || '').toLowerCase();
};

// also expose REGIONS + detectRegion in case you want them directly
window.REGIONS = REGIONS;
window.detectRegion = detectRegion;