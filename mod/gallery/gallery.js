/*
 * gallery.js - gallery module client.
 *
 * Admin side: gallery manager (list + create), the per-gallery editor
 * (rename, add photos by URL, drag-drop uploads, captions, reorder, delete),
 * and a standalone viewer. Public side: a global lightbox that activates on
 * any .ghotiGalleryItem - both standalone gallery pages and [gallery:name]
 * embeds inside normal pages get the same experience with zero extra markup.
 */

/* ================================================================== *
 *  Admin: navigation
 * ================================================================== */

var GALLERY_EDITOR_ID = 0;

function galleryManager(){
	x_getGalleryManager(galleryManager_cb);
}
function galleryManager_cb(result){
	printPage(result);
}
function galleryView(id){
	x_getGalleryById(id, printPage);
}

function galleryEditor(id){
	GALLERY_EDITOR_ID = id;
	x_getGalleryEditor(id, galleryEditor_cb);
}
function galleryEditor_cb(result){
	printPage(result);
	galleryWireUploadZone();
}

/* ================================================================== *
 *  Admin: create / edit gallery details
 * ================================================================== */

function galleryNewForm(){
	$("#popup-content").html(
		"<form id=\"galleryNewForm\" class=\"ghotiForm\" action=\"#\" onsubmit=\"galleryAdd(); return false;\">"+
			"<label class=\"ghotiField\"><span>Name (slug)</span><input type=\"text\" id=\"galleryNewName\" maxlength=\"80\" autocomplete=\"off\" placeholder=\"summer-2026\" /></label>"+
			"<p class=\"ghotiHelpText\">Lowercase letters, numbers, _ . - (no spaces). Used in the embed code: <b>[gallery:name]</b>.</p>"+
			"<label class=\"ghotiField\"><span>Title</span><input type=\"text\" id=\"galleryNewTitle\" maxlength=\"120\" placeholder=\"Summer 2026\" /></label>"+
			"<label class=\"ghotiField\"><span>Description</span><input type=\"text\" id=\"galleryNewDescription\" maxlength=\"500\" /></label>"+
			"<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Create Gallery</button></div>"+
		"</form>"
	);
	$("#popupTitle").html("New gallery");
	showPopup();
}

function galleryAdd(){
	var name  = $("#galleryNewName").val();
	var title = $("#galleryNewTitle").val();
	var desc  = $("#galleryNewDescription").val();
	if(!name || !title){
		popupFeedBack("Name and title are required.");
		return;
	}
	x_addGallery(name,title,desc,galleryAdd_cb);
}
function galleryAdd_cb(result){
	if(/^\d+$/.test(String(result)) && parseInt(result,10) > 0){
		cancelPopup('popup-bg');
		galleryEditor(parseInt(result,10));
	}else{
		popupFeedBack(result);
	}
}

function gallerySaveDetails(){
	var name  = $("#galleryName").val();
	var title = $("#galleryTitle").val();
	var desc  = $("#galleryDescription").val();
	if(!name || !title){
		pageFeedBack("Name and title are required.");
		return;
	}
	x_editGallery(GALLERY_EDITOR_ID,name,title,desc,gallerySaveDetails_cb);
}
function gallerySaveDetails_cb(result){
	if(result === true || result === "1"){
		pageFeedBack("Gallery saved!");
		galleryEditor(GALLERY_EDITOR_ID);
	}else{
		pageFeedBack(result);
	}
}

function galleryDelete(id){
	var confirmation = confirm('Delete this gallery and ALL of its photos?\nThis is permanent!');
	if(confirmation){
		x_deleteGallery(id,galleryDelete_cb);
	}
}
function galleryDelete_cb(result){
	if(result === true || result === "1"){
		galleryManager();
	}else{
		pageFeedBack(result);
	}
}

/* ================================================================== *
 *  Admin: photos (URL add, uploads, captions, reorder, delete)
 * ================================================================== */

function galleryAddPhotoUrl(id){
	var url = $("#galleryPhotoUrl").val();
	if(!url){
		pageFeedBack("Enter an image URL.");
		return;
	}
	x_addPhoto(id,url,"",galleryAddPhotoUrl_cb);
}
function galleryAddPhotoUrl_cb(result){
	if(result === true || result === "1"){
		$("#galleryPhotoUrl").val("");
		galleryEditor(GALLERY_EDITOR_ID);
	}else{
		pageFeedBack(result);
	}
}

