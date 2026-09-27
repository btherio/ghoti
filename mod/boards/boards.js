/*
 * boards.js - draws a [board:slug] placed in a page, and the Boards admin screen.
 *
 * The shortcode only leaves an empty <div class="ghotiBoard" data-board-slug>
 * behind (see boards.async.php for why rendering is not done server-side).
 * boardsScan() finds those containers after any page render and fills each one.
 *
 * Every endpoint answers { success, data, error:{code,message} }; boardsError()
 * turns any reply, including a missing one, into a sentence a person can read.
 */
function boardsOk(result){
	return !!(result && result.success === true);
}
function boardsError(result, fallback){
	return (result && result.error && result.error.message) || fallback || "Something went wrong. Try again.";
}

//Post bodies are plain text: escape first, then turn newlines into breaks, so a
//post can contain <script> and still be a post rather than a script.
function boardsBodyHtml(body){
	return ghotiEscapeHtml(body).replace(/\r\n|\r|\n/g, "<br />");
}

function boardsDate(seconds){
	var n = parseInt(seconds, 10);
	if(!n){ return ""; }
	var d = new Date(n * 1000);
	return d.toLocaleDateString() + " " + d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
}

/* ---------------------------------------------------------------- *
 *  A board placed in a page
 *
 *  boardsState keeps where each container is looking (topic and page) so the
 *  same board can appear twice in one page without the two fighting.
 * ---------------------------------------------------------------- */

var boardsState = {};

function boardsScan(){
	var containers = document.querySelectorAll(".ghotiBoard[data-board-slug]");
	Array.prototype.forEach.call(containers, function(node, index){
		//A container that already has its id is one this scan has filled before;
		//re-loading it would throw away whatever topic the reader had opened.
		if(node.getAttribute("data-board-ready")){ return; }
		if(!node.id){ node.id = "ghotiBoard-" + index + "-" + Math.random().toString(36).slice(2, 8); }
		node.setAttribute("data-board-ready", "1");
		boardsLoad(node.id, 0, 1);
	});
}

function boardsLoad(containerId, topicId, page){
	var node = document.getElementById(containerId);
	if(!node){ return; }
	boardsState[containerId] = { topicId: parseInt(topicId, 10) || 0, page: parseInt(page, 10) || 1 };
	var slug = node.getAttribute("data-board-slug");
	x_boardsGetView(slug, boardsState[containerId].topicId, boardsState[containerId].page, function(result){
		boardsRender(containerId, result);
	});
}

function boardsReload(containerId){
	var state = boardsState[containerId] || { topicId: 0, page: 1 };
	boardsLoad(containerId, state.topicId, state.page);
}

function boardsRender(containerId, result){
	var node = document.getElementById(containerId);
	if(!node){ return; } // the page was navigated away from mid-request
	if(!boardsOk(result)){
		node.innerHTML = "<p class=\"ghotiBoardMessage\" role=\"alert\">" + ghotiEscapeHtml(boardsError(result, "This board could not be loaded.")) + "</p>";
		return;
	}
	var data = result.data;
	//The moderation buttons toggle one flag and must leave the other alone, so
	//the thread they are looking at is kept rather than re-read from the markup.
	boardsTopicCache[containerId] = data.topic || null;
	node.innerHTML = data.view === "topics" ? boardsTopicListHtml(containerId, data) : boardsThreadHtml(containerId, data);
	boardsBind(containerId);
}

function boardsHeaderHtml(data, subtitle){
	var board = data.board;
	var html = "<header class=\"ghotiBoardHeader\"><h2>" + ghotiEscapeHtml(board.name) + "</h2>";
	if(board.description){
		html += "<p class=\"ghotiBoardDescription\">" + ghotiEscapeHtml(board.description) + "</p>";
	}
	if(subtitle){ html += subtitle; }
	if(board.locked){ html += "<p class=\"ghotiBoardLocked\">This board is locked.</p>"; }
	return html + "</header>";
}

