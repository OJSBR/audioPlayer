/**
 * @file plugins/generic/audioPlayer/js/audioPlayer.js
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Audiobook player for the book page.
 *
 * Reads the track list injected by the plugin, adds a play/pause button
 * next to each audio download link and builds a fixed player bar.
 * No external dependencies.
 */
(function () {
	'use strict';

	var node = document.querySelector('.ojsbrAudioPlayerData');
	if (!node) {
		return;
	}

	var cfg;
	try {
		cfg = JSON.parse(node.getAttribute('data-config'));
	} catch (e) {
		return;
	}
	if (!cfg || !cfg.formats || !cfg.formats.length) {
		return;
	}

	var t = cfg.i18n || {};
	var SPEEDS = [0.75, 1, 1.25, 1.5, 1.75, 2];

	/* Tracks in page order, with the format they belong to. Automatic
	   advance only moves within the same format: an audiobook must not
	   run on into the PDF of another format. */
	var playlist = [];
	cfg.formats.forEach(function (format) {
		format.tracks.forEach(function (track) {
			playlist.push({
				formatId: format.id,
				formatLabel: format.label,
				id: track.id,
				name: track.name,
				viewUrl: track.viewUrl,
				streamUrl: track.streamUrl
			});
		});
	});

	/* ------------------------------------------------------------------ *
	 * Position stored in the listener's browser
	 * ------------------------------------------------------------------ */

	function positionKey(track) {
		return 'ojsbrAudio:' + cfg.submissionId + ':' + track.id;
	}

	function savePosition(track, seconds) {
		if (!cfg.rememberPosition) {
			return;
		}
		try {
			if (seconds > 5) {
				window.localStorage.setItem(positionKey(track), String(Math.floor(seconds)));
			} else {
				window.localStorage.removeItem(positionKey(track));
			}
		} catch (e) {
			/* private mode or storage blocked: carry on without remembering */
		}
	}

	function loadPosition(track) {
		if (!cfg.rememberPosition) {
			return 0;
		}
		try {
			return parseInt(window.localStorage.getItem(positionKey(track)), 10) || 0;
		} catch (e) {
			return 0;
		}
	}

	function clearPosition(track) {
		try {
			window.localStorage.removeItem(positionKey(track));
		} catch (e) {
			/* nothing to do */
		}
	}

	/* ------------------------------------------------------------------ *
	 * Utilities
	 * ------------------------------------------------------------------ */

	function pathOf(url) {
		try {
			return new URL(url, window.location.href).pathname;
		} catch (e) {
			return url;
		}
	}

	function formatTime(seconds) {
		if (!isFinite(seconds) || seconds < 0) {
			return '--:--';
		}
		var total = Math.floor(seconds);
		var h = Math.floor(total / 3600);
		var m = Math.floor((total % 3600) / 60);
		var s = total % 60;
		var mm = h > 0 && m < 10 ? '0' + m : String(m);
		var ss = s < 10 ? '0' + s : String(s);
		return (h > 0 ? h + ':' : '') + mm + ':' + ss;
	}

	function el(tag, className, attrs) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				node.setAttribute(k, attrs[k]);
			});
		}
		return node;
	}

	/* Inline SVG icons: no icon font and no CDN. */
	function icon(name) {
		var paths = {
			play: 'M8 5v14l11-7z',
			pause: 'M6 5h4v14H6zm8 0h4v14h-4z',
			prev: 'M6 6h2v12H6zm3.5 6L18 6v12z',
			next: 'M16 6h2v12h-2zM6 6l8.5 6L6 18z',
			close: 'M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z'
		};
		var svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('viewBox', '0 0 24 24');
		svg.setAttribute('aria-hidden', 'true');
		svg.setAttribute('focusable', 'false');
		var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		path.setAttribute('d', paths[name]);
		svg.appendChild(path);
		return svg;
	}

	/* ------------------------------------------------------------------ *
	 * State
	 * ------------------------------------------------------------------ */

	var audio = new Audio();
	audio.preload = 'metadata';

	var speed = parseFloat(cfg.defaultSpeed) || 1;
	if (SPEEDS.indexOf(speed) === -1) {
		speed = 1;
	}

	var current = -1;   // index into playlist
	var bar = null;     // player bar, created on demand
	var ui = {};        // references to the bar controls
	var rowButtons = [];// button for each track, at the same index as in playlist
	var seeking = false;

	/* ------------------------------------------------------------------ *
	 * Button on each file row
	 * ------------------------------------------------------------------ */

	/* Path -> index map, to match each track to the link already rendered
	   by the core without depending on protocol or domain. */
	var byPath = {};
	playlist.forEach(function (track, index) {
		byPath[pathOf(track.viewUrl)] = index;
	});

	var links = document.querySelectorAll('a.cmp_download_link');
	Array.prototype.forEach.call(links, function (link) {
		var index = byPath[pathOf(link.getAttribute('href'))];
		if (index === undefined || rowButtons[index]) {
			return;
		}

		var button = el('button', 'ojsbrAudioRowButton', {
			type: 'button',
			'aria-pressed': 'false',
			'aria-label': t.play + ': ' + playlist[index].name
		});
		button.appendChild(icon('play'));
		button.addEventListener('click', function (event) {
			event.preventDefault();
			toggle(index);
		});

		/* The theme positions the download link absolutely at the left edge
		   of the row and leaves the file name in normal flow. The button is
		   inserted at the start of the name, so it sits right next to the
		   download icon without overriding the theme layout. */
		var item = link.closest('li') || link.parentNode;
		var nameNode = item ? item.querySelector('.name') : null;
		if (nameNode) {
			/* Dedicated container inside .name: aligning button and text with a
			   plugin selector avoids a specificity fight with the theme, which
			   styles .name with much more specific selectors. */
			var wrap = el('span', 'ojsbrAudioNameWrap');
			var text = el('span', 'ojsbrAudioNameText');
			while (nameNode.firstChild) {
				text.appendChild(nameNode.firstChild);
			}
			wrap.appendChild(button);
			wrap.appendChild(text);
			nameNode.appendChild(wrap);
		} else {
			/* Single-file format: the link has no accompanying .name */
			var anchor = link.closest('.link') || link;
			if (!anchor.parentNode) {
				return;
			}
			anchor.parentNode.insertBefore(button, anchor.nextSibling);
		}

		rowButtons[index] = button;

		if (item && item.classList) {
			item.classList.add('ojsbrAudioRow');
		}
	});

	/* No row matched: no buttons, so no player. */
	if (!rowButtons.filter(Boolean).length) {
		return;
	}

	/* ------------------------------------------------------------------ *
	 * Player bar
	 * ------------------------------------------------------------------ */

	function buildBar() {
		bar = el('div', 'ojsbrAudioBar', {
			role: 'region',
			'aria-label': t.player
		});

		var controls = el('div', 'ojsbrAudioControls');

		ui.prev = el('button', 'ojsbrAudioButton', { type: 'button', 'aria-label': t.previous });
		ui.prev.appendChild(icon('prev'));
		ui.prev.addEventListener('click', function () { step(-1); });

		ui.toggle = el('button', 'ojsbrAudioButton ojsbrAudioButtonMain', { type: 'button', 'aria-label': t.play });
		ui.toggle.appendChild(icon('play'));
		ui.toggle.addEventListener('click', function () { toggle(current); });

		ui.next = el('button', 'ojsbrAudioButton', { type: 'button', 'aria-label': t.next });
		ui.next.appendChild(icon('next'));
		ui.next.addEventListener('click', function () { step(1); });

		controls.appendChild(ui.prev);
		controls.appendChild(ui.toggle);
		controls.appendChild(ui.next);

		var middle = el('div', 'ojsbrAudioMiddle');

		ui.title = el('div', 'ojsbrAudioTitle');
		middle.appendChild(ui.title);

		var progress = el('div', 'ojsbrAudioProgress');
		ui.elapsed = el('span', 'ojsbrAudioTime');
		ui.elapsed.textContent = '--:--';

		ui.seek = el('input', 'ojsbrAudioSeek', {
			type: 'range',
			min: '0',
			max: '1000',
			value: '0',
			step: '1',
			'aria-label': t.seek
		});
		/* While dragging, the slider is not overwritten by timeupdate. */
		ui.seek.addEventListener('input', function () {
			seeking = true;
			if (isFinite(audio.duration)) {
				ui.elapsed.textContent = formatTime(audio.duration * (ui.seek.value / 1000));
			}
		});
		ui.seek.addEventListener('change', function () {
			if (isFinite(audio.duration)) {
				audio.currentTime = audio.duration * (ui.seek.value / 1000);
			}
			seeking = false;
		});

		ui.duration = el('span', 'ojsbrAudioTime');
		ui.duration.textContent = '--:--';

		progress.appendChild(ui.elapsed);
		progress.appendChild(ui.seek);
		progress.appendChild(ui.duration);
		middle.appendChild(progress);

		var right = el('div', 'ojsbrAudioRight');

		ui.speed = el('select', 'ojsbrAudioSpeed', { 'aria-label': t.speed });
		SPEEDS.forEach(function (value) {
			var option = el('option');
			option.value = String(value);
			option.textContent = value + 'x';
			if (value === speed) {
				option.selected = true;
			}
			ui.speed.appendChild(option);
		});
		ui.speed.addEventListener('change', function () {
			speed = parseFloat(ui.speed.value) || 1;
			audio.playbackRate = speed;
		});

		ui.close = el('button', 'ojsbrAudioButton ojsbrAudioClose', { type: 'button', 'aria-label': t.close });
		ui.close.appendChild(icon('close'));
		ui.close.addEventListener('click', closeBar);

		right.appendChild(ui.speed);
		right.appendChild(ui.close);

		bar.appendChild(controls);
		bar.appendChild(middle);
		bar.appendChild(right);

		ui.message = el('div', 'ojsbrAudioMessage', { role: 'status' });
		bar.appendChild(ui.message);

		document.body.appendChild(bar);
		document.body.classList.add('ojsbrAudioBarOpen');
	}

	function closeBar() {
		if (current >= 0) {
			savePosition(playlist[current], audio.currentTime);
		}
		audio.pause();
		audio.removeAttribute('src');
		audio.load();
		current = -1;
		refreshRows();
		if (bar) {
			bar.remove();
			bar = null;
			ui = {};
		}
		document.body.classList.remove('ojsbrAudioBarOpen');
	}

	/* ------------------------------------------------------------------ *
	 * Playback
	 * ------------------------------------------------------------------ */

	function play(index) {
		if (index < 0 || index >= playlist.length) {
			return;
		}

		/* Leave the current track, saving where it stopped. */
		if (current >= 0 && current !== index) {
			savePosition(playlist[current], audio.currentTime);
		}

		if (!bar) {
			buildBar();
		}

		var track = playlist[index];

		if (current !== index) {
			current = index;
			audio.src = track.streamUrl;
			var resume = loadPosition(track);
			if (resume > 0) {
				/* currentTime can only be set once metadata has loaded. */
				audio.addEventListener('loadedmetadata', function once() {
					audio.removeEventListener('loadedmetadata', once);
					if (resume < audio.duration - 10) {
						audio.currentTime = resume;
					}
				});
			}
			audio.load();
		}

		audio.playbackRate = speed;
		var started = audio.play();
		if (started && started.catch) {
			started.catch(function () {
				showMessage(t.error);
			});
		}
		refreshAll();
	}

	function toggle(index) {
		if (index < 0) {
			return;
		}
		if (current === index && !audio.paused) {
			audio.pause();
			savePosition(playlist[index], audio.currentTime);
			refreshAll();
			return;
		}
		play(index);
	}

	/* Previous/next within the same publication format. */
	function neighbour(direction) {
		if (current < 0) {
			return -1;
		}
		var next = current + direction;
		if (next < 0 || next >= playlist.length) {
			return -1;
		}
		if (playlist[next].formatId !== playlist[current].formatId) {
			return -1;
		}
		return next;
	}

	function step(direction) {
		var next = neighbour(direction);
		if (next >= 0) {
			play(next);
		}
	}

	function showMessage(text) {
		if (ui.message) {
			ui.message.textContent = text || '';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Interface updates
	 * ------------------------------------------------------------------ */

	function refreshRows() {
		rowButtons.forEach(function (button, index) {
			if (!button) {
				return;
			}
			var active = index === current && !audio.paused;
			button.innerHTML = '';
			button.appendChild(icon(active ? 'pause' : 'play'));
			button.setAttribute('aria-pressed', active ? 'true' : 'false');
			button.setAttribute('aria-label', (active ? t.pause : t.play) + ': ' + playlist[index].name);
			button.classList.toggle('ojsbrAudioRowButtonActive', index === current);
		});
	}

	function refreshBar() {
		if (!bar || current < 0) {
			return;
		}
		var track = playlist[current];
		var playing = !audio.paused;

		ui.title.textContent = track.name;
		ui.title.setAttribute('title', track.formatLabel + ' — ' + track.name);

		ui.toggle.innerHTML = '';
		ui.toggle.appendChild(icon(playing ? 'pause' : 'play'));
		ui.toggle.setAttribute('aria-label', playing ? t.pause : t.play);

		ui.prev.disabled = neighbour(-1) < 0;
		ui.next.disabled = neighbour(1) < 0;

		ui.duration.textContent = formatTime(audio.duration);
		if (!seeking) {
			ui.elapsed.textContent = formatTime(audio.currentTime);
			ui.seek.value = isFinite(audio.duration) && audio.duration > 0
				? String(Math.round((audio.currentTime / audio.duration) * 1000))
				: '0';
		}
		ui.seek.disabled = !isFinite(audio.duration) || audio.duration <= 0;
	}

	function refreshAll() {
		refreshRows();
		refreshBar();
	}

	/* ------------------------------------------------------------------ *
	 * Audio element events
	 * ------------------------------------------------------------------ */

	var lastSaved = 0;

	audio.addEventListener('play', function () { showMessage(''); refreshAll(); });
	audio.addEventListener('pause', refreshAll);
	audio.addEventListener('loadedmetadata', refreshBar);
	audio.addEventListener('durationchange', refreshBar);

	audio.addEventListener('timeupdate', function () {
		refreshBar();
		/* Save the position every 5 s, not on every frame. */
		if (current >= 0 && Math.abs(audio.currentTime - lastSaved) > 5) {
			lastSaved = audio.currentTime;
			savePosition(playlist[current], audio.currentTime);
		}
	});

	audio.addEventListener('ended', function () {
		var finished = current;
		var next = cfg.autoplayNext ? neighbour(1) : -1;

		/* current is reset before switching tracks because play() saves the
		   position of the track that was playing. Without this, the track
		   that just ended would be saved again at its full duration right
		   after being cleared here, and would show up as "stopped at the
		   end" again. */
		current = -1;
		lastSaved = 0;
		if (finished >= 0) {
			clearPosition(playlist[finished]);
		}

		if (next >= 0) {
			play(next);
		} else {
			current = finished;
			refreshAll();
		}
	});

	audio.addEventListener('error', function () {
		showMessage(t.error);
		refreshAll();
	});

	window.addEventListener('beforeunload', function () {
		if (current >= 0 && !audio.paused) {
			savePosition(playlist[current], audio.currentTime);
		}
	});
}());