function gallerySaveCaption(photoId){
	var caption = $("#photoCaption-"+photoId).val();
	x_setPhotoCaption(photoId,caption,galleryPhotoOp_cb);
}
function galleryMovePhoto(photoId,direction){
	x_movePhoto(photoId,direction,galleryPhotoOp_cb);
}
function galleryDeletePhoto(photoId){
	var confirmation = confirm('Delete this photo?\nThis is permanent!');
	if(confirmation){
		x_deletePhoto(photoId,galleryPhotoOp_cb);
	}
}
function galleryPhotoOp_cb(result){
	if(result === true || result === "1"){
		galleryEditor(GALLERY_EDITOR_ID);
	}else if(typeof result === 'string' && result.length){
		pageFeedBack(result);
	}
}

/* Drag-drop + click-to-browse upload. Files are sent one at a time as
 * multipart/form-data (the async layer accepts form-encoded POSTs for this)
 * and the editor is refreshed once the queue is done. */
function galleryWireUploadZone(){
	var zone = document.getElementById('galleryUploadZone');
	var input = document.getElementById('galleryUploadInput');
	if(!zone || !input){ return; }
	zone.addEventListener('click', function(){ input.click(); });
	zone.addEventListener('dragover', function(e){
		e.preventDefault();
		zone.classList.add('ghotiGalleryUploadZoneActive');
	});
	zone.addEventListener('dragleave', function(){
		zone.classList.remove('ghotiGalleryUploadZoneActive');
	});
	zone.addEventListener('drop', function(e){
		e.preventDefault();
		zone.classList.remove('ghotiGalleryUploadZoneActive');
		if(e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length){
			galleryUploadQueue(GALLERY_EDITOR_ID, e.dataTransfer.files);
		}
	});
	input.addEventListener('change', function(){
		if(input.files && input.files.length){
			galleryUploadQueue(GALLERY_EDITOR_ID, input.files);
		}
		input.value = '';
	});
}

function galleryUploadQueue(galleryId, files){
	var progress = document.getElementById('galleryUploadProgress');
	var list = Array.prototype.slice.call(files);
	var total = list.length;
	var done = 0;
	if(progress){ progress.textContent = "Uploading 0 of " + total + "..."; }
	function next(){
		if(!list.length){
			if(progress){ progress.textContent = "Uploaded " + total + " image" + (total === 1 ? "" : "s") + "."; }
			galleryEditor(galleryId);
			return;
		}
		var file = list.shift();
		galleryUploadOne(galleryId, file, function(ok){
			done++;
			if(progress){ progress.textContent = "Uploading " + done + " of " + total + (ok ? "..." : " - one file failed."); }
			next();
		});
	}
	next();
}

function galleryUploadOne(galleryId, file, callback){
	var fd = new FormData();
	fd.append('__ghoti_async','1');
	fd.append('fn','uploadPhoto');
	fd.append('token',GHOTI_CSRF_TOKEN);
	fd.append('args[0]', String(galleryId));
	fd.append('file', file, file.name || 'image');
	fetch(GHOTI_ASYNC_URL, {
		method: 'POST',
		credentials: 'same-origin',
		body: fd
	}).then(function(resp){
		return resp.json();
	}).then(function(data){
		//Success returns the stored relative path ("files/gallery/<id>/<name>");
		//failures return human-readable error strings alongside ok:true.
		if(data && data.ok && typeof data.result === 'string' && data.result.indexOf('files/gallery/') === 0){
			if(callback){ callback(true); }
		}else{
			if(window.console){ console.error('gallery upload:', data && data.error, data && data.result); }
			if(callback){ callback(false); }
		}
	}).catch(function(){
		if(window.console){ console.error('gallery upload failed'); }
		if(callback){ callback(false); }
	});
}

/* ================================================================== *
 *  Public: lightbox (vanilla JS - no jQuery dependency, works on every
 *  page whether the gallery came from a standalone view or a shortcode)
 * ================================================================== */

var ghotiLightbox = null;