//Page N of M, as buttons rather than links: a board lives inside a page that
//was itself loaded over async, so a href would navigate away from it.
function boardsPagerHtml(containerId, data, topicId){
	var pages = Math.ceil(data.total / data.pageSize);
	if(pages <= 1){ return ""; }
	var html = "<nav class=\"ghotiBoardPager\" aria-label=\"Pages\">";
	for(var p = 1; p <= pages; p++){
		html += p === data.page
			? "<span class=\"ghotiBoardPage ghotiBoardPageCurrent\" aria-current=\"page\">" + p + "</span>"
			: "<button type=\"button\" class=\"ghotiBoardPage\" data-board-action=\"page\" data-topic=\"" + topicId + "\" data-page=\"" + p + "\">" + p + "</button>";
	}
	return html + "</nav>";
}

function boardsTopicListHtml(containerId, data){
	var rows = data.topics.map(function(topic){
		return "<li class=\"ghotiBoardTopic" + (topic.sticky ? " ghotiBoardSticky" : "") + "\">" +
			"<button type=\"button\" class=\"ghotiBoardTopicLink\" data-board-action=\"open\" data-topic=\"" + topic.topicId + "\">" +
				(topic.sticky ? "<span class=\"ghotiBoardBadge\">Pinned</span>" : "") +
				(topic.locked ? "<span class=\"ghotiBoardBadge\">Locked</span>" : "") +
				ghotiEscapeHtml(topic.title || "(untitled)") +
			"</button>" +
			"<span class=\"ghotiBoardTopicMeta\">" + ghotiEscapeHtml(topic.userName) + " &middot; " +
				topic.postCount + (topic.postCount === 1 ? " post" : " posts") + " &middot; " +
				ghotiEscapeHtml(boardsDate(topic.lastPostAt)) + "</span>" +
		"</li>";
	}).join("");

	var body = rows
		? "<ul class=\"ghotiBoardTopics\">" + rows + "</ul>"
		: "<p class=\"ghotiBoardEmpty\">No topics yet.</p>";

	return boardsHeaderHtml(data, "") + body + boardsPagerHtml(containerId, data, 0) + boardsNewTopicHtml(data);
}

function boardsNewTopicHtml(data){
	if(!data.canPost){
		return "<p class=\"ghotiBoardMessage\">" + ghotiEscapeHtml(data.postRefusal || "You cannot post here.") + "</p>";
	}
	return "<form class=\"ghotiForm ghotiBoardForm\" data-board-form=\"topic\" action=\"#\">" +
		"<label class=\"ghotiField\"><span>Topic title</span><input type=\"text\" data-board-field=\"title\" maxlength=\"160\" autocomplete=\"off\" required /></label>" +
		"<label class=\"ghotiField\"><span>Message</span><textarea data-board-field=\"body\" rows=\"6\" required></textarea></label>" +
		"<p class=\"ghotiFormError\" data-board-error role=\"alert\" hidden></p>" +
		"<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Start topic</button></div>" +
	"</form>";
}

function boardsPostHtml(post, moderates){
	var canTouch = post.mine || moderates;
	var actions = "";
	if(canTouch){
		actions = "<span class=\"ghotiBoardPostActions\">" +
			"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" data-board-action=\"edit\" data-post=\"" + post.postId + "\">Edit</button>" +
			"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" data-board-action=\"delete-post\" data-post=\"" + post.postId + "\">Delete</button>" +
		"</span>";
	}
	return "<article class=\"ghotiBoardPost\" data-post-id=\"" + post.postId + "\">" +
		"<header class=\"ghotiBoardPostHeader\">" +
			"<span class=\"ghotiBoardAuthor\">" + ghotiEscapeHtml(post.userName) +
				(post.authorAdmin ? "<span class=\"ghotiBoardBadge\">Admin</span>" : "") + "</span>" +
			"<span class=\"ghotiBoardPostCount\">" + post.authorPosts + (post.authorPosts === 1 ? " post" : " posts") + "</span>" +
			"<span class=\"ghotiBoardPostDate\">" + ghotiEscapeHtml(boardsDate(post.createdAt)) +
				(post.editedAt ? " (edited)" : "") + "</span>" +
			actions +
		"</header>" +
		"<div class=\"ghotiBoardPostBody\" data-board-body>" + boardsBodyHtml(post.body) + "</div>" +
	"</article>";
}

