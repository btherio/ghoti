/*
 * filemanager.js - simple GUI file manager (admin).
 * Server-rendered listing (see filemanager.async.php); this file handles
 * navigation, uploads, folder/file ops and the text editor. All actions go
 * through the normal x_* RPC stubs (CSRF token included automatically).
 */

var FM_DIR = '';   // current relative directory ('' = web root)
var FM_FILE = '';  // name of the file open in the text editor

function fileManager(dir){
	FM_DIR = dir || '';
	x_getFileManager(FM_DIR, function(result){
		printPage(result);
		fmWire();
	});
}

/* ---------- delegated actions (survive printPage re-renders) ---------- */

$(document).on('click', '.fmDirLink', function(e){
	e.preventDefault();
	fileManager(this.getAttribute('data-dir') || '');
});
$(document).on('click', '.fmBackLink', function(e){
	e.preventDefault();
	fileManager(FM_DIR);
});
$(document).on('click', '[data-fm-edit]', function(){
	fmEdit(this.getAttribute('data-dir'), this.getAttribute('data-name'));
});
$(document).on('click', '[data-fm-rename]', function(){
	fmRename(this.getAttribute('data-dir'), this.getAttribute('data-name'));
});
$(document).on('click', '[data-fm-delete]', function(){
	fmDelete(this.getAttribute('data-dir'), this.getAttribute('data-name'));
});
$(document).on('click', '[data-fm-download]', function(e){
	e.preventDefault();
	var dir = this.getAttribute('data-dir') || '';
	var name = this.getAttribute('data-name') || '';
	var url = 'mod/filemanager/filemanager.download.php?dir=' + encodeURIComponent(dir)
		+ '&name=' + encodeURIComponent(name)
		+ '&token=' + encodeURIComponent(GHOTI_CSRF_TOKEN);
	window.open(url, '_blank', 'noopener');
});

/* ---------- operations ---------- */

function fmOp_cb(result){
	if(result === true || result === '1'){
		fileManager(FM_DIR);
	}else if(typeof result === 'string' && result.length){
		pageFeedBack(result);
	}
}

function fmNewFolder(){
	var name = prompt("New folder name:", "");
	if(!name || !name.replace(/\s/g, '')){ return; }
	x_fmCreateDir(FM_DIR, name.trim(), fmOp_cb);
}

function fmRename(dir, oldName){
	var name = prompt("Rename '" + oldName + "' to:", oldName);
	if(!name || !name.replace(/\s/g, '')){ return; }
	name = name.trim();
	if(name === oldName){ return; }
	x_fmRename(dir, oldName, name, fmOp_cb);
}

function fmDelete(dir, name){
	var label = (dir ? dir + '/' : '') + name;
	var confirmation = confirm("Delete '" + label + "'?\n\nFolders are deleted together with everything inside them.\nThis is permanent!");
	if(confirmation){
		x_fmDelete(dir, name, fmOp_cb);
	}
}

function fmEdit(dir, name){
	FM_DIR = dir || '';
	FM_FILE = name;
	x_fmGetTextFile(FM_DIR, FM_FILE, function(result){
		printPage(result);
		var editor = document.getElementById('fmEditor');
		if(editor){ editor.focus(); }
	});
}

function fmSaveText(){
	var editor = document.getElementById('fmEditor');
	if(!editor){ return; }
	x_fmSaveTextFile(FM_DIR, FM_FILE, editor.value, fmOp_cb);
}

function fmBack(){
	fileManager(FM_DIR);
}

/* ---------- uploads (multipart, one file per request) ---------- */

function fmToggleUpload(){
	var zone = document.getElementById('fmUploadZone');
	if(!zone){ return; }
	if(zone.hasAttribute('hidden')){
		zone.removeAttribute('hidden');
	}else{
		zone.setAttribute('hidden', '');
	}
}

function fmWire(){
	var zone = document.getElementById('fmUploadZone');
	var input = document.getElementById('fmUploadInput');
	if(!zone || !input){ return; }
	var inner = zone.querySelector('.fmUploadZoneInner');
	if(!inner){ return; }
	inner.addEventListener('click', function(){ input.click(); });
	inner.addEventListener('dragover', function(e){
		e.preventDefault();
		inner.classList.add('fmUploadZoneActive');
	});
	inner.addEventListener('dragleave', function(){
		inner.classList.remove('fmUploadZoneActive');
	});
	inner.addEventListener('drop', function(e){
		e.preventDefault();
		inner.classList.remove('fmUploadZoneActive');
		if(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length){
			fmUploadQueue(FM_DIR, e.dataTransfer.files);
		}
	});
	input.addEventListener('change', function(){
		if(input.files && input.files.length){
			fmUploadQueue(FM_DIR, input.files);
		}
		input.value = '';
	});
}

function fmUploadQueue(dir, files){
	var progress = document.getElementById('fmUploadProgress');
	var list = Array.prototype.slice.call(files);
	var total = list.length;
	var done = 0;
	if(progress){ progress.textContent = "Uploading 0 of " + total + "..."; }
	function next(){
		if(!list.length){
			if(progress){ progress.textContent = "Uploaded " + total + " file" + (total === 1 ? "" : "s") + "."; }
			fileManager(dir);
			return;
		}
		var file = list.shift();
		fmUploadOne(dir, file, function(ok){
			done++;
			if(progress){ progress.textContent = "Uploading " + done + " of " + total + (ok ? "..." : " - one file failed."); }
			next();
		});
	}
	next();
}

function fmUploadOne(dir, file, callback){
	var fd = new FormData();
	fd.append('__ghoti_async','1');
	fd.append('fn','fmUpload');
	fd.append('token',GHOTI_CSRF_TOKEN);
	fd.append('args[0]', dir);
	fd.append('file', file, file.name || 'file');
	fetch(GHOTI_ASYNC_URL, {
		method: 'POST',
		credentials: 'same-origin',
		body: fd
	}).then(function(resp){
		return resp.json();
	}).then(function(data){
		if(data && data.ok && (data.result === true || data.result === '1')){
			if(callback){ callback(true); }
		}else{
			if(window.console){ console.error('filemanager upload:', data && data.error, data && data.result); }
			if(callback){ callback(false); }
		}
	}).catch(function(){
		if(window.console){ console.error('filemanager upload failed'); }
		if(callback){ callback(false); }
	});
}