function ghotiLightboxEnsure(){
	if(ghotiLightbox){ return ghotiLightbox; }
	var el = document.createElement('div');
	el.className = 'ghotiLightbox';
	el.setAttribute('role','dialog');
	el.setAttribute('aria-modal','true');
	el.innerHTML =
		'<div class="ghotiLightboxBackdrop"></div>'+
		'<figure class="ghotiLightboxFigure">'+
			'<img class="ghotiLightboxImage" alt="" />'+
			'<figcaption class="ghotiLightboxCaption"></figcaption>'+
		'</figure>'+
		'<button type="button" class="ghotiLightboxNav ghotiLightboxPrev" aria-label="Previous photo">&#8592;</button>'+
		'<button type="button" class="ghotiLightboxNav ghotiLightboxNext" aria-label="Next photo">&#8594;</button>'+
		'<button type="button" class="ghotiLightboxClose" aria-label="Close">&#10005;</button>'+
		'<span class="ghotiLightboxCounter"></span>';
	document.body.appendChild(el);
	ghotiLightbox = {
		el: el,
		items: [],
		index: 0,
		open: false,
		img: el.querySelector('.ghotiLightboxImage'),
		caption: el.querySelector('.ghotiLightboxCaption'),
		counter: el.querySelector('.ghotiLightboxCounter')
	};
	el.querySelector('.ghotiLightboxClose').addEventListener('click', function(){ ghotiLightboxClose(); });
	el.querySelector('.ghotiLightboxPrev').addEventListener('click', function(e){ e.stopPropagation(); ghotiLightboxStep(-1); });
	el.querySelector('.ghotiLightboxNext').addEventListener('click', function(e){ e.stopPropagation(); ghotiLightboxStep(1); });
	el.querySelector('.ghotiLightboxBackdrop').addEventListener('click', function(){ ghotiLightboxClose(); });
	document.addEventListener('keydown', function(e){
		if(!ghotiLightbox || !ghotiLightbox.open){ return; }
		if(e.key === 'Escape'){ ghotiLightboxClose(); }
		else if(e.key === 'ArrowLeft'){ ghotiLightboxStep(-1); }
		else if(e.key === 'ArrowRight'){ ghotiLightboxStep(1); }
	});
	return ghotiLightbox;
}

function ghotiLightboxOpen(items, index){
	var lb = ghotiLightboxEnsure();
	lb.items = items || [];
	lb.index = Math.max(0, Math.min(index || 0, lb.items.length - 1));
	lb.open = true;
	ghotiLightboxShow(lb);
	lb.el.classList.add('ghotiLightboxOpen');
	document.body.classList.add('ghotiLightboxLocked');
}

function ghotiLightboxShow(lb){
	if(!lb.items.length){ return; }
	var item = lb.items[lb.index];
	lb.img.setAttribute('src', item.src);
	lb.img.setAttribute('alt', item.caption || '');
	lb.caption.textContent = item.caption || '';
	lb.counter.textContent = (lb.index + 1) + ' / ' + lb.items.length;
	lb.img.classList.remove('ghotiLightboxImageReady');
	//restart the zoom-in animation
	void lb.img.offsetWidth;
	lb.img.classList.add('ghotiLightboxImageReady');
}

function ghotiLightboxStep(step){
	if(!ghotiLightbox || !ghotiLightbox.open || !ghotiLightbox.items.length){ return; }
	ghotiLightbox.index = (ghotiLightbox.index + step + ghotiLightbox.items.length) % ghotiLightbox.items.length;
	ghotiLightboxShow(ghotiLightbox);
}

function ghotiLightboxClose(){
	if(!ghotiLightbox){ return; }
	ghotiLightbox.open = false;
	ghotiLightbox.el.classList.remove('ghotiLightboxOpen');
	document.body.classList.remove('ghotiLightboxLocked');
	window.setTimeout(function(){ ghotiLightbox.img.removeAttribute('src'); }, 250);
}

//Collect the gallery a clicked item belongs to and open it in context.
function ghotiLightboxFromItem(item){
	var container = item.closest('.ghotiGallery, .ghotiGalleryPage') || document;
	var figures = container.querySelectorAll('.ghotiGalleryItem');
	var items = [];
	var start = 0;
	for(var i = 0; i < figures.length; i++){
		var img = figures[i].querySelector('img');
		if(!img){ continue; }
		if(figures[i] === item){ start = items.length; }
		items.push({ src: img.getAttribute('src'), caption: img.getAttribute('alt') || '' });
	}
	if(!items.length){ return; }
	ghotiLightboxOpen(items, start);
}

document.addEventListener('click', function(e){
	var item = e.target;
	if(!item || item.nodeType !== 1){ return; }
	var figure = item.closest ? item.closest('.ghotiGalleryItem') : null;
	if(figure){ ghotiLightboxFromItem(figure); }
});