function boardsThreadHtml(containerId, data){
	var isComments = data.board.mode === "comments";
	var subtitle = "";
	var tools = "";

	if(!isComments && data.topic){
		subtitle = "<h3 class=\"ghotiBoardTopicTitle\">" + ghotiEscapeHtml(data.topic.title || "(untitled)") + "</h3>" +
			"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" data-board-action=\"back\">&larr; All topics</button>";
		if(data.moderates){
			tools = "<div class=\"ghotiBoardModTools\">" +
				"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" data-board-action=\"lock\">" + (data.topic.locked ? "Unlock" : "Lock") + "</button>" +
				"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonSecondary\" data-board-action=\"sticky\">" + (data.topic.sticky ? "Unpin" : "Pin") + "</button>" +
				"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" data-board-action=\"delete-topic\">Delete topic</button>" +
			"</div>";
		}
	}

	var posts = (data.posts || []).map(function(post){ return boardsPostHtml(post, data.moderates); }).join("");
	if(!posts){
		posts = "<p class=\"ghotiBoardEmpty\">" + (isComments ? "No comments yet. Be the first." : "No posts in this topic.") + "</p>";
	}

	var reply;
	if(!data.canPost){
		reply = "<p class=\"ghotiBoardMessage\">" + ghotiEscapeHtml(data.postRefusal || "You cannot post here.") + "</p>";
	}else{
		reply = "<form class=\"ghotiForm ghotiBoardForm\" data-board-form=\"reply\" action=\"#\">" +
			"<label class=\"ghotiField\"><span>" + (isComments ? "Comment" : "Reply") + "</span>" +
			"<textarea data-board-field=\"body\" rows=\"" + (isComments ? 4 : 6) + "\" required " +
				"placeholder=\"" + (isComments ? "Write your comment here..." : "Write your reply here...") + "\"></textarea></label>" +
			"<p class=\"ghotiFormError\" data-board-error role=\"alert\" hidden></p>" +
			"<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">" + (isComments ? "Comment" : "Reply") + "</button></div>" +
		"</form>";
	}

	//A comments board is the same renderer with its thread list turned off, so
	//it skips the board heading a standalone message board wants.
	var header = isComments
		? "<header class=\"ghotiBoardHeader ghotiBoardCommentsHeader\"><h2>" + ghotiEscapeHtml(data.board.name) + "</h2></header>"
		: boardsHeaderHtml(data, subtitle);

	return header + tools + "<div class=\"ghotiBoardPosts\">" + posts + "</div>" +
		boardsPagerHtml(containerId, data, data.topic ? data.topic.topicId : 0) + reply;
}

function boardsSetError(node, message){
	var box = node.querySelector("[data-board-error]");
	if(!box){ return; }
	box.textContent = message || "";
	box.hidden = !message;
}

/*
 * The container is re-rendered on every navigation, but it is the SAME element
 * each time - so the listeners go on once and are delegated. Binding per render
 * stacked a fresh pair on every page change, and by the third topic a single
 * Delete click fired three deletes.
 */
