const { chromium } = require('playwright');

function launchBrowser() {
  // CI uses the browser revision locked to Playwright. An explicit channel lets
  // local developers use an already installed browser without an extra download.
  const channel = process.env.PLAYWRIGHT_CHANNEL;
  return chromium.launch({ headless: true, ...(channel ? { channel } : {}) });
}

module.exports = { launchBrowser };
