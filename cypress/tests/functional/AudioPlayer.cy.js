/**
 * @file cypress/tests/functional/AudioPlayer.cy.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Functional tests on OMP.
 *
 * Parameters (--env): contextPath, adminUser, adminPassword (captcha on login
 * must be off for the run). The defaults match the data set of PKP's
 * continuous integration, and the first test enables the plugin when it is off.
 * Every setting touched is put back as it was.
 *
 * The player tests also need audioBookPage: the path, from the site root, of a
 * book page with an audio publication format (for example
 * index.php/press/catalog/book/14). They are skipped without it: the data set of
 * PKP's continuous integration has no audio.
 */

describe('Audio Player plugin', function() {
	const contextPath = Cypress.env('contextPath') || 'publicknowledge';
	const adminUser = Cypress.env('adminUser') || 'admin';
	const adminPassword = Cypress.env('adminPassword') || 'admin';
	const audioBookPage = Cypress.env('audioBookPage');

	const rowName = 'audioplayerplugin';
	const settingsForm = 'form[id="audioPlayerSettings"]';

	// ---- OJSBR spec helpers (padrão v2): work on OJS/OMP 3.3, 3.4 and 3.5 and in PKP's CI ----

	const pageUrl = (path) => '/index.php/' + contextPath + (path ? '/' + path : '');

	// Same as PKP's cy.waitJQuery(), which the support files of OJS 3.3 test sites may lack.
	// The Plugins tab can keep requests open for a while (the plugin gallery), hence the timeout.
	const waitJQuery = () => cy.window().its('jQuery.active', {timeout: 60000}).should('eq', 0);

	// Requests carry the browser's User-Agent: OJS 3.3 drops a session whose agent changes.
	const request = (options) => cy.window({log: false}).then((win) => cy.request(Object.assign(
		typeof options === 'string' ? {url: options} : options,
		{headers: Object.assign({'User-Agent': win.navigator.userAgent}, (typeof options === 'string' ? {} : options.headers) || {})}
	)));

	// Signs in through requests (the login page can re-render while it is typed into), then
	// falls back to the form when the session did not stick (OJS 3.3 cookie handling).
	const login = (username, password) => {
		cy.clearCookies();
		request(pageUrl('login')).then((response) => {
			const token = /name="csrfToken" value="([^"]+)"/.exec(response.body)[1];
			// The form posts to the URL with the language: a redirect would turn the POST into a GET.
			const action = /<form[^>]*id="login"[^>]*action="([^"]+)"/.exec(response.body)[1];
			request({method: 'POST', url: action, form: true, body: {csrfToken: token, username: username, password: password}, log: false});
		});
		cy.visit(pageUrl('submissions') + '?reload=' + Date.now());
		cy.get('body').then(($body) => {
			if ($body.find('form#login').length) {
				cy.get('form#login input[name="username"]').type(username, {delay: 0});
				cy.get('form#login input[name="password"]').type(password, {delay: 0, log: false});
				cy.get('form#login').submit();
				cy.get('form#login', {timeout: 30000}).should('not.exist');
			}
		});
	};

	// REST API calls made from the page itself, so they carry the browser's own session.
	const api = (path, options = {}) => cy.window({log: false}).then((win) => cy.wrap(
		win.fetch(path, Object.assign({credentials: 'same-origin'}, options)).then((response) => {
			if (!response.ok) {
				return response.text().then((text) => {
					throw new Error(path + ' answered ' + response.status + ': ' + text.slice(0, 300));
				});
			}
			return response.json();
		}),
		{log: false, timeout: 30000}
	));

	// The website settings page on its Plugins tab (a new query string forces a load). Load it
	// once per test: loading it again while its plugin gallery request is pending stalls the
	// web server of PKP's CI; API calls and settings modals work on the page already open.
	const openPluginsTab = () => {
		cy.visit(pageUrl('management/settings/website') + '?reload=' + Date.now() + '#plugins');
		cy.get('button[id="plugins-button"]', {timeout: 60000}).click();
		cy.get('button[id="plugins-button"]').should('have.attr', 'aria-selected', 'true');
		waitJQuery();
	};

	// Enables the plugin in the grid when it is off (never turns it off).
	const enablePlugin = (rowName) => {
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]', {timeout: 30000}).then(($checkbox) => {
			if (!$checkbox.is(':checked')) {
				cy.wrap($checkbox).click();
				waitJQuery();
			}
		});
		cy.get('input[id^="select-cell-' + rowName + '-enabled"]').should('be.checked');
	};

	// Opens the settings modal from the grid, without reloading the page: a reload right
	// after saving can stall the web server of PKP's CI. The form is fetched each time.
	const openPluginSettings = (rowName, formSelector) => {
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]', {timeout: 30000}).then(($link) => {
			if (!$link.is(':visible')) {
				cy.get('tr[id$="-row-' + rowName + '"] a.show_extras').first().click();
			}
		});
		// The grid may still be animating the extras row: the link is clicked once it exists.
		cy.get('a[id*="-row-' + rowName + '-settings-button-"]').first().click({force: true});
		waitJQuery();
		cy.window().should((win) => {
			expect(win.jQuery(formSelector).data('pkp.handler')).to.exist;
		});
	};

	// ---- end of helpers ----

	const openSettings = () => openPluginSettings(rowName, settingsForm);

	const save = () => {
		cy.get(settingsForm + ' button[id^="submitFormButton-"]').click({force: true});
		waitJQuery();
		cy.get(settingsForm).should('not.exist');
	};

	// The first track of the page, from the data the plugin adds to it.
	const firstTrack = () => cy.get('.ojsbrAudioPlayerData').invoke('attr', 'data-config').then((json) => JSON.parse(json).formats[0].tracks[0]);

	it('Enables the plugin and saves its settings', function() {
		login(adminUser, adminPassword);
		openPluginsTab();
		enablePlugin(rowName);
		openSettings();

		cy.get(settingsForm + ' input[name="autoplayNext"]').then(($autoplay) => {
			cy.get(settingsForm + ' select[name="defaultSpeed"]').invoke('val').then((speed) => {
				const autoplay = $autoplay.is(':checked');
				const otherSpeed = speed === '1.5' ? '2' : '1.5';

				cy.get(settingsForm + ' input[name="autoplayNext"]').click({force: true});
				cy.get(settingsForm + ' select[name="defaultSpeed"]').select(otherSpeed, {force: true});
				save();

				// A form that "saves" without persisting is the classic plugin_settings defect.
				openSettings();
				cy.get(settingsForm + ' input[name="autoplayNext"]').should(autoplay ? 'not.be.checked' : 'be.checked');
				cy.get(settingsForm + ' select[name="defaultSpeed"]').should('have.value', otherSpeed);

				// Put them back as they were.
				cy.get(settingsForm + ' input[name="autoplayNext"]').click({force: true});
				cy.get(settingsForm + ' select[name="defaultSpeed"]').select(speed, {force: true});
				save();
				openSettings();
				cy.get(settingsForm + ' input[name="autoplayNext"]').should(autoplay ? 'be.checked' : 'not.be.checked');
				cy.get(settingsForm + ' select[name="defaultSpeed"]').should('have.value', speed);
			});
		});
	});

	(audioBookPage ? it : it.skip)('Builds the player from the audio publication format', function() {
		cy.visit('/' + audioBookPage.replace(/^\//, ''));
		// The server only sends the data; the rows and the bar are built by the script.
		cy.get('.ojsbrAudioRow').should('have.length.at.least', 1);
		cy.get('.ojsbrAudioRowButton').first().click();
		// The bar is attached to the body, and so is the state class: it pushes the page footer up.
		cy.get('.ojsbrAudioBar').should('exist');
		cy.get('body').should('have.class', 'ojsbrAudioBarOpen');
		cy.get('.ojsbrAudioTitle').should('not.have.text', '');
	});

	(audioBookPage ? it : it.skip)('Answers a Range request with 206, which seeking depends on', function() {
		cy.visit('/' + audioBookPage.replace(/^\//, ''));
		firstTrack().then((track) => {
			expect(track.streamUrl, 'the track URL carries the streaming parameter').to.contain('audioStream');

			// A proxy that drops the Range header answers 200 here, and every drag of the bar
			// downloads the whole file again.
			request({url: track.streamUrl, headers: {Range: 'bytes=0-99'}}).then((response) => {
				expect(response.status).to.equal(206);
				expect(response.headers['content-range']).to.match(/^bytes 0-99\/\d+$/);
				expect(response.headers['accept-ranges']).to.equal('bytes');
			});

			// An impossible range: 416, not 200 with the whole file.
			request({url: track.streamUrl, headers: {Range: 'bytes=99999999999-'}, failOnStatusCode: false}).its('status').should('equal', 416);
		});
	});

	(audioBookPage ? it : it.skip)('Does not become a way around access control', function() {
		// The plugin only answers inside CatalogBookHandler::download, after OMP's whole
		// access policy. Adding the streaming parameter must not change the answer. The
		// refused id is derived from the real track URL: a URL made up by hand is refused
		// by the router first, and the policy would never be exercised.
		cy.visit('/' + audioBookPage.replace(/^\//, ''));
		firstTrack().then((track) => {
			const [path, query] = track.streamUrl.split('?');
			const refused = path.replace(/\/\d+$/, '/999999');

			request({url: refused, failOnStatusCode: false}).its('status').then((withoutParameter) => {
				expect(withoutParameter, 'a file that does not belong to the format is refused').to.be.at.least(400);
				request({url: refused + '?' + query, failOnStatusCode: false}).its('status').should('equal', withoutParameter);
			});
		});
	});
});