var boardsBound = {};
function boardsBind(containerId){
	var node = document.getElementById(containerId);
	if(!node || boardsBound[containerId]){ return; }
	boardsBound[containerId] = true;
	var slug = node.getAttribute("data-board-slug");

	node.addEventListener("click", function(event){
		var button = event.target.closest("[data-board-action]");
		if(!button || !node.contains(button)){ return; }
		var action = button.getAttribute("data-board-action");

		if(action === "open"){ return boardsLoad(containerId, button.getAttribute("data-topic"), 1); }
		if(action === "back"){ return boardsLoad(containerId, 0, 1); }
		if(action === "page"){ return boardsLoad(containerId, button.getAttribute("data-topic"), button.getAttribute("data-page")); }
		if(action === "edit"){ return boardsBeginEdit(containerId, button.getAttribute("data-post")); }

		if(action === "delete-post"){
			if(!window.confirm("Delete this post? This cannot be undone.")){ return; }
			return x_boardsDeletePost(button.getAttribute("data-post"), function(result){
				if(!boardsOk(result)){ return pageFeedBack(boardsError(result, "The post could not be deleted.")); }
				//Deleting the last post of a thread takes the thread with it, so
				//there is no longer a topic to return to.
				if(result.data.topicDeleted){ return boardsLoad(containerId, 0, 1); }
				boardsReload(containerId);
			});
		}
		if(action === "lock" || action === "sticky"){
			var topic = boardsCurrentTopic(containerId);
			if(!topic){ return; }
			var locked = action === "lock" ? !topic.locked : topic.locked;
			var sticky = action === "sticky" ? !topic.sticky : topic.sticky;
			return x_boardsSetTopicFlags(topic.topicId, locked, sticky, function(result){
				if(!boardsOk(result)){ return pageFeedBack(boardsError(result, "The topic could not be updated.")); }
				boardsReload(containerId);
			});
		}
		if(action === "delete-topic"){
			var current = boardsCurrentTopic(containerId);
			if(!current || !window.confirm("Delete this topic and every post in it? This cannot be undone.")){ return; }
			return x_boardsDeleteTopic(current.topicId, function(result){
				if(!boardsOk(result)){ return pageFeedBack(boardsError(result, "The topic could not be deleted.")); }
				boardsLoad(containerId, 0, 1);
			});
		}
	});

	node.addEventListener("submit", function(event){
		var form = event.target.closest("[data-board-form]");
		if(!form){ return; }
		event.preventDefault();
		var kind = form.getAttribute("data-board-form");
		var body = form.querySelector("[data-board-field=body]").value.trim();
		if(!body){ return boardsSetError(form, "Write something first."); }

		if(kind === "topic"){
			var title = form.querySelector("[data-board-field=title]").value.trim();
			if(!title){ return boardsSetError(form, "Give the topic a title."); }
			return x_boardsAddTopic(slug, title, body, function(result){
				if(!boardsOk(result)){ return boardsSetError(form, boardsError(result, "The topic could not be posted.")); }
				boardsLoad(containerId, result.data.topicId, 1);
			});
		}
		if(kind === "edit"){
			return x_boardsEditPost(form.getAttribute("data-post"), body, function(result){
				if(!boardsOk(result)){ return boardsSetError(form, boardsError(result, "The post could not be saved.")); }
				boardsReload(containerId);
			});
		}
		var live = boardsState[containerId] || { topicId: 0, page: 1 };
		return x_boardsAddPost(slug, live.topicId, body, function(result){
			if(!boardsOk(result)){ return boardsSetError(form, boardsError(result, "Your post could not be saved.")); }
			//A comments board makes its thread on the first post, so take the
			//topic id the server settled on rather than assuming the one we had.
			boardsLoad(containerId, result.data.topicId, live.page);
		});
	});
}

//What the thread view is currently showing, kept so the moderation buttons can
//toggle one flag without clearing the other.
var boardsTopicCache = {};
function boardsCurrentTopic(containerId){
	return boardsTopicCache[containerId] || null;
}

//Swap one post's body for an edit form in place, so the rest of the thread
//stays on screen while it is rewritten.
function boardsBeginEdit(containerId, postId){
	var node = document.getElementById(containerId);
	if(!node){ return; }
	var article = node.querySelector(".ghotiBoardPost[data-post-id=\"" + parseInt(postId, 10) + "\"]");
	if(!article || article.querySelector("[data-board-form]")){ return; }
	var bodyNode = article.querySelector("[data-board-body]");
	var text = bodyNode.innerHTML.replace(/<br\s*\/?>/gi, "\n");
	var textarea = document.createElement("textarea");
	textarea.innerHTML = text; // decode the entities escaping put in
	bodyNode.hidden = true;
	var form = document.createElement("form");
	form.className = "ghotiForm ghotiBoardForm";
	form.setAttribute("data-board-form", "edit");
	form.setAttribute("data-post", parseInt(postId, 10));
	form.innerHTML = "<label class=\"ghotiField\"><span>Edit post</span><textarea data-board-field=\"body\" rows=\"6\" required></textarea></label>" +
		"<p class=\"ghotiFormError\" data-board-error role=\"alert\" hidden></p>" +
		"<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Save</button>" +
		"<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" data-board-action=\"cancel-edit\">Cancel</button></div>";
	form.querySelector("[data-board-field=body]").value = textarea.value;
	form.querySelector("[data-board-action=cancel-edit]").addEventListener("click", function(){
		form.remove();
		bodyNode.hidden = false;
	});
	article.appendChild(form);
	form.querySelector("[data-board-field=body]").focus();
}

/* ---------------------------------------------------------------- *
 *  Admin: the Boards screen
 * ---------------------------------------------------------------- */

