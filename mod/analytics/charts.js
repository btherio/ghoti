//Analytics dashboard: state, ghoti async wiring, and a small hand-rolled SVG chart
//engine (no charting library is vendored in this app, and adding one just for
//these compact charts would be overkill, so this stays plain DOM/SVG).

var ANALYTICS_STATE = { days: 30, excludeAdmin: true };

function showAnalytics(){
	x_showAnalytics(ANALYTICS_STATE.days, ANALYTICS_STATE.excludeAdmin, renderAnalytics_cb);
}
function setAnalyticsRange(days){
	ANALYTICS_STATE.days = days;
	showAnalytics();
}
function toggleAnalyticsAdmin(checked){
	ANALYTICS_STATE.excludeAdmin = !!checked;
	showAnalytics();
}
function renderAnalytics_cb(content){
	printPage(content);
	drawAllAnalyticsCharts();
}

function drawAllAnalyticsCharts(){
	var el = document.getElementById('analyticsData');
	if(!el) return;
	var data;
	try{ data = JSON.parse(el.textContent || el.innerText); }catch(e){ return; }

	drawLineChart('chart-byday', data.byDay || []);
	drawVerticalBars('chart-byhour', (data.byHour || []).map(function(r){ return [hourLabel(r[0]), r[1]]; }));
	drawRankedBars('chart-toppages', (data.topPages || []).map(function(r){ return [r[0], r[2]]; }));
	drawDonutChart('chart-browsers', data.browsers || []);
	drawDonutChart('chart-os', data.oses || []);
	drawDonutChart('chart-devices', data.devices || []);

	var referrerSeries = [];
	var referrers = data.referrers || {};
	for(var host in referrers){ if(referrers.hasOwnProperty(host)){ referrerSeries.push([host, referrers[host]]); } }
	drawRankedBars('chart-referrers', referrerSeries);
}

/* ---- shared helpers ---- */

