const DATA_FILE_NAME = 'committee-manager-data.json';

function doGet() {
  return HtmlService
    .createHtmlOutputFromFile('Index')
    .setTitle('Committee Manager')
    .setXFrameOptionsMode(HtmlService.XFrameOptionsMode.ALLOWALL);
}

function getDataFile_() {
  const files = DriveApp.getFilesByName(DATA_FILE_NAME);
  if (files.hasNext()) return files.next();
  return DriveApp.createFile(DATA_FILE_NAME, '{}', MimeType.PLAIN_TEXT);
}

function loadDriveData() {
  const file = getDataFile_();
  const text = file.getBlob().getDataAsString() || '{}';
  try {
    const data = JSON.parse(text);
    return Object.keys(data).length ? data : null;
  } catch (err) {
    throw new Error('Drive data file is not valid JSON: ' + err.message);
  }
}

function saveDriveData(data) {
  const lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    const file = getDataFile_();
    const payloadObj = data || {};
    if (!payloadObj.updatedAt) payloadObj.updatedAt = Date.now();
    const payload = JSON.stringify(payloadObj, null, 2);
    file.setContent(payload);
    return {
      ok: true,
      fileName: DATA_FILE_NAME,
      updatedAt: payloadObj.updatedAt
    };
  } finally {
    lock.releaseLock();
  }
}