function showBoardManager(){
	x_boardsGetBoards(showBoardManager_cb);
}

function boardsDocsHtml(){
	return ghotiDocsHtml("How to use boards", "placing, modes & moderators", [
		{ heading: "Place a board",
		  list: ["A board only appears where you put it. Type its shortcode, such as <b>[board:general]</b>, into any page body.",
		         "Use <b>Copy</b> beside a board to grab its shortcode.",
		         "The same board can appear on more than one page; it is one conversation either way."] },
		{ heading: "Modes",
		  list: ["<b>Message board</b> shows a list of topics that each open into their own thread.",
		         "<b>Comment section</b> shows one flat stream of comments with no topic list &mdash; put it at the bottom of a page you want commentable."] },
		{ heading: "Who can read and post",
		  list: ["<b>Readers</b> chooses whether signed-out visitors can read the board at all.",
		         "<b>Posting</b> chooses between any signed-in user and moderators only.",
		         "<b>Locked</b> makes the whole board read-only for everyone but its moderators."] },
		{ heading: "Moderators",
		  list: ["Moderators are set per board in <b>Users</b>, under <b>Boards</b> beside each account.",
		         "A moderator can edit and delete any post on their board, and lock, pin or delete its topics.",
		         "Administrators moderate every board without being named on it."] }
	]);
}

function boardRowHtml(board){
	var shortcode = "[board:" + board.slug + "]";
	var id = parseInt(board.boardId, 10);
	return "<article class=\"ghotiCrudRow ghotiBoardRow\" data-board-id=\"" + id + "\">" +
		"<div class=\"ghotiFormGrid\">" +
			"<label class=\"ghotiField\"><span>Name</span><input type=\"text\" data-board-input=\"name\" maxlength=\"64\" value=\"" + ghotiEscapeHtmlAttr(board.name) + "\" /></label>" +
			"<label class=\"ghotiField\"><span>Shortcode name</span><input type=\"text\" data-board-input=\"slug\" maxlength=\"64\" value=\"" + ghotiEscapeHtmlAttr(board.slug) + "\" /></label>" +
			"<label class=\"ghotiField\"><span>Description</span><input type=\"text\" data-board-input=\"description\" maxlength=\"255\" value=\"" + ghotiEscapeHtmlAttr(board.description) + "\" /></label>" +
			"<label class=\"ghotiField\"><span>Mode</span><select data-board-input=\"mode\">" +
				"<option value=\"board\"" + (board.mode === "board" ? " selected" : "") + ">Message board</option>" +
				"<option value=\"comments\"" + (board.mode === "comments" ? " selected" : "") + ">Comment section</option>" +
			"</select></label>" +
			"<label class=\"ghotiField\"><span>Readers</span><select data-board-input=\"readPolicy\">" +
				"<option value=\"public\"" + (board.readPolicy === "public" ? " selected" : "") + ">Anyone</option>" +
				"<option value=\"users\"" + (board.readPolicy === "users" ? " selected" : "") + ">Signed-in users</option>" +
			"</select></label>" +
			"<label class=\"ghotiField\"><span>Posting</span><select data-board-input=\"postPolicy\">" +
				"<option value=\"users\"" + (board.postPolicy === "users" ? " selected" : "") + ">Signed-in users</option>" +
				"<option value=\"mods\"" + (board.postPolicy === "mods" ? " selected" : "") + ">Moderators only</option>" +
			"</select></label>" +
			"<label class=\"ghotiField\"><span>Order</span><input type=\"number\" data-board-input=\"sortOrder\" value=\"" + parseInt(board.sortOrder, 10) + "\" /></label>" +
			"<label class=\"ghotiField ghotiFieldCheck\"><input type=\"checkbox\" data-board-input=\"locked\"" + (board.locked ? " checked" : "") + " /><span>Locked</span></label>" +
		"</div>" +
		"<div class=\"ghotiCrudRowActions\">" +
			"<span class=\"ghotiCrudMeta\"><code>" + ghotiEscapeHtml(shortcode) + "</code> &middot; " +
				board.topicCount + (board.topicCount === 1 ? " topic" : " topics") + " &middot; " +
				board.postCount + (board.postCount === 1 ? " post" : " posts") + "</span>" +
			"<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary ghotiButtonCompact\" data-boards-action=\"copy\" data-shortcode=\"" + ghotiEscapeHtmlAttr(shortcode) + "\">Copy</button>" +
			"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact\" data-boards-action=\"save\">Save</button>" +
			"<button type=\"button\" class=\"ghotiButton ghotiButtonCompact ghotiButtonDanger\" data-boards-action=\"delete\">Delete</button>" +
			"<span class=\"ghotiBoardStatus\" role=\"status\"></span>" +
		"</div>" +
	"</article>";
}