var SVG_NS = 'http://www.w3.org/2000/svg';
function svgEl(tag, attrs){
	var el = document.createElementNS(SVG_NS, tag);
	if(attrs){
		for(var k in attrs){ if(attrs.hasOwnProperty(k)){ el.setAttribute(k, attrs[k]); } }
	}
	return el;
}
function formatCount(n){
	n = Number(n) || 0;
	if(n >= 1000000) return (n/1000000).toFixed(1)+'M';
	if(n >= 1000) return (n/1000).toFixed(1)+'K';
	return String(Math.round(n*10)/10);
}
function hourLabel(h){
	h = Number(h);
	if(h === 0) return '12a';
	if(h === 12) return '12p';
	return h < 12 ? (h+'a') : ((h-12)+'p');
}
function niceCeil(v){
	if(v <= 0) return 1;
	var magnitude = Math.pow(10, Math.floor(Math.log(v)/Math.LN10));
	var residual = v/magnitude;
	var niceResidual;
	if(residual > 5) niceResidual = 10;
	else if(residual > 2) niceResidual = 5;
	else if(residual > 1) niceResidual = 2;
	else niceResidual = 1;
	return niceResidual * magnitude;
}
function svgPoint(svg, evt){
	var pt = svg.createSVGPoint();
	pt.x = evt.clientX; pt.y = evt.clientY;
	var ctm = svg.getScreenCTM();
	if(!ctm) return {x:0,y:0};
	var loc = pt.matrixTransform(ctm.inverse());
	return {x: loc.x, y: loc.y};
}
function makeTooltip(container){
	container.style.position = container.style.position || 'relative';
	var tip = document.createElement('div');
	tip.className = 'chart-tooltip';
	container.appendChild(tip);
	return tip;
}
function showTooltip(tip, container, evt, text){
	tip.textContent = text;
	tip.style.display = 'block';
	var rect = container.getBoundingClientRect();
	tip.style.left = (evt.clientX - rect.left + 12) + 'px';
	tip.style.top = (evt.clientY - rect.top - 12) + 'px';
}
function hideTooltip(tip){
	tip.style.display = 'none';
}
function escapeHtml(s){
	return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* Color follows the entity, never its rank: fixed slot per known category
 * name, stable fallback for anything unrecognized. */
var CATEGORY_COLOR_MAP = {
	'Chrome':'--series-1', 'Firefox':'--series-2', 'Edge':'--series-3', 'Safari':'--series-4',
	'Opera':'--series-5', 'Internet Explorer':'--series-6', 'Other':'--series-7',
	'Windows':'--series-1', 'macOS':'--series-2', 'Linux':'--series-3', 'Android':'--series-4', 'iOS':'--series-5', 'Unknown':'--series-7',
	'Desktop':'--series-1', 'Mobile':'--series-2', 'Tablet':'--series-3'
};
var FALLBACK_SLOTS = ['--series-1','--series-2','--series-3','--series-4','--series-5','--series-6','--series-7','--series-8'];
function colorForCategory(name, fallbackRef){
	if(CATEGORY_COLOR_MAP.hasOwnProperty(name)) return 'var('+CATEGORY_COLOR_MAP[name]+')';
	var slot = FALLBACK_SLOTS[fallbackRef.i % FALLBACK_SLOTS.length];
	fallbackRef.i++;
	return 'var('+slot+')';
}

/* ---- line/area chart (pageviews over time) ---- */
function drawLineChart(containerId, series){
	var container = document.getElementById(containerId);
	if(!container) return;
	container.innerHTML = '';
	if(!series.length){
		container.innerHTML = '<div class="analytics-empty">No data yet.</div>';
		return;
	}

	var W = 600, H = 220, padL = 34, padR = 12, padT = 14, padB = 26;
	var plotW = W - padL - padR, plotH = H - padT - padB;

	var values = series.map(function(p){ return Number(p[1]) || 0; });
	var niceMax = niceCeil(Math.max.apply(null, values.concat([1])));

	var svg = svgEl('svg', {viewBox: '0 0 '+W+' '+H});

	var steps = 4;
	for(var s = 0; s <= steps; s++){
		var y = padT + plotH - (plotH * s / steps);
		var gl = svgEl('line', {x1:padL, x2:W-padR, y1:y, y2:y});
		gl.style.stroke = 'var(--gridline)'; gl.style.strokeWidth = '1';
		svg.appendChild(gl);

		var lbl = svgEl('text', {x:padL-6, y:y+3, 'text-anchor':'end', 'font-size':'9'});
		lbl.style.fill = 'var(--text-muted)';
		lbl.textContent = formatCount(Math.round(niceMax * s/steps));
		svg.appendChild(lbl);
	}
	var baseline = svgEl('line', {x1:padL, x2:W-padR, y1:padT+plotH, y2:padT+plotH});
	baseline.style.stroke = 'var(--baseline)'; baseline.style.strokeWidth = '1';
	svg.appendChild(baseline);

	function xAt(i){ return padL + (series.length<=1 ? plotW/2 : plotW*i/(series.length-1)); }
	function yAt(v){ return padT + plotH - (niceMax>0 ? plotH*v/niceMax : 0); }

	var d = '';
	series.forEach(function(p,i){
		d += (i===0?'M':'L') + xAt(i).toFixed(1) + ',' + yAt(Number(p[1])||0).toFixed(1) + ' ';
	});
	var lastX = xAt(series.length-1);
	var areaD = d + 'L'+lastX.toFixed(1)+','+(padT+plotH)+' L'+xAt(0).toFixed(1)+','+(padT+plotH)+' Z';

	var area = svgEl('path', {d: areaD});
	area.style.fill = 'var(--series-1)'; area.style.opacity = '0.1'; area.style.stroke = 'none';
	svg.appendChild(area);

	var line = svgEl('path', {d: d.trim(), fill:'none'});
	line.style.stroke = 'var(--series-1)'; line.style.strokeWidth = '2';
	line.setAttribute('stroke-linecap','round'); line.setAttribute('stroke-linejoin','round');
	svg.appendChild(line);

	var lastIdx = series.length-1;
	var lx = xAt(lastIdx), ly = yAt(Number(series[lastIdx][1])||0);
	var ring = svgEl('circle', {cx:lx, cy:ly, r:6});
	ring.style.fill = 'var(--surface-1)';
	svg.appendChild(ring);
	var dot = svgEl('circle', {cx:lx, cy:ly, r:4});
	dot.style.fill = 'var(--series-1)';
	svg.appendChild(dot);
	var endLbl = svgEl('text', {x:Math.min(lx+6, W-padR-26), y:Math.max(ly-8, padT+8), 'font-size':'10', 'font-weight':'600'});
	endLbl.style.fill = 'var(--text-primary)';
	endLbl.textContent = formatCount(series[lastIdx][1]);
	svg.appendChild(endLbl);

	var midIdx = Math.floor((series.length-1)/2);
	[0, midIdx, series.length-1].forEach(function(i, pos){
		if(pos===1 && (i===0 || i===series.length-1)) return;
		var anchor = pos===0 ? 'start' : (pos===2 ? 'end' : 'middle');
		var lbl = svgEl('text', {x:xAt(i), y:H-6, 'font-size':'9', 'text-anchor':anchor});
		lbl.style.fill = 'var(--text-muted)';
		lbl.textContent = series[i][0];
		svg.appendChild(lbl);
	});

	var hoverLine = svgEl('line', {y1:padT, y2:padT+plotH});
	hoverLine.style.stroke = 'var(--baseline)'; hoverLine.style.strokeWidth = '1'; hoverLine.style.display = 'none';
	svg.appendChild(hoverLine);
	var hoverDot = svgEl('circle', {r:5});
	hoverDot.style.fill = 'var(--series-1)'; hoverDot.style.display = 'none';
	var hoverRing = svgEl('circle', {r:7});
	hoverRing.style.fill = 'var(--surface-1)'; hoverRing.style.display = 'none';
	svg.appendChild(hoverRing);
	svg.appendChild(hoverDot);

	var overlay = svgEl('rect', {x:padL, y:padT, width:plotW, height:plotH, fill:'transparent'});
	svg.appendChild(overlay);

	container.appendChild(svg);
	var tooltip = makeTooltip(container);

	overlay.addEventListener('mousemove', function(evt){
		var pt = svgPoint(svg, evt);
		var idx = Math.round(((pt.x - padL)/plotW) * (series.length-1));
		idx = Math.max(0, Math.min(series.length-1, idx));
		var vx = xAt(idx), vy = yAt(Number(series[idx][1])||0);
		hoverLine.setAttribute('x1', vx); hoverLine.setAttribute('x2', vx);
		hoverLine.style.display = 'block';
		hoverDot.setAttribute('cx', vx); hoverDot.setAttribute('cy', vy); hoverDot.style.display = 'block';
		hoverRing.setAttribute('cx', vx); hoverRing.setAttribute('cy', vy); hoverRing.style.display = 'block';
		showTooltip(tooltip, container, evt, series[idx][0]+': '+formatCount(series[idx][1]));
	});
	overlay.addEventListener('mouseleave', function(){
		hoverLine.style.display = 'none'; hoverDot.style.display = 'none'; hoverRing.style.display = 'none';
		hideTooltip(tooltip);
	});
}

/* ---- vertical bar chart (hourly distribution) ---- */
function drawVerticalBars(containerId, series){
	var container = document.getElementById(containerId);
	if(!container) return;
	container.innerHTML = '';
	if(!series.length){
		container.innerHTML = '<div class="analytics-empty">No data yet.</div>';
		return;
	}

	var W = 600, H = 220, padL = 30, padR = 10, padT = 14, padB = 26;
	var plotW = W-padL-padR, plotH = H-padT-padB;
	var n = series.length;
	var niceMax = niceCeil(Math.max.apply(null, series.map(function(p){return Number(p[1])||0;}).concat([1])));

	var svg = svgEl('svg', {viewBox:'0 0 '+W+' '+H});

	for(var s=0;s<=4;s++){
		var y = padT + plotH - (plotH*s/4);
		var gl = svgEl('line',{x1:padL,x2:W-padR,y1:y,y2:y});
		gl.style.stroke='var(--gridline)'; gl.style.strokeWidth='1';
		svg.appendChild(gl);
	}
	var baseline = svgEl('line',{x1:padL,x2:W-padR,y1:padT+plotH,y2:padT+plotH});
	baseline.style.stroke='var(--baseline)'; baseline.style.strokeWidth='1';
	svg.appendChild(baseline);

	var slotW = plotW/n;
	var barW = Math.min(24, slotW*0.6);
	var tooltip = makeTooltip(container);

	series.forEach(function(p,i){
		var v = Number(p[1])||0;
		var barH = niceMax>0 ? plotH*v/niceMax : 0;
		var cx = padL + slotW*i + slotW/2;
		var x = cx - barW/2;
		var y = padT+plotH-barH;

		var rect = svgEl('rect',{x:x.toFixed(1), y:y.toFixed(1), width:Math.max(barW-2,1).toFixed(1), height:Math.max(barH,0).toFixed(1), rx:4, ry:4});
		rect.style.fill = 'var(--series-1)';
		svg.appendChild(rect);

		var hit = svgEl('rect',{x:(padL+slotW*i).toFixed(1), y:padT, width:slotW.toFixed(1), height:plotH, fill:'transparent'});
		svg.appendChild(hit);
		hit.addEventListener('mousemove', function(evt){
			rect.style.opacity = '0.8';
			showTooltip(tooltip, container, evt, p[0]+': '+formatCount(v));
		});
		hit.addEventListener('mouseleave', function(){
			rect.style.opacity = '1';
			hideTooltip(tooltip);
		});

		if(i % 3 === 0){
			var lbl = svgEl('text',{x:cx.toFixed(1), y:H-6, 'font-size':'9','text-anchor':'middle'});
			lbl.style.fill = 'var(--text-muted)';
			lbl.textContent = p[0];
			svg.appendChild(lbl);
		}
	});

	container.appendChild(svg);
}

/* ---- ranked horizontal bar list (top pages / top referrers) ----
 * HTML rather than SVG: labels are arbitrary-length page titles/hostnames,
 * and text layout in HTML avoids measuring/truncating strings by hand. */
function drawRankedBars(containerId, series){
	var container = document.getElementById(containerId);
	if(!container) return;
	container.innerHTML = '';
	if(!series.length){
		container.innerHTML = '<div class="analytics-empty">No data yet.</div>';
		return;
	}
	var maxV = Math.max.apply(null, series.map(function(p){ return Number(p[1])||0; }).concat([1]));
	var list = document.createElement('div');
	list.className = 'ranked-bars';
	series.forEach(function(p){
		var v = Number(p[1]) || 0;
		var pct = Math.max(3, Math.round((v/maxV)*100));
		var row = document.createElement('div');
		row.className = 'ranked-row';
		row.innerHTML =
			'<span class="ranked-label" title="'+escapeHtml(p[0])+'">'+escapeHtml(p[0])+'</span>'+
			'<span class="ranked-track"><span class="ranked-fill" style="width:'+pct+'%"></span></span>'+
			'<span class="ranked-value">'+formatCount(v)+'</span>';
		list.appendChild(row);
	});
	container.appendChild(list);
}

/* ---- donut chart (browser / os / device breakdown) ---- */
function drawDonutChart(containerId, series){
	var container = document.getElementById(containerId);
	if(!container) return;
	container.innerHTML = '';
	var total = series.reduce(function(sum,p){ return sum + (Number(p[1])||0); }, 0);
	if(total <= 0){
		container.innerHTML = '<div class="analytics-empty">No data yet.</div>';
		return;
	}

	var size = 170, cx = size/2, cy = size/2, rOuter = 66, rInner = 40;
	var svg = svgEl('svg', {viewBox:'0 0 '+size+' '+size});
	var tooltip = makeTooltip(container);
	var fallbackRef = {i:0};
	var angle = -Math.PI/2;

	var legend = document.createElement('div');
	legend.className = 'chart-legend';

	series.forEach(function(p){
		var label = String(p[0]);
		var value = Number(p[1]) || 0;
		var frac = value/total;
		var sweep = frac * Math.PI * 2;
		var color = colorForCategory(label, fallbackRef);

		var endAngle = angle + sweep;
		var x1 = cx + rOuter*Math.cos(angle), y1 = cy + rOuter*Math.sin(angle);
		var x2 = cx + rOuter*Math.cos(endAngle), y2 = cy + rOuter*Math.sin(endAngle);
		var largeArc = sweep > Math.PI ? 1 : 0;
		var ix1 = cx + rInner*Math.cos(endAngle), iy1 = cy + rInner*Math.sin(endAngle);
		var ix2 = cx + rInner*Math.cos(angle), iy2 = cy + rInner*Math.sin(angle);

		var d = 'M'+x1.toFixed(2)+','+y1.toFixed(2)+
			' A'+rOuter+','+rOuter+' 0 '+largeArc+' 1 '+x2.toFixed(2)+','+y2.toFixed(2)+
			' L'+ix1.toFixed(2)+','+iy1.toFixed(2)+
			' A'+rInner+','+rInner+' 0 '+largeArc+' 0 '+ix2.toFixed(2)+','+iy2.toFixed(2)+' Z';

		var path = svgEl('path', {d:d});
		path.style.fill = color;
		path.setAttribute('stroke','var(--surface-1)'); //2px surface gap between slices
		path.setAttribute('stroke-width','2');
		svg.appendChild(path);

		path.addEventListener('mousemove', function(evt){
			path.style.opacity = '0.85';
			showTooltip(tooltip, container, evt, label+': '+formatCount(value)+' ('+Math.round(frac*100)+'%)');
		});
		path.addEventListener('mouseleave', function(){
			path.style.opacity = '1';
			hideTooltip(tooltip);
		});

		var item = document.createElement('span');
		var swatch = document.createElement('span');
		swatch.className = 'swatch';
		swatch.style.background = color;
		item.appendChild(swatch);
		item.appendChild(document.createTextNode(label+' ('+Math.round(frac*100)+'%)'));
		legend.appendChild(item);

		angle = endAngle;
	});

	var centerVal = svgEl('text', {x:cx, y:cy-1, 'text-anchor':'middle', 'font-size':'17','font-weight':'600'});
	centerVal.style.fill = 'var(--text-primary)';
	centerVal.textContent = formatCount(total);
	svg.appendChild(centerVal);
	var centerSub = svgEl('text', {x:cx, y:cy+13, 'text-anchor':'middle', 'font-size':'9'});
	centerSub.style.fill = 'var(--text-muted)';
	centerSub.textContent = 'total';
	svg.appendChild(centerSub);

	container.appendChild(svg);
	container.appendChild(legend);
}
