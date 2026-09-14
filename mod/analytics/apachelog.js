/*
 * Apache log analyzer - the browser half of the analytics panel's "Apache logs"
 * card. Absorbed from the standalone tool that used to live in lib/Apache-tool.
 *
 * One renderer, two sources: a report arrives either from the analyzeApacheLog
 * async endpoint or, when "Follow live" is on, from apachelog.stream.php as a
 * stream of Server-Sent Events carrying the same shape. Everything below draws
 * that shape, so the live and one-shot paths never diverge.
 *
 * Severity colour is applied through CSS classes (level-error, level-warn, ...)
 * rather than inline fills, so the charts follow the dashboard's light/dark
 * theme the way the rest of analytics does.
 *
 * Log content is untrusted text: every value from the server is written with
 * textContent, never innerHTML.
 */
(function(){
'use strict';

var MAX_RENDERED_ROWS = 1000;
var MAX_SCATTER_POINTS = 450;
var SVG_NS = 'http://www.w3.org/2000/svg';
var LEVEL_ORDER = ['emerg','alert','crit','error','warn','notice','info','debug','trace'];

var el = {};
var currentReport = null;
var currentFileId = '';
var eventSource = null;
var filesById = {};
var streamStarted = false;
var streamRetries = 0;
var listTimer = null;
var csrfToken = '';
var tooltip = null;
var activeHintButton = null;
var pinnedHintButton = null;

/* The analytics dashboard re-renders on every range change, which replaces this
 * card's DOM. Re-initialising therefore has to drop the previous stream and
 * re-resolve every element, or we would keep writing into detached nodes. */
function initApacheLogPanel(){
	stopStream();
	var root = document.getElementById('ghotiApacheLog');
	if(!root){ return; }

	//Same session token the async layer posts; the two plain URLs below need it
	//in the query string because neither is an RPC call.
	csrfToken = (typeof GHOTI_CSRF_TOKEN === 'string') ? GHOTI_CSRF_TOKEN : '';
	el = {
		root: root,
		select: document.getElementById('apacheLogFileSelect'),
		follow: document.getElementById('apacheFollowToggle'),
		refresh: document.getElementById('apacheRefreshButton'),
		analyze: document.getElementById('apacheAnalyzeButton'),
		stop: document.getElementById('apacheStopButton'),
		reload: document.getElementById('apacheReloadButton'),
		exportLink: document.getElementById('apacheExportButton'),
		logDir: document.getElementById('apacheLogDir'),
		status: document.getElementById('apacheStatus'),
		report: document.getElementById('apacheReport'),
		fileMeta: document.getElementById('apacheFileMeta'),
		metrics: document.getElementById('apacheMetrics'),
		categoryChart: document.getElementById('apacheCategoryChart'),
		severityRing: document.getElementById('apacheSeverityRing'),
		timelineScatter: document.getElementById('apacheTimelineScatter'),
		recommendations: document.getElementById('apacheRecommendations'),
		entryRows: document.getElementById('apacheEntryRows'),
		tableFooter: document.getElementById('apacheTableFooter'),
		search: document.getElementById('apacheSearchInput'),
		severityFilter: document.getElementById('apacheSeverityFilter'),
		tabs: Array.prototype.slice.call(root.querySelectorAll('[data-report-tab]')),
		panels: Array.prototype.slice.call(root.querySelectorAll('[data-report-panel]'))
	};

	currentReport = null;
	currentFileId = '';
	filesById = {};

	el.refresh.addEventListener('click', loadLogFiles);
	el.analyze.addEventListener('click', startAnalysis);
	el.reload.addEventListener('click', startAnalysis);
	el.stop.addEventListener('click', function(){ stopStream(); showStatus('Live stream stopped.'); });
	el.select.addEventListener('change', updateButtons);
	el.search.addEventListener('input', renderEntryTable);
	el.severityFilter.addEventListener('change', renderEntryTable);
	el.tabs.forEach(function(tab){
		tab.addEventListener('click', function(){ selectTab(tab.getAttribute('data-report-tab')); });
		tab.addEventListener('keydown', handleTabKeyDown);
	});
	el.entryRows.addEventListener('mouseover', handleHintMouseOver);
	el.entryRows.addEventListener('mousemove', handleHintMouseMove);
	el.entryRows.addEventListener('mouseout', handleHintMouseOut);
	el.entryRows.addEventListener('click', handleHintClick);
	el.entryRows.addEventListener('focusout', handleHintFocusOut);

	selectTab('overview');
	loadLogFiles();
}

/* ---------------- loading and analysis ---------------- */

function loadLogFiles(){
	showStatus('Loading log files…');
	el.refresh.disabled = true;
	//The async wrapper drops the callback entirely when a call fails at the
	//transport level (an expired CSRF token, a 500), so a lost reply would leave
	//this card disabled on "Loading..." forever. Hand the button back either way.
	window.clearTimeout(listTimer);
	listTimer = window.setTimeout(function(){
		el.refresh.disabled = false;
		showStatus('The log list did not come back. Press Refresh list to try again.', true);
	}, 20000);
	x_listApacheLogs(function(payload){
		window.clearTimeout(listTimer);
		el.refresh.disabled = false;
		if(!payload || !payload.ok){
			renderFileOptions([], '');
			showStatus((payload && payload.error) || 'The log list could not be loaded.', true);
			return;
		}
		filesById = {};
		(payload.files || []).forEach(function(file){ filesById[file.id] = file; });
		renderFileOptions(payload.files || [], el.select.value || currentFileId);
		el.logDir.textContent = payload.logDir ? 'Reading ' + payload.logDir : '';
		if(!payload.files || !payload.files.length){
			showStatus('No log files were found in ' + payload.logDir + '.', true);
		}else{
			showStatus('Found ' + formatNumber(payload.files.length) + ' log files. Choose one and press Analyze.');
		}
	});
}

function renderFileOptions(files, preferredId){
	var options = [];
	var firstSupported = '';
	options.push(new Option(files.length ? 'Choose a log file' : 'No log files available', ''));
	files.forEach(function(file){
		var label = [file.name, formatBytes(file.size), file.modifiedEpoch ? formatDate(file.modifiedEpoch) : '']
			.filter(Boolean).join(' — ');
		if(file.note){ label += ' (' + file.note + ')'; }
		var option = new Option(label, file.id);
		option.disabled = !file.supported;
		if(file.supported && !firstSupported){ firstSupported = file.id; }
		options.push(option);
	});
	el.select.replaceChildren.apply(el.select, options);
	var keep = filesById[preferredId] && filesById[preferredId].supported;
	el.select.value = keep ? preferredId : firstSupported;
	updateButtons();
}

function startAnalysis(){
	var fileId = el.select.value;
	if(!fileId){
		showStatus('Choose a readable log file first.', true);
		return;
	}

	stopStream();
	currentFileId = fileId;
	currentReport = null;
	streamStarted = false;
	streamRetries = 0;
	resetFilters();
	selectTab('overview');
	el.report.hidden = true;
	el.exportLink.hidden = true;
	updateButtons(true);

	var file = filesById[fileId];
	showStatus('Opening ' + (file ? file.name : 'selected log') + '…');

	if(!el.follow.checked || !window.EventSource){
		analyzeOnce(fileId);
		return;
	}
	openStream(fileId);
}

function analyzeOnce(fileId){
	x_analyzeApacheLog(fileId, function(payload){
		updateButtons();
		if(!payload || !payload.ok){
			showStatus((payload && payload.error) || 'The log could not be analyzed.', true);
			return;
		}
		applyReport(payload.report);
		showStatus('Analyzed ' + formatNumber(currentReport.entryCount) + ' entries from ' + currentReport.fileName + '.');
	});
}

function openStream(fileId){
	var url = 'mod/analytics/apachelog.stream.php?file=' + encodeURIComponent(fileId)
		+ '&token=' + encodeURIComponent(csrfToken);
	eventSource = new EventSource(url);
	el.stop.hidden = false;

	eventSource.addEventListener('analysis', function(event){
		var payload = parseEvent(event);
		if(!payload){ return; }
		if(!payload.ok){
			showStatus(payload.error || 'The server could not analyze this log.', true);
			return;
		}
		streamStarted = true;
		applyReport(payload.report);
		el.stop.hidden = false;
		showStatus('Watching ' + currentReport.fileName + '; ' + formatNumber(currentReport.entryCount || 0) + ' parsed entries so far.');
	});

	eventSource.addEventListener('reset', function(event){
		var payload = parseEvent(event);
		showStatus((payload && payload.message) || 'Log file changed; rebuilding analysis.');
	});

	eventSource.addEventListener('server-error', function(event){
		var payload = parseEvent(event);
		showStatus((payload && payload.error) || 'The live stream reported an error.', true);
		stopStream();
	});

	eventSource.addEventListener('end', function(event){
		var payload = parseEvent(event);
		stopStream();
		showStatus((payload && payload.message) || 'Live stream ended. Restart analysis to continue watching.');
	});

	//A stream that never delivered a first report is treated as unavailable
	//(EventSource is disabled, buffered by a proxy, or the endpoint refused the
	//request) and the one-shot analysis stands in for it.
	//Each reconnect makes the server re-read and re-parse the whole window, so a
	//connection that keeps dropping is given a few attempts and then left alone
	//rather than looping against a large file.
	eventSource.onerror = function(){
		if(!streamStarted){
			showStatus('The live stream could not be opened. Falling back to a one-time analysis…', true);
			stopStream();
			analyzeOnce(currentFileId);
			return;
		}
		streamRetries++;
		if(streamRetries > 3){
			stopStream();
			showStatus('The live connection kept dropping, so watching stopped. Press Restart analysis to try again.', true);
			return;
		}
		showStatus('Live connection interrupted; reconnecting (attempt ' + streamRetries + ' of 3)…', true);
	};
}

function stopStream(){
	window.clearTimeout(listTimer);
	if(eventSource){
		eventSource.close();
		eventSource = null;
	}
	if(el.stop){ el.stop.hidden = true; }
	updateButtons();
}

function parseEvent(event){
	try{ return JSON.parse(event.data); }
	catch(e){ return null; }
}

function applyReport(report){
	currentReport = report;
	renderReport();
	el.report.hidden = false;
	el.exportLink.href = 'mod/analytics/apachelog.export.php?file=' + encodeURIComponent(currentFileId)
		+ '&token=' + encodeURIComponent(csrfToken);
	el.exportLink.hidden = false;
	updateButtons();
}

function updateButtons(isBusy){
	if(!el.analyze){ return; }
	el.analyze.disabled = !!isBusy || !el.select.value;
}

/* ---------------- tabs ---------------- */

function selectTab(name){
	el.tabs.forEach(function(tab){
		var isActive = tab.getAttribute('data-report-tab') === name;
		tab.classList.toggle('is-active', isActive);
		tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
		tab.tabIndex = isActive ? 0 : -1;
	});
	el.panels.forEach(function(panel){
		panel.hidden = panel.getAttribute('data-report-panel') !== name;
	});
}

function handleTabKeyDown(event){
	var keys = ['ArrowLeft','ArrowRight','Home','End'];
	if(keys.indexOf(event.key) === -1){ return; }
	event.preventDefault();
	var index = el.tabs.indexOf(event.currentTarget);
	var next = index;
	if(event.key === 'Home'){ next = 0; }
	else if(event.key === 'End'){ next = el.tabs.length - 1; }
	else { next = (index + (event.key === 'ArrowRight' ? 1 : -1) + el.tabs.length) % el.tabs.length; }
	selectTab(el.tabs[next].getAttribute('data-report-tab'));
	el.tabs[next].focus();
}

/* ---------------- report rendering ---------------- */

function renderReport(){
	if(!currentReport){ return; }
	var entries = currentReport.entries || [];
	var counts = currentReport.severityCounts || {};
	var total = currentReport.entryCount || entries.length;
	var severe = sumLevels(counts, ['emerg','alert','crit','error']);
	var warnings = counts.warn || 0;

	el.fileMeta.textContent = buildFileMeta(currentReport);
	el.metrics.replaceChildren(
		metricCard('Parsed entries', formatNumber(total), formatNumber(currentReport.ignoredLines || 0) + ' skipped'),
		metricCard('Critical / errors', formatNumber(severe), percentLabel(severe, total)),
		metricCard('Warnings', formatNumber(warnings), percentLabel(warnings, total)),
		metricCard('Unique clients', formatNumber(currentReport.uniqueClients || 0), 'IP or host values'),
		metricCard('Time span', formatDuration(currentReport.firstTimestampEpoch, currentReport.lastTimestampEpoch),
			formatDateRange(currentReport.firstTimestampEpoch, currentReport.lastTimestampEpoch))
	);

	renderCategoryChart(currentReport.categoryCounts || {}, total);
	renderSeverityRing(counts, total);
	renderTimelineScatter(entries);
	renderRecommendations(currentReport.recommendations || []);
	populateSeverityFilter(counts);
	renderEntryTable();
}

function buildFileMeta(report){
	var meta = [
		report.fileName || 'selected log',
		formatBytes(report.fileSize || 0),
		formatNumber(report.ignoredLines || 0) + ' unrecognized lines skipped'
	];
	if(report.initialWindowTruncated){ meta.push('latest ' + formatBytes(report.bytesAnalyzed || 0) + ' scanned'); }
	if(report.live){ meta.push('live'); }
	if(report.entriesLimited){ meta.push('details limited to ' + formatNumber((report.entries || []).length) + ' recent entries'); }
	return meta.join(' — ');
}

function metricCard(label, value, note){
	var card = makeEl('div', '', 'apache-metric');
	card.append(
		makeEl('span', label, 'apache-metric-label'),
		makeEl('strong', value, 'apache-metric-value'),
		makeEl('span', note, 'apache-metric-note')
	);
	return card;
}

function renderCategoryChart(categoryCounts, total){
	var categories = Object.keys(categoryCounts)
		.map(function(name){ return [name, categoryCounts[name]]; })
		.sort(function(a,b){ return b[1] - a[1]; })
		.slice(0, 7);

	if(!categories.length){
		el.categoryChart.replaceChildren(makeEl('p', 'No categories to display.', 'apache-empty'));
		return;
	}

	var max = categories[0][1];
	var rows = categories.map(function(pair){
		var row = makeEl('div', '', 'ranked-row');
		var track = makeEl('div', '', 'ranked-track');
		var fill = makeEl('span', '', 'ranked-fill');
		fill.style.width = Math.max(3, (pair[1] / max) * 100) + '%';
		track.append(fill);
		row.append(
			makeEl('span', pair[0], 'ranked-label'),
			track,
			makeEl('span', formatNumber(pair[1]) + ' / ' + percentValue(pair[1], total) + '%', 'ranked-value')
		);
		return row;
	});
	el.categoryChart.replaceChildren.apply(el.categoryChart, rows);
}

function renderSeverityRing(severityCounts, total){
	var levels = LEVEL_ORDER
		.map(function(level){ return [level, severityCounts[level] || 0]; })
		.filter(function(pair){ return pair[1] > 0; });

	if(!levels.length){
		el.severityRing.replaceChildren(makeEl('p', 'No severities to display.', 'apache-empty'));
		return;
	}

	var chartTotal = levels.reduce(function(sum, pair){ return sum + pair[1]; }, 0);
	var size = 170, center = size / 2, radius = 54;
	var circumference = 2 * Math.PI * radius;
	var offset = 0;

	var svg = makeSvg('svg', {
		'class': 'apache-ring',
		viewBox: '0 0 ' + size + ' ' + size,
		role: 'img',
		'aria-label': 'Severity distribution across ' + formatNumber(chartTotal) + ' parsed entries'
	});
	svg.append(makeSvg('circle', { cx: center, cy: center, r: radius, 'class': 'apache-ring-track', 'stroke-width': 22, fill: 'none' }));

	levels.forEach(function(pair){
		var segment = makeSvg('circle', {
			cx: center, cy: center, r: radius, fill: 'none',
			'class': 'apache-ring-segment level-' + pair[0],
			'stroke-width': 22,
			'stroke-linecap': 'butt',
			'stroke-dasharray': ((pair[1] / chartTotal) * circumference) + ' ' + circumference,
			'stroke-dashoffset': -offset,
			transform: 'rotate(-90 ' + center + ' ' + center + ')'
		});
		segment.append(makeSvg('title', {}, displayLevel(pair[0]) + ': ' + formatNumber(pair[1]) + ' (' + percentValue(pair[1], chartTotal) + '%)'));
		svg.append(segment);
		offset += (pair[1] / chartTotal) * circumference;
	});

	svg.append(
		makeSvg('text', { x: center, y: center - 2, 'class': 'apache-ring-total' }, formatNumber(chartTotal)),
		makeSvg('text', { x: center, y: center + 17, 'class': 'apache-ring-caption' }, 'entries')
	);

	var legend = makeEl('div', '', 'chart-legend');
	legend.replaceChildren.apply(legend, levels.map(function(pair){
		var row = makeEl('span', '', 'apache-legend-row');
		row.append(
			makeEl('span', '', 'swatch level-' + pair[0]),
			makeEl('span', displayLevel(pair[0]) + ' ' + formatNumber(pair[1]) + ' / ' + percentValue(pair[1], total) + '%', '')
		);
		return row;
	}));

	var layout = makeEl('div', '', 'apache-ring-layout');
	layout.replaceChildren(svg, legend);
	el.severityRing.replaceChildren(layout);
}

function renderTimelineScatter(entries){
	var points = entries
		.filter(function(entry){ return entry && LEVEL_ORDER.indexOf(entry.level) !== -1; })
		.map(function(entry, index){
			return {
				entry: entry,
				index: index,
				time: isFinite(entry.dateEpoch) && entry.dateEpoch ? entry.dateEpoch : null,
				levelIndex: LEVEL_ORDER.indexOf(entry.level)
			};
		});

	if(!points.length){
		el.timelineScatter.replaceChildren(makeEl('p', 'No timestamped entries to plot.', 'apache-empty'));
		return;
	}

	var sampled = samplePoints(points, MAX_SCATTER_POINTS);
	var timed = sampled.filter(function(point){ return point.time !== null; });
	var useTime = timed.length >= 2;
	var plotted = useTime ? timed : sampled;
	var xValues = plotted.map(function(point){ return useTime ? point.time : point.index; });
	var minX = Math.min.apply(Math, xValues);
	var maxX = Math.max.apply(Math, xValues);
	var rangeX = Math.max(maxX - minX, 1);
	var width = 720, height = 300;
	var margin = { top: 24, right: 28, bottom: 44, left: 70 };
	var plotWidth = width - margin.left - margin.right;
	var plotHeight = height - margin.top - margin.bottom;

	var svg = makeSvg('svg', {
		'class': 'apache-scatter',
		viewBox: '0 0 ' + width + ' ' + height,
		role: 'img',
		'aria-label': 'Scatter chart of log entries by time and severity'
	});

	LEVEL_ORDER.forEach(function(level, index){
		var y = yForSeverity(index, margin.top, plotHeight);
		svg.append(
			makeSvg('line', { x1: margin.left, y1: y, x2: width - margin.right, y2: y, 'class': 'apache-gridline' }),
			makeSvg('text', { x: margin.left - 12, y: y + 4, 'class': 'apache-axis-label', 'text-anchor': 'end' }, displayLevel(level))
		);
	});

	svg.append(
		makeSvg('line', { x1: margin.left, y1: margin.top, x2: margin.left, y2: height - margin.bottom, 'class': 'apache-axis-line' }),
		makeSvg('line', { x1: margin.left, y1: height - margin.bottom, x2: width - margin.right, y2: height - margin.bottom, 'class': 'apache-axis-line' }),
		makeSvg('text', { x: margin.left, y: height - 16, 'class': 'apache-axis-label', 'text-anchor': 'start' }, useTime ? formatShortDate(minX) : 'Oldest loaded'),
		makeSvg('text', { x: width - margin.right, y: height - 16, 'class': 'apache-axis-label', 'text-anchor': 'end' }, useTime ? formatShortDate(maxX) : 'Newest loaded')
	);

	plotted.forEach(function(point){
		var rawX = useTime ? point.time : point.index;
		var severe = point.entry.level === 'error' || point.entry.level === 'crit';
		var circle = makeSvg('circle', {
			cx: margin.left + ((rawX - minX) / rangeX) * plotWidth,
			cy: yForSeverity(point.levelIndex, margin.top, plotHeight),
			r: severe ? 5 : 4,
			'class': 'apache-point level-' + point.entry.level
		});
		circle.append(makeSvg('title', {}, [
			displayLevel(point.entry.level) + ' — ' + (point.entry.category || 'Other'),
			point.entry.timestamp || '',
			point.entry.message || ''
		].filter(Boolean).join('\n')));
		svg.append(circle);
	});

	var wrapper = makeEl('div', '', 'apache-scatter-wrap');
	wrapper.replaceChildren(svg, makeEl('p', formatNumber(plotted.length) + ' plotted from ' + formatNumber(points.length) + ' loaded entries.', 'table-footer'));
	el.timelineScatter.replaceChildren(wrapper);
}

function renderRecommendations(recommendations){
	if(!recommendations.length){
		el.recommendations.replaceChildren(makeEl('li', 'No recommendations yet. The log has not produced recognized entries.'));
		return;
	}
	el.recommendations.replaceChildren.apply(el.recommendations, recommendations.map(function(text){
		return makeEl('li', text);
	}));
}

function populateSeverityFilter(counts){
	var current = el.severityFilter.value || 'all';
	var options = [new Option('All severities', 'all')];
	LEVEL_ORDER.filter(function(level){ return counts[level]; }).forEach(function(level){
		options.push(new Option(displayLevel(level) + ' (' + formatNumber(counts[level]) + ')', level));
	});
	el.severityFilter.replaceChildren.apply(el.severityFilter, options);
	el.severityFilter.value = options.some(function(option){ return option.value === current; }) ? current : 'all';
}

function renderEntryTable(){
	if(!currentReport){ return; }
	hideTooltip();

	var query = el.search.value.trim().toLowerCase();
	var severity = el.severityFilter.value;
	var entries = currentReport.entries || [];
	var filtered = entries.filter(function(entry){
		if(severity !== 'all' && entry.level !== severity){ return false; }
		if(!query){ return true; }
		var haystack = [entry.timestamp, entry.message, entry.client, entry.code, entry.category, entry.module,
			(entry.hints || []).join(' ')].join(' ').toLowerCase();
		return haystack.indexOf(query) !== -1;
	});
	var visible = filtered.slice(-MAX_RENDERED_ROWS).reverse();

	var rows = visible.map(function(entry){
		var row = document.createElement('tr');
		var source = [entry.module, entry.client, entry.code || (entry.status ? 'HTTP ' + entry.status : '')].filter(Boolean).join(' — ');
		var levelCell = document.createElement('td');
		levelCell.append(makeEl('span', displayLevel(entry.level), 'apache-badge level-' + (entry.level || 'unknown')));

		var messageCell = makeEl('td', '', 'apache-message');
		messageCell.append(makeEl('div', entry.message || ''));
		var hints = (entry.hints || []).slice(0, 3);
		if(hints.length){
			var hintButton = makeEl('button', 'Diagnostic hints available', 'apache-hint-chip');
			hintButton.type = 'button';
			hintButton.setAttribute('aria-expanded', 'false');
			hintButton._hints = hints;
			hintButton._hintTitle = displayLevel(entry.level) + ' — ' + (entry.category || 'Other');
			messageCell.append(hintButton);
		}

		row.append(
			makeEl('td', entry.timestamp || ''),
			levelCell,
			makeEl('td', entry.category || ''),
			makeEl('td', source || 'n/a'),
			messageCell
		);
		return row;
	});

	if(!rows.length){
		var emptyRow = document.createElement('tr');
		var cell = makeEl('td', 'No entries match these filters.', 'apache-empty');
		cell.colSpan = 5;
		emptyRow.append(cell);
		rows.push(emptyRow);
	}
	el.entryRows.replaceChildren.apply(el.entryRows, rows);

	var limitedNote = currentReport.entriesLimited
		? ' The server sent the latest ' + formatNumber(entries.length) + ' entries for browsing; the metrics above count all '
			+ formatNumber(currentReport.entryCount || entries.length) + ' parsed entries.'
		: '';
	el.tableFooter.textContent = 'Showing ' + formatNumber(Math.min(filtered.length, MAX_RENDERED_ROWS))
		+ ' of ' + formatNumber(filtered.length) + ' matching loaded entries.' + limitedNote;
}

function resetFilters(){
	el.search.value = '';
	el.severityFilter.replaceChildren(new Option('All severities', 'all'));
}

function showStatus(message, isError){
	el.status.textContent = message;
	el.status.classList.toggle('is-error', !!isError);
	el.status.hidden = false;
}

/* ---------------- diagnostic hint tooltip ----------------
 * The tooltip lives on <body> rather than inside the table so a long hint is
 * never clipped by the table's own scroll container. */

function ensureTooltip(){
	if(tooltip && document.body.contains(tooltip)){ return tooltip; }
	tooltip = makeEl('aside', '', 'ghotiApacheHint');
	tooltip.id = 'ghotiApacheHintTooltip';
	tooltip.setAttribute('role', 'tooltip');
	tooltip.hidden = true;
	document.body.append(tooltip);
	return tooltip;
}

function hintButtonFromEvent(event){
	var target = event.target instanceof Element ? event.target : null;
	var button = target ? target.closest('button.apache-hint-chip') : null;
	return button && el.entryRows.contains(button) ? button : null;
}

function handleHintMouseOver(event){
	var button = hintButtonFromEvent(event);
	if(!button || button === activeHintButton){ return; }
	if(pinnedHintButton && pinnedHintButton !== button){
		pinnedHintButton.setAttribute('aria-expanded', 'false');
		pinnedHintButton.removeAttribute('aria-describedby');
		pinnedHintButton = null;
	}
	showTooltip(button, event.clientX, event.clientY);
}

function handleHintMouseMove(event){
	var button = hintButtonFromEvent(event);
	if(button && button === activeHintButton && pinnedHintButton !== button){
		positionTooltip(event.clientX, event.clientY);
	}
}

function handleHintMouseOut(event){
	var button = hintButtonFromEvent(event);
	if(!button || button !== activeHintButton){ return; }
	if(event.relatedTarget && button.contains(event.relatedTarget)){ return; }
	if(pinnedHintButton !== button){ hideTooltip(); }
}

function handleHintClick(event){
	var button = hintButtonFromEvent(event);
	if(!button){ return; }
	if(pinnedHintButton === button && tooltip && !tooltip.hidden){
		hideTooltip();
		return;
	}
	pinnedHintButton = button;
	var rect = button.getBoundingClientRect();
	showTooltip(button, event.clientX || rect.right, event.clientY || rect.top);
}

function handleHintFocusOut(event){
	if(!pinnedHintButton){ return; }
	if(event.relatedTarget && pinnedHintButton.contains(event.relatedTarget)){ return; }
	hideTooltip();
}

function showTooltip(button, clientX, clientY){
	var hints = button._hints || [];
	if(!hints.length){
		hideTooltip();
		return;
	}
	var node = ensureTooltip();
	if(activeHintButton && activeHintButton !== button){
		activeHintButton.setAttribute('aria-expanded', 'false');
		activeHintButton.removeAttribute('aria-describedby');
	}
	activeHintButton = button;
	button.setAttribute('aria-expanded', 'true');
	button.setAttribute('aria-describedby', node.id);

	var list = makeEl('ul', '', 'apache-hint-list');
	list.replaceChildren.apply(list, hints.map(function(hint){ return makeEl('li', hint); }));
	node.replaceChildren(
		makeEl('p', 'Diagnostic hints', 'apache-hint-kicker'),
		makeEl('p', button._hintTitle || 'Log entry', 'apache-hint-title'),
		list
	);
	node.hidden = false;
	window.requestAnimationFrame(function(){
		node.classList.add('is-visible');
		positionTooltip(clientX, clientY);
	});
}

function hideTooltip(){
	[activeHintButton, pinnedHintButton].forEach(function(button){
		if(!button){ return; }
		button.setAttribute('aria-expanded', 'false');
		button.removeAttribute('aria-describedby');
	});
	activeHintButton = null;
	pinnedHintButton = null;
	if(tooltip){
		tooltip.classList.remove('is-visible');
		tooltip.hidden = true;
	}
}

function positionTooltip(clientX, clientY){
	if(!tooltip){ return; }
	var gap = 16;
	var rect = tooltip.getBoundingClientRect();
	var left = clientX + gap;
	var top = clientY + gap;
	if(left > window.innerWidth - rect.width - 14){ left = clientX - rect.width - gap; }
	if(top > window.innerHeight - rect.height - 14){ top = clientY - rect.height - gap; }
	tooltip.style.left = Math.max(14, left) + 'px';
	tooltip.style.top = Math.max(14, top) + 'px';
}

document.addEventListener('click', function(event){
	if(!activeHintButton && !pinnedHintButton){ return; }
	if(el.entryRows && hintButtonFromEvent(event)){ return; }
	hideTooltip();
});
document.addEventListener('keydown', function(event){
	if(event.key === 'Escape' && (activeHintButton || pinnedHintButton)){ hideTooltip(); }
});

/* ---------------- small helpers ---------------- */

function makeEl(tagName, text, className){
	var node = document.createElement(tagName);
	if(text){ node.textContent = text; }
	if(className){ node.className = className; }
	return node;
}

function makeSvg(tagName, attributes, text){
	var node = document.createElementNS(SVG_NS, tagName);
	Object.keys(attributes || {}).forEach(function(name){
		node.setAttribute(name, String(attributes[name]));
	});
	if(text){ node.textContent = text; }
	return node;
}

function samplePoints(points, maxPoints){
	if(points.length <= maxPoints){ return points; }
	var step = points.length / maxPoints;
	var out = [];
	for(var i = 0; i < maxPoints; i++){ out.push(points[Math.floor(i * step)]); }
	return out;
}

function yForSeverity(levelIndex, top, plotHeight){
	return top + (levelIndex / Math.max(LEVEL_ORDER.length - 1, 1)) * plotHeight;
}

function sumLevels(counts, levels){
	return levels.reduce(function(total, level){ return total + (counts[level] || 0); }, 0);
}

function displayLevel(level){
	if(!level){ return 'Unknown'; }
	return level === 'crit' ? 'Critical' : level.charAt(0).toUpperCase() + level.slice(1);
}

function formatNumber(value){
	return new Intl.NumberFormat().format(value || 0);
}

function formatBytes(bytes){
	if(!bytes){ return '0 bytes'; }
	var units = ['bytes','KB','MB','GB'];
	var index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
	return (bytes / Math.pow(1024, index)).toFixed(index ? 1 : 0) + ' ' + units[index];
}

function percentLabel(value, total){
	return total ? Math.round((value / total) * 100) + '% of parsed entries' : '0% of parsed entries';
}

function percentValue(value, total){
	return total ? Math.round((value / total) * 100) : 0;
}

function formatDuration(first, last){
	if(!first || !last){ return 'Unknown'; }
	var seconds = Math.max(0, Math.round(last - first));
	if(seconds < 60){ return seconds + 's'; }
	if(seconds < 3600){ return Math.round(seconds / 60) + 'm'; }
	if(seconds < 86400){ return (seconds / 3600).toFixed(1) + 'h'; }
	return (seconds / 86400).toFixed(1) + 'd';
}

function formatDateRange(first, last){
	if(!first || !last){ return 'Timestamps unavailable'; }
	var formatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium' });
	var start = new Date(first * 1000);
	var end = new Date(last * 1000);
	if(start.getTime() === end.getTime()){ return formatter.format(start); }
	return formatter.format(start) + ' to ' + formatter.format(end);
}

function formatDate(epoch){
	return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(epoch * 1000));
}

function formatShortDate(epoch){
	return new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
		.format(new Date(epoch * 1000));
}

//Called by charts.js once the analytics dashboard HTML is in the page.
window.initApacheLogPanel = initApacheLogPanel;
})();