function showBoardManager_cb(result){
	var body;
	if(!boardsOk(result)){
		body = "<p class=\"ghotiEmptyState\" role=\"alert\">" + ghotiEscapeHtml(boardsError(result, "Could not load the boards.")) + "</p>";
	}else if(!result.data.boards.length){
		body = "<p class=\"ghotiEmptyState\">No boards yet. Press <b>Add Board</b> to create one.</p>";
	}else{
		body = "<div class=\"ghotiCrudList\">" + result.data.boards.map(boardRowHtml).join("") + "</div>";
	}
	$("#ghotiContent").html(
		"<section id=\"ghotiManageBoards\" class=\"ghotiAdminPanel\">" +
			"<div class=\"ghotiCrudHeader\"><h1>Boards</h1>" +
			"<button type=\"button\" class=\"ghotiButton ghotiButtonSecondary\" onclick=\"addBoardForm();\">Add Board</button></div>" +
			body + boardsDocsHtml() +
		"</section>"
	);
	bindBoardManager();
}

function boardRowValues(row){
	function field(name){ return row.querySelector("[data-board-input=" + name + "]"); }
	return {
		name: field("name").value.trim(),
		slug: field("slug").value.trim(),
		description: field("description").value.trim(),
		mode: field("mode").value,
		postPolicy: field("postPolicy").value,
		readPolicy: field("readPolicy").value,
		sortOrder: parseInt(field("sortOrder").value, 10) || 0,
		locked: field("locked").checked
	};
}

function bindBoardManager(){
	var panel = document.getElementById("ghotiManageBoards");
	if(!panel){ return; }
	panel.addEventListener("click", function(event){
		var button = event.target.closest("[data-boards-action]");
		if(!button){ return; }
		var row = button.closest(".ghotiBoardRow");
		var action = button.getAttribute("data-boards-action");

		if(action === "copy"){
			var code = button.getAttribute("data-shortcode");
			if(navigator.clipboard){ navigator.clipboard.writeText(code); }
			row.querySelector(".ghotiBoardStatus").textContent = "Copied " + code;
			return;
		}
		var boardId = parseInt(row.getAttribute("data-board-id"), 10);
		if(action === "save"){
			var values = boardRowValues(row);
			return x_boardsSaveBoard(boardId, values.name, values.slug, values.description, values.mode,
				values.postPolicy, values.readPolicy, values.locked, values.sortOrder, function(result){
					var status = row.querySelector(".ghotiBoardStatus");
					if(!boardsOk(result)){ status.textContent = boardsError(result, "Not saved."); return; }
					status.textContent = "Saved";
					showBoardManager(); // the shortcode shown in the row may have changed
				});
		}
		if(action === "delete"){
			if(!window.confirm("Delete this board and every topic and post on it? This cannot be undone.")){ return; }
			return x_boardsDeleteBoard(boardId, function(result){
				if(!boardsOk(result)){ return pageFeedBack(boardsError(result, "The board could not be deleted.")); }
				showBoardManager();
			});
		}
	});
}

function addBoardForm(){
	$("#popup-content").html(
		"<form id=\"addBoardForm\" class=\"ghotiForm\" action=\"#\" novalidate>" +
			"<label class=\"ghotiField\"><span>Board name</span><input type=\"text\" id=\"newBoardName\" maxlength=\"64\" autocomplete=\"off\" required /></label>" +
			"<label class=\"ghotiField\"><span>Shortcode name</span><input type=\"text\" id=\"newBoardSlug\" maxlength=\"64\" autocomplete=\"off\" placeholder=\"leave blank to use the name\" /></label>" +
			"<p class=\"ghotiHelpText\">Place the board in a page with <b>[board:name]</b>.</p>" +
			"<label class=\"ghotiField\"><span>Description</span><input type=\"text\" id=\"newBoardDescription\" maxlength=\"255\" autocomplete=\"off\" /></label>" +
			"<label class=\"ghotiField\"><span>Mode</span><select id=\"newBoardMode\">" +
				"<option value=\"board\">Message board (topics)</option>" +
				"<option value=\"comments\">Comment section (one stream)</option>" +
			"</select></label>" +
			"<label class=\"ghotiField\"><span>Readers</span><select id=\"newBoardRead\">" +
				"<option value=\"public\">Anyone</option><option value=\"users\">Signed-in users</option>" +
			"</select></label>" +
			"<label class=\"ghotiField\"><span>Posting</span><select id=\"newBoardPost\">" +
				"<option value=\"users\">Signed-in users</option><option value=\"mods\">Moderators only</option>" +
			"</select></label>" +
			"<p id=\"boardFormError\" class=\"ghotiFormError\" role=\"alert\" hidden></p>" +
			"<div class=\"ghotiFormActions\"><button type=\"submit\" class=\"ghotiButton\">Add Board</button></div>" +
		"</form>"
	);
	var form = document.getElementById("addBoardForm");
	form.addEventListener("submit", function(event){
		event.preventDefault();
		addBoard();
	});
	$("#popupTitle").html("Add a board");
	showPopup();
}

function setBoardFormError(message){
	var box = document.getElementById("boardFormError");
	if(!box){ return; }
	box.textContent = message || "";
	box.hidden = !message;
}

function addBoard(){
	var name = document.getElementById("newBoardName").value.trim();
	if(!name){ return setBoardFormError("Give the board a name."); }
	x_boardsAddBoard(
		name,
		document.getElementById("newBoardSlug").value.trim(),
		document.getElementById("newBoardDescription").value.trim(),
		document.getElementById("newBoardMode").value,
		document.getElementById("newBoardPost").value,
		document.getElementById("newBoardRead").value,
		0,
		function(result){
			if(!document.getElementById("addBoardForm")){ return; } // popup closed meanwhile
			if(!boardsOk(result)){ return setBoardFormError(boardsError(result, "The board could not be created.")); }
			cancelPopup("popup-bg");
			pageFeedBack("Board created. Place it with [board:" + result.data.slug + "]");
			if(document.getElementById("ghotiManageBoards")){ showBoardManager(); }
		}
	);
}

/* ---------------------------------------------------------------- *
 *  Manage Users: per-board moderator checkboxes
 * ---------------------------------------------------------------- */

function boardsModeratorDialog(userId){
	x_boardsGetUserModeration(userId, function(result){
		if(!boardsOk(result)){ return pageFeedBack(boardsError(result, "Could not load the boards.")); }
		var boards = result.data.boards;
		var body = boards.length
			? "<form id=\"boardsModeratorForm\" class=\"ghotiForm\" action=\"#\">" + boards.map(function(board){
					return "<label class=\"ghotiField ghotiFieldCheck\">" +
						"<input type=\"checkbox\" data-moderates-board=\"" + parseInt(board.boardId, 10) + "\"" + (board.moderates ? " checked" : "") + " />" +
						"<span>" + ghotiEscapeHtml(board.name) + " <code>[board:" + ghotiEscapeHtml(board.slug) + "]</code></span></label>";
				}).join("") + "</form>"
			: "<p class=\"ghotiEmptyState\">No boards yet. Create one in <b>Boards</b> first.</p>";
		$("#popup-content").html(body + "<p class=\"ghotiHelpText\">Changes save as you tick them. Administrators already moderate every board.</p><span id=\"boardsModeratorStatus\" role=\"status\"></span>");
		$("#popupTitle").html("Moderates");
		var form = document.getElementById("boardsModeratorForm");
		if(form){
			form.addEventListener("change", function(event){
				var box = event.target.closest("[data-moderates-board]");
				if(!box){ return; }
				x_boardsSetModerator(userId, box.getAttribute("data-moderates-board"), box.checked, function(reply){
					if(!boardsOk(reply)){
						box.checked = !box.checked; // put the tick back the way the server still sees it
						$("#boardsModeratorStatus").text(boardsError(reply, "Not saved."));
						return;
					}
					$("#boardsModeratorStatus").text("Saved");
				});
			});
		}
		showPopup();
	});
}
